<?php

namespace Klick\AgentsRetour;

use craft\base\Plugin as BasePlugin;
use yii\base\Event;
use Klick\Agents\Plugin as AgentsPlugin;
use Klick\Agents\events\RegisterExternalResourceProvidersEvent;

class Plugin extends BasePlugin
{
    public bool $hasCpSection = false;

    public function init(): void
    {
        parent::init();

        Event::on(
            AgentsPlugin::class,
            AgentsPlugin::EVENT_REGISTER_EXTERNAL_RESOURCE_PROVIDERS,
            function(RegisterExternalResourceProvidersEvent $event): void {
                $event->addProvider(new RetourExternalResourceProvider());
            }
        );
    }
}
