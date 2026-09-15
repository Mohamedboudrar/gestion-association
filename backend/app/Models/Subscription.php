<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Subscription extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'member_id',
        'due_id',
        'amount',
        'payment_method',
        'receipt_number',
        'receipt_file',
        'payment_date',
        'expires_at',
        'status',
        'verified_by',
        'verified_at',
        'notes',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    // The Annual Due this payment applies toward — nullable, since every
    // subscription that predates the Annual Dues system (and any created
    // without an explicit/derivable due) has none. See DuesService.
    public function due()
    {
        return $this->belongsTo(Due::class);
    }

    public function getActivitylogOptions(): LogOptions
{
    return LogOptions::defaults()
        ->logFillable()
        ->logOnlyDirty();
}
}
