<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'AGAIN' }}</title>
</head>
<body style="margin:0;padding:0;background:#ffffff">
{!! $marketing ?? '' !!}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px">
                <tr><td>@include('emails.partials.logo')</td></tr>
                <tr>
                    <td style="padding:0 24px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:24px;color:#292725">
                        {!! $body !!}
                    </td>
                </tr>
                <tr><td>@include('emails.partials.footer')</td></tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
