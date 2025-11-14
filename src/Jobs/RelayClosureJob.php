<?php

declare(strict_types=1);

namespace Atlas\Relay\Jobs;

use Atlas\Relay\Models\Relay;
use Atlas\Relay\Support\RelayJobContext;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Laravel\SerializableClosure\SerializableClosure;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;

/**
 * Queues Closure callbacks for dispatch deliveries while injecting relay payload/context per PRD — Atlas Relay Example Usage.
 */
class RelayClosureJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly SerializableClosure $callback,
        private readonly int $relayId
    ) {}

    /**
     * @return array<int, mixed>
     */
    public function middleware(): array
    {
        return $this->middleware;
    }

    public static function fromClosure(Closure $closure, int $relayId): self
    {
        return new self(new SerializableClosure($closure), $relayId);
    }

    public function handle(): void
    {
        /** @var RelayJobContext $context */
        $context = app(RelayJobContext::class);
        $relay = $context->current();

        if ($relay === null || $relay->id !== $this->relayId) {
            $relay = Relay::query()->findOrFail($this->relayId);
        }

        $closure = $this->callback->getClosure();
        $arguments = $this->determineArguments($closure, $relay);
        $closure(...$arguments);
    }

    /**
     * @return array<int, mixed>
     */
    private function determineArguments(Closure $closure, Relay $relay): array
    {
        $reflection = $this->reflectCallback($closure);

        if ($reflection === null) {
            return [$relay->payload, $relay];
        }

        $parameters = $reflection->getParameters();

        if ($parameters === []) {
            return [];
        }

        $available = [$relay->payload, $relay];
        $arguments = [];

        foreach ($parameters as $index => $parameter) {
            if ($parameter->isVariadic()) {
                $arguments = array_merge($arguments, $available);

                break;
            }

            if ($index < count($available)) {
                $arguments[] = $available[$index];
            }
        }

        return $arguments;
    }

    private function reflectCallback(Closure $closure): ?ReflectionFunctionAbstract
    {
        try {
            return new ReflectionFunction($closure);
        } catch (ReflectionException) {
            return null;
        }
    }
}
