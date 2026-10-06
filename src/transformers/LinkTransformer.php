<?php

namespace chaseburklund\entryporter\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Ref;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\port\ResolverInterface;
use chaseburklund\entryporter\port\TransformerInterface;

/**
 * Ports Craft's core Link field (`craft\fields\Link`).
 *
 * The stored value is an array whose `value` member holds the link. For entry, asset and
 * category links, `value` is a reference tag such as `{entry:123@1:url}` containing the
 * source element's ID. On export the ID is replaced with a placeholder and the element is
 * described as a portable reference; on import the tag is rebuilt around the target's ID.
 * URL, email, phone and SMS links contain no ID and are copied unchanged.
 *
 * A linked element that cannot be described or resolved drops the link, with a warning,
 * rather than keeping an ID that would point at unrelated content on the target.
 */
final class LinkTransformer implements TransformerInterface
{
    private const MARKER = 'link';

    /** Stands in for the element ID inside the reference tag. */
    private const ID_PLACEHOLDER = '__PORTER_ID__';

    /**
     * An element link's stored value, matching the pattern Craft's element link types use to
     * recognize their own values. It is anchored, so a value that merely contains a tag is
     * not treated as an element link. The element type is matched case-insensitively, as
     * Craft does, and its original casing is kept.
     */
    private const ELEMENT_LINK_PATTERN = '/^\{(entry|asset|category):(\d+)((?:@\d+)?:url)\}$/i';

    public function supports(string $fieldClass): bool
    {
        return $fieldClass === 'craft\\fields\\Link';
    }

    public function export(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $link = $value['value'] ?? null;
        if (!is_string($link) || preg_match(self::ELEMENT_LINK_PATTERN, $link, $m) !== 1) {
            // Not an element link, so portable as it is.
            return $value;
        }

        $kindOriginal = $m[1];
        $kind = strtolower($kindOriginal);
        $id = (int)$m[2];
        $ref = $resolver->describeElement($kind, $id);
        if ($ref === null) {
            $report->warn("Field '{$field->handle}': could not describe the linked {$kind} #{$id}; the link was dropped rather than shipped with a source-environment id that would point at unrelated content on the target. The payload carries this field as empty, so importing it will clear whatever link the target currently has here — set it by hand on the draft.");
            return null;
        }

        $portable = $value;
        $portable['__portable'] = self::MARKER;
        $portable['value'] = '{' . $kindOriginal . ':' . self::ID_PLACEHOLDER . $m[3] . '}';
        $portable['ref'] = $ref;
        return $portable;
    }

    public function import(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        if (!is_array($value) || ($value['__portable'] ?? null) !== self::MARKER) {
            return $value;
        }

        // The envelope comes from the payload, so its members are type-checked before use.
        $template = $value['value'] ?? null;
        if (!is_string($template) || !str_contains($template, self::ID_PLACEHOLDER)) {
            $report->warn("Field '{$field->handle}': the portable link's 'value' is "
                . (is_string($template) ? 'missing its id placeholder' : 'a ' . get_debug_type($template) . ', not a string')
                . '; the link could not be rebuilt and no value was written, so the field was left empty.');
            return null;
        }
        $type = $value['type'] ?? null;
        if ($type !== null && !is_string($type)) {
            $report->warn("Field '{$field->handle}': the portable link's 'type' is a " . get_debug_type($type)
                . ', not a string; the link was dropped rather than handed to Craft in a shape its Link field cannot read.');
            return null;
        }

        $ref = $value['ref'] ?? null;
        if (!Ref::isRef($ref)) {
            $report->warn("Field '{$field->handle}': the portable link carries no usable element reference; the link was dropped and the field was left empty.");
            return null;
        }

        $id = $resolver->resolveRef($ref, $report);
        if ($id === null) {
            $kind = is_string($ref['kind'] ?? null) ? $ref['kind'] : 'element';
            $report->warn("Field '{$field->handle}': the linked {$kind} could not be resolved in the target; the link was dropped and the field was left empty rather than pointed at unrelated content.");
            return null;
        }

        $out = $value;
        unset($out['__portable'], $out['ref']);
        $out['value'] = str_replace(self::ID_PLACEHOLDER, (string)$id, $template);
        return $out;
    }
}
