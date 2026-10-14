<?php

namespace App\Http\Requests\Segment;

use App\DTOs\Segment\SegmentExportDTO;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportSegmentRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() instanceof User; }

    public function rules(): array
    {
        return [
            'channel'=>['sometimes', Rule::in(['all','email','whatsapp'])],
            'origin'=>['sometimes', Rule::in(['all','manual','auto'])],
            'consent_only'=>'sometimes|boolean', 'deduplicate'=>'sometimes|boolean',
            'delimiter'=>['sometimes', Rule::in([';', ','])],
            'columns'=>'sometimes|array|min:1|max:17',
            'columns.*'=>['required','string','distinct',Rule::in(array_keys(SegmentExportDTO::HEADERS))],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $contact = match ($this->input('channel')) { 'email'=>'email', 'whatsapp'=>'phone', default=>null };
            if ($contact && $this->has('columns') && is_array($this->input('columns')) && !in_array($contact,$this->input('columns'))) {
                $validator->errors()->add('columns', 'Для выбранного канала нужен столбец контакта.');
            }
        });
    }
}
