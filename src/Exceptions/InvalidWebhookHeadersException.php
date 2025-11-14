<?php

declare(strict_types=1);

namespace Atlas\Relay\Exceptions;

/**
 * Exception thrown when inbound guard header validation fails per PRD: Receive Webhook Relay — Guard Validation.
 */
class InvalidWebhookHeadersException extends \RuntimeException
{
    /**
     * @param  array<int, string>  $violations
     */
    public function __construct(
        private readonly string $guard,
        private readonly array $violations = [],
        ?string $message = null
    ) {
        parent::__construct($message ?? self::buildMessage($guard, $violations));
    }

    /**
     * @param  array<int, string>  $violations
     */
    public static function fromViolations(string $guard, array $violations): self
    {
        return new self($guard, $violations);
    }

    public function guard(): string
    {
        return $this->guard;
    }

    /**
     * @return array<int, string>
     */
    public function violations(): array
    {
        return $this->violations;
    }

    public function statusCode(): int
    {
        return 403;
    }

    /**
     * @param  array<int, string>  $violations
     */
    private static function buildMessage(string $guard, array $violations): string
    {
        if ($violations === []) {
            return sprintf('Inbound guard [%s] rejected the request headers.', $guard);
        }

        return sprintf(
            'Inbound guard [%s] rejected the request headers: %s',
            $guard,
            implode(' ', $violations)
        );
    }
}
