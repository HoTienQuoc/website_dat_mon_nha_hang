<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class BackupRestaurantDatabase extends Command
{
    protected $signature = 'restaurant:backup';

    protected $description = 'Sao lưu MySQL bằng snapshot nhất quán, lưu ngoài thư mục public';

    public function handle(): int
    {
        if (config('database.default') !== 'mysql') {
            $this->error('Lệnh này dành cho MySQL trong Sail.');

            return self::FAILURE;
        }
        $db = config('database.connections.mysql');
        $directory = storage_path('app/private/backups');
        File::ensureDirectoryExists($directory, 0700);
        chmod($directory, 0700);
        $path = $directory.'/restaurant-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.sql';
        $temporary = $path.'.partial';
        touch($temporary);
        chmod($temporary, 0600);
        try {
            $result = Process::env(['MYSQL_PWD' => $db['password']])->timeout(300)->run([
                'mariadb-dump', '--skip-ssl', '--single-transaction', '--skip-lock-tables', '--no-tablespaces', '--hex-blob',
                '--host='.$db['host'], '--port='.$db['port'], '--user='.$db['username'], '--result-file='.$temporary, $db['database'],
            ]);
            if (! $result->successful() || filesize($temporary) === 0) {
                File::delete($temporary);
                $this->error('Sao lưu thất bại. Kiểm tra kết nối MySQL và công cụ mariadb-dump trong Sail.');

                return self::FAILURE;
            }
            File::move($temporary, $path);
            $this->info('Đã sao lưu: '.str_replace(base_path().'/', '', $path));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            File::delete($temporary);
            $this->error('Không hoàn thành sao lưu. Kiểm tra dung lượng và môi trường Sail.');

            return self::FAILURE;
        }
    }
}
