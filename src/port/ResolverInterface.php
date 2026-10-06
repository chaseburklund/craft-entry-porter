<?php

namespace chaseburklund\entryporter\port;

interface ResolverInterface
{
    /** Export: describe a related element as a portable ref array (Ref::make), or null. */
    public function describeElement(string $kind, int $id): ?array;

    /** Descriptors (fieldHandle => FieldDescriptor) for a nested entry type (Matrix / Super Table). */
    public function fieldsForEntryType(string $typeHandle): array;

    /** Descriptors for a Neo block type belonging to a Neo field. */
    public function fieldsForNeoBlockType(string $neoFieldHandle, string $blockTypeHandle): array;

    /** Import: resolve a portable ref to a target-local element ID, recording outcome in $report. */
    public function resolveRef(array $ref, Report $report): ?int;
}
