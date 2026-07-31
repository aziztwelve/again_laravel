<!doctype html>
<html lang="ru">
<body style="margin:0;padding:0;background:#f4f2ef;font-family:Arial,Helvetica,sans-serif;color:#292725">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f2ef">
    <tr>
        <td align="center" style="padding:24px 12px">
            <table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:640px;background:#ffffff">
                <tr>
                    <td align="center" style="padding:26px 32px;border-bottom:1px solid #e8e5e1;font-size:22px;line-height:26px;font-weight:700;letter-spacing:2px">
                        AGAIN
                    </td>
                </tr>
                <tr>
                    <td style="padding:40px 48px 18px">
                        <div style="font-size:30px;line-height:36px;font-weight:700;color:#292725">{{ $headline }}</div>
                        <div style="padding-top:16px;font-size:16px;line-height:24px;color:#5e5955">
                            {{ $greeting }}<br>
                            {!! nl2br(e($messageText)) !!}
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:14px 48px 40px">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#efede9;border-radius:8px">
                            <tr>
                                <td style="padding:20px 20px 8px;font-size:17px;line-height:24px;font-weight:700;color:#292725">{{ $cta }}</td>
                            </tr>
                            <tr>
                                <td style="padding:0 20px 16px;font-size:14px;line-height:20px;color:#5e5955">Успейте купить то, что хотели</td>
                            </tr>
                            <tr>
                                <td style="padding:0 20px 20px">
                                    <a href="{{ $link }}" style="display:inline-block;background:#292725;border-radius:4px;padding:12px 20px;color:#ffffff;text-decoration:none;font-size:14px;line-height:20px;font-weight:700">{{ $cta }}</a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 48px 16px;font-size:19px;line-height:25px;font-weight:700;color:#292725">Товары в корзине</td>
                </tr>
                <tr>
                    <td style="padding:0 48px 24px">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            @foreach($cart->items as $item)
                                @php
                                    $productName = $item->product?->name;
                                    $variantName = $item->productVariant?->name;
                                    $name = $productName ?: $variantName ?: 'Товар';
                                    $imageModel = $item->productVariant?->images?->first() ?: $item->product?->images?->first();
                                    $imageUrl = $imageModel?->url;
                                    if (! $imageUrl && $imageModel?->path) {
                                        $storedPath = ltrim($imageModel->path, '/');
                                        $fileName = basename($storedPath);
                                        $imageCandidates = [
                                            $storedPath,
                                            'products/'.$storedPath,
                                            'products/original_'.$fileName,
                                            'products/lg_'.$fileName,
                                            'products/md_'.$fileName,
                                            'products/sm_'.$fileName,
                                        ];
                                        foreach (array_unique($imageCandidates) as $candidate) {
                                            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($candidate)) {
                                                $imageUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($candidate);
                                                break;
                                            }
                                        }
                                    }
                                    if ($imageUrl && ! \Illuminate\Support\Str::startsWith($imageUrl, ['http://', 'https://'])) {
                                        $imageUrl = url('/'.ltrim($imageUrl, '/'));
                                    }
                                @endphp
                                <tr>
                                    <td style="padding:16px 0;border-top:1px solid #e8e5e1" valign="top">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td width="72" style="width:72px;padding-right:16px" valign="top">
                                                    @if($imageUrl)
                                                        <img src="{{ $imageUrl }}" alt="{{ $name }}" width="64" height="64" style="display:block;width:64px;height:64px;object-fit:cover;border:0;background:#f4f2ef">
                                                    @else
                                                        <div style="width:64px;height:64px;background:#f4f2ef;font-size:11px;line-height:64px;text-align:center;color:#8a847e">AGAIN</div>
                                                    @endif
                                                </td>
                                                <td valign="top" style="font-size:15px;line-height:21px;color:#292725">
                                                    <strong>{{ $name }}</strong><br>
                                                    <span style="font-size:14px;color:#77716b">
                                                        @if($variantName && $variantName !== $name){{ $variantName }} · @endif
                                                        @if($item->color?->name){{ $item->color->name }} · @endif
                                                        {{ $item->quantity }} шт.
                                                    </span>
                                                </td>
                                                <td align="right" valign="bottom" style="padding-left:12px;font-size:15px;line-height:21px;font-weight:700;color:#292725;white-space:nowrap">
                                                    {{ number_format($item->price * $item->quantity, 0, ',', ' ') }} ₽
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 48px 24px;border-top:1px solid #e8e5e1">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="font-size:17px;line-height:25px;font-weight:700;color:#292725">Итого</td>
                                <td align="right" style="font-size:17px;line-height:25px;font-weight:700;color:#292725">{{ $total }} ₽</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                @if($promoCode)
                    <tr>
                        <td style="padding:0 48px 24px;font-size:14px;line-height:21px;color:#5e5955">Ваш промокод: <strong style="color:#292725">{{ $promoCode }}</strong></td>
                    </tr>
                @endif
                <tr>
                    <td align="center" style="padding:0 48px 40px">
                        <a href="{{ $link }}" style="display:inline-block;background:#292725;border-radius:4px;padding:13px 22px;color:#ffffff;text-decoration:none;font-size:14px;line-height:20px;font-weight:700">{{ $cta }}</a>
                    </td>
                </tr>
                <tr><td>@include('emails.partials.footer')</td></tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
