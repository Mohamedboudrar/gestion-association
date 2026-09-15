<?php

namespace App\Exports;

use App\Models\Member;
use Maatwebsite\Excel\Concerns\FromCollection;
use Illuminate\Support\Collection;

class MembersExport implements FromCollection
{
    public function collection(): Collection
    {
        return Member::with('user')
            ->get()
            ->map(function ($member) {

                return [
                    'ID' => $member->id,
                    'Name' => $member->user->name,
                    'Email' => $member->user->email,
                    'Phone' => $member->phone,
                    'Address' => $member->address,
                ];

            });
    }
}