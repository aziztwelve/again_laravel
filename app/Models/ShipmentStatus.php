<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
/**
 * @OA\Schema(
 *     schema="ShipmentStatus",
 *     type="object",
 *     required={"code", "name"},
 *     @OA\Property(
 *         property="id",
 *         type="integer",
 *         description="Уникальный идентификатор статуса доставки"
 *     ),
 *     @OA\Property(
 *         property="code",
 *         type="string",
 *         description="Код статуса доставки (например, 'new', 'processing')"
 *     ),
 *     @OA\Property(
 *         property="name",
 *         type="string",
 *         description="Название статуса доставки"
 *     ),
 *     @OA\Property(
 *         property="description",
 *         type="string",
 *         description="Описание статуса доставки",
 *         nullable=true
 *     ),
 *     @OA\Property(
 *         property="created_at",
 *         type="string",
 *         format="date-time",
 *         description="Дата и время создания статуса"
 *     ),
 *     @OA\Property(
 *         property="updated_at",
 *         type="string",
 *         format="date-time",
 *         description="Дата и время последнего обновления статуса"
 *     )
 * )
 */
class ShipmentStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description'
    ];

    public const NEW = 'new';
    public const PROCESSING = 'processing';
    public const READY_FOR_PICKUP = 'ready_for_pickup';
    public const IN_TRANSIT = 'in_transit';
    public const DELIVERED = 'delivered';
    public const CANCELLED = 'cancelled';
    public const RETURNED = 'returned';

    /** Человекочитаемые названия статусов справочника (см. ShipmentStatusSeeder). */
    public const TITLES = [
        self::NEW => 'Новый',
        self::PROCESSING => 'В обработке',
        self::READY_FOR_PICKUP => 'Готов к отправке',
        self::IN_TRANSIT => 'В пути',
        self::DELIVERED => 'Доставлено',
        self::CANCELLED => 'Отменено',
        self::RETURNED => 'Возврат',
    ];

    /**
     * ID статуса по коду; отсутствующий статус создаётся на месте.
     * `shipments.status_id` — NOT NULL внешний ключ, а справочник на части
     * окружений не засеян, поэтому запись отправления падала на вставке.
     */
    public static function idFor(string $code): int
    {
        return (int) static::query()->firstOrCreate(
            ['code' => $code],
            ['name' => self::TITLES[$code] ?? $code],
        )->id;
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'status_id');
    }
}
