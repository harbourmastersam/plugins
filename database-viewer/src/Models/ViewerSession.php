<?php

namespace GreyHarbour\DatabaseViewer\Models;

use Illuminate\Database\Eloquent\Model;

class ViewerSession extends Model
{
    protected $table = 'database_viewer_sessions';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'last_activity_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
