<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Адрес, отписавшийся от рекламных писем. Письма о заказах отписка не отключает. */
class EmailUnsubscribe extends Model
{
    protected $fillable = ['email', 'source'];

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function isUnsubscribed(string $email): bool
    {
        return static::query()->where('email', static::normalize($email))->exists();
    }

    /** Отписывает адрес и снимает согласие на рассылку у клиента с такой почтой. */
    public static function unsubscribe(string $email, string $source = 'email_link'): void
    {
        $email = static::normalize($email);
        if ($email === '') {
            return;
        }

        static::query()->firstOrCreate(['email' => $email], ['source' => $source]);

        Client::query()->whereRaw('lower(email) = ?', [$email])->update(['subscribed_to_newsletter' => false]);
    }
}
