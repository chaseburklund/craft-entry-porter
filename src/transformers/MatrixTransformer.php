<?php

namespace chaseburklund\entryporter\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Registry;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\port\ResolverInterface;
use chaseburklund\entryporter\port\TransformerInterface;

/**
 * Ports Matrix and Super Table fields. Super Table 4 extends Matrix and stores the same shape.
 *
 * The value is a map of nested entries (blocks), each with `type`, `enabled`, `fields` and
 * optionally `title`, `slug` and `collapsed`. Blocks are re-keyed `new1`, `new2` and so on,
 * Craft's own keys for unsaved nested entries, so they are always created fresh on the
 * target. Each nested field value is ported through the registry using the block type's field
 * layout.
 *
 * Craft silently drops a block with no valid type, so a missing type is reported.
 */
final class MatrixTransformer implements TransformerInterface
{
    private const CLASSES = [
        'craft\\fields\\Matrix',
        'verbb\\supertable\\fields\\SuperTableField',
    ];

    public function __construct(private readonly Registry $registry)
    {
    }

    public function supports(string $fieldClass): bool
    {
        return in_array($fieldClass, self::CLASSES, true);
    }

    public function export(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        return $this->port($field, $value, $resolver, $report, 'export');
    }

    public function import(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        return $this->port($field, $value, $resolver, $report, 'import');
    }

    private function port(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report, string $direction): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        $n = 0;
        foreach ($value as $block) {
            if (!is_array($block)) {
                $report->warn("Field '{$field->handle}': skipped a malformed (non-array) block.");
                continue;
            }
            $out['new' . ++$n] = $this->portBlock($field, $block, $resolver, $report, $direction);
        }
        return $out;
    }

    private function portBlock(FieldDescriptor $field, array $block, ResolverInterface $resolver, Report $report, string $direction): array
    {
        // The block comes from the payload, so its members are type-checked before use. An
        // unusable type handle is treated like a missing one: either way the target drops the
        // block, so a single warning says which it was.
        $typeHandle = $block['type'] ?? null;
        if ($typeHandle !== null && !is_string($typeHandle)) {
            $report->warn("Field '{$field->handle}': block's type handle is a " . get_debug_type($typeHandle)
                . ' rather than a string, so it could not be used; the target will drop this block.');
            $typeHandle = null;
        } elseif ($typeHandle === null) {
            $report->warn("Field '{$field->handle}': block has no type handle; the target will drop it.");
        }
        $descriptors = $typeHandle !== null ? $resolver->fieldsForEntryType($typeHandle) : [];

        $blockFields = $block['fields'] ?? [];
        if (!is_array($blockFields)) {
            $report->warn("Field '{$field->handle}': block's 'fields' is a " . get_debug_type($blockFields)
                . ' rather than an object of field handles; no nested field values were imported for this block.');
            $blockFields = [];
        }

        $fields = [];
        foreach ($blockFields as $handle => $fv) {
            $d = $descriptors[$handle] ?? null;
            if ($d === null) {
                $report->warn($typeHandle === null
                    ? "Field '{$field->handle}': nested field '{$handle}' could not be routed because the block has no type handle; copied verbatim."
                    : "Field '{$field->handle}': nested field '{$handle}' has no descriptor for type '{$typeHandle}'; copied verbatim.");
                $fields[$handle] = $fv;
                continue;
            }
            $ported = $direction === 'export'
                ? $this->registry->export($d, $fv, $resolver, $report)
                : $this->registry->import($d, $fv, $resolver, $report);
            if (Registry::isSkip($ported)) {
                continue;
            }
            $fields[$handle] = $ported;
        }
        $out = ['enabled' => (bool)($block['enabled'] ?? true), 'fields' => $fields];
        if ($typeHandle !== null) {
            $out['type'] = $typeHandle;
        }
        // Craft assigns `title` and `slug` to nullable string properties, so any other type
        // would throw during the save.
        foreach (['title', 'slug'] as $k) {
            if (!array_key_exists($k, $block)) {
                continue;
            }
            if ($block[$k] !== null && !is_string($block[$k])) {
                $report->warn("Field '{$field->handle}': block's '{$k}' is a " . get_debug_type($block[$k])
                    . ' rather than a string; it was dropped, so the block takes the target default for it.');
                continue;
            }
            $out[$k] = $block[$k];
        }
        // Craft reads `collapsed` with empty(), so any value is safe.
        if (array_key_exists('collapsed', $block)) {
            $out['collapsed'] = $block['collapsed'];
        }
        return $out;
    }
}
