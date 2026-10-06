<?php

namespace chaseburklund\entryporter\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Ref;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\port\ResolverInterface;
use chaseburklund\entryporter\port\TransformerInterface;

/**
 * Ports Typed Link fields (`lenz\linkfield\fields\LinkField`).
 *
 * The stored value is a JSON string with `type`, `linkedId`, `linkedSiteId`, `linkedTitle`,
 * `linkedUrl` and `payload` members. `type` is a link type name: `entry`, `asset`, `category`
 * and `user` link to elements, while `url`, `email`, `tel`, `custom` and `site` do not. For
 * the non-element types, `linkedUrl` and `linkedSiteId` hold the link itself, so they are kept.
 * `payload` is a nested JSON string holding the link's text and target attributes.
 *
 * An element link's `linkedId` is replaced with a portable reference, and its other
 * source-specific members are dropped. Non-element links are copied unchanged.
 *
 * This field type is not cleared by a null value: Craft reloads the previously stored link.
 * So whenever a link cannot be exported or imported safely, this transformer returns an empty
 * array (CLEAR), which does clear it, and reports that it did. The target's previous link is
 * never left in place.
 *
 * The field class is referenced only as a string, since the plugin is optional.
 */
final class LenzLinkTransformer implements TransformerInterface
{
    private const MARKER = 'lenzlink';

    /** The value that clears a Typed Link field. */
    private const CLEAR = [];

    /**
     * Members of an element link that only make sense on the source environment: the element
     * ID, the site it was linked in, and the cached title and URL of that element.
     */
    private const SOURCE_SCOPED = ['linkedId', 'linkedSiteId', 'linkedTitle', 'linkedUrl'];

    public function supports(string $fieldClass): bool
    {
        return $fieldClass === 'lenz\\linkfield\\fields\\LinkField';
    }

    public function export(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        [$data, $error] = self::decode($value);
        if ($data === null) {
            $report->warn("Field '{$field->handle}': {$error}, so it could not be checked for a source-environment element id. The field is exported empty rather than copied blind; importing this payload will clear whatever link the target currently has here — set it by hand on the draft.");
            return self::CLEAR;
        }

        $id = self::elementId($data['linkedId'] ?? null);
        if ($id === null) {
            // Not linked to an element, so portable as it is.
            self::warnAboutSiteLink($field, $data, $report);
            return $value;
        }

        $kind = $data['type'] ?? null;
        $ref = is_string($kind) && $kind !== '' ? $resolver->describeElement($kind, $id) : null;
        if ($ref === null) {
            $kindLabel = is_string($kind) && $kind !== '' ? "{$kind} #{$id}" : "linked element #{$id}";
            $report->warn("Field '{$field->handle}': could not describe the {$kindLabel} in this Typed Link Field; the link was dropped rather than shipped with a source-environment id that would point at unrelated content on the target. The payload carries this field as empty, so importing it will clear whatever link the target currently has here — set it by hand on the draft.");
            return self::CLEAR;
        }

        $link = $data;
        $dropped = [];
        foreach (self::SOURCE_SCOPED as $member) {
            if ($member !== 'linkedId' && ($link[$member] ?? null) !== null) {
                $dropped[] = $member;
            }
            $link[$member] = null;
        }
        if ($dropped !== []) {
            $report->warn("Field '{$field->handle}': these members of the link describe the source install (the site the link was made in, and lenz's cache of that element's title and URL there) and were not copied: " . implode(', ', $dropped) . '. The link itself travels as a portable reference and lands on the right element; check it on the draft if this field has element caching turned on.');
        }

        return [
            '__portable' => self::MARKER,
            'link' => $link,
            'ref' => $ref,
        ];
    }

    public function import(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        if (!is_array($value) || ($value['__portable'] ?? null) !== self::MARKER) {
            return $this->importPlainValue($field, $value, $report);
        }

        // The envelope comes from the payload, so its members are type-checked before use.
        $link = $value['link'] ?? null;
        if (!is_array($link)) {
            $report->warn("Field '{$field->handle}': the portable Typed Link Field envelope carries no link object (its 'link' member is a " . get_debug_type($link) . '), so no link could be rebuilt; the field was cleared rather than left pointing at whatever the target already had.');
            return self::CLEAR;
        }
        if (!is_string($link['type'] ?? null)) {
            $report->warn("Field '{$field->handle}': the portable Typed Link Field envelope names no link type (its 'type' is a " . get_debug_type($link['type'] ?? null) . ', not a string), so no link could be rebuilt; the field was cleared rather than left pointing at whatever the target already had.');
            return self::CLEAR;
        }

        $ref = $value['ref'] ?? null;
        if (!Ref::isRef($ref)) {
            $report->warn("Field '{$field->handle}': the portable Typed Link Field carries no usable element reference; the field was cleared rather than left pointing at whatever the target already had.");
            return self::CLEAR;
        }

        $id = $resolver->resolveRef($ref, $report);
        if ($id === null) {
            $kind = is_string($ref['kind'] ?? null) ? $ref['kind'] : 'element';
            $report->warn("Field '{$field->handle}': the linked {$kind} could not be resolved in the target; the Typed Link Field was cleared rather than left pointing at unrelated content — set it by hand on the draft.");
            return self::CLEAR;
        }

        return $this->rebuild($field, $link, $id, $report);
    }

    /**
     * Imports a value that is not a portable envelope: a non-element link, an empty value, or
     * something written by hand. A raw `linkedId` is never written through.
     */
    private function importPlainValue(FieldDescriptor $field, mixed $value, Report $report): mixed
    {
        [$data, $error] = self::decode($value);
        if ($data === null) {
            $report->warn("Field '{$field->handle}': {$error}, so it could not be read as a Typed Link Field value; the field was cleared rather than left pointing at whatever the target already had.");
            return self::CLEAR;
        }
        if (self::elementId($data['linkedId'] ?? null) !== null) {
            $report->warn("Field '{$field->handle}': the payload carries a raw source-environment element id (linkedId) for this Typed Link Field with no portable reference beside it, so it could not be remapped. The field was cleared rather than pointed at whatever element holds that id here — set it by hand on the draft.");
            return self::CLEAR;
        }
        return $value;
    }

    /**
     * Rebuilds the link around the resolved local element ID, dropping any member the field
     * would fail to accept.
     */
    private function rebuild(FieldDescriptor $field, array $link, int $id, Report $report): array
    {
        $out = [];
        foreach ($link as $member => $stored) {
            if ($stored !== null && !is_scalar($stored)) {
                $report->warn("Field '{$field->handle}': the portable Typed Link Field's '{$member}' is a " . get_debug_type($stored) . ', not a scalar; that one attribute was dropped and the rest of the link was written.');
                continue;
            }
            $out[$member] = $stored;
        }

        // The field throws on save unless `payload` is a JSON object string.
        if (($out['payload'] ?? null) !== null && !is_array(json_decode((string)$out['payload'], true))) {
            $report->warn("Field '{$field->handle}': the portable Typed Link Field's 'payload' is not a JSON object, so the link's own text/target attributes were dropped; the link itself was written and points at the right element.");
            unset($out['payload']);
        }

        $out['linkedId'] = $id;
        $out['linkedSiteId'] = null;
        $out['linkedTitle'] = null;
        $out['linkedUrl'] = null;
        return $out;
    }

    /**
     * Site links are copied as they are, but site IDs differ between installs, so this warns.
     */
    private static function warnAboutSiteLink(FieldDescriptor $field, array $data, Report $report): void
    {
        if (($data['type'] ?? null) === 'site' && ($data['linkedSiteId'] ?? null) !== null) {
            $report->warn("Field '{$field->handle}': this Typed Link Field points at a SITE rather than an element, so its site id was copied as-is. Site ids are assigned per install; if the two environments' sites were not created in the same order this will point at a different site — check it on the draft.");
        }
    }

    /**
     * Decodes a stored value (a JSON string, or an array once it has been through a payload).
     *
     * @return array{0: array|null, 1: string|null} the decoded data, or null and a description
     *     of why it could not be decoded
     */
    private static function decode(mixed $value): array
    {
        if (is_array($value)) {
            return [$value, null];
        }
        if (!is_string($value)) {
            return [null, 'its value is a ' . get_debug_type($value) . ', not the JSON string or object a Typed Link Field holds'];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [null, 'its value is a string that does not decode to a JSON object'];
        }
        return [$decoded, null];
    }

    /**
     * The linked element ID, or null when no element is linked. Numeric strings are accepted,
     * since a payload can be edited by hand.
     */
    private static function elementId(mixed $linkedId): ?int
    {
        if (is_int($linkedId)) {
            return $linkedId > 0 ? $linkedId : null;
        }
        if (is_string($linkedId) && ctype_digit($linkedId)) {
            $id = (int)$linkedId;
            return $id > 0 ? $id : null;
        }
        return null;
    }
}
