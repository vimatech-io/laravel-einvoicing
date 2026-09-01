<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Vimatech\EInvoicing\Exceptions\InvalidDriverConfig;
use Vimatech\EInvoicing\Exceptions\NetworkException;
use Vimatech\EInvoicing\Tests\Support\ProbeDriver;

function probeDriver(array $config = []): ProbeDriver
{
    return new ProbeDriver(new HttpFactory, array_merge([
        'base_url' => 'https://ap.example.test/api',
        'token' => 'secret-token',
    ], $config), 'peppol');
}

it('refuses to build an unauthenticated request when no token is configured', function () {
    expect(fn () => probeDriver(['token' => null])->pendingRequest())
        ->toThrow(InvalidDriverConfig::class, 'no "token" configured')
        ->and(fn () => probeDriver(['token' => ''])->pendingRequest())
        ->toThrow(InvalidDriverConfig::class, 'no "token" configured');
});

it('sends no Authorization header when the partner authenticates another way', function () {
    $options = probeDriver(['token' => null, 'auth' => 'none'])->requestOptions();

    expect($options['headers']['Authorization'] ?? null)->toBeNull();
});

it('refuses a token configured alongside auth none', function () {
    expect(fn () => probeDriver(['auth' => 'none'])->pendingRequest())
        ->toThrow(InvalidDriverConfig::class, 'inconsistently');
});

it('refuses an unknown auth mode', function () {
    expect(fn () => probeDriver(['auth' => 'basic'])->pendingRequest())
        ->toThrow(InvalidDriverConfig::class, 'auth');
});

it('applies a timeout supplied as an environment string', function () {
    expect(probeDriver(['timeout' => '120'])->requestOptions()['timeout'])->toBe(120);
});

it('disables certificate verification when verify is the string false', function () {
    expect(probeDriver(['verify' => 'false'])->requestOptions()['verify'])->toBeFalse()
        ->and(probeDriver()->requestOptions()['verify'] ?? true)->toBeTrue();
});

it('refuses to dispatch when no base_url is configured', function () {
    expect(fn () => probeDriver(['base_url' => null])->pendingRequest())
        ->toThrow(InvalidDriverConfig::class, 'base_url');
});

it('refuses an inbound body that is not valid base64', function () {
    expect(fn () => probeDriver()->decode('<Invoice>not encoded</Invoice>'))
        ->toThrow(NetworkException::class, 'not valid base64');
});

it('refuses an inbound body that is absent or empty', function () {
    expect(fn () => probeDriver()->decode(null))
        ->toThrow(NetworkException::class, 'absent')
        ->and(fn () => probeDriver()->decode(''))
        ->toThrow(NetworkException::class, 'absent')
        ->and(fn () => probeDriver()->decode(['nested' => 'document']))
        ->toThrow(NetworkException::class, 'absent');
});

it('returns a decoded body that is itself falsy', function () {
    expect(probeDriver()->decode(base64_encode('0')))->toBe('0');
});
