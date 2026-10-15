<?php

namespace App\Services\Cart;

use App\Helpers\NumberHelper;
use App\Models\Cart;
use DOMDocument;

/** Literal substitutions only: stored templates never execute PHP, Blade or Liquid. */
class CartMessageRenderer
{
    public function render(Cart $cart, array $template, string $url): array
    {
        $cart->loadMissing(['client.profile', 'items.product', 'items.productVariant', 'items.color']);
        $items = $cart->items->map(fn ($item) => ($item->product?->name ?? 'Товар')
            .($item->productVariant?->name ? ' ('.$item->productVariant->name.')' : '')
            .($item->color?->name ? ' · '.$item->color->name : '')
            .' — '.$item->quantity.' шт. × '.NumberHelper::formatRussian($item->price, 2).' ₽')->implode("\n");
        $values = [
            '{{client.name}}' => $cart->client?->profile?->first_name ?? '',
            '{{cart.id}}' => (string) $cart->id,
            '{{cart.items}}' => $items,
            '{{cart.total}}' => NumberHelper::formatRussian($cart->total, 2).' ₽',
            '{{cart.url}}' => $url,
            '{{shop.name}}' => (string) config('app.name', 'AGAIN'),
        ];
        $subject = str_replace(["\r", "\n"], ' ', strtr($template['subject'] ?? '', $values));
        if (($template['format'] ?? 'text') === 'html') {
            $escaped = array_map(fn ($value) => e($value), $values);
            $escaped['{{cart.items}}'] = nl2br($escaped['{{cart.items}}']);
            $html = $this->sanitize(strtr($template['body'], $escaped));
            $body = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/(?:p|div|li|tr|h[1-6])>/i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        } else {
            $body = strtr($template['body'], $values);
            $html = nl2br(e($body));
        }

        return ['subject' => $subject, 'body' => $body, 'html' => $html];
    }

    private function sanitize(string $html): string
    {
        $html = preg_replace('/<(script|style|iframe|object|embed|form)\b[^>]*>.*?<\/\1\s*>/is', '', $html);
        $html = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li><a><div><span><h1><h2><h3><table><tbody><tr><td><th>');
        $document = new DOMDocument;
        $old = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8" ?><div>'.$html.'</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            foreach ($document->getElementsByTagName('*') as $element) {
                foreach (iterator_to_array($element->attributes) as $attribute) {
                    if ($element->tagName === 'a' && $attribute->name === 'href'
                        && preg_match('~^https?://~i', trim($attribute->value))) {
                        continue;
                    }
                    $element->removeAttributeNode($attribute);
                }
            }
            return $document->saveHTML($document->documentElement) ?: '';
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
    }
}
