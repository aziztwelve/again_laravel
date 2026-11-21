{{-- Подвал всех писем: сайт, описание, значки соцсетей и мелкая отписка. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:32px;background:#292725">
    <tr>
        <td align="center" style="padding:28px 24px 8px;font-family:Arial,Helvetica,sans-serif;color:#ffffff">
            <a href="{{ \App\Support\PublicUrl::base() }}" style="color:#ffffff;text-decoration:none;font-size:18px;line-height:24px;font-weight:bold;letter-spacing:1px">{{ \App\Support\PublicUrl::shopHost() }}</a>
            <div style="padding-top:6px;font-size:14px;line-height:20px;color:#d9d5cf">Умное бельё и косметика</div>
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:12px 24px 8px">
            @foreach(\App\Support\EmailBranding::socials() as $social)
                <a href="{{ $social['url'] }}" style="text-decoration:none;display:inline-block;margin:0 5px"><img src="{{ \App\Support\EmailBranding::asset($social['icon']) }}" alt="{{ $social['label'] }}" width="28" height="28" style="display:block;border:0;width:28px;height:28px"></a>
            @endforeach
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:8px 24px 24px;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:16px;color:#a8a39c">
            Если вы не хотите получать от нас рекламные письма, вы можете <a href="{{ \App\Support\EmailBranding::UNSUBSCRIBE_PLACEHOLDER }}" style="color:#a8a39c;text-decoration:underline">отписаться</a>.
        </td>
    </tr>
</table>
