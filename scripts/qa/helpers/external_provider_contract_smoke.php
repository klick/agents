<?php

declare(strict_types=1);

use yii\base\Event;
use Klick\Agents\Plugin;
use Klick\Agents\events\RegisterExternalResourceProvidersEvent;
use Klick\Agents\external\ExternalResourceDefinition;
use Klick\Agents\external\ExternalResourceParameterDefinition;
use Klick\Agents\external\ExternalResourceProviderInterface;
use Klick\Agents\services\ExternalResourceRegistryService;

$pluginRoot = dirname(__DIR__, 3);
$appRoot = dirname(dirname($pluginRoot));
require $appRoot . '/vendor/autoload.php';

final class FixtureExternalResourceProvider implements ExternalResourceProviderInterface
{
    public function getPluginHandle(): string
    {
        return 'fixture';
    }

    public function getPluginName(): string
    {
        return 'Fixture';
    }

    public function getPluginDescription(): string
    {
        return 'Fixture provider for external resource registry smoke coverage.';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getResourceDefinitions(): array
    {
        return [
            new ExternalResourceDefinition(
                handle: 'widgets',
                name: 'Widgets',
                description: 'Fixture widgets resource.',
                queryParameters: [
                    new ExternalResourceParameterDefinition('q', 'string', 'Search term.'),
                    new ExternalResourceParameterDefinition('limit', 'integer', 'Result cap.', false, null, [], 1, 50),
                ],
                listItemSchema: [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                    ],
                ],
                detailSchema: [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                        'status' => ['type' => 'string'],
                    ],
                ],
                itemIdDescription: 'Fixture widget id'
            ),
        ];
    }

    public function fetchResourceList(string $resourceHandle, array $queryParams): array
    {
        if ($resourceHandle !== 'widgets') {
            throw new InvalidArgumentException('Unsupported resource.');
        }

        return [
            'data' => [
                ['id' => 'w1', 'name' => 'Alpha'],
                ['id' => 'w2', 'name' => 'Beta'],
            ],
            'meta' => ['q' => $queryParams['q'] ?? null],
            'page' => ['limit' => 2, 'nextCursor' => null],
        ];
    }

    public function fetchResourceItem(string $resourceHandle, string $id, array $queryParams): ?array
    {
        unset($queryParams);
        if ($resourceHandle !== 'widgets') {
            throw new InvalidArgumentException('Unsupported resource.');
        }

        if ($id !== 'w1') {
            return null;
        }

        return ['id' => 'w1', 'name' => 'Alpha', 'status' => 'ok'];
    }
}

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fail($message);
    }
}

$registry = new ExternalResourceRegistryService();
$registry->clear();
Event::offAll(Plugin::class);
Event::on(Plugin::class, Plugin::EVENT_REGISTER_EXTERNAL_RESOURCE_PROVIDERS, static function(RegisterExternalResourceProvidersEvent $event): void {
    $event->addProvider(new FixtureExternalResourceProvider());
});

$event = new RegisterExternalResourceProvidersEvent();
Event::trigger(Plugin::class, Plugin::EVENT_REGISTER_EXTERNAL_RESOURCE_PROVIDERS, $event);
assertTrue(count($event->providers) === 1, 'Registration event should collect one fixture provider.');
foreach ($event->providers as $provider) {
    $registry->registerProvider($provider);
}

$scopes = $registry->getCapabilityScopes();
assertTrue(isset($scopes['plugins:fixture:widgets:read']), 'Fixture external scope should be generated.');

$capabilityResources = $registry->getCapabilitiesResources();
assertTrue(($capabilityResources[0]['plugin'] ?? null) === 'fixture', 'Capability resource snapshot should expose fixture plugin.');
assertTrue(($capabilityResources[0]['resources'][0]['handle'] ?? null) === 'widgets', 'Capability resource snapshot should expose widgets resource.');

$capabilityEndpoints = $registry->getCapabilityEndpoints();
assertTrue(count($capabilityEndpoints) === 2, 'Fixture resource should expose list and detail capability endpoints.');
assertTrue(($capabilityEndpoints[0]['path'] ?? '') === '/plugins/fixture/widgets', 'List capability endpoint path should match fixture resource.');
assertTrue(($capabilityEndpoints[1]['path'] ?? '') === '/plugins/fixture/widgets/{id}', 'Detail capability endpoint path should match fixture resource.');

$openApiPaths = $registry->buildOpenApiPaths(static fn(array $responses): array => $responses);
assertTrue(isset($openApiPaths['/plugins/fixture/widgets']), 'OpenAPI paths should include the fixture list path.');
assertTrue(isset($openApiPaths['/plugins/fixture/widgets/{id}']), 'OpenAPI paths should include the fixture detail path.');
assertTrue(($openApiPaths['/plugins/fixture/widgets']['get']['x-required-scopes'][0] ?? null) === 'plugins:fixture:widgets:read', 'OpenAPI list path should require the generated fixture scope.');

$schemaCatalog = $registry->buildSchemaCatalog('/agents/v1');
assertTrue(isset($schemaCatalog['plugins.fixture.widgets.list']), 'Schema catalog should expose the fixture list key.');
assertTrue(isset($schemaCatalog['plugins.fixture.widgets.show']), 'Schema catalog should expose the fixture detail key.');
assertTrue(($schemaCatalog['plugins.fixture.widgets.list']['path'] ?? '') === '/agents/v1/plugins/fixture/widgets', 'Schema list path should include the API base path.');

$resource = $registry->getResource('fixture', 'widgets');
assertTrue($resource !== null, 'Fixture resource lookup should resolve.');
assertTrue(($resource['provider']->fetchResourceList('widgets', ['q' => 'alpha'])['meta']['q'] ?? null) === 'alpha', 'Fixture provider list fetch should remain callable.');
assertTrue(($resource['provider']->fetchResourceItem('widgets', 'w1', [])['status'] ?? null) === 'ok', 'Fixture provider detail fetch should remain callable.');

echo "PASS: external provider contract smoke\n";
