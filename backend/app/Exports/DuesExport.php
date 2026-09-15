<?php

namespace App\Exports;

use App\Models\Due;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;

class DuesExport implements FromCollection
{
    public function collection(): Collection
    {
        return Due::with('member.user')->orderByDesc('year')->get()->map(function (Due $due) {
            return [
                'ID' => $due->id,
                'Member' => $due->member->user->name,
                'Year' => $due->year,
                'Amount Due' => $due->amount_due,
                'Amount Paid' => $due->amount_paid,
                'Balance' => $due->balance,
                'Status' => $due->status,
                'Due Date' => $due->due_date?->toDateString(),
                'Paid At' => $due->paid_at,
            ];
        });
    }
}
