<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A member's permanent gold-unlock of one VIP-zone title. */
class VipUnlock extends Model
{
    protected $fillable = ['user_id', 'content_id', 'price_gold'];
}
