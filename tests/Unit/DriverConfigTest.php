<?php

declare(strict_types=1);

use Vimatech\EInvoicing\Exceptions\InvalidDriverConfig;
use Vimatech\EInvoicing\Networks\DriverConfig;

function driverConfig(array $values = []): DriverConfig
{
    return new DriverConfig('peppol', $values);
}

it('reads whole-number strings as integers, as the environment supplies them', function () {
    expect(driverConfig(['timeout' => '120'])->int('timeout', 30))->toBe(120)
        ->and(driverConfig(['timeout' => ' 45 '])->int('timeout', 30))->toBe(45)
        ->and(driverConfig(['timeout' => -1])->int('timeout', 30))->toBe(-1);
});

it('refuses an integer setting it cannot read rather than falling back', function () {
    expect(fn () => driverConfig(['timeout' => 'abc'])->int('timeout', 30))
        ->toThrow(InvalidDriverConfig::class, 'timeout')
        ->and(fn () => driverConfig(['timeout' => 12.5])->int('timeout', 30))
        ->toThrow(InvalidDriverConfig::class)
        ->and(fn () => driverConfig(['timeout' => []])->int('timeout', 30))
        ->toThrow(InvalidDriverConfig::class);
});

it('reads boolean strings, including the "0" the environment never converts', function () {
    expect(driverConfig(['verify' => 'false'])->bool('verify', true))->toBeFalse()
        ->and(driverConfig(['verify' => '0'])->bool('verify', true))->toBeFalse()
        ->and(driverConfig(['verify' => 'TRUE'])->bool('verify', false))->toBeTrue()
        ->and(driverConfig(['verify' => '1'])->bool('verify', false))->toBeTrue();
});

it('refuses a boolean setting it cannot read rather than falling back', function () {
    expect(fn () => driverConfig(['verify' => 'maybe'])->bool('verify', true))
        ->toThrow(InvalidDriverConfig::class, 'verify')
        ->and(fn () => driverConfig(['verify' => 1])->bool('verify', true))
        ->toThrow(InvalidDriverConfig::class);
});

it('refuses a non-string where a string is required', function () {
    expect(fn () => driverConfig(['base_url' => 1234])->string('base_url'))
        ->toThrow(InvalidDriverConfig::class, 'base_url');
});

it('refuses to silently drop a malformed entry from a sub-map or list', function () {
    expect(fn () => driverConfig(['headers' => ['X-Tenant' => 42]])->map('headers'))
        ->toThrow(InvalidDriverConfig::class, 'headers.X-Tenant')
        ->and(fn () => driverConfig(['countries' => ['FR', 33]])->stringList('countries'))
        ->toThrow(InvalidDriverConfig::class, 'countries.1')
        ->and(fn () => driverConfig(['paths' => ['send' => ['/a', '/b']]])->path('send', '/documents'))
        ->toThrow(InvalidDriverConfig::class, 'paths.send')
        ->and(fn () => driverConfig(['headers' => 'X-Tenant: 42'])->map('headers'))
        ->toThrow(InvalidDriverConfig::class, 'headers');
});

it('treats an absent or null setting as not configured', function () {
    expect(driverConfig()->int('timeout', 30))->toBe(30)
        ->and(driverConfig(['timeout' => null])->int('timeout', 30))->toBe(30)
        ->and(driverConfig(['token' => null])->string('token'))->toBe('')
        ->and(driverConfig(['verify' => null])->bool('verify', true))->toBeTrue()
        ->and(driverConfig(['headers' => null])->map('headers'))->toBe([])
        ->and(driverConfig()->path('send', '/documents'))->toBe('/documents');
});
