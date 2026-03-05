<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Facade;

use Illuminate\Support\Facades\Facade;

class TicketBAI extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'ticketbai';
    }
}
