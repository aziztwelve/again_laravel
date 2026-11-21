{{-- Письмо 4: «Заказ отправлен». Тело письма; шапку с логотипом и подвал добавляет emails.shell. --}}
@php
    $btn = 'display:inline-block;padding:14px 28px;background:#292725;color:#ffffff;text-decoration:none;font-size:15px;line-height:20px;font-weight:bold';
@endphp
<h1 style="margin:0 0 16px;font-size:24px;line-height:32px;font-weight:bold;color:#292725">Ваш заказ уже в пути!</h1>

<p style="margin:0 0 12px">{{ $greeting }}</p>
<p style="margin:0 0 12px">Отличные новости! Ваш заказ собран, бережно упакован и передан в службу доставки. Совсем скоро ваша покупка AGAIN будет у вас!</p>
<p style="margin:0 0 20px">Следить за перемещением посылки и узнать ориентировочную дату доставки можно по ссылке ниже.</p>

@if($trackUrl)
    <p style="margin:0 0 16px"><a href="{{ $trackUrl }}" style="{{ $btn }}">Отследить заказ</a></p>
@endif
@if($number)
    <p style="margin:0 0 24px;font-size:16px;line-height:24px"><strong>Номер отправления:</strong>
        @if($trackUrl)<a href="{{ $trackUrl }}" style="color:#292725;text-decoration:underline">{{ $number }}</a>@else{{ $number }}@endif
    </p>
@endif
@if($carrier)
    <p style="margin:0 0 24px;font-size:14px;line-height:20px;color:#5e5955">Служба доставки: {{ $carrier }}</p>
@endif

<p style="margin:0 0 20px">Полная информация по вашим заказам есть в личном кабинете: авторизуйтесь и следите за статусом отправления, бонусами, акциями и промокодами!</p>
<p style="margin:0 0 28px"><a href="{{ $cabinetUrl }}" style="{{ $btn }}">Войти в личный кабинет</a></p>

<p style="margin:0 0 4px">Спасибо, что выбираете AGAIN!</p>
<p style="margin:0">С заботой о вас,<br><strong>Команда AGAIN</strong></p>
