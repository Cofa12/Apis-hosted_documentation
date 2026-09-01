<?php

namespace Cofa\ApiDocs\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = ['title', 'body', 'published'];

    protected $casts = ['published' => 'boolean'];
}
