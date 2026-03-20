<?php

namespace Klick\Agents\external;

final class ExternalResourceParameterDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $type = 'string',
        public readonly string $description = '',
        public readonly bool $required = false,
        public readonly ?string $format = null,
        public readonly array $enum = [],
        public readonly ?int $minimum = null,
        public readonly ?int $maximum = null
    ) {
    }

    public function toOpenApiParameter(): array
    {
        $schema = ['type' => $this->type];
        if ($this->format !== null && $this->format !== '') {
            $schema['format'] = $this->format;
        }
        if ($this->enum !== []) {
            $schema['enum'] = array_values($this->enum);
        }
        if ($this->minimum !== null) {
            $schema['minimum'] = $this->minimum;
        }
        if ($this->maximum !== null) {
            $schema['maximum'] = $this->maximum;
        }

        $parameter = [
            'in' => 'query',
            'name' => $this->name,
            'required' => $this->required,
            'schema' => $schema,
        ];
        if ($this->description !== '') {
            $parameter['description'] = $this->description;
        }

        return $parameter;
    }

    public function toSchemaProperty(): array
    {
        $property = ['type' => $this->type];
        if ($this->format !== null && $this->format !== '') {
            $property['format'] = $this->format;
        }
        if ($this->enum !== []) {
            $property['enum'] = array_values($this->enum);
        }
        if ($this->minimum !== null) {
            $property['minimum'] = $this->minimum;
        }
        if ($this->maximum !== null) {
            $property['maximum'] = $this->maximum;
        }

        return $property;
    }
}
