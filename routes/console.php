<?php

use App\Jobs\AmortissementMensuelJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nettoyage des logs toutes les 3 minutes
Schedule::call(function () {
    $logFile = storage_path('logs/laravel.log');
    $workerLogFile = storage_path('logs/worker-schedule.log');

    if (file_exists($logFile)) {
        file_put_contents($logFile, '');
    }

    if (file_exists($workerLogFile)) {
        file_put_contents($workerLogFile, '');
    }
})->everyThreeMinutes()->name('nettoyage-logs');

// Amortissement mensuel
Schedule::job(new AmortissementMensuelJob())
    ->monthlyOn(1, '00:30')
    ->name('amortissement-mensuel')
    ->withoutOverlapping()
    ->onOneServer();