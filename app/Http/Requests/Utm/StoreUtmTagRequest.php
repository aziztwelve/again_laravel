<?php

namespace App\Http\Requests\Utm;

use Illuminate\Foundation\Http\FormRequest;

class StoreUtmTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100|unique:utm_tags,name',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Название тега обязательно',
            'name.unique' => 'Тег с таким названием уже существует',
        ];
    }
}
