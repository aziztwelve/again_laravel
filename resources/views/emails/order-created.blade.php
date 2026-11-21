{{-- Письмо 2: «Заказ оформлен». Тело письма; шапку с логотипом и подвал добавляет emails.shell. --}}
@php
    $btn = 'display:inline-block;padding:14px 28px;background:#292725;color:#ffffff;text-decoration:none;font-size:15px;line-height:20px;font-weight:bold';
    $btnLight = 'display:inline-block;padding:11px 18px;margin:0 6px 10px 0;border:1px solid #292725;color:#292725;text-decoration:none;font-size:14px;line-height:18px';
@endphp
<h1 style="margin:0 0 16px;font-size:24px;line-height:32px;font-weight:bold;color:#292725">{{ $headline }}</h1>

<p style="margin:0 0 12px">Спасибо, что выбрали AGAIN!<br>Ваш заказ {{ $orderNumber }} успешно создан.</p>

<p style="margin:0 0 20px">Совсем скоро вы сможете познакомиться с бельём, которое мы создавали с особым вниманием к женскому комфорту, свободе движений и заботе о себе.</p>

@if(! $isPaid)
    <p style="margin:0 0 4px;font-size:18px;line-height:26px;font-weight:bold">Что дальше?</p>
    <p style="margin:0 0 20px">Проверьте состав вашего заказа и переходите к оплате.</p>
@endif

<p style="margin:0 0 8px;font-size:18px;line-height:26px;font-weight:bold">Состав заказа</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px">
    @foreach($items as $item)
        <tr>
            <td style="padding:8px 0;border-bottom:1px solid #e8e5e1;font-size:15px;line-height:22px">{{ $item }}</td>
        </tr>
    @endforeach
</table>

<p style="margin:0 0 6px;font-size:16px;line-height:24px"><strong>Итого:</strong> {{ $total }}</p>
@if($delivery)
    <p style="margin:0 0 24px;font-size:16px;line-height:24px"><strong>Доставка:</strong> {{ $delivery }}</p>
@endif

@if($payUrl && ! $isPaid)
    <p style="margin:0 0 28px"><a href="{{ $payUrl }}" style="{{ $btn }}">Перейти к оплате</a></p>
@endif

<p style="margin:0 0 4px;font-size:18px;line-height:26px;font-weight:bold">Не уверены в размере?</p>
<p style="margin:0 0 4px">Хотите уточнить детали доставки или особенности белья?</p>
<p style="margin:0 0 14px">Напишите нам в поддержку на любой удобный мессенджер, мы поможем разобраться.</p>
<p style="margin:0 0 24px">
    <a href="{{ \App\Support\EmailBranding::TELEGRAM_URL }}" style="{{ $btnLight }}">Написать в Telegram</a>
    <a href="{{ \App\Support\EmailBranding::MAX_URL }}" style="{{ $btnLight }}">Написать в MAX</a>
    <a href="{{ \App\Support\EmailBranding::WHATSAPP_URL }}" style="{{ $btnLight }}">Написать в WhatsApp</a>
</p>

<p style="margin:0 0 4px">Спасибо, что доверяете AGAIN.</p>
<p style="margin:0">С заботой о вас и вашем комфорте,<br><strong>Команда AGAIN</strong></p>
