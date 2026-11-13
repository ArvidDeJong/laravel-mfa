<?php

namespace Darvis\Mfa\Support;

use Closure;
use Darvis\Mfa\Contracts\Channel;
use InvalidArgumentException;

/**
 * The channels the package knows. Add your own with:
 *
 *     app(ChannelRegistry::class)->extend('whatsapp', fn () => new WhatsappChannel);
 */
class ChannelRegistry
{
    /** @var array<string, Closure(): Channel> */
    private array $factories = [];

    /** @var array<string, Channel> */
    private array $resolved = [];

    /**
     * @param  Closure(): Channel  $factory
     */
    public function extend(string $name, Closure $factory): void
    {
        $this->factories[$name] = $factory;
        unset($this->resolved[$name]);
    }

    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    public function get(string $name): Channel
    {
        if (! $this->has($name)) {
            throw new InvalidArgumentException("MFA channel [{$name}] is not registered.");
        }

        return $this->resolved[$name] ??= ($this->factories[$name])();
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->factories);
    }
}
