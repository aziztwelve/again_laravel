<?php

namespace Tests\Feature\Order;

use App\Http\Requests\Order\CreateOrderRequest;
use App\Models\Client;
use App\Models\User;
use Tests\TestCase;

/**
 * Заказ 41385: менеджер создал заказ клиенту, а почта менеджера попала в заказ
 * и на неё ушёл кассовый чек.
 */
class OrderCreateStaffEmailTest extends TestCase
{
    private function prepared(object $authUser, array $input): CreateOrderRequest
    {
        $request = CreateOrderRequest::create('/api/orders', 'POST', $input);
        $request->setUserResolver(fn () => $authUser);

        $method = new \ReflectionMethod($request, 'prepareForValidation');
        $method->setAccessible(true);
        $method->invoke($request);

        return $request;
    }

    public function test_staff_email_is_not_copied_into_the_order(): void
    {
        $manager = new User(['email' => 'manager@example.com']);

        $request = $this->prepared($manager, ['user' => ['first_name' => 'Олеся', 'phone' => '+79277014419']]);

        $this->assertEmpty($request->input('user.email'));
    }

    public function test_email_sent_by_the_form_is_kept_for_staff_orders(): void
    {
        $manager = new User(['email' => 'manager@example.com']);

        $request = $this->prepared($manager, ['user' => ['email' => 'client@example.com']]);

        $this->assertSame('client@example.com', $request->input('user.email'));
    }

    public function test_customer_own_email_is_still_used_on_the_storefront(): void
    {
        $client = new Client(['email' => 'client@example.com']);

        $request = $this->prepared($client, ['user' => ['first_name' => 'Олеся']]);

        $this->assertSame('client@example.com', $request->input('user.email'));
    }
}
