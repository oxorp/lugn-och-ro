<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Monthly POI refresh pipeline
Schedule::command('ingest:pois --source=osm --all')->monthly();
Schedule::command('assign:poi-deso')->monthly();
Schedule::command('aggregate:poi-indicators')->monthly();
