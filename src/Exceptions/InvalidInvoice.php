<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Exceptions;

/**
 * Thrown when a CanonicalInvoice fails mandatory-field or arithmetic
 * validation before a document is generated.
 */
final class InvalidInvoice extends EInvoicingException
{
    /**
     * @param  list<string>  $violations  Human-readable rule violations.
     */
    public function __construct(
        public readonly array $violations,
        string $message = '',
    ) {
        parent::__construct(
            $message !== '' ? $message : $this->summarise($violations),
        );
    }

    /**
     * @param  list<string>  $violations
     */
    public static function withViolations(array $violations): self
    {
        return new self($violations);
    }

    /**
     * @return list<string>
     */
    public function violations(): array
    {
        return $this->violations;
    }

    /**
     * @param  list<string>  $violations
     */
    private function summarise(array $violations): string
    {
        if ($violations === []) {
            return 'The invoice is invalid.';
        }

        return 'The invoice is invalid: '.implode('; ', $violations).'.';
    }
}
