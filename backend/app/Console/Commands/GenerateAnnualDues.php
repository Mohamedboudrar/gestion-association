<?php

namespace App\Console\Commands;

use App\Services\DuesService;
use Illuminate\Console\Command;

class GenerateAnnualDues extends Command
{
    protected $signature = 'app:generate-annual-dues {year?}';

    protected $description = 'Generate one annual due per member for the given year (defaults to the current year). Safe to run multiple times — never creates duplicates.';

    public function handle(DuesService $duesService): int
    {
        $year = (int) ($this->argument('year') ?: now()->year);

        $result = $duesService->generateForYear($year);

        $this->info("Annual dues for {$year}: {$result['created']} created, {$result['skipped']} already existed.");

        return self::SUCCESS;
    }
}
