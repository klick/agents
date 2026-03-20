<?php

namespace Klick\Agents\services;

use craft\base\Component;
use InvalidArgumentException;
use Klick\Agents\external\ExternalResourceDefinition;
use Klick\Agents\external\ExternalResourceProviderInterface;

class ExternalResourceRegistryService extends Component
{
    /** @var array<string, ExternalResourceProviderInterface> */
    private array $providers = [];

    /** @var array<string, array{provider: ExternalResourceProviderInterface, definition: ExternalResourceDefinition}> */
    private array $resources = [];

    public function clear(): void
    {
        $this->providers = [];
        $this->resources = [];
    }

    public function registerProvider(ExternalResourceProviderInterface $provider): void
    {
        if (!$provider->isAvailable()) {
            return;
        }

        $pluginHandle = $this->normalizeHandle($provider->getPluginHandle(), 'plugin handle');
        if (isset($this->providers[$pluginHandle])) {
            throw new InvalidArgumentException(sprintf('External resource provider `%s` is already registered.', $pluginHandle));
        }

        $pluginName = trim($provider->getPluginName());
        if ($pluginName === '') {
            throw new InvalidArgumentException(sprintf('External resource provider `%s` must declare a plugin name.', $pluginHandle));
        }

        $definitions = $provider->getResourceDefinitions();
        if ($definitions === []) {
            throw new InvalidArgumentException(sprintf('External resource provider `%s` must declare at least one resource.', $pluginHandle));
        }

        foreach ($definitions as $definition) {
            if (!$definition instanceof ExternalResourceDefinition) {
                throw new InvalidArgumentException(sprintf('External resource provider `%s` returned an invalid resource definition.', $pluginHandle));
            }

            $resourceHandle = $this->normalizeHandle($definition->handle, 'resource handle');
            $resourceKey = $this->resourceKey($pluginHandle, $resourceHandle);
            if (isset($this->resources[$resourceKey])) {
                throw new InvalidArgumentException(sprintf('External resource `%s/%s` is already registered.', $pluginHandle, $resourceHandle));
            }

            $this->resources[$resourceKey] = [
                'provider' => $provider,
                'definition' => $definition,
            ];
        }

        $this->providers[$pluginHandle] = $provider;
        ksort($this->providers);
        ksort($this->resources);
    }

    /** @return array<int, array<string, mixed>> */
    public function getCapabilitiesResources(): array
    {
        $resourcesByPlugin = [];
        foreach ($this->providers as $pluginHandle => $provider) {
            $resourcesByPlugin[$pluginHandle] = [
                'plugin' => $pluginHandle,
                'name' => $provider->getPluginName(),
                'description' => $provider->getPluginDescription(),
                'resources' => [],
            ];
        }

        foreach ($this->resources as $resourceKey => $entry) {
            [$pluginHandle] = explode('::', $resourceKey, 2);
            $definition = $entry['definition'];
            $resourcesByPlugin[$pluginHandle]['resources'][] = [
                'handle' => $definition->handle,
                'name' => $definition->name,
                'description' => $definition->description,
                'scope' => $definition->scopeKey($pluginHandle),
                'listPath' => $definition->listPath($pluginHandle),
                'detailPath' => $definition->supportsDetail() ? $definition->detailPath($pluginHandle) : null,
            ];
        }

        foreach ($resourcesByPlugin as &$pluginEntry) {
            usort($pluginEntry['resources'], static fn(array $a, array $b): int => strcmp((string)$a['handle'], (string)$b['handle']));
        }
        unset($pluginEntry);

        return array_values($resourcesByPlugin);
    }

    /** @return array<string, string> */
    public function getCapabilityScopes(): array
    {
        $scopes = [];
        foreach ($this->resources as $resourceKey => $entry) {
            [$pluginHandle] = explode('::', $resourceKey, 2);
            $definition = $entry['definition'];
            $scopes[$definition->scopeKey($pluginHandle)] = sprintf(
                'Read %s %s resource.',
                $entry['provider']->getPluginName(),
                $definition->name
            );
        }

        ksort($scopes);
        return $scopes;
    }

    /** @return array<int, array<string, mixed>> */
    public function getCapabilityEndpoints(): array
    {
        $endpoints = [];
        foreach ($this->resources as $resourceKey => $entry) {
            [$pluginHandle] = explode('::', $resourceKey, 2);
            $definition = $entry['definition'];
            $scope = $definition->scopeKey($pluginHandle);
            $endpoints[] = [
                'method' => 'GET',
                'path' => $definition->listPath($pluginHandle),
                'requiredScopes' => [$scope],
                'category' => 'external',
                'plugin' => $pluginHandle,
                'resource' => $definition->handle,
            ];
            if ($definition->supportsDetail()) {
                $endpoints[] = [
                    'method' => 'GET',
                    'path' => $definition->detailPath($pluginHandle),
                    'requiredScopes' => [$scope],
                    'category' => 'external',
                    'plugin' => $pluginHandle,
                    'resource' => $definition->handle,
                ];
            }
        }

        return $endpoints;
    }

    /** @return array<string, array<string, mixed>> */
    public function buildOpenApiPaths(callable $guardedResponses): array
    {
        $paths = [];
        foreach ($this->resources as $resourceKey => $entry) {
            [$pluginHandle] = explode('::', $resourceKey, 2);
            $definition = $entry['definition'];
            $scope = $definition->scopeKey($pluginHandle);
            $listParameters = array_map(
                static fn($parameter) => $parameter->toOpenApiParameter(),
                $definition->queryParameters
            );

            $paths[$definition->listPath($pluginHandle)] = [
                'get' => [
                    'summary' => sprintf('%s: %s', $entry['provider']->getPluginName(), $definition->name),
                    'description' => $definition->description,
                    'parameters' => $listParameters,
                    'responses' => $guardedResponses([
                        '200' => ['description' => 'OK'],
                        '400' => ['description' => 'Invalid request'],
                        '404' => ['description' => 'Plugin/resource provider not available'],
                    ]),
                    'x-required-scopes' => [$scope],
                ],
            ];

            if ($definition->supportsDetail()) {
                $paths[$definition->detailPath($pluginHandle)] = [
                    'get' => [
                        'summary' => sprintf('%s: %s detail', $entry['provider']->getPluginName(), $definition->name),
                        'description' => $definition->description,
                        'parameters' => array_merge([
                            [
                                'in' => 'path',
                                'name' => 'id',
                                'required' => true,
                                'schema' => ['type' => 'string'],
                                'description' => $definition->itemIdDescription,
                            ],
                        ], $listParameters),
                        'responses' => $guardedResponses([
                            '200' => ['description' => 'OK'],
                            '400' => ['description' => 'Invalid request'],
                            '404' => ['description' => 'Plugin/resource/item not available'],
                        ]),
                        'x-required-scopes' => [$scope],
                    ],
                ];
            }
        }

        ksort($paths);
        return $paths;
    }

    /** @return array<string, array<string, mixed>> */
    public function buildSchemaCatalog(string $basePath = '/agents/v1'): array
    {
        $schemas = [];
        foreach ($this->resources as $resourceKey => $entry) {
            [$pluginHandle] = explode('::', $resourceKey, 2);
            $definition = $entry['definition'];
            $schemas[$definition->listSchemaKey($pluginHandle)] = [
                'method' => 'GET',
                'path' => $basePath . $definition->listPath($pluginHandle),
                'query' => $definition->querySchema(),
                'response' => $definition->listResponseSchema(),
            ];
            if ($definition->supportsDetail()) {
                $schemas[$definition->detailSchemaKey($pluginHandle)] = [
                    'method' => 'GET',
                    'path' => $basePath . str_replace('{id}', '{id}', $definition->detailPath($pluginHandle)),
                    'query' => $definition->querySchema(),
                    'response' => $definition->detailResponseSchema(),
                ];
            }
        }

        ksort($schemas);
        return $schemas;
    }

    /** @return array{provider: ExternalResourceProviderInterface, definition: ExternalResourceDefinition}|null */
    public function getResource(string $pluginHandle, string $resourceHandle): ?array
    {
        $pluginHandle = strtolower(trim($pluginHandle));
        $resourceHandle = strtolower(trim($resourceHandle));
        if ($pluginHandle === '' || $resourceHandle === '') {
            return null;
        }

        return $this->resources[$this->resourceKey($pluginHandle, $resourceHandle)] ?? null;
    }

    private function normalizeHandle(string $value, string $label): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || preg_match('/^[a-z0-9._-]+$/', $normalized) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid external resource %s `%s`.', $label, $value));
        }

        return $normalized;
    }

    private function resourceKey(string $pluginHandle, string $resourceHandle): string
    {
        return sprintf('%s::%s', $pluginHandle, $resourceHandle);
    }
}
