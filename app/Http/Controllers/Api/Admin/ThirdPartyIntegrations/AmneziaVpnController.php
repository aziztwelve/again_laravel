<?php

namespace App\Http\Controllers\Api\Admin\ThirdPartyIntegrations;

use App\Http\Controllers\Controller;
use App\Services\Integrations\AmneziaVpnService;
use Illuminate\Http\Request;

class AmneziaVpnController extends Controller
{
    public function __construct(private readonly AmneziaVpnService $service)
    {
    }

    public function show()
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->settings(),
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'scheme' => ['required', 'in:socks5,socks5h,http,https'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1024'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Amnezia VPN settings saved.',
            'data' => $this->service->update($validated),
        ]);
    }

    public function test()
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->test(),
        ]);
    }
}
