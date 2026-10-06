<?php

namespace chaseburklund\entryporter\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\port\ResolverInterface;
use chaseburklund\entryporter\port\TransformerInterface;

/**
 * Ports simplemap Map fields (`ether\simplemap\fields\MapField`).
 *
 * The value is location data (coordinates, zoom, address and address parts) plus the IDs of
 * simplemap's map record and of the element and field it belongs to. Those IDs only mean
 * something on the source environment, so they are left out of the payload. simplemap ignores
 * them when it reads a value, and finds or creates the target's own record when the entry is
 * saved.
 *
 * The plugin is optional, so its field class is referenced only as a string.
 */
final class SimpleMapTransformer implements TransformerInterface
{
    /** Members that identify simplemap's record on the source environment. */
    private const SOURCE_IDS = ['id', 'ownerId', 'ownerSiteId', 'fieldId'];

    public function supports(string $fieldClass): bool
    {
        return $fieldClass === 'ether\\simplemap\\fields\\MapField';
    }

    public function export(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        return self::withoutSourceIds($value);
    }

    public function import(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        return self::withoutSourceIds($value);
    }

    private static function withoutSourceIds(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach (self::SOURCE_IDS as $key) {
            unset($value[$key]);
        }
        return $value;
    }
}
