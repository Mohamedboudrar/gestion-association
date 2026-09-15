<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Donation extends Model
{
    use HasFactory, LogsActivity;

    // The only status that represents money actually collected for a
    // project. Draft/pending/rejected donations never count toward
    // collected/remaining — only approved does, matching Expense's
    // FINANCIALLY_COUNTED_STATUSES pattern (donations have no "paid"
    // equivalent state, so this is a single-status set).
    public const FINANCIALLY_COUNTED_STATUSES = ['approved'];

    protected $fillable = [
        'member_id',
        'project_id',
        'donor_name',
        'amount',
        'payment_method',
        'receipt_number',
        'receipt_file',
        'donation_date',
        'notes',
        'recorded_by',
        'status',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'rejection_type',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'donation_date' => 'date',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    // Single source of truth for "does this donation count toward collected
    // funds" — every financial sum (project cards, available funds, reports,
    // dashboard, validation) must query through this rather than sum('amount')
    // over all donations.
    public function scopeFinanciallyCounted($query)
    {
        return $query->whereIn('status', self::FINANCIALLY_COUNTED_STATUSES);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }
}
