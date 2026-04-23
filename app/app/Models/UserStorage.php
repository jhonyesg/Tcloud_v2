<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserStorage extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'storage_provider_id', 'permissions', 'can_create_shares'];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function storageProvider()
    {
        return $this->belongsTo(StorageProvider::class);
    }
}