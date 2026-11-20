<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomBalanceSheetItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'period',
        'side',
        'sub_category',
        'code',
        'name',
        'amount_prev_month',
        'amount_current_month',
        'amount_total',
        'is_auto',
    ];

    protected $casts = [
        'amount_prev_month' => 'float',
        'amount_current_month' => 'float',
        'amount_total' => 'float',
        'is_auto' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(ProgramCategory::class, 'category_id');
    }
}
