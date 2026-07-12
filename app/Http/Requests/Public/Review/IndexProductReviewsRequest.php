<?php

namespace App\Http\Requests\Public\Review;

use Illuminate\Foundation\Http\FormRequest;

class IndexProductReviewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ];
    }
}
