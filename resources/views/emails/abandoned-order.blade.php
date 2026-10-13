<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><title>{{ $subject }}</title></head>
<body style="margin:0;background:#f7f5f1;color:#292725;font-family:Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;"><tr><td style="padding:32px;">
<p style="margin:0 0 32px;font-size:24px;font-weight:bold;letter-spacing:3px;">AGAIN</p>
<p style="font-size:22px;font-weight:bold;">{{ $paragraphs[0] }}</p>
<p style="font-size:16px;line-height:26px;">{{ $paragraphs[1] }}</p>
<p style="font-size:16px;line-height:26px;">Мы забронировали ваши товары, чтобы их случайно не купил кто-то другой, но бронь действует только <strong>24 часа</strong>.</p>
<p style="font-size:16px;line-height:26px;">{{ $paragraphs[3] }}</p>
<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background:#292725;padding:16px 24px;">
<a href="{{ $url }}" style="color:#ffffff;text-decoration:none;font-size:16px;font-weight:bold;">👉 Завершить покупку в 1 клик</a>
</td></tr></table>
</td></tr></table>
</td></tr></table>
</body>
</html>
