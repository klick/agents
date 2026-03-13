<?php

namespace Klick\Agents\events;

use yii\base\Event;

class RegisterExternalResourceProvidersEvent extends Event
{
    /**
     * Compatibility shim for branches that do not yet ship the external adapter registry.
     * Standalone adapters can register providers without crashing, but the providers are not
     * consumed unless the full external adapter surface is present.
     *
     * @var array<int, object>
     */
    public array $providers = [];

    public function addProvider(object $provider): void
    {
        $this->providers[] = $provider;
    }
}
