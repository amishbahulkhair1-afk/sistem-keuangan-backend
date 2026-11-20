<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgramCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'qris_unit_digit',
        'category_type',
        'pic_name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'assigned_category_id');
    }

    public function materials()
    {
        return $this->hasMany(MaterialItem::class, 'category_id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'category_id');
    }

    public function posTransactions()
    {
        return $this->hasMany(PosTransaction::class, 'category_id');
    }

    public function debtTransactions()
    {
        return $this->hasMany(DebtTransaction::class, 'category_id');
    }
}
