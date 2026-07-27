<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ProductVariant;

/** Safely links legacy order items which were imported without an SKU. */
class LegacyOrderItemNameMatcher
{
    private const SIZES = ['xxs', 'xs', 's', 'm', 'l', 'xl', 'xxl', 'xxxl', 'xxxxl', 'xxxxxl'];

    /** @var array<string, array<int, int>> */
    private array $productsByAlias = [];
    /** @var array<int, array<int, array{id:int,sku:string}>> */
    private array $variantsByProduct = [];

    public function __construct()
    {
        Product::query()->select(['id', 'code'])->chunkById(500, function ($products) {
            foreach ($products as $product) {
                $code = trim((string) $product->code);
                if ($code === '') continue;

                $aliases = [$this->normalize($code)];
                $parts = array_filter(explode('-', mb_strtolower($code)));
                if (count($parts) === 2) $aliases[] = $this->normalize($parts[1].'-'.$parts[0]);

                foreach (array_unique($aliases) as $alias) {
                    if ($alias !== '') $this->productsByAlias[$alias][] = $product->id;
                }
            }
        });

        ProductVariant::query()->select(['id', 'product_id', 'sku'])->chunkById(1000, function ($variants) {
            foreach ($variants as $variant) {
                $sku = trim((string) $variant->sku);
                if ($sku !== '') $this->variantsByProduct[$variant->product_id][] = ['id' => $variant->id, 'sku' => $sku];
            }
        });
    }

    /** @return array{product_id:?int,variant_id:?int,reason:string} */
    public function match(string $legacyName): array
    {
        $base = preg_replace('/\s*\([^)]*\)\s*$/u', '', trim($legacyName)) ?: '';
        $productIds = array_values(array_unique($this->productsByAlias[$this->normalize($base)] ?? []));
        if (count($productIds) !== 1) return ['product_id' => null, 'variant_id' => null, 'reason' => count($productIds) ? 'ambiguous_product' : 'product_not_found'];

        $productId = $productIds[0];
        [$size, $color] = $this->parseOptions($legacyName);
        if ($size === null && $color === null) return ['product_id' => $productId, 'variant_id' => null, 'reason' => 'product_only'];

        $matches = array_values(array_filter($this->variantsByProduct[$productId] ?? [], function (array $variant) use ($size, $color) {
            $sku = mb_strtolower($variant['sku']);
            return ($size === null || preg_match('/(?:^|-)'.preg_quote($size, '/').'(?:$|-)/u', $sku))
                && ($color === null || str_contains($sku, $color));
        }));

        return count($matches) === 1
            ? ['product_id' => $productId, 'variant_id' => $matches[0]['id'], 'reason' => 'product_and_variant']
            : ['product_id' => $productId, 'variant_id' => null, 'reason' => count($matches) ? 'ambiguous_variant' : 'variant_not_found'];
    }

    /** @return array{0:?string,1:?string} */
    private function parseOptions(string $name): array
    {
        if (!preg_match('/\(([^)]*)\)/u', $name, $match)) return [null, null];
        $parts = array_map(fn ($v) => mb_strtolower(trim($v)), preg_split('/\s*\/\s*/u', $match[1]) ?: []);
        $size = collect($parts)->first(fn ($v) => in_array($v, self::SIZES, true));
        $color = collect($parts)->first(fn ($v) => !in_array($v, self::SIZES, true));
        return [$size, $color ? str_replace('ё', 'е', $color) : null];
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^\pL\pN]+/u', '', mb_strtolower(str_replace('ё', 'е', $value))) ?: '';
    }
}
