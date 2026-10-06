<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Dish;
use App\Models\Order;
use App\Models\RestaurantSetting;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

#[Signature('restaurant:benchmark {--database= : Empty MySQL database named restaurant_benchmark_*} {--reuse : Reuse only this generated benchmark dataset} {--requests=500} {--concurrency=50}')]
#[Description('Seed an isolated database and measure concurrent read requests; never modifies the restaurant database')]
class BenchmarkRestaurant extends Command
{
    public function handle(): int
    {
        $database = (string) $this->option('database');
        if (app()->configurationIsCached() || ! preg_match('/^restaurant_benchmark_[a-z0-9_]+$/D', $database) || $database === config('database.connections.mysql.database')) {
            $this->error('Use a separate, empty restaurant_benchmark_* database.');

            return self::FAILURE;
        }
        if (! extension_loaded('curl')) {
            $this->error('PHP curl is required.');

            return self::FAILURE;
        }
        $count = (int) $this->option('requests');
        $concurrency = (int) $this->option('concurrency');
        if ($count < 50 || $count > 5000 || $concurrency < 1 || $concurrency > 50) {
            $this->error('Use 50–5000 requests and 1–50 concurrent clients.');

            return self::FAILURE;
        }
        config(['database.default' => 'mysql', 'database.connections.mysql.database' => $database, 'database.connections.mysql.url' => null]);
        DB::purge('mysql');
        Schema::clearResolvedInstance('db.schema');
        $populated = count(Schema::connection('mysql')->getTables($database)) > 0;
        if ($populated && ! $this->option('reuse')) {
            $this->error('Refusing to seed a non-empty benchmark database.');

            return self::FAILURE;
        }
        if ($populated && (Order::count() !== 10000 || Order::where('customer_email', '!=', 'benchmark@example.test')->exists() || Dish::count() !== 100)) {
            $this->error('Unexpected dataset; refusing to modify it.');

            return self::FAILURE;
        }
        if (! $populated) {
            $this->call('migrate', ['--force' => true, '--no-interaction' => true]);
            $this->info('Preparing 100 dishes and 10,000 orders in '.$database);
            Order::withoutEvents(function () {
                $category = Category::create(['name' => 'Món kiểm thử', 'name_en' => 'Benchmark food', 'slug' => 'benchmark']);
                Dish::withoutEvents(fn () => Dish::factory()->count(100)->create(['category_id' => $category->id, 'name_en' => 'Benchmark dish']));
                RestaurantSetting::current();
                $now = now();
                for ($batch = 0; $batch < 20; $batch++) {
                    $orders = [];
                    $items = [];
                    for ($i = 1; $i <= 500; $i++) {
                        $id = $batch * 500 + $i;
                        $orders[] = ['id' => $id, 'code' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Benchmark '.$id, 'customer_email' => 'benchmark@example.test', 'fulfillment_type' => 'pickup', 'status' => $id % 10 === 0 ? 'confirmed' : 'completed', 'payment_method' => 'cash', 'payment_status' => $id % 10 === 0 ? 'unpaid' : 'paid', 'subtotal' => 65000, 'delivery_fee' => 0, 'total' => 65000, 'is_demo' => true, 'locale' => 'vi', 'paid_at' => $id % 10 === 0 ? null : $now, 'completed_at' => $id % 10 === 0 ? null : $now, 'created_at' => $now, 'updated_at' => $now];
                        $items[] = ['order_id' => $id, 'dish_id' => ($id % 100) + 1, 'dish_name' => 'Món kiểm thử', 'dish_name_en' => 'Benchmark dish', 'unit_price' => 65000, 'quantity' => 1, 'preparation_status' => $id % 10 === 0 ? 'pending' : 'served', 'created_at' => $now, 'updated_at' => $now];
                    }
                    DB::table('orders')->insert($orders);
                    DB::table('order_items')->insert($items);
                }
            });
        }

        $cookies = [];
        $cookieName = config('session.cookie');
        for ($i = 0; $i < $concurrency; $i++) {
            $owner = User::where('email', 'benchmark-owner-'.$i.'@example.test')->first() ?? User::factory()->create(['role' => 'admin', 'email' => 'benchmark-owner-'.$i.'@example.test']);
            $id = Str::random(40);
            $payload = [auth()->guard()->getName() => $owner->id, '_token' => Str::random(40)];
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $owner->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Restaurant benchmark', 'payload' => base64_encode(config('session.serialization') === 'json' ? json_encode($payload) : serialize($payload)), 'last_activity' => time()]);
            $cookies[] = $cookieName.'='.rawurlencode(app('encrypter')->encrypt(CookieValuePrefix::create($cookieName, app('encrypter')->getKey()).$id, false));
        }
        $server = new Process([PHP_BINARY, '-d', 'opcache.enable_cli=1', '-S', '127.0.0.1:9001', '-t', public_path(), public_path('index.php')], base_path(), ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database, 'DB_URL' => '', 'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:9001', 'SESSION_DRIVER' => 'database', 'SESSION_SECURE_COOKIE' => 'false', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'PHP_CLI_SERVER_WORKERS' => '8']);
        $server->setTimeout(null);
        $server->disableOutput();
        $server->start();
        try {
            $ready = false;
            for ($i = 0; $i < 50; $i++) {
                $socket = @fsockopen('127.0.0.1', 9001, $errno, $error, 0.1);
                if ($socket) {
                    fclose($socket);
                    $ready = true;
                    break;
                }usleep(100000);
            }
            if (! $ready || ! $server->isRunning()) {
                throw new \RuntimeException('Benchmark server did not start on port 9001.');
            }
            $paths = ['/', '/?q=benchmark', '/nhan-vien/don-hang', '/quan-ly/doanh-thu', '/nhan-vien/thong-bao-don'];
            foreach ($paths as $path) {
                $h = curl_init('http://127.0.0.1:9001'.$path);
                curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => $cookies[0], CURLOPT_TIMEOUT => 30]);
                curl_exec($h);
                if (curl_getinfo($h, CURLINFO_HTTP_CODE) !== 200) {
                    throw new \RuntimeException('Warm-up failed: '.$path.' HTTP '.curl_getinfo($h, CURLINFO_HTTP_CODE));
                }curl_close($h);
            }
            $multi = curl_multi_init();
            $handles = [];
            $results = [];
            $next = 0;
            $started = microtime(true);
            $enqueue = function (int $number) use ($multi, &$handles, $cookies, $paths, $concurrency) {
                $path = $paths[$number % count($paths)];
                $h = curl_init('http://127.0.0.1:9001'.$path);
                curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => $cookies[$number % $concurrency], CURLOPT_HTTPHEADER => ['Accept: text/html,application/json']]);
                $handles[spl_object_id($h)] = ['path' => $path, 'start' => microtime(true)];
                curl_multi_add_handle($multi, $h);
            };
            while ($next < min($count, $concurrency)) {
                $enqueue($next++);
            }
            do {
                curl_multi_exec($multi, $running);
                while ($info = curl_multi_info_read($multi)) {
                    $h = $info['handle'];
                    $meta = $handles[spl_object_id($h)];
                    $results[] = ['path' => $meta['path'], 'ms' => round((microtime(true) - $meta['start']) * 1000, 2), 'status' => curl_getinfo($h, CURLINFO_HTTP_CODE), 'error' => $info['result']];
                    unset($handles[spl_object_id($h)]);
                    curl_multi_remove_handle($multi, $h);
                    curl_close($h);
                    if ($next < $count) {
                        $enqueue($next++);
                    }
                }
                if ($handles) {
                    curl_multi_select($multi, 0.2);
                }
            } while ($handles);
            curl_multi_close($multi);
            $times = array_column($results, 'ms');
            sort($times);
            $p95 = $times[(int) ceil(count($times) * 0.95) - 1];
            $errors = count(array_filter($results, fn ($r) => $r['status'] !== 200 || $r['error'] !== 0));
            $report = ['created_at' => now()->toIso8601String(), 'database' => $database, 'database_engine' => 'MySQL', 'php' => PHP_VERSION, 'server' => 'PHP development server, 8 workers, Docker local', 'dataset' => ['dishes' => 100, 'orders' => 10000, 'active_orders' => 1000], 'concurrency' => $concurrency, 'requests' => $count, 'duration_seconds' => round(microtime(true) - $started, 2), 'p95_ms' => $p95, 'p50_ms' => $times[(int) floor(count($times) * 0.5)], 'max_ms' => max($times), 'errors' => $errors, 'passed' => $p95 < 2000 && $errors === 0, 'scope' => 'Read-only HTTP requests; excludes payment/write throughput, internet latency and browser assets.', 'routes' => []];
            foreach ($paths as $path) {
                $set = array_column(array_filter($results, fn ($r) => $r['path'] === $path), 'ms');
                sort($set);
                $report['routes'][$path] = ['requests' => count($set), 'p95_ms' => $set[(int) ceil(count($set) * 0.95) - 1]];
            }
            $file = storage_path('app/private/benchmark-'.now()->format('Ymd-His').'.json');
            file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->line(json_encode($report));
            $this->info($file);

            return $report['passed'] ? self::SUCCESS : self::FAILURE;
        } finally {
            $server->stop(3);
        }
    }
}
