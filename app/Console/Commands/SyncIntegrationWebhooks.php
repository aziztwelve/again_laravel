<?php

namespace App\Console\Commands;

use App\Models\VKSettings;
use App\Services\Integrations\AmneziaVpnService;
use App\Services\Max\MaxService;
use App\Support\PublicUrl;
use DefStudio\Telegraph\Models\TelegraphBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Переводит все входящие вебхуки на актуальный публичный домен.
 *
 * Домен нигде не зашит: адрес берётся из APP_URL/FRONTEND_URL. После переезда
 * достаточно поправить .env и выполнить `php artisan integrations:sync-webhooks`,
 * иначе Telegram/MAX/VK продолжат стучаться на прежний адрес и входящие
 * сообщения будут теряться.
 */
class SyncIntegrationWebhooks extends Command
{
    protected $signature = 'integrations:sync-webhooks
        {--check : Только показать текущее состояние, ничего не менять}
        {--only= : Ограничить каналами через запятую: telegram,max,vk,cdek}';

    protected $description = 'Синхронизировать вебхуки Telegram/MAX/VK/CDEK с текущим APP_URL';

    private bool $check = false;

    /** @var array<int, string> */
    private array $problems = [];

    public function handle(): int
    {
        $this->check = (bool) $this->option('check');

        $only = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('only'))
        )));

        $this->line('Канонический адрес: '.PublicUrl::base());
        if ($legacy = PublicUrl::legacyHosts()) {
            $this->line('Прежние хосты (LEGACY_HOSTS): '.implode(', ', $legacy));
        }
        $this->newLine();

        foreach (['telegram', 'max', 'vk', 'cdek'] as $channel) {
            if ($only && ! in_array($channel, $only, true)) {
                continue;
            }

            $this->components->info(strtoupper($channel));

            try {
                $this->{'sync'.ucfirst($channel)}();
            } catch (\Throwable $e) {
                $this->problems[] = $channel.': '.$e->getMessage();
                $this->error('  '.$e->getMessage());
            }

            $this->newLine();
        }

        if ($this->problems) {
            $this->error('Есть проблемы:');
            foreach ($this->problems as $problem) {
                $this->error('  - '.$problem);
            }

            return self::FAILURE;
        }

        $this->info($this->check ? 'Проверка завершена.' : 'Синхронизация завершена.');

        return self::SUCCESS;
    }

    private function syncTelegram(): void
    {
        $bots = TelegraphBot::all();

        if ($bots->isEmpty()) {
            $this->warn('  Боты не настроены — нечего синхронизировать.');

            return;
        }

        // Telegram API недоступен с RU-хостинга напрямую, поэтому идём через
        // тот же клиент с SOCKS5, что и остальная интеграция.
        $http = app(AmneziaVpnService::class)->telegramHttp();

        foreach ($bots as $bot) {
            $target = PublicUrl::to('/api/telegraph/'.$bot->token.'/webhook');

            $info = $http->get("https://api.telegram.org/bot{$bot->token}/getWebhookInfo")->json('result') ?? [];
            $currentHost = parse_url((string) ($info['url'] ?? ''), PHP_URL_HOST) ?: '(не задан)';

            $this->line("  {$bot->name}: текущий хост {$currentHost}, pending "
                .($info['pending_update_count'] ?? '?')
                .', ошибка: '.($info['last_error_message'] ?? 'нет'));

            if (($info['url'] ?? null) === $target) {
                $this->line('    уже на актуальном адресе');

                continue;
            }

            if ($this->check) {
                $this->warn('    требует обновления');
                $this->problems[] = "telegram:{$bot->name} указывает на {$currentHost}";

                continue;
            }

            $response = $http->get("https://api.telegram.org/bot{$bot->token}/setWebhook", ['url' => $target]);

            if (! $response->ok() || ! $response->json('ok')) {
                $this->problems[] = "telegram:{$bot->name} setWebhook => ".$response->body();
                $this->error('    setWebhook не удался: '.$response->body());

                continue;
            }

            $this->info('    webhook переведён на '.PublicUrl::host());
        }
    }

    private function syncMax(): void
    {
        $service = app(MaxService::class);
        $target = $service->getWebhookUrl();
        $subscriptions = $service->getWebhookSubscriptions();

        $urls = array_values(array_filter(array_map(
            static fn ($item) => is_array($item) ? ($item['url'] ?? null) : ($item->url ?? null),
            $subscriptions
        )));

        $this->line('  подписки: '.($urls ? implode(', ', $urls) : 'нет'));
        $this->line('  требуется: '.$target);

        $stale = array_values(array_diff($urls, [$target]));

        if (! $stale && in_array($target, $urls, true)) {
            $this->line('    уже на актуальном адресе');

            return;
        }

        if ($this->check) {
            $this->warn('    требует обновления');
            $this->problems[] = 'max: подписки '.($urls ? implode(', ', $urls) : 'отсутствуют');

            return;
        }

        foreach ($stale as $url) {
            $service->unregisterWebhook($url);
            $this->line('    снята подписка '.$url);
        }

        $result = $service->registerWebhookIfNeeded();

        if (! ($result['success'] ?? false)) {
            $this->problems[] = 'max: '.($result['message'] ?? 'registerWebhookIfNeeded failed');
            $this->error('    '.($result['message'] ?? 'ошибка регистрации'));

            return;
        }

        $this->info('    подписка переведена на '.PublicUrl::host());
    }

    private function syncVk(): void
    {
        $settings = VKSettings::first();

        if (! $settings || ! $settings->access_token) {
            $this->warn('  VK не настроен — нечего синхронизировать.');

            return;
        }

        $base = [
            'group_id' => $settings->community_id,
            'access_token' => $settings->access_token,
            'v' => $settings->api_version,
        ];
        $target = PublicUrl::to('/api/public/vk/webhook');

        $servers = Http::timeout(25)
            ->get('https://api.vk.com/method/groups.getCallbackServers', $base)
            ->json('response.items') ?? [];

        foreach ($servers as $server) {
            $url = (string) ($server['url'] ?? '');

            if ($url === '') {
                continue;
            }

            $this->line("  server#{$server['id']}: {$url} (status {$server['status']})");

            if ($url === $target && $server['status'] === 'ok') {
                $this->line('    уже на актуальном адресе');

                continue;
            }

            if ($this->check) {
                $this->warn('    требует обновления');
                $this->problems[] = "vk:server#{$server['id']} url={$url} status={$server['status']}";

                continue;
            }

            // VK проверяет адрес запросом type=confirmation и ждёт строку,
            // выданную сообществу. Синхронизируем её, иначе получим status=failed.
            $code = Http::timeout(25)
                ->get('https://api.vk.com/method/groups.getCallbackConfirmationCode', $base)
                ->json('response.code');

            if ($code && $code !== $settings->confirmation_token) {
                $settings->update(['confirmation_token' => $code]);
                $this->line('    confirmation_token синхронизирован с VK');
            }

            $edit = Http::timeout(25)->asForm()->post('https://api.vk.com/method/groups.editCallbackServer', $base + [
                'server_id' => $server['id'],
                'url' => $target,
                'title' => $server['title'] ?? 'Callback',
            ]);

            if (! $edit->json('response')) {
                $this->problems[] = "vk:server#{$server['id']} editCallbackServer => ".$edit->body();
                $this->error('    editCallbackServer не удался: '.$edit->body());

                continue;
            }

            $this->info('    адрес обновлён на '.PublicUrl::host());
        }
    }

    private function syncCdek(): void
    {
        $configured = (string) config('services.cdek_delivery.webhook_url');
        $this->line('  требуется: '.$configured);

        if ($this->check) {
            $this->line('  проверка регистрации выполняется командой cdek:register-webhook');

            return;
        }

        $this->call('cdek:register-webhook');
    }
}
