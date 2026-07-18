<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:32px;background:#292725">
    <tr>
        <td style="padding:24px 32px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;color:#ffffff">
            © {{ now()->year }} AGAIN<br>
            Вы получили это письмо, потому что зарегистрировались на сайте AGAIN или оставили контактные данные для получения уведомлений.<br><br>
            Это сообщение отправлено вам от:<br>
            AGAIN | {{ config('mail.from.address') }}<br><br>
            <a href="mailto:{{ config('mail.from.address') }}?subject={{ rawurlencode('Отписка от рассылки AGAIN') }}" style="color:#ffffff;text-decoration:underline">Отписаться от рассылки</a>
        </td>
    </tr>
</table>
