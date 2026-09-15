<?php

namespace App\Exports;

use App\Models\Subscription;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;

class SubscriptionsExport implements FromCollection
{
    public function collection(): Collection
    {
        return Subscription::with(['member.user','verifier'])
            ->get()
            ->map(function ($subscription){

                return [

                    'ID' => $subscription->id,

                    'Member' => $subscription->member->user->name,

                    'Amount' => $subscription->amount,

                    'Status' => $subscription->status,

                    'Payment Date' => $subscription->payment_date,

                    'Expiration' => $subscription->expires_at,

                    'Verified By' => optional($subscription->verifier)->name,

                ];

            });
    }
}