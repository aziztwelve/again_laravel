<?php

namespace App\Http\Requests\Utm;

use App\Rules\AllowedTargetHost;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUtmLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:150',
            'marketing_channel_id' => 'sometimes|required|integer|exists:marketing_channels,id',
            'utm_tag_id' => 'nullable|integer|exists:utm_tags,id',
            'target_url' => ['sometimes', 'required', 'url', 'max:2048', new AllowedTargetHost],
            'utm_source' => 'nullable|string|max:255',
            'utm_medium' => 'nullable|string|max:255',
            'utm_campaign' => 'nullable|string|max:255',
            'utm_content' => 'nullable|string|max:255',
            'utm_term' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'marketing_channel_id.exists' => 'Канал не найден',
            'utm_tag_id.exists' => 'Тег не найден',
            'target_url.url' => 'Целевая страница должна быть корректным URL',
        ];
    }
}
