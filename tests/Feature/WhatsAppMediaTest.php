<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Services\Messaging\Adapters\WhatsAppAdapter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Фото, голосовые, видео и файлы WhatsApp: приём в админку и отправка клиенту. */
class WhatsAppMediaTest extends TestCase
{
    use DatabaseTransactions;

    private const CHAT = '79991230000@c.us';

    private function webhook(array $fields, ?UploadedFile $media = null)
    {
        Event::fake();
        Storage::fake('public');

        $payload = array_merge([
            'phone_number' => self::CHAT,
            'message_text' => '',
            'message_id' => 'MSG'.uniqid(),
            'timestamp' => '2026-11-13T05:55:11.000Z',
            'from_id' => self::CHAT,
        ], $fields);
        if ($media) {
            $payload['media'] = $media;
        }

        return $this->post('/api/public/whatsapp/webhook', $payload, ['Accept' => 'application/json']);
    }

    private function lastMessage()
    {
        return Conversation::where('source', 'whatsapp')->where('external_id', self::CHAT)->firstOrFail()
            ->messages()->with('attachments')->latest('id')->firstOrFail();
    }

    public function test_photo_with_caption_is_saved_as_an_image_attachment(): void
    {
        $this->webhook(['message_text' => 'Вот фото заказа', 'media_type' => 'image'], UploadedFile::fake()->image('attachment.jpg', 40, 40))->assertOk();

        $message = $this->lastMessage();
        $this->assertSame('Вот фото заказа', $message->content);
        $this->assertCount(1, $message->attachments);
        $attachment = $message->attachments->first();
        $this->assertSame('image', $attachment->type);
        $this->assertSame('image/jpeg', $attachment->mime_type);
        $this->assertSame('Фото.jpg', $attachment->file_name);
        $this->assertStringStartsWith('chat-attachments/', $attachment->file_path);
        Storage::disk('public')->assertExists($attachment->file_path);
        $this->assertStringContainsString('/storage/chat-attachments/', $attachment->url);
    }

    public function test_voice_message_without_text_has_no_placeholder_and_is_an_audio_attachment(): void
    {
        $voice = UploadedFile::fake()->create('attachment', 20, 'audio/ogg; codecs=opus');
        $this->webhook(['media_type' => 'ptt'], $voice)->assertOk();

        $message = $this->lastMessage();
        $this->assertSame('', trim((string) $message->content));
        $attachment = $message->attachments->first();
        $this->assertSame('audio', $attachment->type);
        $this->assertSame('audio/ogg', $attachment->mime_type, 'параметры вроде codecs=opus отбрасываются');
        $this->assertSame('Голосовое сообщение.ogg', $attachment->file_name);
        Storage::disk('public')->assertExists($attachment->file_path);
    }

    public function test_video_sticker_and_document_get_their_types(): void
    {
        $this->webhook(['media_type' => 'video'], UploadedFile::fake()->create('attachment', 50, 'video/mp4'))->assertOk();
        $this->assertSame('video', $this->lastMessage()->attachments->first()->type);

        $this->webhook(['media_type' => 'sticker'], UploadedFile::fake()->create('attachment', 5, 'image/webp'))->assertOk();
        $sticker = $this->lastMessage()->attachments->first();
        $this->assertSame('image', $sticker->type);
        $this->assertSame('Стикер.webp', $sticker->file_name);

        $this->webhook(['media_type' => 'document'], UploadedFile::fake()->create('Накладная.pdf', 30, 'application/pdf'))->assertOk();
        $document = $this->lastMessage()->attachments->first();
        $this->assertSame('file', $document->type);
        $this->assertSame('Накладная.pdf', $document->file_name);
    }

    public function test_failed_download_is_reported_with_a_specific_placeholder(): void
    {
        $this->webhook(['media_type' => 'image', 'media_error' => 'download_failed'])->assertOk();

        $message = $this->lastMessage();
        $this->assertStringContainsString('Не удалось получить вложение', $message->content);
        $this->assertCount(0, $message->attachments);
    }

    public function test_message_without_media_still_uses_the_old_placeholder(): void
    {
        $this->webhook(['message_text' => ''])->assertOk();

        $this->assertStringContainsString('Сообщение без текста', $this->lastMessage()->content);
    }

    // ── Отправка клиенту ─────────────────────────────────────────────────────

    private function adapter(): WhatsAppAdapter
    {
        config(['services.whatsapp.service_url' => 'http://wa.test']);

        return new WhatsAppAdapter();
    }

    private function storedFile(string $name, string $content = 'FILEDATA'): string
    {
        Storage::fake('public');
        $path = 'chat-attachments/2026/11/'.$name;
        Storage::disk('public')->put($path, $content);

        return $path;
    }

    public function test_image_is_sent_with_the_text_as_caption(): void
    {
        Http::fake(['wa.test/*' => Http::response(['success' => true])]);
        $path = $this->storedFile('a.jpg', 'JPEGDATA');

        $ok = $this->adapter()->sendMessage(self::CHAT, 'Ваш заказ', [
            ['type' => 'image', 'file_path' => $path, 'file_name' => 'order.jpg', 'mime_type' => 'image/jpeg'],
        ]);

        $this->assertTrue($ok);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === 'http://wa.test/send-media'
            && $r['phone_number'] === self::CHAT
            && base64_decode($r['data']) === 'JPEGDATA'
            && $r['mimetype'] === 'image/jpeg'
            && $r['filename'] === 'order.jpg'
            && $r['caption'] === 'Ваш заказ'
            && $r['as_document'] === false);
    }

    public function test_attachment_without_text_is_sent_without_caption(): void
    {
        Http::fake(['wa.test/*' => Http::response(['success' => true])]);
        $path = $this->storedFile('v.mp4');

        $ok = $this->adapter()->sendMessage(self::CHAT, null, [
            ['type' => 'video', 'file_path' => $path, 'file_name' => 'v.mp4', 'mime_type' => 'video/mp4'],
        ]);

        $this->assertTrue($ok);
        Http::assertSent(fn (Request $r) => $r->url() === 'http://wa.test/send-media' && $r['caption'] === null);
    }

    public function test_several_files_send_the_caption_only_with_the_first(): void
    {
        Http::fake(['wa.test/*' => Http::response(['success' => true])]);
        $a = $this->storedFile('1.jpg');
        Storage::disk('public')->put('chat-attachments/2026/11/2.jpg', 'SECOND');

        $this->adapter()->sendMessage(self::CHAT, 'Подпись', [
            ['type' => 'image', 'file_path' => $a, 'file_name' => '1.jpg', 'mime_type' => 'image/jpeg'],
            ['type' => 'image', 'file_path' => 'chat-attachments/2026/11/2.jpg', 'file_name' => '2.jpg', 'mime_type' => 'image/jpeg'],
        ]);

        Http::assertSentCount(2);
        $captions = collect(Http::recorded())->map(fn ($pair) => $pair[0]['caption'])->all();
        $this->assertSame(['Подпись', null], $captions);
    }

    public function test_long_text_is_sent_as_a_separate_message_before_the_file(): void
    {
        Http::fake(['wa.test/*' => Http::response(['success' => true])]);
        $path = $this->storedFile('a.jpg');

        $this->adapter()->sendMessage(self::CHAT, str_repeat('а', 1500), [
            ['type' => 'image', 'file_path' => $path, 'file_name' => 'a.jpg', 'mime_type' => 'image/jpeg'],
        ]);

        $urls = collect(Http::recorded())->map(fn ($pair) => $pair[0]->url())->all();
        $this->assertSame(['http://wa.test/send-message', 'http://wa.test/send-media'], $urls);
    }

    public function test_documents_are_sent_as_files(): void
    {
        Http::fake(['wa.test/*' => Http::response(['success' => true])]);
        $path = $this->storedFile('d.pdf');

        $this->adapter()->sendMessage(self::CHAT, '', [
            ['type' => 'file', 'file_path' => $path, 'file_name' => 'd.pdf', 'mime_type' => 'application/pdf'],
        ]);

        Http::assertSent(fn (Request $r) => $r['as_document'] === true);
    }

    public function test_plain_text_still_uses_send_message(): void
    {
        Http::fake(['wa.test/*' => Http::response(['success' => true])]);

        $this->assertTrue($this->adapter()->sendMessage(self::CHAT, 'Здравствуйте'));

        Http::assertSent(fn (Request $r) => $r->url() === 'http://wa.test/send-message' && $r['message_text'] === 'Здравствуйте');
    }

    public function test_service_error_or_missing_file_means_not_sent(): void
    {
        Http::fake(['wa.test/*' => Http::response(['success' => false, 'error' => 'WhatsApp не подключен'], 400)]);
        $path = $this->storedFile('a.jpg');
        $attachment = ['type' => 'image', 'file_path' => $path, 'file_name' => 'a.jpg', 'mime_type' => 'image/jpeg'];

        $this->assertFalse($this->adapter()->sendMessage(self::CHAT, 'x', [$attachment]));
        $this->assertFalse($this->adapter()->sendMessage(self::CHAT, 'x', [['file_path' => 'nope.jpg', 'type' => 'image'] + $attachment]));
        $this->assertFalse($this->adapter()->sendMessage(self::CHAT, '', []), 'нечего отправлять');
    }
}
