<?php

/**
 * Client model - top of the ownership chain (Client -> Campaign -> Data).
 *
 * @author Chris Delport <chrisd@chrisd.co.za>
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory;

    /** Only name is user-writable; ids/timestamps are framework-managed. */
    protected $fillable = ['name'];

    /** A client owns many campaigns; deleting it cascades (FK level). */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }
}
