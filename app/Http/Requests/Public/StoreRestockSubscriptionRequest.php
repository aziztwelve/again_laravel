<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class StoreRestockSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            // Обязательный чекбокс согласия ПДн/маркетинг (#8).
            'consent' => ['required', 'accepted'],
            'meta' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required' => 'Не указан товар.',
            'product_id.exists' => 'Товар не найден.',
            'email.required' => 'Укажите эл. почту.',
            'email.email' => 'Некорректный адрес эл. почты.',
            'consent.required' => 'Необходимо согласие на рассылку.',
            'consent.accepted' => 'Необходимо согласие на рассылку.',
        ];
    }
}
