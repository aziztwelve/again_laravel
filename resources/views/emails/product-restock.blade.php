<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Уже в наличии — Again</title>
</head>
@php
    $imageUrl = optional($product->main_image)->url;
    $price = $product->discount_price > 0 ? $product->discount_price : $product->price;
    $priceFormatted = number_format((float)$price, 0, '.', ' ') . ' р.';
    $font = "'Open Sans',Segoe UI,Roboto,Helvetica Neue,Arial,sans-serif";
@endphp
<body style="margin:0;padding:0;background:#ffffff;color:#2B2B2B;">
<div style="margin:0 auto;max-width:600px;background:#ffffff;padding:32px 20px;font-family:{{ $font }};">

    {{-- Шапка: логотип Again по центру --}}
    <div style="text-align:center;padding:8px 0 32px 0;">
        <span style="font-size:30px;font-weight:700;letter-spacing:2px;color:#2B2B2B;">AGAIN</span>
    </div>

    {{-- Заголовок «УЖЕ В НАЛИЧИИ» --}}
    <div style="text-align:center;padding-bottom:20px;">
        <span style="font-size:26px;font-weight:600;letter-spacing:6px;color:#4a4a4a;text-transform:uppercase;">
            Уже в наличии
        </span>
    </div>

    {{-- Текст --}}
    <div style="text-align:center;font-size:16px;line-height:1.6;color:#2B2B2B;padding:0 10px 32px 10px;">
        «{{ $product->name }}» уже на сайте. Успейте заказать нужный размер
    </div>

    {{-- Блок товара: 2 колонки (фото | название + цена + кнопка) --}}
    <table cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;">
        <tr>
            <td style="width:50%;vertical-align:top;padding-right:12px;">
                @if($imageUrl)
                    <img src="{{ $imageUrl }}" alt="{{ $product->name }}"
                         style="display:block;width:100%;max-width:280px;height:auto;border:0;">
                @else
                    <div style="width:100%;max-width:280px;height:320px;background:#f5f5f5;"></div>
                @endif
            </td>
            <td style="width:50%;vertical-align:top;padding-left:12px;">
                <div style="font-size:18px;font-weight:600;letter-spacing:1px;text-transform:uppercase;line-height:1.4;color:#2B2B2B;padding-bottom:24px;">
                    {{ $product->name }}
                </div>
                <div style="font-size:20px;color:#787878;padding-bottom:28px;">
                    {{ $priceFormatted }}
                </div>
                <a href="{{ $productUrl }}" target="_blank"
                   style="display:inline-block;background-color:#CB0B13;color:#ffffff;font-size:15px;font-weight:600;letter-spacing:2px;text-transform:uppercase;text-decoration:none;padding:14px 38px;">
                    Купить
                </a>
            </td>
        </tr>
    </table>

    {{-- Футер --}}
    <div style="text-align:center;font-size:12px;color:#999999;padding-top:40px;border-top:1px solid #eeeeee;margin-top:40px;">
        С уважением, команда Again
    </div>
</div>
</body>
</html>
