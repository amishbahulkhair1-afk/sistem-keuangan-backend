<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DonationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_date',
        'donor_name',
        'item_category',
        'item_name',
        'quantity',
        'unit',
        'estimated_value',
        'description',
        'category_id',
        'photo_path',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'quantity' => 'float',
        'estimated_value' => 'float',
    ];

    public function category()
    {
        return $this->belongsTo(ProgramCategory::class, 'category_id');
    }
}
