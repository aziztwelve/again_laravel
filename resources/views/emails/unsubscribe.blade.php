<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Отписка от писем AGAIN</title>
</head>
<body style="margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#292725">
<div style="max-width:480px;margin:0 auto;padding:24px;text-align:center">
    @include('emails.partials.logo')
    @if($done)
        <h1 style="margin:0 0 12px;font-size:22px;line-height:30px">Вы отписались</h1>
        <p style="margin:0 0 12px;font-size:15px;line-height:22px">Адрес {{ $email }} больше не получает рекламные письма AGAIN.</p>
        <p style="margin:0 0 24px;font-size:15px;line-height:22px;color:#5e5955">Письма о ваших заказах (заказ принят, оплачен, отправлен, чек) мы по-прежнему отправляем: они нужны, чтобы вы получили покупку.</p>
    @else
        <h1 style="margin:0 0 12px;font-size:22px;line-height:30px">Отписаться от рассылки?</h1>
        <p style="margin:0 0 20px;font-size:15px;line-height:22px">Адрес {{ $email }} перестанет получать рекламные письма AGAIN: напоминания о корзине, «снова в наличии» и промокоды. Письма о заказах продолжат приходить.</p>
        <form method="post" action="{{ $action }}">
            <button type="submit" style="padding:14px 28px;background:#292725;color:#ffffff;border:0;font-size:15px;cursor:pointer">Отписаться</button>
        </form>
    @endif
    <p style="margin:28px 0 0;font-size:13px"><a href="{{ \App\Support\PublicUrl::base() }}" style="color:#5e5955">Вернуться на сайт</a></p>
</div>
</body>
</html>
