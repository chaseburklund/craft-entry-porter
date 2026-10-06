<?php

namespace chaseburklund\entryporter\tests\Support;

use chaseburklund\entryporter\services\CraftResolver;

/**
 * CraftResolver with only the UID lookup replaced, since that is the one step of
 * resolveRef() that needs a running Craft app. Everything else is the real code.
 */
final class RecordingResolver extends CraftResolver
{
    /** @var array<int, array{0: string, 1: string|null}> every (uid, elementType) pair asked for */
    public array $uidLookups = [];

    /** What the stand-in lookup returns; null means "no canonical element holds this UID here". */
    public ?int $idForUid = null;

    protected function lookupCanonicalIdByUid(string $uid, ?string $elementType): ?int
    {
        $this->uidLookups[] = [$uid, $elementType];
        return $this->idForUid;
    }
}
