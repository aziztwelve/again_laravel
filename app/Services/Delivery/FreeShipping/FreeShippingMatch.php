<?php

namespace App\Services\Delivery\FreeShipping;

/**
 * Результат срабатывания правила бесплатной доставки.
 */
final class FreeShippingMatch
{
    public function __construct(
        public int $ruleId,
        public string $ruleName,
        public float $minOrderAmount,
        public float $qualifyingAmount,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->ruleId,
            'name' => $this->ruleName,
            'min_order_amount' => round($this->minOrderAmount, 2),
            'qualifying_amount' => round($this->qualifyingAmount, 2),
        ];
    }
}
