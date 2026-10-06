<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Dish;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Services\LocalHttpProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class VerifyLocalConcurrency extends Command
{
    protected $signature = 'restaurant:verify-local {--database= : A new empty restaurant_acceptance_* MySQL database}';

    protected $description = 'Exercise concurrent local writes and race protection in an isolated database';

    private array $report = [];

    public function handle(LocalHttpProbe $http): int
    {
        $database = (string) $this->option('database');
        if (! app()->environment('local') || app()->configurationIsCached() || ! preg_match('/^restaurant_acceptance_[a-z0-9_]+$/D', $database) || $database === config('database.connections.mysql.database')) {
            $this->error('Use local mode, uncached configuration and a separate empty restaurant_acceptance_* database.');

            return self::FAILURE;
        }
        if (! extension_loaded('curl')) {
            $this->error('PHP curl is required.');

            return self::FAILURE;
        }
        $socket = @fsockopen('127.0.0.1', 9002, $errno, $error, 0.2);
        if ($socket) {
            fclose($socket);
            $this->error('Port 9002 is already in use.');

            return self::FAILURE;
        }
        config(['database.default' => 'mysql', 'database.connections.mysql.database' => $database, 'database.connections.mysql.url' => null]);
        DB::purge('mysql');
        Schema::clearResolvedInstance('db.schema');
        if (Schema::connection('mysql')->getTables($database)) {
            $this->error('Refusing to modify a non-empty database.');

            return self::FAILURE;
        }
        $this->call('migrate', ['--force' => true, '--no-interaction' => true]);
        $category = Category::create(['name' => 'Kiểm thử đồng thời', 'name_en' => 'Concurrency tests', 'slug' => 'concurrency']);
        $dishes = Dish::factory()->count(50)->create(['category_id' => $category->id, 'price' => 65000]);
        $customers = User::factory()->count(50)->create(['role' => 'customer']);
        $staff = User::factory()->count(20)->create(['role' => 'admin']);
        $sessions = [];
        foreach ($customers as $i => $customer) {
            $sessions[] = $http->session($customer, ['cart' => [$dishes[$i]->id => 1]]);
        }
        $staffSessions = $staff->map(fn ($user) => $http->session($user))->all();
        $server = new Process([PHP_BINARY, '-d', 'opcache.enable_cli=1', '-S', '127.0.0.1:9002', '-t', public_path(), public_path('index.php')], base_path(), ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database, 'DB_URL' => '', 'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:9002', 'SESSION_DRIVER' => 'database', 'SESSION_ENCRYPT' => 'false', 'SESSION_SECURE_COOKIE' => 'false', 'CACHE_STORE' => 'database', 'MAIL_MAILER' => 'array', 'PHP_CLI_SERVER_WORKERS' => '8']);
        $server->setTimeout(null);
        $server->disableOutput();
        $this->report = ['created_at' => now()->toIso8601String(), 'database' => $database, 'engine' => 'MySQL', 'server' => 'Sail local, PHP 8.5, 8 workers, OPcache', 'csrf' => 'enabled', 'session' => 'database, separate session per actor', 'cache' => 'database with real locks and rate limits', 'max_concurrency' => 50, 'phases' => [], 'checks' => [], 'passed' => false];
        $server->start();
        try {
            $ready = false;
            for ($i = 0; $i < 50; $i++) {
                $socket = @fsockopen('127.0.0.1', 9002, $errno, $error, 0.1);
                if ($socket) {
                    fclose($socket);
                    $ready = true;
                    break;
                }usleep(100000);
            }
            $this->verify($ready && $server->isRunning(), 'isolated_server_started');
            $requests = [];
            foreach ($customers as $i => $customer) {
                $requests[] = ['path' => '/dat-mon/xac-nhan', 'session' => $sessions[$i], 'data' => ['customer_name' => $customer->name, 'customer_email' => $customer->email, 'fulfillment_type' => 'pickup', 'payment_method' => 'demo_card']];
            }
            $reviews = $this->phase('50_checkout_reviews', $http->batch($requests), [302 => 50]);
            $storeRequests = [];
            foreach ($reviews as $i => $response) {
                $key = basename(parse_url($response['location'], PHP_URL_PATH));
                $storeRequests[] = ['path' => '/don-hang', 'session' => $sessions[$i], 'data' => ['idempotency_key' => $key]];
            }
            $clones = [];
            for ($i = 0; $i < 10; $i++) {
                $clones[] = [...$storeRequests[0], 'session' => $http->session($customers[0], $http->attributes($sessions[0]))];
            }
            $this->phase('10_simultaneous_submissions_same_draft', $http->batch($clones), [302 => 10]);
            $this->verify(Order::count() === 1, 'one_order_for_duplicate_draft');
            $this->phase('50_customers_submit_orders', $http->batch($storeRequests), [302 => 50]);
            $this->verify(Order::count() === 50 && (int) Order::sum('total') === 3250000, '50_orders_exact_total');
            $orders = Order::orderBy('id')->get()->keyBy('user_id');
            $card = ['cardholder' => 'LOCAL TEST', 'card_number' => '4242424242424242', 'expiry' => now()->addYear()->format('m/y'), 'cvc' => '123', 'email' => 'local-test@example.test', 'billing_address' => '12 Duong Mau Nha Trang', 'country' => 'VN', 'postal_code' => '65000'];
            $payments = [];
            foreach ($customers as $i => $customer) {
                $payments[] = ['path' => '/don-hang/'.$orders[$customer->id]->code.'/thanh-toan-demo', 'session' => $sessions[$i], 'data' => $card];
            }
            $duplicates = [];
            for ($i = 0; $i < 6; $i++) {
                $duplicates[] = [...$payments[0], 'session' => $http->session($customers[0])];
            }
            $this->phase('6_simultaneous_payments_same_order', $http->batch($duplicates), [302 => 6]);
            $this->verify(Payment::count() === 1, 'one_payment_for_duplicate_submissions');
            $this->phase('50_customers_pay_demo_cards', $http->batch($payments), [302 => 50]);
            $this->verify(Payment::count() === 50 && (int) Payment::sum('amount') === 3250000 && Order::where('payment_status', 'paid')->count() === 50, '50_payments_exact_total');
            $cash = Order::factory()->create(['status' => 'confirmed', 'fulfillment_type' => 'dine_in']);
            $item = $cash->items()->create(['dish_name' => 'Race item', 'unit_price' => 65000, 'quantity' => 1, 'preparation_status' => 'pending']);
            $edits = [];
            foreach ($staffSessions as $session) {
                $edits[] = ['path' => '/nhan-vien/mon-trong-don/'.$item->id.'/dieu-chinh', 'method' => 'PATCH', 'session' => $session, 'data' => ['quantity' => 2, 'expected_quantity' => 1, 'expected_status' => 'pending', 'reason' => 'Local concurrent edit']];
            }
            $this->phase('20_staff_edit_same_item', $http->batch($edits), [302 => 1, 422 => 19]);
            $this->verify($item->fresh()->quantity === 2 && $cash->fresh()->total === 130000, 'one_item_edit_no_lost_update');
            $transitions = [];
            foreach ($staffSessions as $session) {
                $transitions[] = ['path' => '/nhan-vien/don-hang/'.$cash->id, 'method' => 'PATCH', 'session' => $session, 'data' => ['status' => 'preparing', 'expected_status' => 'confirmed']];
            }
            $this->phase('20_staff_transition_same_order', $http->batch($transitions), [302 => 1, 422 => 19]);
            $this->verify($cash->histories()->where('from_status', 'confirmed')->where('to_status', 'preparing')->count() === 1, 'one_status_transition');
            $table = RestaurantTable::create(['name' => 'RACE-TABLE', 'capacity' => 4, 'is_active' => true]);
            $pos = [];
            foreach ($staffSessions as $session) {
                $pos[] = ['path' => '/nhan-vien/tao-don', 'session' => $session, 'data' => ['restaurant_table_id' => $table->id, 'request_key' => (string) Str::uuid(), 'quantities' => [$dishes[0]->id => 1]]];
            }
            $this->phase('20_staff_claim_same_table', $http->batch($pos), [302 => 1, 422 => 19]);
            $this->verify($table->activeOrders()->count() === 1, 'one_active_order_per_table');
            $this->phase('csrf_rejected_without_token', $http->batch([['path' => '/ngon-ngu', 'session' => $sessions[0], 'data' => ['locale' => 'en'], 'omit_csrf' => true]]), [419 => 1]);
            $this->phase('customer_cannot_open_staff_orders', $http->batch([['path' => '/nhan-vien/don-hang', 'method' => 'GET', 'session' => $sessions[0]]]), [403 => 1]);
            $this->phase('cross_customer_order_hidden', $http->batch([['path' => '/don-hang/'.$orders[$customers[1]->id]->code, 'method' => 'GET', 'session' => $sessions[0]]]), [404 => 1]);
            $payload = DB::table('sessions')->pluck('payload')->map(fn ($v) => base64_decode($v))->implode('');
            $this->verify(! str_contains($payload, '4242424242424242') && ! str_contains($payload, '"cvc"'), 'card_data_not_saved_to_session');
            $this->report['passed'] = true;
        } catch (\Throwable $exception) {
            $this->report['failure'] = $exception->getMessage();
            $this->error($exception->getMessage());
        } finally {
            $server->stop(3);
            File::ensureDirectoryExists(storage_path('app/private'));
            $path = storage_path('app/private/local-concurrency-'.now()->format('Ymd-His').'.json');
            file_put_contents($path, json_encode($this->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info($path);
        }

        return $this->report['passed'] ? self::SUCCESS : self::FAILURE;
    }

    private function phase(string $name, array $responses, array $expected): array
    {
        $statuses = array_count_values(array_column($responses, 'status'));
        ksort($statuses);
        ksort($expected);
        $times = array_column($responses, 'ms');
        sort($times);
        $passed = $statuses === $expected && ! array_filter($responses, fn ($r) => $r['error'] !== 0);
        $this->report['phases'][$name] = ['requests' => count($responses), 'statuses' => $statuses, 'expected' => $expected, 'p95_ms' => $times[(int) ceil(count($times) * 0.95) - 1], 'passed' => $passed];
        $this->line($name.': '.json_encode($statuses));
        $this->verify($passed, $name);

        return $responses;
    }

    private function verify(bool $condition, string $name): void
    {
        $this->report['checks'][$name] = $condition;
        if (! $condition) {
            throw new \RuntimeException('Failed: '.$name);
        }
    }
}
