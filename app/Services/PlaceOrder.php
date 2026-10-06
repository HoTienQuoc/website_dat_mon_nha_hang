<?php

namespace App\Services;

use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\RestaurantSetting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlaceOrder
{
    public function __construct(private Cart $cart) {}

    public function handle(array $data, array $quantities, string $key, string $owner, int $quotedTotal, ?int $customerId = null): Order
    {
        $fingerprint = hash('sha256', json_encode([$data, $quantities, $quotedTotal, $customerId]));
        $existing = Order::where('idempotency_key', $key)->first();
        if ($existing) {
            return $this->replay($existing, $owner, $fingerprint);
        }
        try {
            return DB::transaction(function () use ($data, $quantities, $key, $owner, $fingerprint, $quotedTotal, $customerId) {
                $lines = $this->cart->lines($quantities, true, true);
                $zone = null;
                if ($data['fulfillment_type'] === 'delivery') {
                    $zone = DeliveryZone::whereKey($data['delivery_zone_id'])->where('is_active', true)->where('service_city', DeliveryZone::SERVICE_CITY)->lockForUpdate()->first();
                    if (! $zone) {
                        throw ValidationException::withMessages(['delivery_zone_id' => __('Địa chỉ nằm ngoài vùng đang phục vụ.')]);
                    }
                }
                $subtotal = (int) $lines->sum('line_total');
                $fee = $zone?->fee ?? 0;
                RestaurantSetting::current()->assertCanOrder($subtotal);
                if ($subtotal + $fee !== $quotedTotal) {
                    throw ValidationException::withMessages(['cart' => __('Giá món hoặc phí giao hàng đã thay đổi. Vui lòng quay lại giỏ hàng và xác nhận mức giá mới.')]);
                }
                $order = Order::create([
                    'is_demo' => ($data['payment_method'] ?? 'cash') === 'demo_card', 'user_id' => $customerId, 'payment_method' => $data['payment_method'] ?? 'cash', 'payment_status' => 'unpaid', 'locale' => app()->getLocale(), 'customer_email' => $data['customer_email'] ?? null,
                    'code' => (string) Str::uuid(), 'idempotency_key' => $key, 'checkout_owner' => $owner, 'request_fingerprint' => $fingerprint,
                    'customer_name' => $data['customer_name'], 'customer_phone' => $data['customer_phone'] ?? null, 'fulfillment_type' => $data['fulfillment_type'],
                    'delivery_ward' => $zone ? ($data['delivery_ward'] ?? null) : null, 'address' => $zone ? $data['address'] : null, 'delivery_zone_id' => $zone?->id, 'delivery_zone_name' => $zone?->name, 'delivery_zone_name_en' => $zone?->name_en,
                    'note' => $data['note'] ?? null, 'status' => 'pending', 'subtotal' => $subtotal, 'delivery_fee' => $fee, 'total' => $subtotal + $fee,
                ]);
                foreach ($lines as $line) {
                    $order->items()->create(['dish_id' => $line['dish']->id, 'dish_name' => $line['dish']->name, 'dish_name_en' => $line['dish']->name_en, 'unit_price' => $line['dish']->price, 'quantity' => $line['quantity']]);
                }
                $order->histories()->create(['to_status' => 'pending']);

                return $order;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            $existing = Order::where('idempotency_key', $key)->first();
            if (! $existing) {
                throw $e;
            }

            return $this->replay($existing, $owner, $fingerprint);
        }
    }

    private function replay(Order $order, string $owner, string $fingerprint): Order
    {
        abort_unless(hash_equals((string) $order->checkout_owner, $owner), 403);
        if (! hash_equals((string) $order->request_fingerprint, $fingerprint)) {
            throw ValidationException::withMessages(['order' => __('Yêu cầu đã được sử dụng. Vui lòng tạo lượt đặt mới.')]);
        }

        return $order;
    }
}
