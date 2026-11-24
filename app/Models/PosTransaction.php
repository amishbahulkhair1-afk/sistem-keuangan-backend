<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'transaction_date',
        'type',
        'category_tx_type',
        'source_transaction_id',
        'source_debt_tx_id',
        'payment_status',
        'amount',
        'recipient_or_sender',
        'description',
        'receipt_photo_path',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'float',
    ];

    public function category()
    {
        return $this->belongsTo(ProgramCategory::class, 'category_id');
    }

    public function sourceTransaction()
    {
        return $this->belongsTo(Transaction::class, 'source_transaction_id');
    }

    public function sourceDebtTransaction()
    {
        return $this->belongsTo(DebtTransaction::class, 'source_debt_tx_id');
    }
}
