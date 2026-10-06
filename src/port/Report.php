<?php

namespace chaseburklund\entryporter\port;

final class Report
{
    /** @var string[] */
    public array $warnings = [];
    /** @var array[] refs that could not be resolved on import */
    public array $unresolved = [];
    /** @var array[] entries: ['ref' => array, 'how' => string] */
    public array $resolvedByFallback = [];

    public function warn(string $message): void
    {
        if (!in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }

    public function toArray(): array
    {
        return [
            'warnings' => $this->warnings,
            'unresolved' => $this->unresolved,
            'resolvedByFallback' => $this->resolvedByFallback,
        ];
    }
}
