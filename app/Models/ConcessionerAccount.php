<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConcessionerAccount extends Model
{
    use HasFactory;

    protected $table = 'concessioner_accounts';

    protected $fillable = [
        'user_id',
        'zone',
        'account_no',
        'address',
        'property_type',
        'rate_code',
        'status',
        'meter_brand',
        'meter_serial_no',
        'sc_no',
        'date_connected',
        'sequence_no',
        'meter_type',
        'meter_wire',
        'meter_form',
        'meter_class',
        'lat_long',
        'isErcSealed',
        'inspection_image',
    ];

    public function propertyType()
    {
        return $this->belongsTo(PropertyTypes::class, 'property_type_id');
    }


    public function readings()
    {
        return $this->hasMany(Reading::class, 'account_no', 'account_no');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function discounts()
    {
        return $this->hasMany(Discount::class, 'account_no', 'account_no');
    }

    public function statusCode()
    {
        return $this->belongsTo(StatusCode::class, 'status', 'code');
    }


}

?>
