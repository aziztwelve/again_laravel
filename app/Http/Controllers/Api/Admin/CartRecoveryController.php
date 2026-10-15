<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\User;
use App\Services\Cart\AbandonedCartService;
use App\Services\Cart\CartRecoverySettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CartRecoveryController extends Controller
{
    public function settings(Request $request, CartRecoverySettings $settings)
    {
        $this->staff($request);
        return response()->json(['data' => $settings->read() + [
            'schedule_hours' => [2, 24, 48],
            'start_after_minutes' => (int) config('abandoned_cart.abandon_after_minutes', 30),
            'environment_enabled' => (bool) config('abandoned_cart.enabled', true),
            'variables' => ['{{client.name}}', '{{cart.id}}', '{{cart.items}}', '{{cart.total}}', '{{cart.url}}', '{{shop.name}}'],
        ]]);
    }

    public function update(Request $request, CartRecoverySettings $settings)
    {
        $this->staff($request);
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'channels' => 'required|array:email,telegram,max,vk',
            'channels.email' => 'required|boolean', 'channels.telegram' => 'required|boolean',
            'channels.max' => 'required|boolean', 'channels.vk' => 'required|boolean',
            'templates' => 'present|array|max:24',
            'templates.*' => 'array:audience,step,channel,subject,body,format',
            'templates.*.audience' => 'required|in:new,returning',
            'templates.*.step' => 'required|integer|in:1,2,3',
            'templates.*.channel' => ['required', Rule::in(CartRecoverySettings::CHANNELS)],
            'templates.*.subject' => 'nullable|string|max:255',
            'templates.*.body' => 'required|string|max:50000',
            'templates.*.format' => 'required|in:text,html',
        ]);
        $keys = [];
        foreach ($data['templates'] as $i => $template) {
            $key = $template['audience'].':'.$template['step'].':'.$template['channel'];
            if (isset($keys[$key]) || ($template['channel'] !== 'email' && $template['format'] !== 'text')) {
                throw ValidationException::withMessages(["templates.$i" => 'Шаблон повторяется или HTML выбран для мессенджера.']);
            }
            if ($template['channel'] === 'email' && trim($template['subject'] ?? '') === '') {
                throw ValidationException::withMessages(["templates.$i.subject" => 'Укажите тему письма.']);
            }
            $keys[$key] = true;
        }
        $settings->save($data);
        return response()->json(['success' => true, 'message' => 'Настройки брошенных корзин сохранены.']);
    }

    public function preview(Request $request, AbandonedCartService $service)
    {
        $this->staff($request);
        $data = $request->validate([
            'cart_id' => 'required|integer',
            'step' => 'required|integer|in:1,2,3',
            'channel' => ['required', Rule::in(CartRecoverySettings::CHANNELS)],
            'audience' => 'nullable|in:new,returning',
            'subject' => 'nullable|string|max:255',
            'body' => 'nullable|string|max:50000',
            'format' => 'nullable|in:text,html',
            'use_default' => 'nullable|boolean',
        ]);
        $cart = Cart::query()->whereNotNull('client_id')->findOrFail($data['cart_id']);
        $override = isset($data['body']) && trim($data['body']) !== '' ? [
            'subject' => $data['subject'] ?? '', 'body' => $data['body'],
            'format' => $data['channel'] === 'email' ? ($data['format'] ?? 'text') : 'text',
        ] : null;
        $message = $service->buildMessage($cart, $data['step'], channel: $data['channel'], preview: true,
            override: $override, audience: $data['audience'] ?? null, useDefault: $data['use_default'] ?? false);
        return response()->json(['data' => $message]);
    }

    private function staff(Request $request): void
    {
        abort_unless($request->user() instanceof User, 403);
    }
}
