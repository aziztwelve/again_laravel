<?php

namespace App\Http\Requests\Utm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMarketingChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $channelId = $this->route('marketing_channel')?->id
            ?? $this->route('marketing_channel');

        return [
            'name' => 'sometimes|required|string|max:100',
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                'alpha_dash',
                Rule::unique('marketing_channels', 'code')->ignore($channelId),
            ],
            'is_active' => 'sometimes|boolean',
            'sort' => 'sometimes|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'Канал с таким кодом уже существует',
            'code.alpha_dash' => 'Код может содержать только буквы, цифры, дефис и подчёркивание',
        ];
    }
}
