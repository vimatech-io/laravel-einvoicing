<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Formats\Support;

use DOMDocument;
use DOMElement;
use Vimatech\EInvoicing\Exceptions\EInvoicingException;

/**
 * A thin, namespace-aware convenience layer over ext-dom.
 *
 * Keeps the generators readable while guaranteeing correct prefix/namespace
 * binding and proper XML escaping (handled natively by DOMDocument).
 */
final class DomBuilder
{
    private DOMDocument $dom;

    /**
     * @param  array<string, string>  $namespaces  prefix => namespace URI for child elements.
     */
    public function __construct(
        string $rootName,
        string $rootNamespace,
        private readonly array $namespaces,
    ) {
        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;
        $this->dom->preserveWhiteSpace = false;

        $root = $this->dom->createElementNS($rootNamespace, $rootName);

        foreach ($this->namespaces as $prefix => $uri) {
            $root->setAttributeNS('http://www.w3.org/2000/xmlns/', "xmlns:{$prefix}", $uri);
        }

        $this->dom->appendChild($root);
    }

    public function root(): DOMElement
    {
        /** @var DOMElement $root */
        $root = $this->dom->documentElement;

        return $root;
    }

    /**
     * Create a namespaced element (without attaching it).
     *
     * @param  array<string, scalar>  $attributes
     */
    public function element(string $qualifiedName, ?string $value = null, array $attributes = []): DOMElement
    {
        $namespace = $this->resolveNamespace($qualifiedName);

        $element = $this->dom->createElementNS($namespace, $qualifiedName);

        if ($value !== null) {
            // createTextNode escapes &, < and > natively, which createElementNS's
            // value argument does not — so all text content goes through it.
            $element->appendChild($this->dom->createTextNode($value));
        }

        foreach ($attributes as $name => $attributeValue) {
            $element->setAttribute($name, (string) $attributeValue);
        }

        return $element;
    }

    /**
     * Create a namespaced element and append it to the given parent.
     *
     * @param  array<string, scalar>  $attributes
     */
    public function child(DOMElement $parent, string $qualifiedName, ?string $value = null, array $attributes = []): DOMElement
    {
        $element = $this->element($qualifiedName, $value, $attributes);
        $parent->appendChild($element);

        return $element;
    }

    public function toXml(): string
    {
        $xml = $this->dom->saveXML();

        if ($xml === false || $xml === '') {
            throw new EInvoicingException('The document could not be serialised to XML.');
        }

        return $xml;
    }

    private function resolveNamespace(string $qualifiedName): string
    {
        $prefix = str_contains($qualifiedName, ':')
            ? strstr($qualifiedName, ':', true)
            : '';

        return $this->namespaces[$prefix] ?? $this->root()->namespaceURI ?? '';
    }
}
