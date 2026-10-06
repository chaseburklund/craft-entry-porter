<?php

namespace chaseburklund\entryporter\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\port\ResolverInterface;
use chaseburklund\entryporter\port\TransformerInterface;

final class KnownSafeTransformer implements TransformerInterface
{
    private const CLASSES = [
        'craft\\fields\\PlainText', 'craft\\fields\\Lightswitch', 'craft\\fields\\Dropdown',
        'craft\\fields\\RadioButtons', 'craft\\fields\\Checkboxes', 'craft\\fields\\MultiSelect',
        'craft\\fields\\Number', 'craft\\fields\\Email', 'craft\\fields\\Url', 'craft\\fields\\Color',
        'craft\\fields\\Date', 'craft\\fields\\Time', 'craft\\fields\\Money', 'craft\\fields\\Table',
        'craft\\fields\\Country', 'craft\\fields\\ButtonGroup', 'craft\\fields\\Icon',
    ];

    public function supports(string $fieldClass): bool
    {
        return in_array($fieldClass, self::CLASSES, true);
    }

    public function export(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        return $value;
    }

    public function import(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        return $value;
    }
}
