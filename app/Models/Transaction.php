<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_date',
        'type',
        'account_id',
        'category_id',
        'donor_id',
        'donor_name_raw',
        'amount',
        'description',
        'receipt_photo_path',
        'qris_reff_id',
        'qris_bill_no',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'float',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function category()
    {
        return $this->belongsTo(ProgramCategory::class, 'category_id');
    }

    public function donor()
    {
        return $this->belongsTo(Donor::class, 'donor_id');
    }
}
