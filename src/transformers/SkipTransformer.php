<?php

namespace chaseburklund\entryporter\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Registry;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\port\ResolverInterface;
use chaseburklund\entryporter\port\TransformerInterface;

/**
 * Leaves out field types whose values are derived data the target regenerates itself, such as
 * SEOmatic's optimized images. The import report notes that no action is needed.
 */
final class SkipTransformer implements TransformerInterface
{
    public function __construct(private readonly array $classes)
    {
    }

    public function supports(string $fieldClass): bool
    {
        return in_array($fieldClass, $this->classes, true);
    }

    public function export(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        return Registry::skip(Registry::SKIP_DERIVED);
    }

    public function import(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        return Registry::skip(Registry::SKIP_DERIVED);
    }
}
