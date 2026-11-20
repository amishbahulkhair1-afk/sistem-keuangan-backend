<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'initial_balance',
        'current_balance',
    ];

    protected $casts = [
        'initial_balance' => 'float',
        'current_balance' => 'float',
    ];

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'account_id');
    }
}
