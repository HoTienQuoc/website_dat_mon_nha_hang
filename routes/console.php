<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('restaurant:backup')
    ->dailyAt('02:00')->timezone('Asia/Ho_Chi_Minh')->withoutOverlapping(10)
    ->appendOutputTo(storage_path('logs/backup.log'));
