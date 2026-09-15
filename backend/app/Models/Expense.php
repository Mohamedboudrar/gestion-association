<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Expense extends Model
{
    use HasFactory, LogsActivity;

    // The only statuses that represent money actually committed against a
    // project's funds. Rejected/pending/draft expenses never reduce
    // remaining/available funds — approved and paid both do, since paid is
    // just the post-approval settlement of the same spend.
    public const FINANCIALLY_COUNTED_STATUSES = ['approved', 'paid'];

    protected $fillable = [
        'project_id',
        'supplier_name',
        'description',
        'amount',
        'payment_method',
        'invoice_number',
        'invoice_path',
        'expense_date',
        'notes',
        'created_by',
        'status',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'rejection_type',
        'paid_by',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    // Single source of truth for "does this expense count against project
    // funds" — every financial sum (project cards, available funds, reports,
    // dashboard, validation) must query through this rather than sum('amount')
    // over all expenses.
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
