<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PeriodBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'closing_period_id',
        'category_id',
        'starting_balance',
        'total_income',
        'total_expense',
        'ending_balance',
    ];

    protected $casts = [
        'starting_balance' => 'float',
        'total_income' => 'float',
        'total_expense' => 'float',
        'ending_balance' => 'float',
    ];

    public function closingPeriod()
    {
        return $this->belongsTo(ClosingPeriod::class, 'closing_period_id');
    }

    public function category()
    {
        return $this->belongsTo(ProgramCategory::class, 'category_id');
    }
}
