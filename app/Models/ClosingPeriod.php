<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClosingPeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'year',
        'month',
        'auditor_name',
        'in_charge_name',
        'is_locked',
        'closed_at',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'is_locked' => 'boolean',
        'closed_at' => 'datetime',
    ];

    public function balances()
    {
        return $this->hasMany(PeriodBalance::class, 'closing_period_id');
    }
}
