<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'is_paid', 'annual_allowance_days',
        'requires_document', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'requires_document' => 'boolean',
            'is_active' => 'boolean',
            'annual_allowance_days' => 'decimal:2',
        ];
    }
}
