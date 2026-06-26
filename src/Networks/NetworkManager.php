<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Networks;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Vimatech\EInvoicing\Contracts\EInvoiceNetwork;
use Vimatech\EInvoicing\Enums\LifecycleStatus;
use Vimatech\EInvoicing\Exceptions\NetworkException;

/**
 * Resolves and caches network driver instances from configuration.
 *
 * Drivers are referenced by key; each key declares a built-in driver alias or
 * a fully-qualified class. Custom drivers can be registered with extend().
 */
final class NetworkManager
{
    /** @var array<string, EInvoiceNetwork> */
    private array $resolved = [];

    /** @var array<string, Closure(array<string, mixed>, string): EInvoiceNetwork> */
    private array $extensions = [];

    /**
     * @param  array<array-key, mixed>  $config  The full `einvoicing` config array.
     */
    public function __construct(
        private readonly Container $container,
        private array $config,
    ) {}

    /**
     * Resolve a network by its config key (singleton per key).
     */
    public function network(string $key): EInvoiceNetwork
    {
        return $this->resolved[$key] ??= $this->resolve($key);
    }

    /**
     * Register a custom driver factory.
     *
     * @param  Closure(array<string, mixed>, string): EInvoiceNetwork  $factory
     */
    public function extend(string $driver, Closure $factory): self
    {
        $this->extensions[$driver] = $factory;

        return $this;
    }

    /**
     * Swap a network (or every configured network) for an in-memory FakeDriver,
     * returning it so tests can assert against it.
     */
    public function fake(string $key, LifecycleStatus $status = LifecycleStatus::Delivered): FakeDriver
    {
        $fake = new FakeDriver($key, $status);
        $this->resolved[$key] = $fake;

        return $fake;
    }

    public function forget(string $key): void
    {
        unset($this->resolved[$key]);
    }

    /**
     * @return array<string, mixed>
     */
    public function networkConfig(string $key): array
    {
        $networks = $this->config['networks'] ?? [];

        if (! is_array($networks) || ! isset($networks[$key])) {
            throw NetworkException::unknownDriver($key);
        }

        $networkConfig = $networks[$key];

        if (! is_array($networkConfig)) {
            throw NetworkException::unknownDriver($key);
        }

        $config = [];
        foreach ($networkConfig as $name => $value) {
            $config[(string) $name] = $value;
        }

        return $config;
    }

    private function resolve(string $key): EInvoiceNetwork
    {
        $config = $this->networkConfig($key);
        $driverValue = $config['driver'] ?? $key;
        $driver = is_string($driverValue) ? $driverValue : $key;

        if (isset($this->extensions[$driver])) {
            return ($this->extensions[$driver])($config, $key);
        }

        return match ($driver) {
            'null' => new NullDriver($key),
            'fake' => new FakeDriver($key),
            'peppol' => new PeppolDriver($this->httpFactory(), $config, $key),
            'fr_pdp' => new FrPdpDriver($this->httpFactory(), $config, $key),
            default => $this->resolveClass($driver, $config, $key),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveClass(string $driver, array $config, string $key): EInvoiceNetwork
    {
        if (! class_exists($driver) || ! is_subclass_of($driver, EInvoiceNetwork::class)) {
            throw NetworkException::unknownDriver($key);
        }

        if (is_subclass_of($driver, AbstractHttpDriver::class)) {
            return new $driver($this->httpFactory(), $config, $key);
        }

        $instance = $this->container->make($driver, ['config' => $config, 'key' => $key]);

        if (! $instance instanceof EInvoiceNetwork) {
            throw NetworkException::unknownDriver($key);
        }

        return $instance;
    }

    private function httpFactory(): HttpFactory
    {
        return $this->container->make(HttpFactory::class);
    }
}
