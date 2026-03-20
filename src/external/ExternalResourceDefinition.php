<?php

namespace Klick\Agents\external;

final class ExternalResourceDefinition
{
    /** @param ExternalResourceParameterDefinition[] $queryParameters */
    public function __construct(
        public readonly string $handle,
        public readonly string $name,
        public readonly string $description,
        public readonly array $queryParameters,
        public readonly array $listItemSchema,
        public readonly ?array $detailSchema = null,
        public readonly string $itemIdDescription = 'Resource identifier'
    ) {
    }

    public function scopeKey(string $pluginHandle): string
    {
        return sprintf('plugins:%s:%s:read', $pluginHandle, $this->handle);
    }

    public function listPath(string $pluginHandle): string
    {
        return sprintf('/plugins/%s/%s', $pluginHandle, $this->handle);
    }

    public function detailPath(string $pluginHandle): string
    {
        return sprintf('/plugins/%s/%s/{id}', $pluginHandle, $this->handle);
    }

    public function listSchemaKey(string $pluginHandle): string
    {
        return sprintf('plugins.%s.%s.list', $pluginHandle, $this->handle);
    }

    public function detailSchemaKey(string $pluginHandle): string
    {
        return sprintf('plugins.%s.%s.show', $pluginHandle, $this->handle);
    }

    public function supportsDetail(): bool
    {
        return $this->detailSchema !== null;
    }

    public function querySchema(): array
    {
        $properties = [];
        foreach ($this->queryParameters as $parameter) {
            $properties[$parameter->name] = $parameter->toSchemaProperty();
        }

        return [
            'type' => 'object',
            'properties' => $properties,
        ];
    }

    public function listResponseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'service' => ['type' => 'string'],
                'version' => ['type' => 'string'],
                'provider' => ['type' => 'object'],
                'data' => [
                    'type' => 'array',
                    'items' => $this->listItemSchema,
                ],
                'meta' => ['type' => 'object'],
                'page' => ['type' => 'object'],
            ],
        ];
    }

    public function detailResponseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'service' => ['type' => 'string'],
                'version' => ['type' => 'string'],
                'provider' => ['type' => 'object'],
                'data' => $this->detailSchema ?? $this->listItemSchema,
                'meta' => ['type' => 'object'],
            ],
        ];
    }
}
