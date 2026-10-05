<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Formats\Support;

use DOMDocument;

/**
 * @internal
 */
final class Xml
{
    public static function parse(string $contents): ?DOMDocument
    {
        if ($contents === '') {
            return null;
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $parsed = $dom->loadXML($contents, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $parsed ? $dom : null;
    }
}
