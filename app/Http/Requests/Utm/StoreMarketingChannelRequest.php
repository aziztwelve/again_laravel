<?php

namespace App\Http\Requests\Utm;

use Illuminate\Foundation\Http\FormRequest;

class StoreMarketingChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50|alpha_dash|unique:marketing_channels,code',
            'is_active' => 'sometimes|boolean',
            'sort' => 'sometimes|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Название канала обязательно',
            'code.required' => 'Код канала обязателен',
            'code.unique' => 'Канал с таким кодом уже существует',
            'code.alpha_dash' => 'Код может содержать только буквы, цифры, дефис и подчёркивание',
        ];
    }
}
