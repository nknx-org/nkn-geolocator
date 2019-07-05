<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class CrawledNode extends Model
{
    protected $fillable = ['ip','continent_code','country_code2','city','latitude','longitude','isp','organization','pk','version'];
    protected $connection = 'pgsql2';
}
