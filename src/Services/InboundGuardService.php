<?php

declare(strict_types=1);

namespace Atlas\Relay\Services;

use Atlas\Relay\Contracts\InboundGuardValidatorInterface;
use Atlas\Relay\Exceptions\ForbiddenWebhookException;
use Atlas\Relay\Exceptions\InvalidWebhookPayloadException;
use Atlas\Relay\Models\Relay;
use Atlas\Relay\Support\InboundGuardProfile;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * Resolves and executes inbound guard profiles defined in PRD: Inbound Guards — Authentication Gate.
 */
class InboundGuardService
{
    public function __construct(
        private readonly Container $container
    ) {}

    public function resolveProfile(?string $explicitGuard, ?string $provider): ?InboundGuardProfile
    {
        $guardName = $this->normalizeGuardName($explicitGuard);

        if ($guardName === null && $provider !== null) {
            $guardName = $this->mapProviderToGuard($provider);
        }

        if ($guardName === null) {
            return null;
        }

        $config = $this->guardConfig($guardName);

        return new InboundGuardProfile(
            name: $guardName,
            captureForbidden: (bool) ($config['capture_forbidden'] ?? true),
            requiredHeaders: $this->normalizeHeaderRequirements($config['required_headers'] ?? []),
            validatorClass: $this->normalizeValidatorClass($config['validator'] ?? null)
        );
    }

    public function validate(Request $request, InboundGuardProfile $profile, ?Relay $relay = null): void
    {
        $violations = [];

        foreach ($profile->requiredHeaders as $requirement) {
            $value = $this->extractHeaderValue($request, $requirement['name']);

            if ($value === null) {
                $violations[] = sprintf('Missing required header [%s].', $requirement['name']);

                continue;
            }

            if ($requirement['expected'] !== null && $value !== $requirement['expected']) {
                $violations[] = sprintf(
                    'Header [%s] value mismatch.',
                    $requirement['name']
                );
            }
        }

        if ($violations !== []) {
            throw ForbiddenWebhookException::fromViolations($profile->name, $violations);
        }

        if ($profile->validatorClass === null) {
            return;
        }

        $validator = $this->container->make($profile->validatorClass);

        if (! $validator instanceof InboundGuardValidatorInterface) {
            throw new \InvalidArgumentException(sprintf(
                'Inbound guard validator [%s] must implement [%s].',
                $profile->validatorClass,
                InboundGuardValidatorInterface::class
            ));
        }

        try {
            $validator->validate($request, $profile, $relay);
        } catch (ForbiddenWebhookException|InvalidWebhookPayloadException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw InvalidWebhookPayloadException::fromViolations($profile->name, [$exception->getMessage()]);
        }
    }

    /**
     * @return list<array{name:string,lookup:string,expected:?string}>
     */
    private function normalizeHeaderRequirements(mixed $headers): array
    {
        if ($headers === null || $headers === []) {
            return [];
        }

        if (! is_array($headers)) {
            throw new \InvalidArgumentException('Inbound guard required_headers must be an array.');
        }

        $requirements = [];

        foreach ($headers as $key => $value) {
            if (is_int($key)) {
                if (! is_string($value) || trim($value) === '') {
                    throw new \InvalidArgumentException('Inbound guard header names must be non-empty strings.');
                }

                $requirements[] = $this->headerRequirement($value, null);

                continue;
            }

            if (! is_string($value)) {
                throw new \InvalidArgumentException('Inbound guard header rules must map header names to string values.');
            }

            $name = trim((string) $key);

            if ($name === '') {
                throw new \InvalidArgumentException('Inbound guard header names must be non-empty strings.');
            }

            $requirements[] = $this->headerRequirement($name, $value);
        }

        return $requirements;
    }

    /**
     * @return array{name:string,lookup:string,expected:?string}
     */
    private function headerRequirement(string $name, ?string $expected): array
    {
        $normalized = trim($name);

        if ($normalized === '') {
            throw new \InvalidArgumentException('Inbound guard header names cannot be empty.');
        }

        return [
            'name' => $this->normalizeHeaderCasing($normalized),
            'lookup' => strtolower($normalized),
            'expected' => $expected,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function guardConfig(string $guard): array
    {
        $guards = config('atlas-relay.inbound.guards', []);

        if (! is_array($guards) || ! array_key_exists($guard, $guards)) {
            throw new \InvalidArgumentException(sprintf('Inbound guard [%s] is not defined.', $guard));
        }

        $config = $guards[$guard];

        if (! is_array($config)) {
            throw new \InvalidArgumentException(sprintf('Inbound guard [%s] must be an array configuration.', $guard));
        }

        return $config;
    }

    private function normalizeValidatorClass(mixed $validator): ?string
    {
        if ($validator === null) {
            return null;
        }

        if (! is_string($validator) || trim($validator) === '') {
            throw new \InvalidArgumentException('Inbound guard validator must be a fully-qualified class name.');
        }

        return $validator;
    }

    private function mapProviderToGuard(?string $provider): ?string
    {
        if ($provider === null || trim($provider) === '') {
            return null;
        }

        $mapping = config('atlas-relay.inbound.provider_guards', []);

        if (! is_array($mapping)) {
            return null;
        }

        $key = strtolower($provider);

        foreach ($mapping as $providerName => $guard) {
            if (! is_string($providerName)) {
                continue;
            }

            if (strtolower($providerName) === $key) {
                return $this->normalizeGuardName($guard);
            }
        }

        return null;
    }

    private function normalizeGuardName(?string $guard): ?string
    {
        if ($guard === null) {
            return null;
        }

        $trimmed = trim($guard);

        return $trimmed === '' ? null : $trimmed;
    }

    private function extractHeaderValue(Request $request, string $header): ?string
    {
        $value = $request->headers->get($header);

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function normalizeHeaderCasing(string $header): string
    {
        $segments = preg_split('/[-_\s]+/', $header) ?: [];
        $segments = array_filter($segments, static fn (string $segment): bool => $segment !== '');

        if ($segments === []) {
            return $header;
        }

        $segments = array_map(static fn (string $segment): string => ucfirst(strtolower($segment)), $segments);

        return implode('-', $segments);
    }
}
