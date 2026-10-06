<?php

namespace chaseburklund\entryporter\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\Json;
use craft\web\Controller;
use chaseburklund\entryporter\port\Registry;
use chaseburklund\entryporter\Plugin;
use chaseburklund\entryporter\services\ImportException;
use yii\base\InvalidArgumentException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Control panel endpoints for the CP buttons and the browser extension. Every action runs as
 * the requesting user, so the plugin's permissions and the importer's per-entry checks apply.
 *
 * Import bodies are JSON and are decoded from the raw request body rather than through
 * getBodyParams(). This keeps JSON types intact (the importer requires a real integer version
 * and a real boolean `enabled`), and avoids a TypeError in Craft's request handling when the
 * body is a bare JSON scalar such as `5`.
 */
class PorterController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();

        // Reject a JSON body that is not an object or array before CSRF validation runs.
        // CSRF validation reads the body through getBodyParams(), which throws a TypeError on
        // a bare JSON scalar, so without this check such a request fails with a 500 instead of
        // a 400. The check has no side effects and refuses only bodies that could never be
        // valid; login, CSRF and permission checks still run afterward as usual.
        $request = Craft::$app->getRequest();
        $isCsrfUnsafeMethod = !in_array($request->getMethod(), $request->csrfTokenSafeMethods, true);
        if ($isCsrfUnsafeMethod && self::hasJsonContentType($request->getContentType())) {
            $this->readJsonPayload();
        }

        return parent::beforeAction($action);
    }

    /**
     * Reports the plugin and payload versions, and what the current user may do, so callers
     * can decide what to offer before attempting an export or import.
     */
    public function actionPing(): Response
    {
        $this->requireGetRequest();
        $user = Craft::$app->getUser();
        return $this->asJson([
            'ok' => true,
            'plugin' => 'entry-porter',
            'version' => Plugin::getInstance()->getVersion(),
            // `payloadVersion` is the version this install writes, and
            // `acceptedPayloadVersions` the versions it can import. A caller can compare the
            // source's payloadVersion with the target's accepted versions before exporting.
            // Older installs omit `acceptedPayloadVersions`, which then means [payloadVersion].
            'payloadVersion' => Registry::PAYLOAD_VERSION,
            'acceptedPayloadVersions' => Registry::SUPPORTED_PAYLOAD_VERSIONS,
            'craft' => Craft::$app->getVersion(),
            'configTimestamp' => (int)(Craft::$app->getProjectConfig()->get('dateModified') ?? 0),
            'username' => $user->getIdentity()?->username,
            'canExport' => $user->checkPermission('entryPorter-export'),
            'canImport' => $user->checkPermission('entryPorter-import'),
        ]);
    }

    public function actionExport(): Response
    {
        $this->requireGetRequest();
        $this->requirePermission('entryPorter-export');
        $request = Craft::$app->getRequest();
        $entryId = (int)$request->getRequiredParam('entryId');
        $siteHandle = $request->getParam('site');
        $siteId = $request->getParam('siteId');

        // Export the canonical entry whatever its status, never a draft. An unpublished draft
        // has no canonical entry and so cannot be exported; the CP button is hidden for it.
        $query = Entry::find()->id($entryId)->status(null)->drafts(false)->provisionalDrafts(false);
        if ($siteHandle) {
            $query->site($siteHandle);
        } elseif ($siteId) {
            $query->siteId((int)$siteId);
        }
        $entry = $query->one();
        if ($entry === null) {
            throw new NotFoundHttpException("Entry #$entryId not found.");
        }
        if (!Craft::$app->getElements()->canView($entry, Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException('You are not allowed to view this entry.');
        }
        $result = Plugin::getInstance()->getExporter()->export($entry);
        return $this->asJson($result['payload'] + ['report' => $result['report']->toArray()]);
    }

    public function actionImport(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('entryPorter-import');
        $payload = $this->readJsonPayload();
        try {
            $result = Plugin::getInstance()->getImporter()->import($payload, Craft::$app->getUser()->getIdentity());
        } catch (ImportException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }
        return $this->asJson($result);
    }

    /**
     * Decodes the raw request body as JSON.
     *
     * @throws BadRequestHttpException if the body is not valid JSON or does not decode to an
     *     array (an empty body or a bare scalar such as `5`, `"x"` or `true`)
     */
    private function readJsonPayload(): array
    {
        $rawBody = (string)Craft::$app->getRequest()->getRawBody();
        try {
            $payload = Json::decode($rawBody);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException('Request body is not valid JSON: ' . $e->getMessage());
        }
        if (!is_array($payload)) {
            throw new BadRequestHttpException('Request body is not a Porter payload object.');
        }
        return $payload;
    }

    /**
     * Whether the content type is `application/json`, with or without parameters. This is the
     * content type Craft parses as JSON, and so the only one whose parsed body can be a scalar.
     */
    private static function hasJsonContentType(?string $contentType): bool
    {
        if ($contentType === null) {
            return false;
        }
        $stripped = strtolower(trim(explode(';', $contentType, 2)[0]));
        return $stripped === 'application/json';
    }

    private function requireGetRequest(): void
    {
        if (!Craft::$app->getRequest()->getIsGet()) {
            throw new MethodNotAllowedHttpException('GET request required');
        }
    }
}
