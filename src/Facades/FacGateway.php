<?php
namespace SoftlogicGT\FacGateway\Facades;

use Illuminate\Support\Facades\Facade;

class FacGateway extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'laravel-facgateway';
    }
}
