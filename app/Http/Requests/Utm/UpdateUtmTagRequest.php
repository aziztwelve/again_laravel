<?php

namespace App\Http\Requests\Utm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUtmTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tagId = $this->route('utm_tag')?->id ?? $this->route('utm_tag');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('utm_tags', 'name')->ignore($tagId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Тег с таким названием уже существует',
        ];
    }
}
