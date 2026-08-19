<?php

namespace App\Services\MoySklad;

use App\Models\DeliveryServiceSetting;
use App\Traits\ProductsTrait;
use Evgeek\Moysklad\Api\Record\Objects\UnknownObject;
use Evgeek\Moysklad\MoySklad;
use Exception;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestSize\Unknown;

class MoySkladHelperService
{
    use ProductsTrait;

    private MoySklad $moySklad;
    private $token;
    private $baseURL = "https://api.moysklad.ru/api/remap/1.2";

    public function __construct()
    {

        $moyskadSettings = DeliveryServiceSetting
            ::where('service_name', 'moysklad')
            ->first();

        if (!$moyskadSettings) {
            throw new Exception("Настройки для МойСклад не найдены. Пожалуйста, настройте сервис в админке.");
        }

        $this->token = $moyskadSettings->token;
        $this->moySklad = new MoySklad(["{$moyskadSettings->token}"]);
    }


    public function get_currencies()
    {
        return $this->moySklad->query()->entity()->currency()->get()->rows[0];
    }

    public function get_price_types()
    {
        return $this->moySklad->query()->context()->companysettings()->pricetype()->get();
    }

    public function get_units($search = null)
    {
        // $search = "штук";
        $units = $this->moySklad->query()->entity()->uom()->get();
        $rows = collect($units->rows ?? []);

        if (!$search) {
            return $rows;
        }

        $search = mb_strtolower(trim($search));

        return $rows->first(function ($unit) use ($search) {
            return str_contains(mb_strtolower($unit->description ?? ''), $search)
                || str_contains(mb_strtolower($unit->name ?? ''), $search);
        });
    }

    public function get_products()
    {
        return $this->moySklad->query()->entity()->product()->get();
    }

    public function get_product_variants()
    {
        $modifications = $this->moySklad->query()->entity()->variant()->get();

        return $modifications;
    }

    /**
     * Остатки товаров/вариантов из МойСклад.
     *
     * Использует /report/stock/all (не /report/stock/all/current), потому
     * что только этот отчёт отдаёт поле reserve и посчитанное доступное
     * количество (quantity = stock - reserve). Ключ 'stock' в результате —
     * ДОСТУПНОЕ количество (с учётом резерва по активным заказам), а не
     * физический остаток на полке: именно оно должно попадать на витрину,
     * иначе резервирование товара под заказ (см. OrderService::pushOrder())
     * не защищает от продажи того же товара другому покупателю.
     * Физический остаток сохранён отдельно в 'physical_stock'.
     *
     * @return array<string, array{stock: int, physical_stock: int, reserve: int}>
     */
    public function check_stock()
    {
        $result = [];
        $offset = 0;
        $limit = 1000;

        do {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
                'Content-Type' => 'application/json',
            ])->get("{$this->baseURL}/report/stock/all", [
                'limit' => $limit,
                'offset' => $offset,
            ]);

            if (!$response->successful()) {
                \Illuminate\Support\Facades\Log::error('MoySklad: не удалось получить отчёт по остаткам', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return $result;
            }

            $rows = $response->json('rows') ?? [];

            foreach ($rows as $row) {
                $href = $row['meta']['href'] ?? null;
                $assortmentId = $href ? basename(strtok($href, '?')) : null;

                if (!$assortmentId) {
                    continue;
                }

                $result[$assortmentId] = [
                    'assortmentId' => $assortmentId,
                    'stock' => (float) ($row['quantity'] ?? 0),
                    'physical_stock' => (float) ($row['stock'] ?? 0),
                    'reserve' => (float) ($row['reserve'] ?? 0),
                ];
            }

            $total = $response->json('meta.size') ?? count($rows);
            $offset += $limit;
        } while ($offset < $total);

        return $result;
    }


    public function create_characteristics()
    {
        $objects = [
            ["name" => "Размер", "type" => "string"],
            ["name" => "Цвет", "type" => "string"],
//            ["name" => "Номинал", "type" => "string"]
            // ["name" => "Test", "type" => "string"]
        ];

        $all_characteristics = $this->get_characteristics();

        foreach ($objects as $key => $value) {
            if (isset($all_characteristics[$value['name']])) {
                continue;
            }
            // url name after every "/" -> https://api.moysklad.ru/api/remap/1.2/entity/variant/metadata/characteristics
            $msCharacteristic = UnknownObject::make($this->moySklad, [
                'entity',
                'variant',
                'metadata',
                'characteristics'
            ], 'characteristic');

            $msCharacteristic->name = $value['name'];
            $msCharacteristic->type = $value['type'];

            $msCharacteristic->create();
        }

        return response()->json([
            'success' => true,
            'message' => 'Характеристики успешно созданы',
        ]);
    }

    public function get_characteristics()
    {
        $characteristics = $this->moySklad->query()
            ->entity()
            ->variant()
            ->metadata()
            ->characteristics()
            ->get();

        $characteristics = $characteristics->characteristics ?? [];

        $result = [];

        foreach ($characteristics as $characteristic) {
            $result[$characteristic->name] = [
                'id' => $characteristic->id,
                'name' => $characteristic->name,
                'type' => $characteristic->type,
                'meta' => $characteristic->meta,
            ];
        }
        return $result;
    }


    public function ensureCharacteristic(string $name, string $type = 'string'): string
    {
        $characteristics = $this->get_characteristics();

        if (!isset($characteristics[$name])) {
            $this->create_custom_characteristic($name, $type);

            // Повторно получаем после создания
            $characteristics = $this->get_characteristics();
        }

        $id = $characteristics[$name]['id'] ?? null;

        if (!$id) {
            throw new Exception("Характеристика '{$name}' не найдена в МойСклад даже после попытки создания.");
        }

        return $id;
    }


    public function sync_products_with_moysklad()
    {
        $productsAndVariantsSyncWithMoySklad = new ProductsAndVariantsSyncWithMoySkladService();

        return $productsAndVariantsSyncWithMoySklad->sync_products_with_moysklad();
    }

    /**
     * Получить первое юрлицо (organization) из МойСклад.
     * Возвращает массив с полями meta, id, name.
     *
     * @return array|null
     */
    public function get_organization(): ?array
    {
        $response = Http::withHeaders([
            'Authorization'   => 'Bearer ' . $this->token,
            'Accept-Encoding' => 'gzip',
        ])->get("{$this->baseURL}/entity/organization", ['limit' => 1]);

        if (! $response->successful() || empty($response->json('rows'))) {
            return null;
        }

        return $response->json('rows.0');
    }

    private function create_custom_characteristic(string $name, string $type = 'string'): void
    {
        $msCharacteristic = UnknownObject::make($this->moySklad, [
            'entity',
            'variant',
            'metadata',
            'characteristics'
        ], 'characteristic');

        $msCharacteristic->name = $name;
        $msCharacteristic->type = $type;

        $msCharacteristic->create();
    }
}
