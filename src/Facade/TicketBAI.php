<?php
namespace EBethus\LaravelTicketBAI\Facade;

use Illuminate\Support\Facades\Facade;

class TicketBAI extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'ticketbai';
    }
}