<?php

namespace chaseburklund\entryporter\port;

interface TransformerInterface
{
    public function supports(string $fieldClass): bool;

    /** Serialized Craft value -> portable value (IDs become refs). */
    public function export(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed;

    /** Portable value -> Craft-importable serialized value (refs become target IDs). */
    public function import(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed;
}
