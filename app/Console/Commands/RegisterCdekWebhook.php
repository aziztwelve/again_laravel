<?php

namespace App\Console\Commands;

use App\Services\Delivery\CdekDeliveryService;
use Illuminate\Console\Command;

class RegisterCdekWebhook extends Command
{
    protected $signature = 'cdek:register-webhook';
    protected $description = 'Register exactly one CDEK ORDER_STATUS webhook for the configured URL';

    public function handle(CdekDeliveryService $service): int
    {
        $url = rtrim((string) config('services.cdek_delivery.webhook_url'), '/');
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with($url, 'https://')) {
            $this->error('CDEK_DELIVERY_WEBHOOK_URL must be a valid HTTPS URL.');
            return self::FAILURE;
        }

        $result = $service->webhooks();
        if (! $result['successful']) {
            $this->error('Unable to retrieve CDEK webhook subscriptions (HTTP '.$result['status'].').');
            return self::FAILURE;
        }

        $matches = collect($result['data'])->filter(fn (array $webhook) => ($webhook['type'] ?? null) === 'ORDER_STATUS'
            && rtrim((string) ($webhook['url'] ?? ''), '/') === $url)->values();

        if ($matches->isEmpty()) {
            $created = $service->registerWebhook($url);
            if (! $created['successful']) {
                $this->error('Unable to create CDEK webhook subscription (HTTP '.$created['status'].').');
                return self::FAILURE;
            }
            $this->info('CDEK ORDER_STATUS webhook created.');
            return self::SUCCESS;
        }

        foreach ($matches->slice(1) as $webhook) {
            $deleted = $service->deleteWebhook((string) $webhook['uuid']);
            if (! $deleted['successful']) {
                $this->error('Unable to remove duplicate CDEK webhook '.$webhook['uuid'].' (HTTP '.$deleted['status'].').');
                return self::FAILURE;
            }
        }

        $this->info($matches->count() === 1 ? 'CDEK ORDER_STATUS webhook is already registered.' : 'Duplicate CDEK webhooks removed.');
        return self::SUCCESS;
    }
}
