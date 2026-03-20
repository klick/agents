<?php

namespace Klick\Agents\external;

interface ExternalResourceProviderInterface
{
    public function getPluginHandle(): string;

    public function getPluginName(): string;

    public function getPluginDescription(): string;

    public function isAvailable(): bool;

    /** @return ExternalResourceDefinition[] */
    public function getResourceDefinitions(): array;

    public function fetchResourceList(string $resourceHandle, array $queryParams): array;

    public function fetchResourceItem(string $resourceHandle, string $id, array $queryParams): ?array;
}
