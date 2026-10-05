<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Http;
use Vimatech\EInvoicing\Exceptions\UnrecognisedInboundDocument;
use Vimatech\EInvoicing\Facades\EInvoice;
use Vimatech\EInvoicing\Tests\Support\InboxProbeDriver;
use Vimatech\EInvoicing\Tests\Support\RecordingExceptionHandler;
use Vimatech\EInvoicing\Tests\Support\ThreeArgumentInboxDriver;

function recordReportedExceptions(): RecordingExceptionHandler
{
    $exceptions = new RecordingExceptionHandler;
    app()->instance(ExceptionHandler::class, $exceptions);

    return $exceptions;
}

it('gives the configured peppol driver the exception handler', function () {
    $exceptions = recordReportedExceptions();
    config()->set('einvoicing.networks.peppol.base_url', 'https://ap.example.test');
    config()->set('einvoicing.networks.peppol.token', 'ap-token');
    Http::fake(['*/inbound' => Http::response(['documents' => [
        ['id' => 'in-pdf', 'document' => base64_encode('%PDF-1.7')],
        ['id' => 'in-unknown', 'document' => base64_encode('not an invoice')],
    ]], 200)]);

    $documents = EInvoice::receive('peppol');

    expect(array_map(fn ($document) => $document->messageId, $documents))->toBe(['in-pdf'])
        ->and($exceptions->reported)->toHaveCount(1)
        ->and($exceptions->reported[0]->messageId)->toBe('in-unknown');
});

it('gives an AbstractHttpDriver subclass referenced by class the exception handler', function () {
    $exceptions = recordReportedExceptions();
    config()->set('einvoicing.networks.probe', ['driver' => InboxProbeDriver::class]);

    $documents = EInvoice::receive('probe');

    expect(array_map(fn ($document) => $document->messageId, $documents))->toBe(['probe-pdf'])
        ->and($exceptions->reported)->toHaveCount(1)
        ->and($exceptions->reported[0]->messageId)->toBe('probe-unknown');
});

it('stops the batch of a subclass whose constructor drops the exception handler', function () {
    $exceptions = recordReportedExceptions();
    config()->set('einvoicing.networks.probe', ['driver' => ThreeArgumentInboxDriver::class]);

    expect(fn () => EInvoice::receive('probe'))->toThrow(UnrecognisedInboundDocument::class, 'probe-unknown')
        ->and($exceptions->reported)->toBe([]);
});
