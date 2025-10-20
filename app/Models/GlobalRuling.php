<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlobalRuling extends Model
{
    protected $table = 'global_ruling';

    protected $fillable = [
        'due_date',
        'disconnection_date',
        'disconnection_rule',
        'snr_dc_rule',
        'created_at',
        'updated_at',
    ];
}
