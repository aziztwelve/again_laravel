<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Заказ №<?php echo e($order->order_number ?? $order->id); ?></title>
</head>
<body style="margin:0;padding:0;background:#fff;">
<div style="margin:0">
    <div style="background-color:#fff;border:1px solid #f3f2f2;margin-bottom:30px;margin-left:auto;margin-right:auto;max-width:500px">

        
        <div style="background-color:#f5f5f5;box-sizing:border-box;padding:40px 0;padding-left:20px;padding-right:20px">
            <table style="margin-left:auto;margin-right:auto;max-width:580px;width:100%" cellspacing="0" cellpadding="0">
                <tr>
                    <td style="color:#000;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px">
                        <?php echo e($shopName); ?>

                    </td>
                    <td style="color:#000;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px;text-align:right">
                        Заказ № <?php echo e($order->order_number ?? $order->id); ?>

                    </td>
                </tr>
            </table>
        </div>

        
        <div style="box-sizing:border-box;padding-top:28px;padding-bottom:10px;padding-left:20px;padding-right:20px">
            <table style="margin-left:auto;margin-right:auto;max-width:580px;width:100%" cellspacing="0" cellpadding="0">
                <tr>
                    <td style="color:#000;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px;vertical-align:top">
                        <div style="font-weight:600;line-height:1.5;margin-bottom:10px">
                            Здравствуйте<?php echo e($customerName ? ', '.$customerName : ''); ?>!
                        </div>
                        <div style="color:#6d6d6d">
                            Благодарим вас за заказ!
                        </div>
                        <div style="background-color:#787878;border-radius:1px;height:2px;margin-bottom:8px;margin-top:8px;width:42px"></div>
                    </td>
                    <?php if($viewOrderUrl): ?>
                        <td style="font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px;text-align:right;vertical-align:top">
                            <a href="<?php echo e($viewOrderUrl); ?>"
                               style="background-color:#9f80f1;border-radius:19px;color:#f9f9f9;display:inline-block;font-size:16px;font-weight:300;line-height:1;padding:11px 13px 12px 13px;text-decoration:none;white-space:nowrap"
                               target="_blank">
                                <span>Посмотреть заказ</span>
                            </a>
                        </td>
                    <?php endif; ?>
                </tr>
                <tr>
                    <td colspan="2" style="color:#000;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px">
                        <div style="margin-top:20px">Вы заказали:</div>
                    </td>
                </tr>
            </table>
        </div>

        
        <div style="box-sizing:border-box;margin-bottom:28px;padding-left:0px;padding-right:0px">
            <div style="box-sizing:border-box;margin-left:auto;margin-right:auto;max-width:580px;width:100%">
                <table style="width:100%" cellspacing="0" cellpadding="0">
                    <?php $__currentLoopData = $order->items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <?php
                            $unitPrice = (float) ($item->unit_price ?? $item->price ?? 0);
                            $qty = (int) $item->quantity;
                            $rowTotal = $unitPrice * $qty;
                            $productName = $item->product->name ?? $item->legacy_name ?? '—';
                            $variant = $item->variant->name ?? null;
                            $color = $item->color->name ?? null;
                            $extras = array_filter([$variant, $color]);
                            $itemImage = $item->variant?->images?->first()?->url
                                ?? $item->product?->images?->first()?->url
                                ?? null;
                        ?>
                        <tr style="background-color:#f7f5fd">
                            <td style="padding-left:20px;color:#000;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px;padding-bottom:7px;padding-top:7px;vertical-align:middle;width:82px">
                                <div style="background-color:#fff;display:inline-block;height:82px;overflow:hidden;vertical-align:top;width:82px;text-align:center;line-height:82px">
                                    <?php if($itemImage): ?>
                                        <img src="<?php echo e($itemImage); ?>" alt="<?php echo e($productName); ?>" style="max-width:82px;max-height:82px;vertical-align:middle">
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="padding-bottom:5px;padding-top:5px;color:#000;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px;padding-left:20px;padding-right:15px;width:80%">
                                <p style="color:#000;display:inline-block;margin:0 0 5px;text-decoration:none">
                                    <?php echo e($productName); ?><?php if(!empty($extras)): ?> (<?php echo e(implode(' / ', $extras)); ?>)<?php endif; ?>
                                </p>
                                <p style="color:#6d6d6d;font-size:12px;margin:0">
                                    <span style="white-space:nowrap">цена: <?php echo e(number_format($unitPrice, 0, ',', ' ')); ?> ₽,</span>
                                    <span style="white-space:nowrap">количество: <?php echo e($qty); ?> шт.</span>
                                </p>
                            </td>
                            <td style="padding-bottom:5px;padding-top:5px;padding-right:20px;color:#000;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px;text-align:right;white-space:nowrap;width:110px">
                                <?php echo e(number_format($rowTotal, 0, ',', ' ')); ?> ₽
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </table>
            </div>
        </div>

        
        <div style="box-sizing:border-box;margin-bottom:28px;padding-left:20px;padding-right:20px">
            <div style="margin-left:auto;margin-right:auto;max-width:580px;width:100%">
                <table style="width:100%" cellspacing="0" cellpadding="0">
                    <tr>
                        <td style="color:#000;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px">
                            <div>Сумма:</div>
                            <div style="background-color:#787878;border-radius:1px;height:2px;margin-bottom:8px;margin-top:8px;width:42px"></div>
                        </td>
                        <td style="color:#000;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px;text-align:right;white-space:nowrap;width:110px;vertical-align:top">
                            <?php echo e(number_format($subtotal, 0, ',', ' ')); ?> ₽
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        
        <?php if($discountAmount > 0): ?>
            <div style="box-sizing:border-box;margin-bottom:28px;padding-left:20px;padding-right:20px">
                <div style="margin-left:auto;margin-right:auto;max-width:580px;width:100%">
                    <table style="width:100%" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="color:#000;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px">
                                <p style="font-size:12px;color:#6d6d6d;margin-bottom:0">Скидка:</p>
                                <div style="font-size:14px"><?php echo e($discountLabel ?: 'Применённая скидка'); ?></div>
                            </td>
                            <td style="color:#000;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px;text-align:right;white-space:nowrap;width:110px">
                                −<?php echo e(number_format($discountAmount, 0, ',', ' ')); ?> ₽
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        
        <?php if($deliveryMethodLine || $deliveryAddress): ?>
            <div style="box-sizing:border-box;margin-bottom:28px;padding-left:20px;padding-right:20px">
                <div style="margin-left:auto;margin-right:auto;max-width:580px;width:100%">
                    <table style="width:100%" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="color:#000;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px">
                                <p style="font-size:12px;color:#6d6d6d;margin-bottom:0">Способ получения товара:</p>
                                <div style="font-size:14px">
                                    <?php if($deliveryMethodLine): ?><?php echo e($deliveryMethodLine); ?><?php endif; ?>
                                    <?php if($deliveryAddress): ?><br><?php echo e($deliveryAddress); ?><?php endif; ?>
                                </div>
                            </td>
                            <td style="color:#000;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px;text-align:right;white-space:nowrap;width:110px">
                                <?php echo e(number_format((float) $order->delivery_cost, 0, ',', ' ')); ?> ₽
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        
        <?php if($paymentMethodLabel): ?>
            <div style="box-sizing:border-box;margin-bottom:28px;padding-left:20px;padding-right:20px">
                <div style="margin-left:auto;margin-right:auto;max-width:580px;width:100%">
                    <table style="width:100%" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="color:#000;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px;padding-bottom:28px">
                                <p style="font-size:12px;color:#6d6d6d;margin:0">Способ оплаты:</p>
                                <p style="font-size:14px;margin:0"><?php echo e($paymentMethodLabel); ?></p>
                            </td>
                            <td style="color:#000;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px;text-align:right;white-space:nowrap;width:110px"></td>
                        </tr>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        
        <?php if($paymentStatusLabel): ?>
            <div style="box-sizing:border-box;margin-bottom:28px;padding-left:20px;padding-right:20px">
                <div style="margin-left:auto;margin-right:auto;max-width:580px;width:100%">
                    <table style="width:100%" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="color:#000;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px">
                                <p style="font-size:12px;color:#6d6d6d;margin:0">Статус оплаты:</p>
                                <p style="font-size:14px;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;margin:0"><?php echo e($paymentStatusLabel); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        
        <div style="background-color:#f7f5fd;box-sizing:border-box;padding:20px 0;padding-left:20px;padding-right:20px">
            <div style="margin-left:auto;margin-right:auto;max-width:580px;width:100%">
                <table style="width:100%" cellspacing="0" cellpadding="0">
                    <tr>
                        <td style="color:#000;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px;font-weight:600">Итого к оплате:</td>
                        <td style="color:#000;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px;font-weight:600;text-align:right;white-space:nowrap;width:110px">
                            <?php echo e(number_format((float) $order->total_amount, 0, ',', ' ')); ?> ₽
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        
        <?php if(!empty($messengerLinks)): ?>
            <div style="box-sizing:border-box;padding-bottom:30px;padding-left:20px;padding-right:20px;padding-top:30px">
                <div style="margin-left:auto;margin-right:auto;max-width:580px;width:100%">
                    <table style="width:100%" cellspacing="0" cellpadding="0">
                        <tr>
                            <td style="color:#000;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px;vertical-align:top">
                                <div style="padding-right:30px">
                                    Отследить заказ <br>
                                    через мессенджеры
                                    <div style="background-color:#787878;border-radius:1px;height:2px;margin-bottom:8px;margin-top:8px;width:42px"></div>
                                </div>
                            </td>
                            <td style="color:#000;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px;vertical-align:top">
                                <div>
                                    <?php $__currentLoopData = $messengerLinks; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $messenger): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <a href="<?php echo e($messenger['url']); ?>" style="color:#a080f2;margin-right:30px;text-decoration:none" target="_blank"><?php echo e($messenger['label']); ?></a>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        
        <div style="background-color:#f5f5f5;box-sizing:border-box;padding-bottom:17px;padding-left:20px;padding-right:20px;padding-top:17px">
            <div style="margin-left:auto;margin-right:auto;max-width:580px;width:100%">
                <table style="width:100%" cellspacing="0" cellpadding="0">
                    <tr>
                        <td style="color:#000;font-family:'Open Sans',Segoe UI,Roboto,Ubuntu,Cantarell,Fira Sans,Droid Sans,Helvetica Neue,sans-serif,arial;font-size:16px">
                            <div style="color:#6d6d6d;font-size:12px">
                                Интернет-магазин <a href="<?php echo e($shopUrl); ?>" style="color:#725aaf;display:inline-block;margin-bottom:4px;text-decoration:none;vertical-align:baseline" target="_blank"><?php echo e($shopName); ?>.</a>
                                <?php if($contactPhone): ?>
                                    <br>Контактный телефон: <a href="tel:<?php echo e(preg_replace('/\D+/', '', $contactPhone)); ?>" style="color:#725aaf;text-decoration:none;white-space:nowrap" target="_blank"><?php echo e($contactPhone); ?></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>
<?php /**PATH /var/www/html/laravel/resources/views/emails/order-receipt.blade.php ENDPATH**/ ?>