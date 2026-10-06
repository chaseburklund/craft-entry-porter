<?php

namespace chaseburklund\entryporter\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\port\ResolverInterface;
use chaseburklund\entryporter\port\TransformerInterface;

final class FallbackTransformer implements TransformerInterface
{
    public function supports(string $fieldClass): bool
    {
        return true;
    }

    public function export(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        $report->warn("Field '{$field->handle}': unhandled field type {$field->class} copied verbatim — verify on the draft.");
        return $value;
    }

    public function import(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        return $value;
    }
}
