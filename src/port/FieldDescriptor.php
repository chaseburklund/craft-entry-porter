<?php

namespace chaseburklund\entryporter\port;

final class FieldDescriptor
{
    public function __construct(
        public readonly string $class,
        public readonly string $handle,
        public readonly array $settings = [],
    ) {
    }
}
