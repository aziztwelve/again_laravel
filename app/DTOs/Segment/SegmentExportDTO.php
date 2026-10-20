<?php

namespace App\DTOs\Segment;

class SegmentExportDTO
{
    public const HEADERS = [
        'id'=>'ID', 'full_name'=>'ФИО', 'email'=>'Email', 'phone'=>'Телефон',
        'birthday'=>'Дата рождения', 'address'=>'Адрес', 'registered_at'=>'Дата регистрации',
        'orders_count'=>'Количество покупок', 'total_amount'=>'Сумма покупок', 'average_check'=>'Средний чек',
        'segment_name'=>'Сегмент', 'membership_source'=>'Добавление', 'added_to_segment_at'=>'Дата добавления',
        'subscribed_to_newsletter'=>'Подписка Email',
        'personal_data_consent'=>'Согласие ПДн', 'tags'=>'Теги',
    ];
    public const DEFAULT_COLUMNS = ['id','full_name','phone','email','birthday','address','average_check','total_amount','orders_count'];

    public function __construct(
        public readonly int $segmentId,
        public readonly array $columns = self::DEFAULT_COLUMNS,
        public readonly string $channel = 'all',
        public readonly string $origin = 'all',
        public readonly bool $consentOnly = false,
        public readonly bool $deduplicate = true,
        public readonly string $delimiter = ';',
    ) {}

    public static function fromRequest(int $segmentId, array $data): self
    {
        $channel = $data['channel'] ?? 'all';
        $defaults = match ($channel) {
            'email' => ['id','full_name','email'],
            default => self::DEFAULT_COLUMNS,
        };
        return new self($segmentId, $data['columns'] ?? $defaults, $channel, $data['origin'] ?? 'all',
            (bool)($data['consent_only'] ?? ($channel !== 'all')),
            (bool)($data['deduplicate'] ?? true), $data['delimiter'] ?? ';');
    }

    public function getHeaders(): array { return self::HEADERS; }

    public function getSelectedHeaders(): array
    {
        $headers=[];
        foreach ($this->columns as $column) $headers[$column]=self::HEADERS[$column];
        return $headers;
    }
}
