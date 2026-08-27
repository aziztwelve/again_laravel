<?php

namespace Tests\Feature\Telegram;

use App\Jobs\Telegram\DownloadTelegramAttachmentsJob;
use App\Models\Conversation;
use App\Models\MailSetting;
use App\Models\Message;
use App\Services\Telegram\TelegramService;
use DefStudio\Telegraph\Models\TelegraphBot;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Вложения Telegram докачиваются очередью, а не внутри webhook-запроса:
 * getFile + сам файл идут через SOCKS5-прокси и выходили за таймаут Telegram,
 * после чего Telegram повторял апдейт (дубли в диалоге).
 */
class TelegramAttachmentsQueueTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        MailSetting::firstOrCreate([], [
            'mailer' => 'smtp',
            'host' => 'localhost',
            'port' => 25,
            'username' => 'test',
            'password' => 'secret',
            'from_address' => 'test@example.com',
        ]);
    }

    public function test_incoming_message_with_photo_defers_download_to_queue(): void
    {
        Bus::fake();
        // Ни одного обращения к Telegram внутри обработки апдейта.
        Http::preventStrayRequests();

        $bot = TelegraphBot::create(['token' => '111:AAA', 'name' => 'test_bot']);

        app(TelegramService::class)->findOrCreateConversationAndSendMessage(
            555001,
            null,
            'фото во вложении',
            ['photo' => [['file_id' => 'small-id'], ['file_id' => 'largest-id']]],
            $bot->token
        );

        $conversation = Conversation::where('external_id', 555001)->where('source', 'telegram')->firstOrFail();
        $message = Message::where('conversation_id', $conversation->id)->latest('id')->firstOrFail();

        self::assertSame('фото во вложении', $message->content);
        // Текст доступен сразу, вложений пока нет.
        self::assertCount(0, $message->attachments);

        Bus::assertDispatched(DownloadTelegramAttachmentsJob::class, function (DownloadTelegramAttachmentsJob $job) use ($message, $bot) {
            return $job->messageId === $message->id
                && $job->telegraphBotId === $bot->id
                // Из группы размеров берём самый большой файл.
                && $job->descriptors === [['file_id' => 'largest-id', 'file_name' => null]];
        });
    }

    public function test_message_without_attachments_does_not_dispatch_job(): void
    {
        Bus::fake();

        app(TelegramService::class)->findOrCreateConversationAndSendMessage(
            555002,
            null,
            'просто текст',
            ['text' => 'просто текст']
        );

        Bus::assertNotDispatched(DownloadTelegramAttachmentsJob::class);
    }

    public function test_job_attaches_file_and_is_idempotent_on_retry(): void
    {
        Storage::fake('public');

        $bot = TelegraphBot::create(['token' => '222:BBB', 'name' => 'test_bot_2']);
        $conversation = Conversation::create([
            'source' => 'telegram',
            'external_id' => 555003,
            'status' => 'new',
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'incoming',
            'content' => 'с документом',
            'content_type' => 'text',
            'status' => 'sent',
        ]);

        Http::fake([
            'api.telegram.org/bot222:BBB/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'documents/file_1.pdf'],
            ]),
            'api.telegram.org/file/bot222:BBB/*' => Http::response('%PDF-1.4 fake'),
        ]);

        $descriptors = [['file_id' => 'doc-file-id', 'file_name' => 'счёт.pdf']];

        (new DownloadTelegramAttachmentsJob($message->id, $descriptors, $bot->id))
            ->handle(app(TelegramService::class));

        $message->refresh()->load('attachments');
        self::assertCount(1, $message->attachments);

        $attachment = $message->attachments->first();
        self::assertSame('doc-file-id', $attachment->source_file_id);
        self::assertSame('счёт.pdf', $attachment->file_name);
        Storage::disk('public')->assertExists($attachment->file_path);

        // Повторный прогон (retry очереди) не должен создавать второе вложение.
        (new DownloadTelegramAttachmentsJob($message->id, $descriptors, $bot->id))
            ->handle(app(TelegramService::class));

        self::assertCount(1, $message->refresh()->load('attachments')->attachments);
    }

    public function test_job_does_nothing_when_message_is_gone(): void
    {
        $bot = TelegraphBot::create(['token' => '333:CCC', 'name' => 'test_bot_3']);
        Http::preventStrayRequests();

        (new DownloadTelegramAttachmentsJob(999999999, [['file_id' => 'x', 'file_name' => null]], $bot->id))
            ->handle(app(TelegramService::class));

        // Отсутствие исключения — достаточный результат: задача не должна
        // ретраиться из-за удалённого сообщения.
        self::assertTrue(true);
    }
}
