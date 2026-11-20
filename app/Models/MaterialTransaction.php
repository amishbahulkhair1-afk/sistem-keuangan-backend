<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaterialTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'material_id',
        'category_id',
        'transaction_date',
        'type',
        'quantity',
        'unit_price',
        'total_price',
        'supplier_or_source',
        'usage_for',
        'photo_path',
        'source_pos_tx_id',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'quantity' => 'float',
        'unit_price' => 'float',
        'total_price' => 'float',
    ];

    public function material()
    {
        return $this->belongsTo(MaterialItem::class, 'material_id');
    }

    public function category()
    {
        return $this->belongsTo(ProgramCategory::class, 'category_id');
    }

    public function sourcePosTx()
    {
        return $this->belongsTo(PosTransaction::class, 'source_pos_tx_id');
    }
}
