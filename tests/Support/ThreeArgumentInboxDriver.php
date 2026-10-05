<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Tests\Support;

use Illuminate\Http\Client\Factory as HttpFactory;

final class ThreeArgumentInboxDriver extends InboxProbeDriver
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(HttpFactory $http, array $config, string $key)
    {
        parent::__construct($http, $config, $key);
    }
}
