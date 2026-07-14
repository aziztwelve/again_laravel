<!doctype html>
<html lang="ru"><body style="margin:0;padding:0;background:#f5f3f1;font-family:Arial,Helvetica,sans-serif;color:#171717">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff">
<tr><td style="padding:28px 32px;border-bottom:1px solid #ebe7e3;font-size:22px;font-weight:700;letter-spacing:1px">AGAIN</td></tr>
<tr><td style="padding:34px 32px 20px"><div style="font-size:30px;line-height:1.12;font-weight:700">Ваш выбор<br>всё ещё в корзине</div><p style="margin:16px 0 0;font-size:16px;line-height:1.5;color:#625f5b">{{ $intro }}</p></td></tr>
<tr><td style="padding:8px 32px 24px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f7f5f3">
@foreach($cart->items as $item)
@php($name = $item->productVariant?->name ?: $item->product?->name ?: 'Товар')
@php($image = $item->productVariant?->images?->first()?->url ?? $item->product?->images?->first()?->url)
<tr><td style="padding:14px;width:72px">@if($image)<img src="{{ $image }}" alt="{{ $name }}" width="64" height="64" style="display:block;object-fit:cover;background:#fff">@endif</td><td style="padding:14px 8px;font-size:14px;line-height:1.4"><strong>{{ $name }}</strong><br><span style="color:#716d69">{{ $item->color?->name }} · {{ $item->quantity }} шт.</span></td><td style="padding:14px;text-align:right;font-size:14px;white-space:nowrap">{{ number_format($item->price * $item->quantity, 0, ',', ' ') }} ₽</td></tr>
@endforeach
</table></td></tr>
<tr><td align="center" style="padding:0 32px 28px"><a href="{{ $link }}" style="display:inline-block;background:#171717;color:#fff;padding:16px 28px;text-decoration:none;font-weight:700;font-size:14px">ВЕРНУТЬСЯ К ОФОРМЛЕНИЮ</a></td></tr>
<tr><td style="padding:22px 32px;background:#171717;color:#fff"><table width="100%"><tr><td style="font-size:14px">Сумма корзины</td><td align="right" style="font-size:20px;font-weight:700">{{ $total }} ₽</td></tr></table>@if($promoCode)<p style="margin:16px 0 0;font-size:14px">Ваш промокод: <strong>{{ $promoCode }}</strong></p>@endif</td></tr>
<tr><td style="padding:24px 32px;font-size:12px;line-height:1.5;color:#817c77">Если нужна помощь с размером или оформлением, просто ответьте на это письмо.<br>© AGAIN</td></tr>
</table></td></tr></table></body></html>
