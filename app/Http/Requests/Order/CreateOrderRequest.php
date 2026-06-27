<?php

namespace App\Http\Requests\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Fallback 1: если авторизованный клиент не прислал part полей (старая
        // сборка фронта или JS-кэш), дотягиваем недостающие first_name/last_name/phone
        // из его же профиля в БД — заказ всё равно оформляется на этого же клиента,
        // дублировать ввод тех же данных на чекауте не требуется.
        //
        // Для гостевых заказов (нет $this->user()) этот блок безопасно пропускается:
        // данные приходят целиком из payload фронта.
        $user = $this->input('user');
        if (! is_array($user)) {
            $user = [];
        }
        $authUser = $this->user();
        $profile = $authUser?->profile ?? null;
        if ($profile) {
            foreach (['first_name', 'last_name', 'middle_name', 'phone'] as $f) {
                if (empty($user[$f]) && ! empty($profile->{$f})) {
                    $user[$f] = $profile->{$f};
                }
            }
        }
        if ($authUser && empty($user['email']) && ! empty($authUser->email)) {
            $user['email'] = $authUser->email;
        }
        $this->merge(['user' => $user]);

        // Fallback 2: обратная совместимость со старыми клиентами фронта, которые
        // шлют только `user` без отдельного `recipient`. В 99% случаев заказчик
        // и получатель — один и тот же человек; копируем из user.
        $recipient = $this->input('recipient');
        if (! is_array($recipient) || $this->isRecipientEffectivelyEmpty($recipient)) {
            $this->merge([
                'recipient' => [
                    'first_name' => $user['first_name'] ?? null,
                    'last_name' => $user['last_name'] ?? null,
                    'middle_name' => $user['middle_name'] ?? null,
                    'phone' => $user['phone'] ?? null,
                ],
            ]);
        }

        $items = $this->input('items', []);
        if (! is_array($items)) {
            return;
        }

        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                $normalized[] = $item;

                continue;
            }
            if (array_key_exists('variant_id', $item) && ! array_key_exists('product_variant_id', $item)) {
                $item['product_variant_id'] = $item['variant_id'];
            }
            $normalized[] = $item;
        }

        $this->merge(['items' => $normalized]);

        $this->normalizePromotions();
    }

    /**
     * Нормализация акций к единому формату — массив `promotions[]`.
     *
     * Обратная совместимость: старые клиенты фронта присылают одиночные поля
     * promotion_id / gift_product_id / gift_product_variant_id /
     * use_discount_instead. Если массив `promotions` не передан, но есть
     * одиночный promotion_id — сворачиваем его в массив из одного элемента.
     * Дальше вся валидация и применение работают только с `promotions[]`.
     */
    private function normalizePromotions(): void
    {
        $promotions = $this->input('promotions');

        if (is_array($promotions) && ! empty($promotions)) {
            // Уже новый формат — ничего не делаем.
            return;
        }

        if ($this->filled('promotion_id')) {
            $single = [
                'promotion_id' => $this->input('promotion_id'),
                'use_discount_instead' => $this->boolean('use_discount_instead'),
            ];

            if ($this->filled('gift_product_id')) {
                $single['gift_product_id'] = $this->input('gift_product_id');
            }
            if ($this->filled('gift_product_variant_id')) {
                $single['gift_product_variant_id'] = $this->input('gift_product_variant_id');
            }

            $this->merge(['promotions' => [$single]]);
        }
    }

    private function isRecipientEffectivelyEmpty(array $recipient): bool
    {
        foreach (['first_name', 'last_name', 'phone'] as $f) {
            if (! empty($recipient[$f])) {
                return false;
            }
        }

        return true;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $user = $this->user();
            if ($user instanceof User) {
                if ($this->filled('client_id') === false || $this->input('client_id') === null || $this->input('client_id') === '') {
                    $validator->errors()->add('client_id', 'Укажите клиента (client_id).');
                }
            }

            $this->validatePromotions($validator);
        });
    }

    /**
     * Валидация набора акций (накопительные/стекируемые подарки).
     *
     * Работает с нормализованным массивом `promotions[]` (см. normalizePromotions).
     * Для каждой акции проверяет выбор подарка/варианта и наличие на складе;
     * для всего набора — совместимость с промокодом и взаимоисключающие акции.
     */
    private function validatePromotions($validator): void
    {
        $promotions = $this->input('promotions', []);
        if (! is_array($promotions) || empty($promotions)) {
            return;
        }

        $hasPromoCode = $this->filled('promo_code');
        $loaded = [];

        foreach ($promotions as $i => $selection) {
            if (! is_array($selection) || empty($selection['promotion_id'])) {
                continue;
            }

            $promotionId = (int) $selection['promotion_id'];
            $promotion = \App\Models\Promotion::with('giftProducts')->find($promotionId);

            if (! $promotion) {
                $validator->errors()->add("promotions.$i.promotion_id", 'Акция не найдена.');

                continue;
            }

            $loaded[] = $promotion;

            $useDiscountInstead = (bool) ($selection['use_discount_instead'] ?? false);

            // Промокод несовместим с акцией, запрещающей промокоды.
            if ($hasPromoCode && ! $promotion->allow_promo_codes) {
                $validator->errors()->add(
                    'promo_code',
                    'Промокод нельзя использовать с акцией «'.$promotion->name.'».'
                );
            }

            // BUG-3: «скидку вместо подарка» нельзя выбрать у акции с allow_promo_codes=false.
            if ($useDiscountInstead && ! $promotion->allow_promo_codes) {
                $validator->errors()->add(
                    "promotions.$i.use_discount_instead",
                    'С акцией «'.$promotion->name.'» нельзя выбрать скидку вместо подарка.'
                );

                continue;
            }

            if ($useDiscountInstead) {
                // Скидка вместо подарка — подарок не выбирается.
                continue;
            }

            // Подарок обязателен.
            $giftProductId = isset($selection['gift_product_id']) ? (int) $selection['gift_product_id'] : null;
            if (! $giftProductId) {
                $validator->errors()->add("promotions.$i.gift_product_id", 'Выберите подарок для акции.');

                continue;
            }

            $giftProduct = $promotion->giftProducts->firstWhere('id', $giftProductId);
            if (! $giftProduct) {
                $validator->errors()->add(
                    "promotions.$i.gift_product_id",
                    'Выбранный подарок недоступен в этой акции.'
                );

                continue;
            }

            $giftQuantity = (int) ($giftProduct->pivot->quantity ?? 1);
            $variantId = isset($selection['gift_product_variant_id'])
                ? (int) $selection['gift_product_variant_id']
                : null;

            if ($giftProduct->has_variants) {
                if (! $variantId) {
                    $validator->errors()->add(
                        "promotions.$i.gift_product_variant_id",
                        'Выберите размер для подарка.'
                    );

                    continue;
                }

                $variant = \App\Models\ProductVariant::where('id', $variantId)
                    ->where('product_id', $giftProductId)
                    ->where('is_active', true)
                    ->first();

                if (! $variant) {
                    $validator->errors()->add(
                        "promotions.$i.gift_product_variant_id",
                        'Выбранный размер недоступен.'
                    );

                    continue;
                }

                $stock = $variant->stock_quantity;
                if ($stock !== null && $stock !== '' && (float) $stock < $giftQuantity) {
                    $validator->errors()->add(
                        "promotions.$i.gift_product_variant_id",
                        'Выбранного размера нет в наличии.'
                    );
                }
            } else {
                $stock = $giftProduct->stock_quantity;
                if ($stock !== null && $stock !== '' && (float) $stock < $giftQuantity) {
                    $validator->errors()->add(
                        "promotions.$i.gift_product_id",
                        'Подарка нет в наличии.'
                    );
                }
            }
        }

        // Взаимоисключающие (невзаимные) акции нельзя присылать в наборе из нескольких.
        if (count($loaded) > 1) {
            $nonStackable = array_filter($loaded, fn ($p) => ! $p->isStackable());
            if (! empty($nonStackable)) {
                $names = implode(', ', array_map(fn ($p) => '«'.$p->name.'»', $nonStackable));
                $validator->errors()->add(
                    'promotions',
                    'Акция '.$names.' не суммируется с другими акциями.'
                );
            }
        }
    }

    public function rules(): array
    {
        $rules = [
            // Адрес доставки
            'delivery_address' => 'required|array',
            'delivery_address.country' => 'required|string|max:255',
            'delivery_address.region' => 'nullable|string|max:255',
            'delivery_address.city' => 'required|string|max:255',
            'delivery_address.postal_code' => 'nullable|string|max:20',
            'delivery_address.address' => 'required|string|max:1000',
            'delivery_address.entrance' => 'nullable|string|max:50',
            'delivery_address.floor' => 'nullable|string|max:50',
            'delivery_address.intercom' => 'nullable|string|max:50',
            'delivery_address.delivery_comment' => 'nullable|string|max:1000',
            'delivery_address.delivery_date' => 'nullable|date',
            'delivery_address.buyer_comment' => 'nullable|string|max:1000',

            // Получатель (имя/фамилия/телефон обязательны, отчество опционально)
            'recipient' => 'required|array',
            'recipient.first_name' => 'required|string|max:255',
            'recipient.last_name' => 'required|string|max:255',
            'recipient.middle_name' => 'nullable|string|max:255',
            'recipient.phone' => 'required|string|max:32',

            // Заметки
            'notes' => 'nullable|string|max:1000',

            // UTM-атрибуция: явная привязка заказа к метке (см. docs/tasks/utm-tracking.md, риск #3).
            // На ручных заказах из админки кука utm_link_id принадлежит менеджеру, а не
            // покупателю, поэтому менеджер может указать метку вручную. Приоритет — над кукой.
            'utm_link_id' => 'nullable|integer|exists:utm_links,id',

            // Промокод
            'promo_code' => 'nullable|string|max:50',

            // Акция (одиночный формат — обратная совместимость, нормализуется в promotions[])
            'promotion_id' => 'nullable|integer|exists:promotions,id',
            'gift_product_id' => 'nullable|integer|exists:products,id',
            'gift_product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'use_discount_instead' => 'nullable|boolean',

            // Акции (новый формат — массив, накопительные/стекируемые подарки)
            'promotions' => 'nullable|array',
            'promotions.*.promotion_id' => 'required|integer|exists:promotions,id',
            'promotions.*.gift_product_id' => 'nullable|integer|exists:products,id',
            'promotions.*.gift_product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'promotions.*.use_discount_instead' => 'nullable|boolean',

            // Контактная информация
            'user' => 'required|array',
            'user.first_name' => 'required|string|max:255',
            'user.last_name' => 'required|string|max:255',
            'user.phone' => 'nullable|string|max:20',
            // Email опционален: для гостевого заказа полезен (чек/уведомление),
            // для авторизованного клиента подтягивается из его аккаунта.
            'user.email' => 'nullable|email|max:255',

            'client_id' => [
                'nullable',
                'integer',
                Rule::exists('clients', 'id')->whereNull('deleted_at'),
            ],

            'status' => ['nullable', 'string', Rule::in(OrderStatus::values())],
            'payment_status' => ['nullable', 'string', Rule::in(PaymentStatus::values())],
            'payment_method' => 'nullable|string|max:255',
            'source' => 'nullable|string|max:255',

            'delivery_method' => 'nullable|array',
            'delivery_method.name' => 'nullable|string|max:255',

            // Товары
            'items' => 'required|array|min:1|max:50',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.variant_id' => 'nullable|integer|exists:product_variants,id',
            'items.*.product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'items.*.color_id' => 'nullable|integer|exists:colors,id',
            'items.*.quantity' => 'required|integer|min:1|max:999',
            'items.*.price' => 'required|numeric|min:0|max:9999999',

            'gift_card_code' => 'nullable|string|size:12',

            'gift_card_data' => 'nullable|array',
            'gift_card_data.type' => 'required_with:gift_card_data|in:electronic,plastic',
            'gift_card_data.sender_name' => 'required_with:gift_card_data|string|min:2|max:100',
            'gift_card_data.message' => 'nullable|string|max:500',

        ];

        if ($this->input('gift_card_data.type') === 'electronic') {
            $rules['gift_card_data.recipient_type'] = 'required|in:self,someone';
            $rules['gift_card_data.delivery_channel'] = 'required|in:email,telegram';
            $rules['gift_card_data.delivery_type'] = 'required|in:immediate,scheduled';

            // Для другого получателя
            if ($this->input('gift_card_data.recipient_type') === 'someone') {
                $rules['gift_card_data.recipient_name'] = 'required|string|min:2|max:100';

                if ($this->input('gift_card_data.delivery_channel') === 'email') {
                    $rules['gift_card_data.recipient_email'] = 'required|email|max:100';
                }

                if ($this->input('gift_card_data.delivery_channel') === 'telegram') {
                    $rules['gift_card_data.recipient_phone'] = 'required|string|max:20';
                }
            }

            // Для запланированной отправки
            if ($this->input('gift_card_data.delivery_type') === 'scheduled') {
                $rules['gift_card_data.scheduled_date'] = 'required|date|after_or_equal:today';
                $rules['gift_card_data.scheduled_time'] = 'required|date_format:H:i';
                $rules['gift_card_data.timezone'] = 'required|string|max:50';
            }
        }

        return $rules;

    }

    public function messages(): array
    {
        return [
            'delivery_address.required' => 'Адрес доставки обязателен',
            'delivery_address.country.required' => 'Страна обязательна',
            'delivery_address.city.required' => 'Город обязателен',
            'delivery_address.address.required' => 'Адрес обязателен',

            'user.required' => 'Контактная информация обязательна',
            'user.first_name.required' => 'Имя обязательно',
            'user.last_name.required' => 'Фамилия обязательна',
            'user.phone.required' => 'Телефон обязателен',

            'recipient.required' => 'Данные получателя обязательны',
            'recipient.first_name.required' => 'Имя получателя обязательно',
            'recipient.last_name.required' => 'Фамилия получателя обязательна',
            'recipient.phone.required' => 'Телефон получателя обязателен',

            'items.required' => 'Необходимо добавить товары в заказ',
            'items.min' => 'Необходимо добавить хотя бы один товар',
            'items.max' => 'Максимальное количество товаров в заказе: 50',

            'gift_card_data.sender_name.required_with' => 'Укажите ваше имя',
            'gift_card_data.recipient_name.required_if' => 'Укажите имя получателя',
            'gift_card_data.recipient_email.required_if' => 'Укажите email получателя',
            'gift_card_data.recipient_phone.required_if' => 'Укажите телефон получателя',
            'gift_card_data.scheduled_date.required_if' => 'Укажите дату отправки',
            'gift_card_data.scheduled_time.required_if' => 'Укажите время отправки',
            'gift_card_data.message.max' => 'Сообщение не должно превышать 500 символов',

        ];
    }
}
