<?php

declare(strict_types=1);

namespace Atlas\Relay\Exceptions;

/**
 * Exception thrown when inbound guard validators reject payload contents after authentication succeeds.
 */
class InvalidWebhookPayloadException extends \RuntimeException
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
        return new self($guard, $violations, self::buildMessage($guard, $violations));
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
        return 422;
    }

    /**
     * @param  array<int, string>  $violations
     */
    private static function buildMessage(string $guard, array $violations): string
    {
        $summary = $violations === []
            ? 'Payload failed guard validator checks.'
            : implode(' ', $violations);

        return sprintf('Inbound guard [%s] rejected payload: %s', $guard, $summary);
    }
}
