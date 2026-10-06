<?php

namespace chaseburklund\entryporter\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Registry;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\port\ResolverInterface;
use chaseburklund\entryporter\port\TransformerInterface;

/**
 * Refuses field types known to hold element IDs that cannot be remapped.
 *
 * Unlike FallbackTransformer, which copies unknown field types verbatim, this leaves the
 * field out entirely: a copied ID would point at an unrelated element on the target. On
 * export the field is omitted from the payload; on import nothing is written, so the draft
 * keeps the target's existing value. Either way the report names the field so it can be set
 * by hand.
 */
final class UnportableTransformer implements TransformerInterface
{
    /**
     * @param array<string, string> $classes field class => description of the field type, used
     *     in the report
     */
    public function __construct(private readonly array $classes = [])
    {
    }

    public function supports(string $fieldClass): bool
    {
        return isset($this->classes[$fieldClass]);
    }

    public function export(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        $report->warn($this->reason($field) . ' It was left out of this copy entirely rather than exported with an id that would link to unrelated content on the target; set it by hand on the draft.');
        return Registry::skip(Registry::SKIP_UNPORTABLE);
    }

    public function import(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        $report->warn($this->reason($field) . ' Nothing was written for it, so the draft keeps whatever the target already had (a new entry gets the field\'s default); set it by hand.');
        return Registry::skip(Registry::SKIP_UNPORTABLE);
    }

    private function reason(FieldDescriptor $field): string
    {
        return "Field '{$field->handle}' is " . ($this->classes[$field->class] ?? 'a field type this version cannot port') . '.';
    }
}
