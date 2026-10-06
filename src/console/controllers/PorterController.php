<?php

namespace chaseburklund\entryporter\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Json;
use chaseburklund\entryporter\Plugin;
use chaseburklund\entryporter\services\ImportException;
use yii\base\InvalidArgumentException;
use yii\console\ExitCode;

class PorterController extends Controller
{
    /** Export an entry as Porter JSON: craft entry-porter/porter/export-entry <entryId> [siteHandle] */
    public function actionExportEntry(int $entryId, ?string $siteHandle = null): int
    {
        $query = Entry::find()->id($entryId)->status(null)->drafts(false)->provisionalDrafts(false);
        if ($siteHandle !== null) {
            $query->site($siteHandle);
        }
        $entry = $query->one();
        if ($entry === null) {
            $this->stderr("Entry #$entryId not found.\n");
            return ExitCode::DATAERR;
        }
        $result = Plugin::getInstance()->getExporter()->export($entry);
        $this->stdout(Json::encode($result['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        foreach ($result['report']->warnings as $w) {
            $this->stderr("WARN: $w\n");
        }
        return ExitCode::OK;
    }

    /**
     * Import a Porter JSON file as a draft: craft entry-porter/porter/import-file <path>
     *
     * Runs as the first admin user, so permission checks always pass.
     */
    public function actionImportFile(string $path): int
    {
        if (!is_file($path)) {
            $this->stderr("File not found: $path\n");
            return ExitCode::DATAERR;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->stderr("Could not read: $path\n");
            return ExitCode::DATAERR;
        }

        try {
            $payload = Json::decode($contents);
        } catch (InvalidArgumentException $e) {
            $this->stderr('IMPORT ERROR: ' . $path . ' is not valid JSON: ' . $e->getMessage() . "\n");
            return ExitCode::DATAERR;
        }
        if (!is_array($payload)) {
            $this->stderr("IMPORT ERROR: $path does not contain a Porter payload object.\n");
            return ExitCode::DATAERR;
        }

        $admin = User::find()->admin()->status(null)->orderBy('id ASC')->one();
        if ($admin === null) {
            $this->stderr("No admin user found.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        try {
            $result = Plugin::getInstance()->getImporter()->import($payload, $admin);
        } catch (ImportException $e) {
            $this->stderr('IMPORT ERROR: ' . $e->getMessage() . "\n");
            return ExitCode::DATAERR;
        }

        $this->stdout(Json::encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        foreach ($result['report']['warnings'] as $w) {
            $this->stderr("WARN: $w\n");
        }
        return ExitCode::OK;
    }
}
