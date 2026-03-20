<?php

namespace Klick\Agents\events;

use Klick\Agents\external\ExternalResourceProviderInterface;
use yii\base\Event;

class RegisterExternalResourceProvidersEvent extends Event
{
    /** @var array<int, ExternalResourceProviderInterface> */
    public array $providers = [];

    public function addProvider(ExternalResourceProviderInterface $provider): void
    {
        $this->providers[] = $provider;
    }
}
