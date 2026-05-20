<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Унифицируем номер заказа: order_number = id.
 *
 * До: orders.order_number = 'ORD-6A068ABD7CDC6' (uniqid), orders.id = 67715 —
 * два разных номера на один заказ, путаница в коммуникации со складом/клиентом.
 *
 * После: для всех «своих» заказов (сгенерированных сервисом, префикс ORD-)
 * order_number перезаписывается значением id, т.е. чистым числом ('67715').
 *
 * Legacy-заказы, импортированные из InSales, имеют внешний номер из CSV
 * (например, '123456' — без префикса 'ORD-'). Их НЕ трогаем, иначе потеряем
 * соответствие с историческими данными InSales.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Защита от UNIQUE-коллизии: если у какого-то legacy-заказа
        // order_number численно совпадает с id «нашего» ORD-заказа —
        // прямой UPDATE упадёт. Считаем такие пересечения и предупреждаем.
        $collisions = DB::select("
            SELECT o1.id, o1.order_number AS old_number, o2.id AS legacy_id
            FROM orders o1
            INNER JOIN orders o2 ON o2.order_number = CAST(o1.id AS CHAR)
            WHERE o1.order_number LIKE 'ORD-%'
              AND o2.id <> o1.id
        ");

        if (! empty($collisions)) {
            // Логируем и прерываем — пусть оператор разрулит вручную.
            throw new \RuntimeException(
                'Обнаружены коллизии: legacy-заказы с order_number, '.
                'совпадающим с id обновляемых ORD-заказов. Кол-во: '.count($collisions).
                '. Первые: '.json_encode(array_slice($collisions, 0, 5))
            );
        }

        // Перезаписываем order_number = id для всех заказов с префиксом ORD-.
        DB::statement("
            UPDATE orders
            SET order_number = CAST(id AS CHAR)
            WHERE order_number LIKE 'ORD-%'
        ");
    }

    public function down(): void
    {
        // Откат невозможен: исходные ORD-... значения утеряны.
        // Сознательно оставляем no-op, чтобы случайный rollback не сломал данные.
    }
};
