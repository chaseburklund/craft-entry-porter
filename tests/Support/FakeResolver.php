<?php

namespace chaseburklund\entryporter\tests\Support;

use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\port\ResolverInterface;

final class FakeResolver implements ResolverInterface
{
    /**
     * @param array $elements "kind:id" => ref array (export lookups)
     * @param array $resolutions uid => target element ID (import lookups)
     * @param array $entryTypeFields typeHandle => [fieldHandle => FieldDescriptor]
     * @param array $neoBlockFields "neoFieldHandle:blockTypeHandle" => [fieldHandle => FieldDescriptor]
     */
    public function __construct(
        public array $elements = [],
        public array $resolutions = [],
        public array $entryTypeFields = [],
        public array $neoBlockFields = [],
    ) {
    }

    public function describeElement(string $kind, int $id): ?array
    {
        return $this->elements["$kind:$id"] ?? null;
    }

    public function fieldsForEntryType(string $typeHandle): array
    {
        return $this->entryTypeFields[$typeHandle] ?? [];
    }

    public function fieldsForNeoBlockType(string $neoFieldHandle, string $blockTypeHandle): array
    {
        return $this->neoBlockFields["$neoFieldHandle:$blockTypeHandle"] ?? [];
    }

    public function resolveRef(array $ref, Report $report): ?int
    {
        $id = $this->resolutions[$ref['uid'] ?? ''] ?? null;
        if ($id === null) {
            $report->unresolved[] = $ref;
        }
        return $id;
    }
}
