<?php

namespace App\Services\Import;

use App\Models\Client;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ClientConsentRestoreService
{
    public function plan(string $file): array
    {
        $clients = Client::with('profile')->get();
        $emails = $phones = [];
        foreach ($clients as $client) {
            $email = mb_strtolower(trim((string) $client->email));
            $phone = $this->phone($client->profile?->phone);
            if ($email !== '') {
                $emails[$email][] = $client->id;
            }
            if ($phone !== '') {
                $phones[$phone][] = $client->id;
            }
        }
        $clients = $clients->keyBy('id');
        $stats = array_fill_keys(['rows', 'matched_rows', 'unmatched', 'ambiguous', 'invalid', 'conflicting_clients', 'unchanged', 'changes'], 0);
        $targets = $blocked = [];
        foreach (app(ClientImportService::class)->parseRows($file) as $row) {
            $stats['rows']++;
            $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
            $phone = $this->phone($row['phone'] ?? null);
            $emailIds = $emails[$email] ?? [];
            $phoneIds = $phones[$phone] ?? [];
            // Prefer unique email. A unique phone identifying another client is a conflict.
            if (count($emailIds) > 1 || (count($emailIds) === 1 && count($phoneIds) === 1 && $emailIds[0] !== $phoneIds[0]) || ($emailIds === [] && count($phoneIds) > 1)) {
                $stats['ambiguous']++;
                continue;
            }
            $id = $emailIds[0] ?? $phoneIds[0] ?? null;
            if ($id === null) {
                $stats['unmatched']++;
                continue;
            }
            $pd = $this->boolean($row['personal_data_consent'] ?? null);
            $newsletter = $this->boolean($row['subscribed_to_newsletter'] ?? null);
            $invalid = $pd === null || $newsletter === null;
            foreach ($row as $key => $value) {
                if (str_starts_with($key, 'subscribed_to_newsletter__dup_') && $this->boolean($value) !== $newsletter) {
                    $invalid = true;
                }
            }
            if ($invalid) {
                $stats['invalid']++;
                continue;
            }
            $stats['matched_rows']++;
            $target = ['personal_data_consent' => $pd, 'subscribed_to_newsletter' => $newsletter];
            if (isset($targets[$id]) && $targets[$id] !== $target) {
                $blocked[$id] = true;
            }
            $targets[$id] = $target;
        }
        $stats['conflicting_clients'] = count($blocked);
        $changes = [];
        foreach ($targets as $id => $target) {
            if (isset($blocked[$id])) {
                continue;
            }
            $client = $clients[$id];
            $before = [
                'personal_data_consent' => (bool) $client->personal_data_consent,
                'subscribed_to_newsletter' => (bool) $client->subscribed_to_newsletter,
            ];
            if ($before === $target) {
                $stats['unchanged']++;
                continue;
            }
            $changes[$id] = ['before' => $before, 'after' => $target];
        }
        $stats['changes'] = count($changes);

        return ['source_sha256' => hash_file('sha256', $file), 'stats' => $stats, 'changes' => $changes];
    }

    public function apply(array $plan): int
    {
        return DB::transaction(function () use ($plan) {
            $count = 0;
            foreach ($plan['changes'] as $id => $change) {
                $client = Client::whereKey($id)->lockForUpdate()->first();
                if (! $client || (bool) $client->personal_data_consent !== $change['before']['personal_data_consent'] || (bool) $client->subscribed_to_newsletter !== $change['before']['subscribed_to_newsletter']) {
                    throw new RuntimeException("Client {$id} changed since planning; restoration rolled back.");
                }
                // Historical import: do not fabricate a self-confirmation date or touch other flags.
                $client->update($change['after']);
                $count++;
            }

            return $count;
        });
    }

    private function phone(?string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value ?? '');
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7'.substr($digits, 1);
        }

        return $digits;
    }

    private function boolean(mixed $value): ?bool
    {
        return match (mb_strtolower(trim((string) $value))) {
            'да', 'yes', '1', 'true' => true,
            'нет', 'no', '0', 'false' => false,
            default => null,
        };
    }
}
