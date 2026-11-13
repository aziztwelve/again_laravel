<?php

namespace App\Support\RichText;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Приводит HTML описания товара к единому виду, чтобы витрина и редактор
 * показывали его одинаково, откуда бы текст ни пришёл (визуальный редактор,
 * вставка из Word/Google Docs, МойСклад).
 *
 * Что делает:
 *  - выбрасывает инлайн-оформление, которое ломает единый стиль сайта
 *    (font-family, font-size, line-height, отступы, mso-*), оставляя цвет и
 *    выравнивание; убирает <font> и служебные классы;
 *  - «листовые» <div> (внутри только текст и инлайн-теги) превращает в <p>;
 *  - раскрывает <div>-обёртки, внутри которых одни блоки;
 *  - удаляет пустые абзацы/блоки и абзацы из одних <br>/&nbsp;;
 *  - h1 понижает до h2 (h1 на странице товара — название).
 *
 * Простой текст без тегов возвращается как есть. Повторный вызов на уже
 * нормализованном HTML ничего не меняет.
 */
class DescriptionHtml
{
    private const INLINE = ['strong', 'b', 'em', 'i', 'u', 's', 'a', 'span', 'br', 'sub', 'sup', 'small', 'mark', 'code'];

    private const REMOVE_STYLE = '/^(font(-[a-z]+)?|line-height|margin(-[a-z]+)?|padding(-[a-z]+)?|white-space|text-indent|letter-spacing|word-spacing|mso-.*|-webkit-.*)$/i';

    private const MEDIA = ['img', 'video', 'iframe', 'table', 'hr', 'picture', 'source'];

    public static function normalize(?string $html): ?string
    {
        if ($html === null || ! preg_match('/<\/?[a-z][^>]*>/i', $html)) {
            return $html;
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">'.$html.'</div>', LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('__root');
        if (! $root) {
            return $html;
        }

        self::cleanAttributes($doc);
        self::unwrapFont($doc);
        self::renameH1($doc);
        self::convertDivs($root);
        self::removeEmpty($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim(preg_replace('/\n{2,}/', "\n", $out));
    }

    private static function cleanAttributes(DOMDocument $doc): void
    {
        foreach ((new DOMXPath($doc))->query('//*[@style or @class or @align]') as $el) {
            /** @var DOMElement $el */
            if ($el->hasAttribute('style')) {
                $kept = [];
                foreach (explode(';', $el->getAttribute('style')) as $declaration) {
                    [$prop, $value] = array_pad(explode(':', $declaration, 2), 2, null);
                    if ($value === null || preg_match(self::REMOVE_STYLE, trim($prop))) {
                        continue;
                    }
                    $kept[] = trim($prop).': '.trim($value);
                }
                $kept ? $el->setAttribute('style', implode('; ', $kept).';') : $el->removeAttribute('style');
            }

            // Служебные классы Word/редакторов (MsoNormal, docs-internal-guid…) не нужны.
            if ($el->hasAttribute('class') && ! in_array($el->tagName, ['img', 'video', 'iframe', 'table'], true)) {
                $el->removeAttribute('class');
            }
            $el->removeAttribute('align');
        }
    }

    private static function unwrapFont(DOMDocument $doc): void
    {
        foreach (iterator_to_array($doc->getElementsByTagName('font')) as $font) {
            self::unwrap($font);
        }
    }

    private static function renameH1(DOMDocument $doc): void
    {
        foreach (iterator_to_array($doc->getElementsByTagName('h1')) as $h1) {
            $h2 = $doc->createElement('h2');
            while ($h1->firstChild) {
                $h2->appendChild($h1->firstChild);
            }
            $h1->parentNode->replaceChild($h2, $h1);
        }
    }

    /** Снизу вверх: листовой div → p, div из одних блоков → раскрыть. */
    private static function convertDivs(DOMElement $root): void
    {
        $divs = iterator_to_array($root->getElementsByTagName('div'));
        foreach (array_reverse($divs) as $div) {
            if (! $div->parentNode || $div->getAttribute('id') === '__root') {
                continue;
            }

            if (self::isLeaf($div)) {
                $p = $div->ownerDocument->createElement('p');
                foreach (iterator_to_array($div->attributes) as $attr) {
                    $p->setAttribute($attr->name, $attr->value);
                }
                while ($div->firstChild) {
                    $p->appendChild($div->firstChild);
                }
                $div->parentNode->replaceChild($p, $div);
            } elseif (! self::hasInlineContent($div)) {
                self::unwrap($div);
            }
        }
    }

    /** В div только текст и инлайн-теги. */
    private static function isLeaf(DOMElement $el): bool
    {
        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMElement && ! in_array($child->tagName, self::INLINE, true)) {
                return false;
            }
        }

        return true;
    }

    /** В div есть непустой текст или инлайн-тег рядом с блоками (смешанное содержимое). */
    private static function hasInlineContent(DOMElement $el): bool
    {
        foreach ($el->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE && trim(self::text($child)) !== '') {
                return true;
            }
            if ($child instanceof DOMElement && in_array($child->tagName, self::INLINE, true)) {
                return true;
            }
        }

        return false;
    }

    private static function removeEmpty(DOMElement $root): void
    {
        $nodes = (new DOMXPath($root->ownerDocument))->query('.//p | .//div | .//span | .//h2 | .//h3 | .//h4 | .//h5 | .//h6 | .//li | .//strong | .//b | .//em | .//i', $root);
        foreach (array_reverse(iterator_to_array($nodes)) as $el) {
            if ($el->parentNode && self::isBlank($el)) {
                $el->parentNode->removeChild($el);
            }
        }
    }

    private static function isBlank(DOMElement $el): bool
    {
        if (trim(self::text($el)) !== '') {
            return false;
        }
        foreach (self::MEDIA as $tag) {
            if ($el->getElementsByTagName($tag)->length > 0) {
                return false;
            }
        }
        // Список с пунктами не считаем пустым даже без текста внутри li (редкий случай).
        return true;
    }

    private static function text(DOMNode $node): string
    {
        return str_replace("\u{00A0}", ' ', $node->textContent);
    }

    private static function unwrap(DOMElement $el): void
    {
        $parent = $el->parentNode;
        while ($el->firstChild) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }
}
