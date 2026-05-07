<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class District extends Model
{
    protected $fillable = ['region_id', 'name', 'lat', 'long'];

    /**
     * Bu tuman tegishli bo'lgan viloyat.
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
