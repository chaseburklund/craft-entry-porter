<?php

namespace chaseburklund\entryporter\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Ref;
use chaseburklund\entryporter\port\Registry;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\port\ResolverInterface;
use chaseburklund\entryporter\port\TransformerInterface;

/**
 * Ports relation fields. Each related element ID becomes a portable reference on export and
 * is resolved to a local ID on import; references that cannot be resolved are dropped.
 */
final class RelationTransformer implements TransformerInterface
{
    /**
     * Field class => reference kind. Each kind must be supported by CraftResolver in both
     * directions.
     */
    private const KINDS = [
        'craft\\fields\\Entries' => 'entry',
        'craft\\fields\\Assets' => 'asset',
        'craft\\fields\\Categories' => 'category',
        'craft\\fields\\Tags' => 'tag',
        'craft\\fields\\Users' => 'user',
        'verbb\\formie\\fields\\Forms' => 'form',
    ];

    public function supports(string $fieldClass): bool
    {
        return isset(self::KINDS[$fieldClass]);
    }

    public function export(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $kind = self::KINDS[$field->class];
        $refs = [];
        foreach ($value as $id) {
            if (!is_numeric($id)) {
                continue;
            }
            $ref = $resolver->describeElement($kind, (int)$id);
            if ($ref === null) {
                $report->warn("Field '{$field->handle}': could not describe {$kind} #{$id}; reference dropped.");
                continue;
            }
            $refs[] = $ref;
        }
        return $refs;
    }

    public function import(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $ids = [];
        foreach ($value as $ref) {
            if (!Ref::isRef($ref)) {
                continue;
            }
            $id = $resolver->resolveRef($ref, $report);
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        return $ids;
    }
}
