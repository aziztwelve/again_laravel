{{-- Письмо 3: «Заказ оплачен». Тело письма; шапку с логотипом и подвал добавляет emails.shell. --}}
@php
    $btn = 'display:inline-block;padding:14px 28px;background:#292725;color:#ffffff;text-decoration:none;font-size:15px;line-height:20px;font-weight:bold';
    $btnLight = 'display:inline-block;padding:11px 18px;margin:0 6px 10px 0;border:1px solid #292725;color:#292725;text-decoration:none;font-size:14px;line-height:18px';
    $support = \App\Support\EmailBranding::SUPPORT_EMAIL;
@endphp
<h1 style="margin:0 0 16px;font-size:24px;line-height:32px;font-weight:bold;color:#292725">Спасибо за ваш выбор!</h1>

<p style="margin:0 0 12px">{{ $greeting }}</p>
<p style="margin:0 0 12px">Ваш заказ успешно оплачен и уже готовится к отправке.</p>
<p style="margin:0 0 20px">Спасибо, что выбрали AGAIN. Мы создаём бельё, в котором комфорт становится естественной частью каждого дня.</p>

<p style="margin:0 0 6px;font-size:18px;line-height:26px;font-weight:bold">Что дальше?</p>
<p style="margin:0 0 10px">Обработка и подготовка заказа к отправке занимает 1–2 рабочих дня.</p>
<p style="margin:0 0 10px">За это время мы бережно соберём, проверим и упакуем вашу покупку.</p>
<p style="margin:0 0 24px">Как только заказ будет передан в службу доставки, вы получите письмо с подтверждением отправки и трек-номером для отслеживания посылки.</p>

@if($orderUrl)
    <p style="margin:0 0 28px"><a href="{{ $orderUrl }}" style="{{ $btn }}">Посмотреть заказ</a></p>
@endif

<p style="margin:0 0 4px;font-size:18px;line-height:26px;font-weight:bold">Есть вопросы?</p>
<p style="margin:0 0 4px">Мы всегда на связи и с радостью поможем.</p>
<p style="margin:0 0 14px">Напишите нам: <a href="mailto:{{ $support }}" style="color:#292725;text-decoration:underline">{{ $support }}</a><br>или</p>
<p style="margin:0 0 24px">
    <a href="{{ \App\Support\EmailBranding::TELEGRAM_URL }}" style="{{ $btnLight }}">Написать в Telegram</a>
    <a href="{{ \App\Support\EmailBranding::MAX_URL }}" style="{{ $btnLight }}">Написать в MAX</a>
    <a href="{{ \App\Support\EmailBranding::WHATSAPP_URL }}" style="{{ $btnLight }}">Написать в WhatsApp</a>
</p>

<p style="margin:0">С заботой о вас,<br><strong>Команда AGAIN</strong></p>
