<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryServiceSetting;
use App\Models\FreeShippingRule;
use App\Services\Delivery\CdekDeliveryService;
use App\Traits\HelperTrait;
use Illuminate\Http\Request;

class CDEKController extends Controller
{
    use HelperTrait;


    public function get_cdek_locations(Request $request)
    {
        $cdek_service = new CdekDeliveryService();

        $locations = $cdek_service->get_offices(
            $request->get('country_code', 'ru'),
            $request->get('city_code'),
            $request->get('region_code'),
            $request->get('city_name'),
            true,
            $request->boolean('get_locations_only', false)
        );

        if ($request->get('per_page')) {
            $paginated = $this->paginate_collection($locations, $request);
            return response()->json([
                'cdek_offices' => $paginated->items(),
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                    'last_page' => $paginated->lastPage(),
                ],
            ]);
        } else {
            return response()->json([
                'cdek_offices' => $locations
            ]);
        }
    }

    public function get_cdek_cities(Request $request)
    {

        $request->validate([
            'city' => 'nullable|string',
            'country_code' => 'nullable|string',
            'region_code' => 'nullable|string',
            'code' => 'nullable|string',
        ]);

        $cdek_service = new CdekDeliveryService();

        $cities = $cdek_service->location_cities($request);

        $paginated = $this->paginate_collection($cities, $request);

        return response()->json([
            'cities' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }


    public function get_cdek_regions(Request $request)
    {
        $cdek_service = new CdekDeliveryService();
        return $cdek_service->location_regions($request);
    }

    public function get_tariffs()
    {
        return response()->json(['tariffs' => (new CdekDeliveryService())->availableTariffs()]);
    }

    public function check_address()
    {
    }

    public function update_cdek_settings(Request $request)
    {
        $request->validate([
            'acount' => 'required|string',
            'secure_password' => 'required|string',
        ]);

        $cdek_settings = DeliveryServiceSetting::where('service_name', 'cdek')->first();

        if (!$cdek_settings) {
            $cdek_settings = DeliveryServiceSetting::create([
                'service_name' => 'cdek',
            ]);
        }

        $cdek_settings->token = $request->get('acount');
        $cdek_settings->secret = $request->get('secure_password');
        $cdek_settings->call_courier_to_the_office = $request->boolean('call_courier_to_the_office', false);
        $cdek_settings->save();

        return response()->json([
            'message' => 'Настройки CDEK успешно обновлены',
            'settings' => $cdek_settings
        ]);
    }
    public function settings()
    {
        $settings = DeliveryServiceSetting::query()->where('service_name', 'cdek')->value('settings') ?? [];
        unset($settings['secure_password']);

        return response()->json(['success' => true, 'settings' => $settings]);
    }

    public function tariffs()
    {
        return response()->json(['tariffs' => (new CdekDeliveryService())->availableTariffs()]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.enabled' => ['boolean'],
            'settings.account' => ['nullable', 'string', 'max:255'],
            'settings.secure_password' => ['nullable', 'string', 'max:255'],
            'settings.sender' => ['nullable', 'array'],
            'settings.sender.city_code' => ['nullable', 'integer'],
            'settings.sender.address' => ['nullable', 'string', 'max:255'],
            'settings.sender.name' => ['nullable', 'string', 'max:255'],
            'settings.sender.postal_code' => ['nullable', 'string', 'max:20'],
            'settings.sender.phone' => ['nullable', 'string', 'max:50'],
            'settings.tariff_codes' => ['nullable', 'array'],
            'settings.tariff_codes.*' => ['integer'],
            'settings.status_mapping' => ['nullable', 'array'],
            'settings.default_package' => ['nullable', 'array'],
            'settings.default_package.weight' => ['nullable', 'numeric', 'min:1'],
            'settings.default_package.length' => ['nullable', 'numeric', 'min:0.1'],
            'settings.default_package.width' => ['nullable', 'numeric', 'min:0.1'],
            'settings.default_package.height' => ['nullable', 'numeric', 'min:0.1'],
        ]);

        $record = DeliveryServiceSetting::firstOrCreate(['service_name' => 'cdek']);
        $newSettings = $data['settings'];
        if (blank($newSettings['secure_password'] ?? null)) unset($newSettings['secure_password']);
        $settings = array_replace_recursive($record->settings ?? [], $newSettings);
        $record->update(['settings' => $settings]);

        $settings = $this->syncCdekFreeShippingRule($record, $settings);

        unset($settings['secure_password']);
        return response()->json(['success' => true, 'message' => 'Настройки СДЭК сохранены', 'settings' => $settings]);
    }

    /** Keep the CDEK page threshold as an additional (OR) rule in the shared engine. */
    private function syncCdekFreeShippingRule(DeliveryServiceSetting $record, array $settings): array
    {
        $threshold = max(0, (float) data_get($settings, 'price_rules.threshold', 0));
        $rule = filled($settings['free_shipping_rule_id'] ?? null)
            ? FreeShippingRule::query()->find($settings['free_shipping_rule_id'])
            : null;

        if ($threshold <= 0) {
            if ($rule) $rule->update(['is_active' => false]);
            return $settings;
        }

        $attributes = [
            'name' => 'СДЭК: бесплатная доставка из настроек',
            'is_active' => true,
            'priority' => 0,
            'min_order_amount' => $threshold,
            'services' => ['cdek'],
            'delivery_types' => [],
            'payment_methods' => [],
            'starts_at' => null,
            'ends_at' => null,
        ];
        $rule ??= FreeShippingRule::create($attributes);
        $rule->update($attributes);
        $settings['free_shipping_rule_id'] = $rule->id;
        $record->update(['settings' => $settings]);
        app(\App\Services\Delivery\FreeShippingService::class)->flushCache();

        return $settings;
    }
}
