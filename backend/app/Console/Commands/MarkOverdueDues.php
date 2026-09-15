<?php

namespace App\Console\Commands;

use App\Services\DuesService;
use Illuminate\Console\Command;

class MarkOverdueDues extends Command
{
    protected $signature = 'app:mark-overdue-dues';

    protected $description = 'Mark annual dues as overdue once their due date has passed with an outstanding balance, and notify the member and treasury roles.';

    public function handle(DuesService $duesService): int
    {
        $count = $duesService->markOverdue();

        $this->info("{$count} due(s) marked overdue.");

        return self::SUCCESS;
    }
}
