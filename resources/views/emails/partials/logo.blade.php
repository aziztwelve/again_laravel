{{-- Шапка письма: логотип AGAIN по центру. Один и тот же во всех письмах. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
        <td align="center" style="padding:28px 0 24px">
            <a href="{{ \App\Support\PublicUrl::base() }}" style="text-decoration:none">
                <img src="{{ \App\Support\EmailBranding::asset('logo.png') }}" alt="AGAIN" width="120" style="display:block;width:120px;height:auto;border:0">
            </a>
        </td>
    </tr>
</table>
