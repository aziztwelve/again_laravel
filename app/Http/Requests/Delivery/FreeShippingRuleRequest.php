<?php

namespace App\Http\Requests\Delivery;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Валидация правила бесплатной доставки (docs/tasks/free-shipping.md).
 *
 * Пустой мультивыбор допустим и означает «условие не ограничивает».
 */
class FreeShippingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = in_array($this->method(), ['PUT', 'PATCH'], true);
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
            'min_order_amount' => [$required, 'numeric', 'min:0', 'max:99999999'],

            'services' => ['nullable', 'array'],
            'services.*' => ['string', Rule::in(array_keys(config('free_shipping.services', [])))],

            'delivery_types' => ['nullable', 'array'],
            'delivery_types.*' => ['string', Rule::in(array_keys(config('free_shipping.delivery_types', [])))],

            'payment_methods' => ['nullable', 'array'],
            'payment_methods.*' => ['string', Rule::in(array_keys(config('free_shipping.payment_methods', [])))],

            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],

            // ВНИМАНИЕ: в legacy-справочниках есть запись с id = 0
            // (Россия / «Москва и Московская обл.»), поэтому min:0.
            'country_ids' => ['nullable', 'array'],
            'country_ids.*' => ['integer', 'min:0', 'exists:country,id'],

            'region_ids' => ['nullable', 'array'],
            'region_ids.*' => ['integer', 'min:0', 'exists:region,id'],

            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Укажите название правила',
            'min_order_amount.required' => 'Укажите сумму, от которой доставка бесплатна',
            'min_order_amount.numeric' => 'Сумма должна быть числом',
            'ends_at.after_or_equal' => 'Дата окончания не может быть раньше даты начала',
        ];
    }
}
