<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class CachedNode extends Model
{
    protected $fillable = ['ip','continent_code','country_code2','city','latitude','longitude','isp','organization'];

}
