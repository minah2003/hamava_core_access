<?php

namespace Hamava\CoreAccess\Facades;

use Illuminate\Support\Facades\Facade;

class CoreAccess extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'hamava.core-access';
    }
}
