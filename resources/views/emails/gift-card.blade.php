<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#333333;line-height:1.6">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
        <td align="center" style="padding:20px">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%">
                <tr>
                    <td style="font-size:16px">
                        <h2 style="margin:0 0 16px;font-size:22px;line-height:1.3">{{ $subject }}</h2>

                        <p style="margin:0 0 16px">
                            @if($name)
                                {{ $name }}, заботиться о себе — это здорово!🌸
                            @else
                                Заботиться о себе — это здорово!🌸
                            @endif
                        </p>

                        <p style="margin:0 0 16px">В этом письме твой подарочный сертификат на сумму <strong>{{ $nominal }}</strong></p>

                        @if($imageUrl)
                            <img src="{{ $imageUrl }}" alt="Подарочный сертификат Again на {{ $nominal }}" width="560" style="display:block;width:100%;max-width:560px;height:auto;border:0;margin:0 0 16px">
                        @endif

                        @if($message)
                            <p style="margin:0 0 16px">
                                💌 Сообщение{{ $sender ? ' от '.$sender : '' }}:<br>
                                «{{ $message }}»
                            </p>
                        @endif

                        <p style="margin:0 0 16px">
                            Скопируй промокод
                            <strong style="display:inline-block;padding:2px 10px;background:#f3f1ee;border:1px dashed #292725;font-family:'Courier New',monospace;font-size:18px;letter-spacing:2px">{{ $code }}</strong>
                            для того, чтобы приобрести менструальное белье Again на самых привлекательных условиях.
                        </p>

                        <h3 style="margin:24px 0 8px;font-size:18px">Как использовать промокод?</h3>
                        <ol style="margin:0 0 16px;padding-left:20px">
                            <li style="margin-bottom:8px">Зарегистрируйся на сайте <a href="{{ $shopUrl }}" style="color:#292725">{{ $shopUrl }}</a> или войди в свой личный кабинет.</li>
                            <li style="margin-bottom:8px">Добавь выбранное белье в корзину и нажми на кнопку «Оформить заказ». В поле «Подарочная карта» введи код, указанный выше.</li>
                            <li>Ты супер! Больше никаких действий совершать не нужно. Сумма твоего заказа будет пересчитана автоматически, с учетом скидки.</li>
                        </ol>

                        <p style="margin:0 0 12px">Если у тебя остаются вопросы — напиши в нашу службу заботы по этой ссылке👇</p>

                        @foreach($supportLinks as $link)
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 10px">
                                <tr>
                                    <td align="center" bgcolor="#292725" style="border-radius:4px">
                                        <a href="{{ $link['url'] }}" target="_blank" style="display:inline-block;padding:12px 24px;font-size:15px;color:#ffffff;text-decoration:none">{{ $link['label'] }}</a>
                                    </td>
                                </tr>
                            </table>
                        @endforeach

                        <p style="margin:16px 0">Наслаждайся каждым днем и продолжай выбирать для себя только лучшее! Ждем тебя за покупками снова!</p>
                        <p style="margin:0">Again.♥️</p>
                    </td>
                </tr>
                <tr>
                    <td>@include('emails.partials.footer')</td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
