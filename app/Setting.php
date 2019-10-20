<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'jml_kendaraan_per_kartu', 'masa_aktif_member',
        'location_name', 'location_address', 'additional_info_ticket',
        'default_plate_number', 'must_checkout'
    ];
}
