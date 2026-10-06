<?php

namespace chaseburklund\entryporter\services;

use Craft;
use craft\elements\Entry;
use craft\helpers\App;
use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Registry;
use chaseburklund\entryporter\port\Report;
use yii\base\InvalidConfigException;

/**
 * Builds a portable payload from an entry: its attributes, its field values converted by the
 * registry's transformers, and a report of anything that could not be carried across.
 */
class Exporter
{
    public function __construct(
        private readonly Registry $registry,
        private readonly CraftResolver $resolver,
    ) {
    }

    /** @return array{payload: array, report: Report} */
    public function export(Entry $entry): array
    {
        // Export the canonical entry, never a draft.
        $entry = $entry->getCanonical();
        $report = new Report();

        // Describe related elements in the entry's own site. The resolver is shared, so its
        // previous site is restored afterward.
        $previousSiteId = $this->resolver->siteId;
        $this->resolver->siteId = $entry->siteId;
        try {
            return $this->buildPayload($entry, $report);
        } finally {
            $this->resolver->siteId = $previousSiteId;
        }
    }

    /** @return array{payload: array, report: Report} */
    private function buildPayload(Entry $entry, Report $report): array
    {
        // getType() throws if the entry's type has been deleted. The payload is still built,
        // without fields, and the importer will refuse it.
        $type = null;
        try {
            $type = $entry->getType();
        } catch (InvalidConfigException $e) {
            $report->warn("Entry #{$entry->id}: its entry type could not be resolved ({$e->getMessage()}); exported with no fields and a null typeHandle and fieldLayoutUid, so this payload is incomplete and will be refused on import.");
        }
        $fieldLayout = $type?->getFieldLayout();

        $fields = [];
        $stripped = [];
        foreach ($fieldLayout?->getCustomFields() ?? [] as $field) {
            $descriptor = new FieldDescriptor(get_class($field), $field->handle);
            $serialized = $field->serializeValue($entry->getFieldValue($field->handle), $entry);
            $ported = $this->registry->export($descriptor, $serialized, $this->resolver, $report);
            if (Registry::isSkip($ported)) {
                $report->warn("Field '{$field->handle}': its transformer skipped this field; it is not included in the export.");
                // Record why the field was left out, so the import report can explain it
                // rather than suggesting the source field was empty.
                $reason = Registry::skipReason($ported);
                if ($reason !== null) {
                    $stripped[$field->handle] = $reason;
                }
                continue;
            }
            $fields[$field->handle] = $ported;
        }

        $parent = null;
        $parentId = $entry->getParentId();
        if ($parentId !== null) {
            $parent = $this->resolver->describeElement('entry', $parentId);
            if ($parent === null) {
                $report->warn("Parent entry #{$parentId} could not be described; copy will land at section root.");
            }
        }

        $payload = [
            'porter' => [
                'version' => Registry::PAYLOAD_VERSION,
                'source' => [
                    'origin' => rtrim((string)(App::env('BACKEND_URL') ?? ''), '/'),
                    'craft' => Craft::$app->getVersion(),
                    'configTimestamp' => (int)(Craft::$app->getProjectConfig()->get('dateModified') ?? 0),
                    // Null, like typeHandle, when the entry type could not be resolved.
                    'fieldLayoutUid' => $fieldLayout?->uid,
                ],
                // `stripped` (field handle => reason) is added below only when a field was
                // skipped. Older versions ignore it, so it does not require a new payload
                // version. Only top-level fields are listed.
            ],
            'entry' => [
                'uid' => $entry->uid,
                'sectionHandle' => $entry->getSection()?->handle,
                'typeHandle' => $type?->handle,
                'siteHandle' => $entry->getSite()->handle,
                'title' => (string)$entry->title,
                'slug' => (string)$entry->slug,
                'enabled' => (bool)$entry->enabled,
                'postDate' => $entry->postDate?->format(DATE_ATOM),
                'parent' => $parent,
                'fields' => $fields,
            ],
        ];
        if ($stripped !== []) {
            $payload['porter']['stripped'] = $stripped;
        }

        return ['payload' => $payload, 'report' => $report];
    }
}
