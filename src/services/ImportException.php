<?php

namespace chaseburklund\entryporter\services;

/**
 * Thrown when an import cannot proceed: an invalid payload, a schema mismatch between the
 * environments, or a permission failure. The message is meant to be shown to the user as is.
 */
class ImportException extends \RuntimeException
{
}
