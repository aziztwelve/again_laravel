<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'order_id',
        'provider',
        'amount',
        'currency',
        'status',
        'provider_payment_id',
        'provider_data',
        'error_message'
    ];

    /** Подписи для карточки заказа в админке (виджет «Платежи»). */
    protected $appends = ['provider_label', 'status_label', 'refund_label'];

    protected $casts = [
        'amount' => 'decimal:2',
        'provider_data' => 'array'
    ];

    public function getProviderLabelAttribute(): string
    {
        return match ($this->provider) {
            'robokassa' => 'Robokassa',
            'cloudpayment' => 'CloudPayments',
            'yandexpay' => 'Яндекс Пэй',
            'yookassa' => 'ЮKassa',
            default => (string) $this->provider,
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Ожидает оплаты',
            self::STATUS_COMPLETED => 'Оплачен',
            self::STATUS_FAILED => 'Не прошёл',
            self::STATUS_REFUNDED => 'Возвращён',
            default => (string) $this->status,
        };
    }

    /** Состояние возврата, если он был запрошен или найден (Robokassa отдаёт finished / processing / canceled). */
    public function getRefundLabelAttribute(): ?string
    {
        $label = data_get($this->provider_data, 'refund.label');

        if ($label !== null) {
            return match ($label) {
                'finished' => 'Возврат выполнен',
                'processing' => 'Возврат в обработке',
                'canceled' => 'Возврат отменён',
                default => 'Возврат: ' . $label,
            };
        }

        return $this->status === self::STATUS_REFUNDED ? 'Возврат выполнен' : null;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PaymentReceipt::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED;
    }
} 