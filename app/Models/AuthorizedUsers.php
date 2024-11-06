<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuthorizedUsers extends Model
{
    use HasFactory;

    protected $table = 'authorized_users';

    protected $fillable = [
        'id',
        'name',
        'email',
        'status',
    ];
}
