<?php

namespace chaseburklund\entryporter\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\port\ResolverInterface;
use chaseburklund\entryporter\port\TransformerInterface;

final class SeoSettingsTransformer implements TransformerInterface
{
    public function supports(string $fieldClass): bool
    {
        return $fieldClass === 'nystudio107\\seomatic\\fields\\SeoSettings';
    }

    public function export(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        $report->warn("Field '{$field->handle}': SEOmatic settings copied verbatim — review embedded IDs/URLs in the draft.");
        return $value;
    }

    public function import(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        return $value;
    }
}
