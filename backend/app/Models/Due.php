<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Due extends Model
{
    use HasFactory, LogsActivity;

    public const STATUSES = ['pending', 'partial', 'paid', 'overdue', 'waived'];

    protected $fillable = [
        'member_id',
        'year',
        'amount_due',
        'amount_paid',
        'balance',
        'status',
        'due_date',
        'paid_at',
        'waived_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount_due' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance' => 'decimal:2',
            'due_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    // Every subscription payment applied toward this due — see
    // Subscription::due_id. Only 'verified' payments count toward
    // amount_paid (see DuesService::recalculate).
    public function payments()
    {
        return $this->hasMany(Subscription::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }
}
