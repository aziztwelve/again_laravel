<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DiscountRequest extends FormRequest
{

    public function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'type' => 'required|in:percentage,fixed,special_price',
            'value' => 'required|numeric|min:0',
            'is_active' => 'boolean',
            'customer_type' => ['nullable', Rule::in(['authorized', 'guest', 'all'])],
            'starts_at' => 'nullable|date',
            'ends_at' => [
                'nullable',
                'date',
                'after:starts_at',
                \Illuminate\Validation\Rule::requiredIf(fn() => !$this->boolean('is_unlimited', false)),
            ],
            'priority' => 'nullable|integer|min:0',
            'conditions' => 'nullable|array',
            'discount_type' => 'required|in:specific,category,all',
            'categories' => 'nullable|array|required_if:discount_type,category',
            'categories.*' => 'exists:categories,id',
            'products' => 'nullable|array|required_if:discount_type,specific',
            'products.*' => 'exists:products,id',
            'product_variants' => 'nullable|array',
            'product_variants.*' => 'exists:product_variants,id',

            // Новое поле — чекбокс
            'is_unlimited' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Укажите название скидки.',
            'name.string' => 'Название скидки должно быть строкой.',
            'name.max' => 'Название скидки не должно превышать 255 символов.',
            'type.required' => 'Выберите тип скидки.',
            'type.in' => 'Некорректный тип скидки.',
            'value.required' => 'Укажите значение скидки.',
            'value.numeric' => 'Значение скидки должно быть числом.',
            'value.min' => 'Значение скидки не может быть меньше 0.',
            'is_active.boolean' => 'Поле активности должно быть да или нет.',
            'customer_type.in' => 'Некорректная аудитория скидки.',
            'starts_at.date' => 'Дата начала должна быть корректной датой.',
            'ends_at.required' => 'Укажите дату окончания или включите бессрочную скидку.',
            'ends_at.date' => 'Дата окончания должна быть корректной датой.',
            'ends_at.after' => 'Дата окончания должна быть позже даты начала.',
            'priority.integer' => 'Приоритет должен быть целым числом.',
            'priority.min' => 'Приоритет не может быть меньше 0.',
            'conditions.array' => 'Условия скидки должны быть массивом.',
            'discount_type.required' => 'Выберите область применения скидки.',
            'discount_type.in' => 'Некорректная область применения скидки.',
            'categories.array' => 'Категории должны быть массивом.',
            'categories.required_if' => 'Выберите категории для скидки по категориям.',
            'categories.*.exists' => 'Выбрана несуществующая категория.',
            'products.array' => 'Товары должны быть массивом.',
            'products.required_if' => 'Выберите товары для скидки на конкретные товары.',
            'products.*.exists' => 'Выбран несуществующий товар.',
            'product_variants.array' => 'Варианты товаров должны быть массивом.',
            'product_variants.*.exists' => 'Выбран несуществующий вариант товара.',
            'is_unlimited.boolean' => 'Поле бессрочной скидки должно быть да или нет.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'название скидки',
            'type' => 'тип скидки',
            'value' => 'значение скидки',
            'is_active' => 'активность',
            'customer_type' => 'аудитория',
            'starts_at' => 'дата начала',
            'ends_at' => 'дата окончания',
            'priority' => 'приоритет',
            'conditions' => 'условия',
            'discount_type' => 'область применения',
            'categories' => 'категории',
            'products' => 'товары',
            'product_variants' => 'варианты товаров',
            'is_unlimited' => 'бессрочная скидка',
        ];
    }

}
