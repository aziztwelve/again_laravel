<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Ограничивает host у target_url списком разрешённых доменов
 * (config('utm.allowed_target_hosts')). Если список пуст — разрешён любой host
 * (по ТЗ «любая страница сайта»). Защищает редирект-трекер /go/{slug} от
 * увода на сторонние домены.
 */
class AllowedTargetHost implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $allowed = config('utm.allowed_target_hosts', []);

        if (empty($allowed)) {
            return;
        }

        $host = is_string($value) ? parse_url($value, PHP_URL_HOST) : null;

        if (! $host || ! in_array($host, $allowed, true)) {
            $fail('Целевая ссылка должна вести на один из разрешённых доменов: '
                .implode(', ', $allowed).'.');
        }
    }
}
