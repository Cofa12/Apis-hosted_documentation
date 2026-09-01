<?php

namespace Cofa\ApiDocs\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $fillable = ['name', 'email', 'status', 'age', 'is_admin'];

    protected $hidden = ['password'];

    protected $casts = [
        'age' => 'integer',
        'is_admin' => 'boolean',
        'settings' => 'array',
        'verified_at' => 'datetime',
    ];
}
