<?php

namespace chaseburklund\entryporter\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Ref;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\port\ResolverInterface;
use chaseburklund\entryporter\port\TransformerInterface;

/**
 * Ports rich-text fields built on `craft\htmlfield\HtmlField`: CKEditor and Redactor.
 *
 * Both store raw HTML containing Craft reference tags such as `{entry:123@1:url||fallback}`.
 * A tag's numeric ID is only meaningful on the environment that wrote it; copied verbatim, it
 * would resolve to whatever element holds that ID on the target. On export each ID-based tag
 * is replaced with a placeholder and a portable reference; on import the placeholder is
 * replaced with the target's ID.
 *
 * The tag pattern follows Craft's own grammar (`Elements::REF_TAG_PATTERN`): the site may be
 * an ID, handle or UUID; the attribute and the `||` fallback are independent and optional; the
 * attribute contains no spaces; and the element type is matched case-insensitively. Tags that
 * reference an element by slug rather than ID are already portable and are left alone.
 *
 * A tag whose element cannot be described on export, or resolved on import, is removed with a
 * warning rather than kept, so it cannot link to unrelated content. CKEditor nested entry
 * cards (`<craft-entry>`) are not supported and are removed with a warning.
 *
 * Both editor plugins are optional, so their classes are referenced only as strings.
 */
final class HtmlFieldTransformer implements TransformerInterface
{
    /**
     * Identifies this transformer's portable value. Payload version 1 used LEGACY_MARKER, and
     * since the change cannot be read by older installs, it is one reason the payload version
     * was raised to 2 (see Registry::PAYLOAD_VERSION).
     */
    private const MARKER = 'htmlfield';

    /** The marker written by payload version 1. Accepted on import, never written. */
    private const LEGACY_MARKER = 'ckeditor';

    /** The supported field classes, mapped to the editor name used in messages. */
    private const FIELD_CLASSES = [
        'craft\\ckeditor\\Field' => 'CKEditor',
        'craft\\redactor\\Field' => 'Redactor',
    ];

    /** ID-based reference tags for the element types that can be ported. */
    private const TAG_PATTERN = '/\{(entry|asset|category|tag|user):(\d+)((?:@[^:}|]+)?(?::[^}| ]+)?(?:\ *\|\|\ *[^}]+)?)\}/i';

    private const CARD_PATTERN = '/<craft-entry\b[^>]*>(?:.*?<\/craft-entry\s*>)?/si';

    private const CARD_ID_PATTERN = '/data-entry-id=["\']?(\d+)/i';

    public function supports(string $fieldClass): bool
    {
        return isset(self::FIELD_CLASSES[$fieldClass]);
    }

    /** The editor name for messages, falling back to the field class. */
    private static function editorName(FieldDescriptor $field): string
    {
        return self::FIELD_CLASSES[$field->class] ?? $field->class;
    }

    public function export(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $editor = self::editorName($field);
        $markup = $this->stripEntryCards($field, $value, $report);

        $refs = [];
        $i = 0;
        $rewritten = preg_replace_callback(
            self::TAG_PATTERN,
            function (array $m) use (&$refs, &$i, $field, $editor, $resolver, $report) {
                $kindOriginal = $m[1];
                $kind = strtolower($kindOriginal);
                $id = (int)$m[2];
                $ref = $resolver->describeElement($kind, $id);
                if ($ref === null) {
                    $report->warn("Field '{$field->handle}': could not describe {$kind} #{$id} referenced in {$editor} content; reference tag removed.");
                    return '';
                }
                $key = 'ref' . ++$i;
                $refs[$key] = $ref;
                return '{' . $kindOriginal . ':__PORTER_' . $key . '__' . $m[3] . '}';
            },
            $markup,
        );
        if ($rewritten === null) {
            $report->warn("Field '{$field->handle}': {$editor} reference tags could not be processed (regex engine error); left unchanged.");
        } else {
            $markup = $rewritten;
        }

        if ($refs === [] && $markup === $value) {
            return $value;
        }

        return ['__portable' => self::MARKER, 'markup' => $markup, 'refs' => $refs];
    }

    public function import(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        if (!is_array($value) || !in_array($value['__portable'] ?? null, [self::MARKER, self::LEGACY_MARKER], true)) {
            return $value;
        }
        $editor = self::editorName($field);

        // The payload may have been edited by hand, so `markup` is type-checked rather than
        // cast: casting an array raises a warning, and casting another scalar would silently
        // become the field's content.
        $markup = $value['markup'] ?? null;
        if (!is_string($markup)) {
            if ($markup !== null) {
                $report->warn("Field '{$field->handle}': {$editor} portable value's 'markup' is a "
                    . get_debug_type($markup) . ", not a string; nothing could be imported for this field, so it was left empty.");
            }
            $markup = '';
        }
        $refs = $value['refs'] ?? [];
        if (!is_array($refs)) {
            $report->warn("Field '{$field->handle}': {$editor} portable value has a malformed 'refs' entry (not an array); no reference tags could be resolved.");
            $refs = [];
        }

        foreach ($refs as $key => $ref) {
            if (!Ref::isRef($ref)) {
                $report->warn("Field '{$field->handle}': {$editor} reference '{$key}' in portable value is malformed; reference tag removed.");
                $markup = $this->removePlaceholderTag($field, $markup, (string)$key, $report);
                continue;
            }
            $id = $resolver->resolveRef($ref, $report);
            if ($id !== null) {
                $markup = str_replace('__PORTER_' . $key . '__', (string)$id, $markup);
            } else {
                // The reference may have been rejected as malformed, so its kind and UID are
                // not assumed to be strings.
                $kind = is_string($ref['kind'] ?? null) ? $ref['kind'] : 'element';
                $uid = is_string($ref['uid'] ?? null) ? $ref['uid'] : '?';
                $report->warn("Field '{$field->handle}': {$kind} reference (uid {$uid}) in {$editor} content could not be resolved in the target; reference tag removed.");
                $markup = $this->removePlaceholderTag($field, $markup, (string)$key, $report);
            }
        }

        return $markup;
    }

    /**
     * Removes the placeholder tag for one reference key. Any element type is matched, so the
     * removal does not need updating when TAG_PATTERN gains a type.
     */
    private function removePlaceholderTag(FieldDescriptor $field, string $markup, string $key, Report $report): string
    {
        $editor = self::editorName($field);
        $result = preg_replace(
            '/\{[^{}:]+:__PORTER_' . preg_quote($key, '/') . '__[^}]*\}/',
            '',
            $markup,
        );
        if ($result === null) {
            $report->warn("Field '{$field->handle}': {$editor} reference tag for '{$key}' could not be removed (regex engine error); left unchanged.");
            return $markup;
        }
        return $result;
    }

    /**
     * Removes CKEditor nested entry cards, with one warning per entry ID.
     */
    private function stripEntryCards(FieldDescriptor $field, string $markup, Report $report): string
    {
        if (stripos($markup, '<craft-entry') === false) {
            return $markup;
        }
        $editor = self::editorName($field);

        $result = preg_replace_callback(
            self::CARD_PATTERN,
            function (array $m) use ($field, $editor, $report) {
                $id = 'unknown';
                if (preg_match(self::CARD_ID_PATTERN, $m[0], $idMatch) === 1) {
                    $id = $idMatch[1];
                }
                $report->warn("Field '{$field->handle}': {$editor} nested entry card for entry #{$id} is not supported in v1 and was removed.");
                return '';
            },
            $markup,
        );

        if ($result === null) {
            $report->warn("Field '{$field->handle}': {$editor} entry-card markup could not be processed (regex engine error); left unchanged.");
            return $markup;
        }

        return $result;
    }
}
