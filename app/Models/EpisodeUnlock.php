<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EpisodeUnlock extends Model
{
    protected $fillable = ['user_id', 'episode_id'];
}
