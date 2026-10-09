<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MoneyRequestType extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'requires_receipt', 'is_active'];

    protected function casts(): array
    {
        return [
            'requires_receipt' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
