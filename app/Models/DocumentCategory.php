<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug'];

    public function documents()
    {
        return $this->hasMany(EmployeeDocument::class, 'category_id');
    }
}
