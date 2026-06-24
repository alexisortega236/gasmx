<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('fuel:import-prices --all --confirm-full')
    ->dailyAt('18:15')
    ->timezone('America/Mexico_City')
    ->withoutOverlapping();
