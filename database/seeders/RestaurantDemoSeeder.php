<?php

namespace Database\Seeders;

use App\Models\DeliveryZone;
use App\Models\Dish;
use App\Models\Order;
use App\Models\RestaurantSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class RestaurantDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        RestaurantSetting::firstOrCreate(['id' => 1], ['name' => 'Bếp Nhà', 'name_en' => 'Bep Nha Kitchen', 'address' => '12 Đường Mẫu, Nha Trang (địa chỉ minh họa)', 'address_en' => '12 Sample Street, Nha Trang (demo address)', 'phone' => '0900000000', 'email' => 'hello@bepnha.test', 'opens_at' => '09:00', 'closes_at' => '22:00', 'accepting_orders' => true, 'enforce_hours' => false, 'minimum_order' => 25000, 'preparation_minutes' => 25]);
        $this->call(DeliveryZoneSeeder::class);
        $dishes = Dish::orderBy('id')->get();
        $zones = DeliveryZone::where('service_city', DeliveryZone::SERVICE_CITY)->where('is_active', true)->orderBy('id')->get();
        $cashier = User::where('role', 'cashier')->first();
        $kitchen = User::where('role', 'head_chef')->first();
        for ($i = 0; $i < 48; $i++) {
            $key = 'restaurant-demo-v1-'.$i;
            if (Order::where('demo_key', $key)->exists()) {
                continue;
            }
            DB::transaction(function () use ($i, $key, $dishes, $zones, $cashier, $kitchen) {
                $status = $i < 36 ? 'completed' : (['pending', 'confirmed', 'preparing', 'ready', 'cancelled'][$i % 5]);
                $created = $i < 36 ? now()->subDays(1 + ($i % 14))->setTime(11 + ($i % 8), ($i * 7) % 60) : now()->subMinutes(5 + ($i % 12) * 3);
                $first = $dishes[$i % $dishes->count()];
                $second = $dishes[($i + 5) % $dishes->count()];
                $quantity = 1 + ($i % 3);
                $delivery = $i % 3 === 0;
                $zone = $delivery ? $zones[$i % $zones->count()] : null;
                $subtotal = $first->price * $quantity + $second->price;
                $order = Order::create(['demo_key' => $key, 'is_demo' => true, 'code' => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, $key), 'idempotency_key' => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, $key.'-request'), 'customer_name' => ['Khách mẫu An', 'Demo customer Alex', 'Khách mẫu Bình', 'Demo customer Jamie', 'Khách mẫu Chi', 'Demo customer Taylor'][$i % 6].' '.($i + 1), 'customer_phone' => '090000'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT), 'customer_email' => 'demo'.($i + 1).'@example.test', 'fulfillment_type' => $delivery ? 'delivery' : 'pickup', 'address' => $delivery ? 'Số '.($i + 1).' Đường Mẫu, Nha Trang (dữ liệu minh họa)' : null, 'delivery_ward' => $delivery ? 'Nha Trang' : null, 'address_verified_at' => $delivery && ! in_array($status, ['pending', 'cancelled']) ? $created->copy()->addMinutes(1) : null, 'address_verified_by' => $delivery && ! in_array($status, ['pending', 'cancelled']) ? $cashier?->id : null, 'delivery_zone_id' => $zone?->id, 'delivery_zone_name' => $zone?->name, 'delivery_zone_name_en' => $zone?->name_en, 'status' => $status, 'subtotal' => $subtotal, 'delivery_fee' => $zone?->fee ?? 0, 'total' => $subtotal + ($zone?->fee ?? 0), 'note' => $i % 2 === 0 ? 'Đơn mẫu để kiểm tra vận hành. Không giao thực tế.' : 'Demo order for workflow testing. Do not fulfil.', 'payment_method' => 'cash', 'payment_status' => $status === 'completed' ? 'paid' : 'unpaid', 'paid_at' => $status === 'completed' ? $created->copy()->addMinutes(35) : null, 'completed_at' => $status === 'completed' ? $created->copy()->addMinutes(35) : null, 'locale' => $i % 2 === 0 ? 'vi' : 'en', 'cancellation_reason' => $status === 'cancelled' ? 'Khách mẫu yêu cầu hủy trước khi bếp chuẩn bị.' : null]);
                $order->created_at = $created;
                $order->updated_at = $created->copy()->addMinutes(35);
                $order->save();
                foreach ([[$first, $quantity], [$second, 1]] as [$dish,$qty]) {
                    $order->items()->create(['dish_id' => $dish->id, 'dish_name' => $dish->name, 'dish_name_en' => $dish->name_en, 'unit_price' => $dish->price, 'quantity' => $qty, 'preparation_status' => match ($status) {
                        'completed' => 'served', 'ready' => 'ready', 'preparing' => 'preparing', 'cancelled' => 'cancelled', default => 'pending'
                    }]);
                }
                $sequence = match ($status) {
                    'completed' => $delivery ? ['pending', 'confirmed', 'preparing', 'ready', 'delivering', 'delivered', 'completed'] : ['pending', 'confirmed', 'preparing', 'ready', 'completed'],'cancelled' => ['pending', 'cancelled'],'ready' => ['pending', 'confirmed', 'preparing', 'ready'],'preparing' => ['pending', 'confirmed', 'preparing'],'confirmed' => ['pending', 'confirmed'],default => ['pending']
                };
                foreach ($sequence as $step => $state) {
                    $history = $order->histories()->create(['from_status' => $sequence[$step - 1] ?? null, 'to_status' => $state, 'user_id' => $state === 'pending' ? null : (in_array($state, ['preparing', 'ready']) ? $kitchen?->id : $cashier?->id), 'reason' => $state === 'cancelled' ? $order->cancellation_reason : null]);
                    $history->created_at = $created->copy()->addMinutes($step * 5);
                    $history->updated_at = $history->created_at;
                    $history->save();
                }
            });
        }
    }
}
