<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Networks\NetworkManager;
use Vimatech\EInvoicing\Routing\EInvoiceRouter;

final class EInvoicingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/einvoicing.php', 'einvoicing');

        $this->app->singleton(NetworkManager::class, function (Application $app): NetworkManager {
            $config = $app->make(Repository::class);

            return new NetworkManager($app, $config->array('einvoicing'));
        });

        $this->app->singleton(EInvoiceRouter::class, function (Application $app): EInvoiceRouter {
            $config = $app->make(Repository::class);

            $routes = [];
            foreach ($config->array('einvoicing.routing') as $country => $network) {
                if (is_string($network)) {
                    $routes[strtoupper((string) $country)] = $network;
                }
            }

            $fallback = $config->get('einvoicing.fallback');

            return new EInvoiceRouter(
                $app->make(NetworkManager::class),
                $routes,
                is_string($fallback) ? $fallback : null,
            );
        });

        $this->app->singleton(EInvoiceManager::class, function (Application $app): EInvoiceManager {
            $config = $app->make(Repository::class);
            $default = Format::tryFrom($config->string('einvoicing.default_format', 'ubl')) ?? Format::Ubl;

            return new EInvoiceManager(
                $app->make(NetworkManager::class),
                $app->make(EInvoiceRouter::class),
                $app->make(Dispatcher::class),
                $default,
            );
        });

        $this->app->alias(EInvoiceManager::class, 'einvoice');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/einvoicing.php' => $this->app->configPath('einvoicing.php'),
            ], 'einvoicing-config');
        }
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            EInvoiceManager::class,
            NetworkManager::class,
            EInvoiceRouter::class,
            'einvoice',
        ];
    }
}
