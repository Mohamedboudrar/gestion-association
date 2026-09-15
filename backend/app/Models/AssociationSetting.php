<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssociationSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'association_name',
        'association_logo',
        'address',
        'phone',
        'email',
        'website',
        'annual_subscription_amount',
        'currency',
        'description',
    ];

    protected $casts = [
        'annual_subscription_amount' => 'decimal:2',
    ];
}
