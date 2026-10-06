<?php

namespace chaseburklund\entryporter\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Ref;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\port\ResolverInterface;
use chaseburklund\entryporter\port\TransformerInterface;

/**
 * Ports Hyper link fields (`verbb\hyper\fields\HyperField`).
 *
 * A Hyper value is a list of links, each with a `type` (the link class) and a `linkValue`.
 * For element links, `linkValue` is the linked element's ID, sometimes wrapped in a
 * single-item array; it is replaced with a portable reference on export and resolved back to
 * a local ID on import. Other link types (URL, email, phone and so on) are copied unchanged.
 *
 * All of Hyper's element link types are listed in ELEMENT_LINK_KINDS, including those for
 * Commerce, Calendar and Shopify. Element types the resolver does not support cannot be
 * described, so those links are cleared with a warning rather than copied with a source ID.
 *
 * `linkSiteId` is cleared. Custom fields nested inside a link are copied without remapping
 * their element references, with a warning.
 */
final class HyperTransformer implements TransformerInterface
{
    private const ELEMENT_LINK_KINDS = [
        'verbb\\hyper\\links\\Entry' => 'entry',
        'verbb\\hyper\\links\\Asset' => 'asset',
        'verbb\\hyper\\links\\Category' => 'category',
        'verbb\\hyper\\links\\User' => 'user',
        'verbb\\hyper\\links\\FormieForm' => 'form',
        'verbb\\hyper\\links\\CalendarEvent' => 'calendarEvent',
        'verbb\\hyper\\links\\Product' => 'product',
        'verbb\\hyper\\links\\ShopifyProduct' => 'shopifyProduct',
        'verbb\\hyper\\links\\Variant' => 'variant',
    ];

    public function supports(string $fieldClass): bool
    {
        return $fieldClass === 'verbb\\hyper\\fields\\HyperField';
    }

    public function export(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $link) {
            $out[] = $this->exportLink($field, $link, $resolver, $report);
        }
        return $out;
    }

    public function import(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $link) {
            $out[] = $this->importLink($field, $link, $resolver, $report);
        }
        return $out;
    }

    private function exportLink(FieldDescriptor $field, mixed $link, ResolverInterface $resolver, Report $report): mixed
    {
        if (!is_array($link)) {
            $report->warn("Field '{$field->handle}': kept a Hyper link that is not an array; copied verbatim.");
            return $link;
        }

        $kind = self::ELEMENT_LINK_KINDS[$link['type'] ?? ''] ?? null;
        if ($kind !== null) {
            [$id, $malformed] = self::extractElementId($link['linkValue'] ?? null);
            if ($malformed) {
                $report->warn("Field '{$field->handle}': Hyper {$kind} link has a linkValue that isn't a usable element ID; left as-is.");
            } elseif ($id !== null) {
                $ref = $resolver->describeElement($kind, $id);
                if ($ref === null) {
                    $report->warn("Field '{$field->handle}': could not describe {$kind} #{$id} in Hyper link; cleared.");
                    $link['linkValue'] = null;
                } else {
                    $link['linkValue'] = $ref;
                }
            }
        }

        // Site IDs are per install, and the link resolves in the entry's own site anyway.
        if (array_key_exists('linkSiteId', $link)) {
            $link['linkSiteId'] = null;
        }

        if (!empty($link['fields'])) {
            $report->warn("Field '{$field->handle}': Hyper link carries nested custom-field content that is copied verbatim; element references inside it are not remapped.");
        }

        return $link;
    }

    private function importLink(FieldDescriptor $field, mixed $link, ResolverInterface $resolver, Report $report): mixed
    {
        if (!is_array($link)) {
            $report->warn("Field '{$field->handle}': kept a Hyper link that is not an array; copied verbatim.");
            return $link;
        }

        if (Ref::isRef($link['linkValue'] ?? null)) {
            $link['linkValue'] = $resolver->resolveRef($link['linkValue'], $report);
        }

        return $link;
    }

    /**
     * Reads the element ID from an element link's `linkValue`, which may be a scalar or a
     * single-item array.
     *
     * @return array{0: int|null, 1: bool} the ID (null when nothing is selected), and whether
     *     the value was present but not a usable ID
     */
    private static function extractElementId(mixed $linkValue): array
    {
        if (is_array($linkValue)) {
            $linkValue = array_values($linkValue)[0] ?? null;
        }
        if ($linkValue === null || $linkValue === '') {
            return [null, false];
        }
        if (is_numeric($linkValue)) {
            return [(int)$linkValue, false];
        }
        return [null, true];
    }
}
