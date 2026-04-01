<?php

declare(strict_types=1);

use EBethus\LaravelTicketBAI\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific TestCase class.
| By default, that's the TestCase class we defined in tests/TestCase.php.
|
*/

uses(TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Pest Expectations
|--------------------------------------------------------------------------
|
| When you're testing your Laravel application, you often look for specific values
| in the response. The "expect()" function gives you access to a set of helpful
| assertions you can perform on your application. Of course, you may extend these
| assertions using the "extend" method.
|
| expect()->extend('toBeWithinRange', function ($min, $max) {
|     return $this->toBeGreaterThanOrEqual($min)->toBeLessThanOrEqual($max);
| });
|
| explore() may be used in Web, Application, JSON, Testing and Response assertions.
|
*/

expect()->extend('toBeWithinRange', function (int $min, int $max) {
    return $this->toBeGreaterThanOrEqual($min)->toBeLessThanOrEqual($max);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| Pest is setup with a number of helpful functions for your convenience.
|
| actingAs(), mock(), spy(), etc.
|
*/


/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions.
| The "expect()" function gives you access to a set of expectations that you can assert against your code.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});
