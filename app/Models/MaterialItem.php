<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaterialItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'code',
        'name',
        'unit',
        'unit_price',
        'initial_stock',
        'current_stock',
    ];

    protected $casts = [
        'unit_price' => 'float',
        'initial_stock' => 'float',
        'current_stock' => 'float',
    ];

    public function category()
    {
        return $this->belongsTo(ProgramCategory::class, 'category_id');
    }

    public function transactions()
    {
        return $this->hasMany(MaterialTransaction::class, 'material_id');
    }
}
