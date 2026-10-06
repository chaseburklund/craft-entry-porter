<?php

namespace chaseburklund\entryporter\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\User;
use craft\errors\ElementNotFoundException;
use craft\errors\InvalidElementException;
use craft\errors\UnsupportedSiteException;
use craft\helpers\DateTimeHelper;
use craft\models\Section;
use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Registry;
use chaseburklund\entryporter\port\Report;
use yii\base\InvalidConfigException;

/**
 * Lands a payload produced by Exporter on the target environment.
 *
 * An import never publishes and never modifies a canonical entry. Content always lands as a
 * draft, by one of three paths:
 *
 *  - No element with the payload's UID exists: a new entry is saved as an unpublished draft.
 *  - A canonical entry with the payload's UID exists: a new draft of it is created and the
 *    payload's content is staged on that draft. The canonical entry is only ever read.
 *  - An unpublished draft from an earlier import of the same entry is still pending: the
 *    payload is staged onto that draft, so pushing the same entry twice before it is applied
 *    does not create a second element.
 *
 * The path taken is returned as `path`.
 *
 * On the update path, createDraft() already persists the draft, so the saveElement() that
 * follows is a second save. It is kept deliberately: saving a draft creates no revision, and
 * folding the payload into createDraft()'s attributes would validate the imported field
 * values only under the essentials scenario, which skips custom-field validation.
 */
class Importer
{
    /** A new element was created and saved as an unpublished draft. */
    public const PATH_CREATED = 'created';

    /** The payload was staged onto an unpublished draft left by an earlier import of this entry. */
    public const PATH_PENDING_DRAFT = 'updated-pending-draft';

    /** A canonical entry matched by UID; the payload was staged onto a new draft of it. */
    public const PATH_NEW_DRAFT_OF_CANONICAL = 'new-draft-of-canonical';

    /**
     * Keys the payload's `entry` must contain, mapped to their required type. A wrong-typed
     * value would otherwise reach a typed Craft API and fail with a TypeError instead of a
     * readable ImportException.
     */
    private const REQUIRED_ENTRY_KEYS = [
        'uid' => 'string',
        'sectionHandle' => 'string',
        'typeHandle' => 'string',
        'siteHandle' => 'string',
        'fields' => 'fieldsObject',
    ];

    /**
     * Keys that may be absent but must have the right type when present.
     *
     * `enabled` must be a real boolean: casting would read `"enabled": "no"` as true on the
     * attribute that governs publication. `postDate` must be a string because
     * DateTimeHelper::toDateTime() passes some array shapes to string-typed parsers. The
     * nested `parent.kind` is checked separately in validatePayload().
     */
    private const OPTIONAL_ENTRY_KEYS = [
        'title' => 'string',
        'slug' => 'string',
        'enabled' => 'bool',
        'postDate' => 'string',
        'parent' => 'refObject',
    ];

    public function __construct(
        private readonly Registry $registry,
        private readonly CraftResolver $resolver,
    ) {
    }

    /**
     * Structural validation of a payload, with no Craft calls.
     *
     * Presence is tested with isset(), so a key that is present but null counts as missing.
     * That matters for payloads exported from an entry whose entry type had been deleted:
     * Exporter emits a null `typeHandle`, and this reports it as the source-side problem it is
     * rather than as a configuration mismatch on the target.
     *
     * @return string[] error messages; empty means valid
     */
    public static function validatePayload(array $payload): array
    {
        $errors = [];

        // The version must be a real integer from the supported set. A cast would accept
        // "1", 1.9 and true, none of which an exported payload can contain.
        $version = $payload['porter']['version'] ?? null;
        if (!is_int($version) || !in_array($version, Registry::SUPPORTED_PAYLOAD_VERSIONS, true)) {
            // Name the accepted versions, so the operator can tell whether re-exporting from
            // the source or upgrading this install is the fix.
            $errors[] = 'Unsupported payload version (this install accepts '
                . implode(', ', Registry::SUPPORTED_PAYLOAD_VERSIONS)
                . ', as a JSON integer).';
        }

        // Check the envelope members that import() reads.
        $source = $payload['porter']['source'] ?? null;
        if ($source !== null && !is_array($source)) {
            $errors[] = "'porter.source' must be an object.";
        } elseif (is_array($source)) {
            foreach (['origin', 'fieldLayoutUid'] as $key) {
                if (isset($source[$key]) && !is_string($source[$key])) {
                    $errors[] = "'porter.source.$key' must be a string.";
                }
            }
            if (isset($source['configTimestamp']) && !is_int($source['configTimestamp'])) {
                $errors[] = "'porter.source.configTimestamp' must be an integer.";
            }
        }

        $entry = $payload['entry'] ?? null;
        if (!is_array($entry)) {
            $errors[] = "Missing 'entry' object.";
            return $errors;
        }

        foreach (self::REQUIRED_ENTRY_KEYS as $key => $expected) {
            if (!isset($entry[$key])) {
                $errors[] = "Missing 'entry.$key'.";
                continue;
            }
            if (!self::isOfType($entry[$key], $expected)) {
                $errors[] = self::typeError($key, $expected);
            }
        }

        foreach (self::OPTIONAL_ENTRY_KEYS as $key => $expected) {
            if (isset($entry[$key]) && !self::isOfType($entry[$key], $expected)) {
                $errors[] = self::typeError($key, $expected);
            }
        }

        // `parent.kind` is the one nested value that reaches a string operation.
        if (isset($entry['parent']) && is_array($entry['parent'])
            && isset($entry['parent']['kind']) && !is_string($entry['parent']['kind'])) {
            $errors[] = "'entry.parent.kind' must be a string.";
        }

        return $errors;
    }

    /**
     * Checks that the element an update is about to write into has the payload's section and
     * entry type.
     *
     * Both update lookups match on UID and site only. If the target element's entry type
     * differs from the payload's, Craft accepts setFieldValues() but persists only the fields
     * in the element's own layout, silently dropping the rest. That is refused rather than
     * warned, and the message names the fields that would have been lost.
     *
     * @param string[] $payloadHandles field handles the payload carries
     * @param string[] $targetLayoutHandles field handles in the target element's own layout
     * @return string|null null when the target matches
     */
    public static function targetParityError(
        ?string $targetSectionHandle,
        ?string $targetTypeHandle,
        string $payloadSectionHandle,
        string $payloadTypeHandle,
        array $payloadHandles,
        array $targetLayoutHandles,
    ): ?string {
        $sectionDiffers = $targetSectionHandle !== $payloadSectionHandle;
        $typeDiffers = $targetTypeHandle !== $payloadTypeHandle;
        if (!$sectionDiffers && !$typeDiffers) {
            return null;
        }

        // A nested entry (owned by a Matrix or Neo field) has no section.
        $shownSection = $targetSectionHandle === null
            ? 'none (a nested entry, owned by a field rather than by a section)'
            : "'{$targetSectionHandle}'";
        $shownType = $targetTypeHandle === null ? 'unresolvable' : "'{$targetTypeHandle}'";

        $mismatch = [];
        if ($sectionDiffers) {
            $mismatch[] = "it is in section {$shownSection}, but the payload describes an entry in section '{$payloadSectionHandle}'";
        }
        if ($typeDiffers) {
            $mismatch[] = "it is of entry type {$shownType}, but the payload's field values were validated against entry type '{$payloadTypeHandle}'";
        }

        // Name the fields, since they are what the operator needs to act on.
        $lost = array_values(array_diff($payloadHandles, $targetLayoutHandles));
        $consequence = $lost === []
            ? ' Every field handle in the payload does happen to exist in the target element\'s layout, so some values would survive — but the entry would still be the wrong one to write into, and Craft would keep its own type either way.'
            : ' Writing anyway would silently discard these payload fields, because Craft persists content from the target element\'s own field layout: ' . implode(', ', $lost) . '.';

        return 'The entry this payload targets already exists here with a different shape: '
            . implode('; and ', $mismatch) . '.' . $consequence
            . ' Resolve it on the target (change that entry\'s type, or fix config parity between the two environments), then re-import.';
    }

    /**
     * Runs a single save call and turns a field type's rejection of a payload value into an
     * ImportException.
     *
     * Craft normalizes field values lazily, during the save, through each field type's
     * normalizeValue(). A malformed value therefore fails inside Craft rather than in this
     * plugin: as a TypeError, an ErrorException, or, for Craft's Link field with an
     * unregistered link type, a yii\base\InvalidArgumentException. Field types are an open set,
     * so these cannot all be validated up front.
     *
     * The wrapped region is deliberately a single vendor save call with no plugin code inside
     * it, so a bug in this plugin is never reported as a payload problem. Only those three
     * classes are caught; other errors, database exceptions and ImportExceptions pass through
     * unchanged. yii\base\InvalidArgumentException is unrelated to PHP's
     * \InvalidArgumentException, which is intentionally not caught. Registering a
     * save-lifecycle event handler in this plugin would put plugin code inside the region, so
     * revisit this if that ever happens.
     *
     * @template T
     * @param string[] $handles the field handles this import set values for
     * @param callable(): T $save
     * @return T
     * @throws ImportException
     */
    public static function convertingFieldValueFailures(array $handles, callable $save): mixed
    {
        try {
            return $save();
        // Fully qualified so it cannot be confused with PHP's \InvalidArgumentException.
        } catch (\TypeError | \ErrorException | \yii\base\InvalidArgumentException $e) {
            throw new ImportException(self::fieldValueFailureMessage($e, $handles), 0, $e);
        }
    }

    /**
     * Which field rejected the value cannot be recovered at this point, so the message lists
     * the fields this import set and includes the underlying error.
     */
    private static function fieldValueFailureMessage(\Throwable $e, array $handles): string
    {
        $scope = $handles === []
            ? 'This import set no field values at all, so the value at fault is one of the entry\'s own attributes rather than a custom field.'
            : 'Fields this import set values for: ' . implode(', ', $handles) . '.';

        // Assets auto-created during the import are saved outside the transaction, so the
        // message only claims that no entry or draft was written.
        return 'A value in the payload is one this entry\'s own fields could not accept, so no entry or draft was written. '
            . 'This usually means a field value has the wrong shape for its field type -- a hand-edited payload, or a '
            . 'field whose type differs between the two environments. Which field it was is not recoverable here: the '
            . 'failure happens inside Craft\'s own normalisation during the save, after the value has left this plugin. '
            . $scope
            . ' Underlying error: ' . get_debug_type($e) . ': ' . $e->getMessage();
    }

    private static function isOfType(mixed $value, string $expected): bool
    {
        return match ($expected) {
            'fieldsObject', 'refObject' => is_array($value),
            'bool' => is_bool($value),
            default => is_string($value),
        };
    }

    private static function typeError(string $key, string $expected): string
    {
        return match ($expected) {
            'fieldsObject' => "'entry.$key' must be an object of field handles.",
            'refObject' => "'entry.$key' must be an object.",
            'bool' => "'entry.$key' must be true or false.",
            default => "'entry.$key' must be a string.",
        };
    }

    /**
     * Warnings for fields in the target layout that the payload carried no value for.
     *
     * A field can be absent for different reasons, and the operator should know which: the
     * source could not port its field type, the source left it out because it is data each
     * environment derives for itself, or the source layout simply lacks it. The reason comes
     * from the payload's `porter.stripped` map. Only known reason tokens are used and the
     * handles named come from this install's layout, so no payload text reaches the report.
     * Anything unrecognized is treated as plainly absent.
     *
     * @param string[] $layoutHandles every custom-field handle in the target entry type
     * @param string[] $payloadHandles the handles the payload carried a value for
     * @param mixed $stripped `porter.stripped`, raw: handle => reason token
     * @return string[] zero to three warnings
     */
    public static function absentFieldWarnings(array $layoutHandles, array $payloadHandles, mixed $stripped = null): array
    {
        $absent = array_values(array_diff($layoutHandles, $payloadHandles));
        if ($absent === []) {
            return [];
        }

        $reasons = is_array($stripped) ? $stripped : [];
        $unportable = [];
        $derived = [];
        $merelyAbsent = [];
        foreach ($absent as $handle) {
            $reason = $reasons[$handle] ?? null;
            match ($reason) {
                Registry::SKIP_UNPORTABLE => $unportable[] = $handle,
                Registry::SKIP_DERIVED => $derived[] = $handle,
                default => $merelyAbsent[] = $handle,
            };
        }

        $warnings = [];
        if ($unportable !== []) {
            $warnings[] = 'These fields were REMOVED from the copy by the source, because its version of Entry Porter cannot port their field type — so they are not necessarily empty on the source, and nothing here reflects what they hold there. Nothing was written for them, so the draft keeps whatever the target already had (a new entry gets their defaults); set them by hand: '
                . implode(', ', $unportable) . '.';
        }
        if ($derived !== []) {
            $warnings[] = 'These fields were left out of the copy by the source on purpose, as data this environment derives for itself rather than content to be copied. Nothing was written for them, so the draft keeps this environment\'s own values — which is the intended outcome, not something to fix: '
                . implode(', ', $derived) . '.';
        }
        if ($merelyAbsent !== []) {
            $warnings[] = 'These fields exist in this layout but carried no value in the payload, so the draft keeps whatever the target already had for them (a new entry gets their defaults): '
                . implode(', ', $merelyAbsent) . '.';
        }
        return $warnings;
    }

    /**
     * @return array{entryId: int, draftId: int|null, draftElementId: int|null, cpEditUrl: string, created: bool, path: string, report: array}
     * @throws ImportException on validation, config-parity, permission, or save failure
     */
    public function import(array $payload, User $importer): array
    {
        $errors = self::validatePayload($payload);
        if ($errors !== []) {
            throw new ImportException(implode(' ', $errors));
        }
        $data = $payload['entry'];
        $report = new Report();

        // --- Parity checks -------------------------------------------------
        $entriesService = Craft::$app->getEntries();
        $section = $entriesService->getSectionByHandle($data['sectionHandle']);
        if ($section === null) {
            throw new ImportException("Section '{$data['sectionHandle']}' does not exist here — config parity broken?");
        }
        $type = $entriesService->getEntryTypeByHandle($data['typeHandle']);
        if ($type === null) {
            throw new ImportException("Entry type '{$data['typeHandle']}' does not exist here — config parity broken?");
        }
        $site = Craft::$app->getSites()->getSiteByHandle($data['siteHandle']) ?? Craft::$app->getSites()->getPrimarySite();
        if ($site->handle !== $data['siteHandle']) {
            $report->warn("Site '{$data['siteHandle']}' not found; using primary site '{$site->handle}'.");
        }

        // Warn when the two environments' project config differs. A source timestamp of 0
        // means the source could not determine it.
        $sourceConfigTimestamp = $payload['porter']['source']['configTimestamp'] ?? null;
        $sourceConfigTimestamp = is_int($sourceConfigTimestamp) ? $sourceConfigTimestamp : 0;
        $localConfigTimestamp = (int)(Craft::$app->getProjectConfig()->get('dateModified') ?? 0);
        if ($sourceConfigTimestamp === 0) {
            $report->warn('The payload declares no source project-config timestamp, so config parity with the source could not be compared; review the draft carefully.');
        } elseif ($sourceConfigTimestamp !== $localConfigTimestamp) {
            $report->warn("Project config differs: the source's config was last modified at {$sourceConfigTimestamp}, this environment's at {$localConfigTimestamp}. The two environments may not be running the same project config; review the draft carefully.");
        }

        $fieldLayout = $type->getFieldLayout();
        // Type-checked rather than cast, since the value comes from the payload.
        $sourceLayoutUid = $payload['porter']['source']['fieldLayoutUid'] ?? null;
        $sourceLayoutUid = is_string($sourceLayoutUid) ? $sourceLayoutUid : '';
        if ($sourceLayoutUid !== '' && $sourceLayoutUid !== (string)$fieldLayout->uid) {
            $report->warn('Field layout UID differs from source — schemas may be out of parity; review the draft carefully.');
        }

        $layoutFields = [];
        foreach ($fieldLayout->getCustomFields() as $field) {
            $layoutFields[$field->handle] = new FieldDescriptor(get_class($field), $field->handle);
        }
        foreach (array_keys($data['fields']) as $handle) {
            if (!isset($layoutFields[$handle])) {
                throw new ImportException("Field '{$handle}' is not in the '{$data['typeHandle']}' layout here — config parity broken?");
            }
        }
        // A source layout that is a subset of this one is legitimate, but the operator should
        // know which fields received no value, and why.
        foreach (self::absentFieldWarnings(
            array_keys($layoutFields),
            array_keys($data['fields']),
            $payload['porter']['stripped'] ?? null,
        ) as $warning) {
            $report->warn($warning);
        }

        // --- Resolver state ------------------------------------------------
        // The resolver is shared with the exporter, so these import-specific settings are
        // restored afterwards, including when the import fails. The site scopes natural-key
        // lookups, the source origin is used to report assets fetched from a different host,
        // and the importing user is checked for volume permissions before an asset is created.
        $previousSiteId = $this->resolver->siteId;
        $previousSourceOrigin = $this->resolver->sourceOrigin;
        $previousImportingUser = $this->resolver->importingUser;
        $origin = $payload['porter']['source']['origin'] ?? null;
        $origin = (is_string($origin) && $origin !== '') ? $origin : null;
        $this->resolver->siteId = $site->id;
        $this->resolver->sourceOrigin = $origin;
        $this->resolver->importingUser = $importer;

        try {
            return $this->write($data, $section, $type->id, $site->id, $layoutFields, $importer, $origin, $report);
        } finally {
            $this->resolver->siteId = $previousSiteId;
            $this->resolver->sourceOrigin = $previousSourceOrigin;
            $this->resolver->importingUser = $previousImportingUser;
        }
    }

    /**
     * Finds the target, checks permissions, transforms the payload's field values, and lands
     * the result as a draft, in that order. Transforming values can create assets, so it must
     * not happen before authorization.
     *
     * @param array<string, FieldDescriptor> $layoutFields
     * @return array{entryId: int, draftId: int|null, draftElementId: int|null, cpEditUrl: string, created: bool, path: string, report: array}
     */
    private function write(
        array $data,
        Section $section,
        int $typeId,
        int $siteId,
        array $layoutFields,
        User $importer,
        ?string $origin,
        Report $report,
    ): array {
        $notes = 'Imported from ' . ($origin ?? 'unknown');
        $elements = Craft::$app->getElements();
        $sectionId = $section->id;

        // Canonical entries only, so createDraft() is never handed a draft or revision.
        $existing = Entry::find()
            ->uid($data['uid'])
            ->status(null)
            ->drafts(false)
            ->provisionalDrafts(false)
            ->siteId($siteId)
            ->one();

        // With no canonical match, the UID may belong to an unpublished draft left by an
        // earlier import of this entry. Updating it keeps re-pushes idempotent. Only
        // unpublished drafts are considered: a draft of a canonical entry is someone's
        // in-progress work and must not be overwritten.
        $pendingDraft = $existing !== null ? null : Entry::find()
            ->uid($data['uid'])
            ->status(null)
            ->drafts(true)
            ->draftOf(false)
            ->provisionalDrafts(false)
            ->siteId($siteId)
            ->one();
        // Re-check what the query guarantees, since everything below depends on it.
        if ($pendingDraft !== null && !$pendingDraft->getIsUnpublishedDraft()) {
            throw new ImportException("The element holding UID '{$data['uid']}' here is not an unpublished draft; refusing to write to it.");
        }

        // --- Permissions ---------------------------------------------------
        // Checked before anything with side effects, including transforming field values,
        // which can create assets. On the update path the canonical entry is checked rather
        // than the new draft: createDraft() saves the draft, and a draft's creator can always
        // save it, so checking the draft afterwards would be both too late and always true.
        if ($existing !== null) {
            if (!$elements->canView($existing, $importer)) {
                throw new ImportException('You do not have permission to view the existing entry this payload targets.');
            }
            if (!$elements->canSave($existing, $importer)) {
                throw new ImportException('You do not have permission to save entries in this section.');
            }
        }

        // A pending draft keeps its original creator, so canSave() is a real check here.
        if ($pendingDraft !== null) {
            if (!$elements->canView($pendingDraft, $importer)) {
                throw new ImportException('You do not have permission to view the pending imported draft this payload targets.');
            }
            if (!$elements->canSave($pendingDraft, $importer)) {
                throw new ImportException('You do not have permission to save the pending imported draft this payload targets.');
            }
        }

        // --- Target shape --------------------------------------------------
        // The lookups matched on UID and site only, so make sure the element found has the
        // payload's section and type before writing into it. This runs after the permission
        // checks, so it reveals nothing to an unauthorized user, and before any writes.
        $updateTarget = $existing ?? $pendingDraft;
        if ($updateTarget !== null) {
            $parityError = self::targetParityError(
                self::sectionHandleOf($updateTarget),
                self::typeHandleOf($updateTarget),
                $data['sectionHandle'],
                $data['typeHandle'],
                array_keys($data['fields']),
                array_map(
                    static fn($field): string => $field->handle,
                    $updateTarget->getFieldLayout()?->getCustomFields() ?? [],
                ),
            );
            if ($parityError !== null) {
                throw new ImportException($parityError);
            }
        }

        // A Single's entry is created by Craft from the section definition, so an import
        // cannot create one. Say so directly rather than letting canSave() fail with a
        // permissions message.
        $isNew = $existing === null && $pendingDraft === null;
        if ($isNew && $section->type === Section::TYPE_SINGLE) {
            throw new ImportException("Section '{$section->handle}' is a Single, whose one entry Craft creates and maintains from the section definition — an import cannot create a new entry in it. No entry here matches this payload's UID '{$data['uid']}', which usually means the Single's entry exists here with a different UID (it was created by this environment's own config, not copied from the source). Export and import cannot introduce a Single; sync the two environments' project config instead.");
        }

        // canSave() needs the element to exist; buildNewEntry() sets attributes only and
        // writes nothing.
        $newEntry = $isNew
            ? $this->buildNewEntry($data, $sectionId, $typeId, $siteId, $report)
            : null;
        if ($newEntry !== null && !$elements->canSave($newEntry, $importer)) {
            throw new ImportException('You do not have permission to create entries in this section.');
        }

        // --- Transform field values (refs -> local IDs) --------------------
        $values = [];
        foreach ($data['fields'] as $handle => $portable) {
            $ported = $this->registry->import($layoutFields[$handle], $portable, $this->resolver, $report);
            if (Registry::isSkip($ported)) {
                // The transformer declined to write this field, so the target keeps its
                // current value.
                $report->warn("Field '{$handle}': its transformer declined to write this field on import; no value was set for it, so the draft keeps the target's current value (a new entry gets the field's default).");
                continue;
            }
            $values[$handle] = $ported;
        }

        // --- Land it as a draft --------------------------------------------
        // Each path runs in a transaction, so a failed save cannot leave a half-written draft
        // behind. Auto-created assets stay outside it, since their files are already on disk.
        // The field handles are passed along so a field-value failure can name them.
        $handles = array_keys($values);
        if ($newEntry !== null) {
            $this->applyParent($newEntry, $data, $sectionId, $report);
            $newEntry->setFieldValues($values);
            $target = $this->inTransaction(fn() => $this->createAsDraft($newEntry, $importer, $notes, $handles));
            $path = self::PATH_CREATED;
        } elseif ($pendingDraft !== null) {
            $target = $this->inTransaction(fn() => $this->updatePendingDraft($pendingDraft, $data, $values, $notes, $report));
            $path = self::PATH_PENDING_DRAFT;
        } else {
            /** @var Entry $existing */
            $target = $this->inTransaction(fn() => $this->updateViaDraft($existing, $data, $values, $importer, $notes, $report));
            $path = self::PATH_NEW_DRAFT_OF_CANONICAL;
        }

        return [
            // The canonical entry's id when there is one, otherwise the draft's own id. The
            // draft element's id is reported separately.
            'entryId' => (int)$target->getCanonicalId(),
            'draftId' => $target->draftId,
            'draftElementId' => $target->id,
            'cpEditUrl' => (string)$target->getCpEditUrl(),
            // True only when a new element was created; `path` distinguishes the three cases.
            'created' => $path === self::PATH_CREATED,
            'path' => $path,
            'report' => $report->toArray(),
        ];
    }

    /**
     * The entry's type handle, or null if its entry type no longer exists.
     */
    private static function typeHandleOf(Entry $entry): ?string
    {
        try {
            return $entry->getType()->handle;
        } catch (InvalidConfigException) {
            return null;
        }
    }

    /**
     * The entry's section handle, or null for a nested entry (which has no section) or an
     * entry whose section no longer exists.
     */
    private static function sectionHandleOf(Entry $entry): ?string
    {
        try {
            return $entry->getSection()?->handle;
        } catch (InvalidConfigException) {
            return null;
        }
    }

    /**
     * Runs $fn inside a database transaction, rolling back if it throws.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function inTransaction(callable $fn): mixed
    {
        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $result = $fn();
            $transaction->commit();
            return $result;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Builds, but does not save, the new entry for the create path. Field values and the
     * structure parent are applied after the permission check, because resolving them has
     * side effects.
     */
    private function buildNewEntry(array $data, int $sectionId, int $typeId, int $siteId, Report $report): Entry
    {
        $entry = new Entry();
        $entry->sectionId = $sectionId;
        $entry->setTypeId($typeId);
        $entry->siteId = $siteId;
        $entry->title = $data['title'] ?? null;
        $entry->slug = $data['slug'] ?? null;
        // A payload that omits `enabled` produces a disabled entry.
        $entry->enabled = $data['enabled'] ?? false;

        // Keep the source's UID so a later push of the same entry matches this one. Skip it if
        // any element here already holds that UID, including trashed ones, since Craft does
        // not enforce UID uniqueness.
        $uidTaken = (new Query())->from(Table::ELEMENTS)->where(['uid' => $data['uid']])->exists();
        if ($uidTaken) {
            $report->warn("Entry UID '{$data['uid']}' is already held by another element here that this import could not target — most often an entry or pending draft that exists on this environment but is not propagated to the '{$data['siteHandle']}' site, or a trashed entry that still owns the UID. To avoid two elements sharing one UID, this import's draft was given a locally generated UID instead, so a later re-push will again create a new draft rather than updating this one. Resolve the collision (propagate, restore, or hard-delete the holder), then re-import, to restore UID matching.");
        } else {
            $entry->uid = $data['uid'];
        }

        if (!empty($data['postDate'])) {
            $entry->postDate = DateTimeHelper::toDateTime($data['postDate']) ?: null;
        }

        return $entry;
    }

    /**
     * Resolves the payload's parent reference and positions the new entry under it. Any case
     * that leaves the entry at the section root is reported.
     */
    private function applyParent(Entry $entry, array $data, int $sectionId, Report $report): void
    {
        $parentRef = $data['parent'] ?? null;
        if (!is_array($parentRef)) {
            return;
        }

        // Only entry references are accepted as a parent, so a malformed payload cannot make
        // another element type the parent.
        $kind = $parentRef['kind'] ?? null;
        if ($kind !== 'entry') {
            $shown = is_string($kind) ? "'{$kind}'" : get_debug_type($kind);
            $report->warn("Payload's parent reference is not an entry reference (kind {$shown}); it was ignored and the imported draft was placed at the section root.");
            return;
        }

        $parentId = $this->resolver->resolveRef($parentRef, $report);
        if ($parentId === null) {
            // resolveRef() has already recorded the unresolved reference.
            $report->warn('Parent entry could not be resolved here; the imported draft was placed at the section root instead.');
            return;
        }

        // A parent in another section is not a valid structure position.
        $parent = Entry::find()->id($parentId)->status(null)->drafts(false)->provisionalDrafts(false)->siteId($entry->siteId)->one();
        if ($parent === null || $parent->sectionId !== $sectionId) {
            $report->warn('Parent entry resolved to an element outside this section (or not present in this site); the imported draft was placed at the section root instead.');
            return;
        }

        $entry->setParentId($parentId);
    }

    /**
     * Create path: the element is a draft from its first write.
     *
     * @param string[] $handles field handles this import set values for
     */
    private function createAsDraft(Entry $entry, User $importer, string $notes, array $handles): Entry
    {
        // A validation failure returns false, but the save can also throw.
        try {
            $saved = self::convertingFieldValueFailures(
                $handles,
                fn() => Craft::$app->getDrafts()->saveElementAsDraft($entry, $importer->id, 'Entry Porter import', $notes),
            );
        } catch (InvalidElementException | UnsupportedSiteException | ElementNotFoundException $e) {
            throw new ImportException('Import save failed: ' . $e->getMessage(), 0, $e);
        }
        if (!$saved) {
            throw new ImportException('Import save failed: ' . implode('; ', $entry->getErrorSummary(true)));
        }
        return $entry;
    }

    /**
     * Stages the payload onto an unpublished draft left by an earlier import of this entry.
     *
     * Unlike updateViaDraft(), this applies the slug and post date: the draft has never been
     * published, so there is no live URL or publication order to disturb.
     *
     * @param array<string, mixed> $values
     */
    private function updatePendingDraft(Entry $pending, array $data, array $values, string $notes, Report $report): Entry
    {
        // Update the draft's notes to name this import's origin.
        if ($pending->getBehavior('draft') !== null) {
            $pending->draftNotes = $notes;
        }

        $pending->title = $data['title'] ?? $pending->title;
        $slug = $data['slug'] ?? null;
        if (is_string($slug) && $slug !== '') {
            $pending->slug = $slug;
        }
        // A payload that omits `enabled` keeps the draft's current value.
        $pending->enabled = $data['enabled'] ?? $pending->enabled;
        if (!empty($data['postDate'])) {
            $pending->postDate = DateTimeHelper::toDateTime($data['postDate']) ?: $pending->postDate;
        }
        $pending->setFieldValues($values);

        $report->warn("An unpublished draft from an earlier import of this entry was already pending here, so this import updated that draft in place rather than creating a second one. Review and apply draft #{$pending->id}.");

        if (is_array($data['parent'] ?? null)) {
            $report->warn('The payload declares a structure parent; this import updated an existing pending draft and did not move it, so its position in the structure is unchanged.');
        }

        try {
            $saved = self::convertingFieldValueFailures(
                array_keys($values),
                fn() => Craft::$app->getElements()->saveElement($pending),
            );
        } catch (InvalidElementException | UnsupportedSiteException | ElementNotFoundException $e) {
            throw new ImportException('Pending draft save failed: ' . $e->getMessage(), 0, $e);
        }
        if (!$saved) {
            throw new ImportException('Pending draft save failed: ' . implode('; ', $pending->getErrorSummary(true)));
        }
        return $pending;
    }

    /**
     * Stages the payload onto a new draft of the canonical entry. $existing is only read and
     * passed to createDraft(); it is never modified or saved.
     *
     * @param array<string, mixed> $values
     */
    private function updateViaDraft(Entry $existing, array $data, array $values, User $importer, string $notes, Report $report): Entry
    {
        // createDraft() throws on failure. It is not wrapped in the field-value handler,
        // because it normalizes the canonical entry's existing values rather than the
        // payload's.
        try {
            $draft = Craft::$app->getDrafts()->createDraft($existing, $importer->id, 'Entry Porter import', $notes);
        } catch (InvalidElementException | UnsupportedSiteException | ElementNotFoundException $e) {
            throw new ImportException("Could not create a draft of entry #{$existing->id}: " . $e->getMessage(), 0, $e);
        }

        $draft->title = $data['title'] ?? $draft->title;
        // A payload that omits `enabled` keeps the entry's current value, so a disabled entry
        // is never enabled by omission.
        $draft->enabled = $data['enabled'] ?? $draft->enabled;
        $draft->setFieldValues($values);

        // An import does not change an existing entry's slug, post date or structure parent,
        // since those affect live URLs, publication order and structure. Differences are
        // reported so the operator can apply them by hand. A declared parent is always
        // reported, since comparing it would mean resolving the reference.
        $unapplied = [];
        $slug = $data['slug'] ?? null;
        if (is_string($slug) && $slug !== '' && $slug !== (string)$existing->slug) {
            $unapplied[] = "slug ('{$slug}' here would replace '{$existing->slug}')";
        }
        if (!empty($data['postDate'])) {
            $payloadPostDate = DateTimeHelper::toDateTime($data['postDate']) ?: null;
            if ($payloadPostDate !== null && $existing->postDate?->getTimestamp() !== $payloadPostDate->getTimestamp()) {
                $unapplied[] = 'postDate';
            }
        }
        if ($unapplied !== []) {
            $report->warn('The payload carries a different ' . implode(' and ', $unapplied)
                . ', which an update import does not change on an existing entry; the draft keeps the entry\'s current value. Adjust it by hand if the copy is meant to include it.');
        }
        if (is_array($data['parent'] ?? null)) {
            $report->warn('The payload declares a structure parent; an update import does not move an existing entry, so this entry\'s position in the structure is unchanged.');
        }

        try {
            $saved = self::convertingFieldValueFailures(
                array_keys($values),
                fn() => Craft::$app->getElements()->saveElement($draft),
            );
        } catch (InvalidElementException | UnsupportedSiteException | ElementNotFoundException $e) {
            throw new ImportException('Draft save failed: ' . $e->getMessage(), 0, $e);
        }
        if (!$saved) {
            throw new ImportException('Draft save failed: ' . implode('; ', $draft->getErrorSummary(true)));
        }
        return $draft;
    }
}
