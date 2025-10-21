<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reading extends Model
{
    use HasFactory;

    protected $table = 'readings';

    protected $fillable = [
    'zone',
    'account_no',
    'previous_reading',
    'present_reading',
    'consumption',
    'reader_name',
    'isReRead',
    'reread_reference_no',
    'created_at',
    'updated_at',
    ];


    public function concessionaire()
    {
        return $this->belongsTo(ConcessionerAccount::class, 'account_no', 'account_no');
    }

    public function bill()
    {
        return $this->hasOne(Bill::class, 'reading_id', 'id');
    }
    public function sc_discount()
    {
        return $this->belongsTo(SCDiscount::class);
    }

    public function propertyType() {
        return $this->belongsTo(PropertyTypes::class, 'property_type_id');
    }

}
