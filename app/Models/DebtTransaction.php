<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DebtTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'transaction_date',
        'type',
        'party_name',
        'amount',
        'description',
        'receipt_photo_path',
        'source_pos_tx_id',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'float',
    ];

    public function category()
    {
        return $this->belongsTo(ProgramCategory::class, 'category_id');
    }

    public function sourcePosTx()
    {
        return $this->belongsTo(PosTransaction::class, 'source_pos_tx_id');
    }
}
