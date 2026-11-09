<?php

namespace TecClub\OtpKit\Generators;

use Closure;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use TecClub\OtpKit\Contracts\CodeGenerator;
use TecClub\OtpKit\Enums\Charset;

/**
 * @internal
 */
class GeneratorRegistry
{
    /** @var array<string, class-string<CodeGenerator>|Closure(): CodeGenerator> */
    private array $generators = [];

    /** @var array<string, CodeGenerator> */
    private array $resolved = [];

    public function __construct(private readonly Container $container)
    {
        $this->register(Charset::Numeric->value, NumericGenerator::class);
        $this->register(Charset::Alphanumeric->value, AlphanumericGenerator::class);
    }

    /**
     * @param  class-string<CodeGenerator>|Closure(): CodeGenerator  $generator
     */
    public function register(string $name, string|Closure $generator): void
    {
        $this->generators[$name] = $generator;
        unset($this->resolved[$name]);
    }

    public function has(string $name): bool
    {
        return isset($this->generators[$name]);
    }

    public function get(string $name): CodeGenerator
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $generator = $this->generators[$name]
            ?? throw new InvalidArgumentException("OtpKit code generator [{$name}] is not registered.");

        $instance = $generator instanceof Closure
            ? $generator()
            : $this->container->make($generator);

        if (! $instance instanceof CodeGenerator) {
            throw new InvalidArgumentException("OtpKit code generator [{$name}] must implement ".CodeGenerator::class.'.');
        }

        return $this->resolved[$name] = $instance;
    }
}
