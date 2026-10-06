<?php

namespace chaseburklund\entryporter\port;

final class Ref
{
    public static function make(string $kind, string $uid, array $keys, array $extras = []): array
    {
        return array_merge([
            '__portable' => 'ref',
            'kind' => $kind,
            'uid' => $uid,
            'keys' => $keys,
        ], $extras);
    }

    public static function isRef(mixed $v): bool
    {
        return is_array($v) && ($v['__portable'] ?? null) === 'ref' && isset($v['kind'], $v['uid']);
    }
}
