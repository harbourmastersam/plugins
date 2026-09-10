<?php

namespace HarbourmasterSam\ServerLifecycle\Models;

use Illuminate\Database\Eloquent\Model;

class LifecycleNotificationDelivery extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['target_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime']; }
}
