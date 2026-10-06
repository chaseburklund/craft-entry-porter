<?php

namespace chaseburklund\entryporter\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Registry;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\port\ResolverInterface;
use chaseburklund\entryporter\port\TransformerInterface;

/**
 * Ports Neo fields (`benf\neo\Field`).
 *
 * The value is a list of blocks, each with `type`, `enabled`, `level`, `fields` and
 * optionally `collapsed`. Neo rebuilds the hierarchy from block order and level, so levels are
 * passed through unchanged (they start at 1). Blocks are re-keyed `new1`, `new2` and so on so
 * they are always created fresh on the target, and block UIDs are never sent, so they cannot
 * collide with blocks that already exist there. Each nested field value is ported through the
 * registry using the block type's field layout.
 *
 * Neo silently drops a block with no valid type, so such blocks are reported and skipped.
 */
final class NeoTransformer implements TransformerInterface
{
    public function __construct(private readonly Registry $registry)
    {
    }

    public function supports(string $fieldClass): bool
    {
        return $fieldClass === 'benf\\neo\\Field';
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
                $report->warn("Field '{$field->handle}': skipped a Neo block that is not an array.");
                continue;
            }
            if (!isset($block['type'])) {
                $report->warn("Field '{$field->handle}': Neo block has no type handle; the target will drop it.");
                continue;
            }
            if (!is_string($block['type'])) {
                $report->warn("Field '{$field->handle}': Neo block's type handle is a " . get_debug_type($block['type'])
                    . ' rather than a string, so it could not be used; the block was dropped, as the target would have dropped it.');
                continue;
            }
            $out['new' . ++$n] = $this->portBlock($field, $block, $resolver, $report, $direction);
        }
        return $out;
    }

    private function portBlock(FieldDescriptor $field, array $block, ResolverInterface $resolver, Report $report, string $direction): array
    {
        // port() has already checked that the type is a string.
        $typeHandle = $block['type'];
        $descriptors = $resolver->fieldsForNeoBlockType($field->handle, $typeHandle);

        $blockFields = $block['fields'] ?? [];
        if (!is_array($blockFields)) {
            $report->warn("Field '{$field->handle}': Neo block's 'fields' is a " . get_debug_type($blockFields)
                . ' rather than an object of field handles; no nested field values were imported for this block.');
            $blockFields = [];
        }

        $fields = [];
        foreach ($blockFields as $handle => $fv) {
            $d = $descriptors[$handle] ?? null;
            if ($d === null) {
                $report->warn("Field '{$field->handle}': nested field '{$handle}' has no descriptor for Neo block type '{$typeHandle}'; copied verbatim.");
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
        $out = [
            'type' => $typeHandle,
            'enabled' => (bool)($block['enabled'] ?? true),
            'level' => (int)($block['level'] ?? 1),
            'fields' => $fields,
        ];
        if (array_key_exists('collapsed', $block)) {
            $out['collapsed'] = $block['collapsed'];
        }
        return $out;
    }
}
