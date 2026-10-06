<?php

namespace chaseburklund\entryporter\services;

use Craft;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\Db;
use craft\models\Volume;
use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Ref;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\port\ResolverInterface;

/**
 * Translates between local element IDs and portable references.
 *
 * On export it describes a related element as a reference carrying its UID plus natural keys
 * (such as section, type and slug). On import it resolves a reference back to a local ID: by
 * UID first, then by natural keys, and for assets, optionally by downloading the file from the
 * source environment.
 */
class CraftResolver implements ResolverInterface
{
    /** Maximum size of an auto-created asset's download, in bytes. */
    private const MAX_ASSET_DOWNLOAD_BYTES = 100 * 1024 * 1024;

    /** Time budget for an asset download, in seconds. See downloadToFile(). */
    private const DOWNLOAD_TIMEOUT_SECONDS = 30;

    /**
     * The element class for each reference kind. Passing it to Craft's element lookups avoids
     * an extra type query, and makes a reference whose UID belongs to a different element
     * type resolve to null rather than to an element of the wrong type.
     *
     * Formie is optional, so its class is a string; Craft returns null for a class that does
     * not exist.
     */
    private const KIND_ELEMENT_CLASSES = [
        'entry' => Entry::class,
        'asset' => Asset::class,
        'category' => Category::class,
        'tag' => Tag::class,
        'user' => User::class,
        'form' => 'verbb\\formie\\elements\\Form',
    ];

    /**
     * Whether an asset reference that matches nothing here may be created by downloading the
     * file from the reference's URL. Configurable through `config/entry-porter.php`. Creation
     * is limited to public http(s) URLs (isUrlPubliclyFetchable()) and to users with the
     * relevant volume permissions (assetCreatePermissionError()).
     */
    public bool $createMissingAssets = true;

    /**
     * The control panel user an import runs as. Asset creation is checked against this user's
     * volume permissions, and is refused when no user is set.
     */
    public ?User $importingUser = null;

    /**
     * The site to resolve related elements in. Set by the exporter and importer; when null,
     * lookups search every site.
     */
    public ?int $siteId = null;

    /**
     * The source origin declared by the payload being imported. Used only to report when an
     * asset is downloaded from a different host; it does not restrict the download.
     */
    public ?string $sourceOrigin = null;

    public function describeElement(string $kind, int $id): ?array
    {
        $elementType = self::KIND_ELEMENT_CLASSES[$kind] ?? null;
        // Pass the site explicitly. Without it Craft uses the current site, which in a console
        // request is the primary site, so relations that exist only in another site would not
        // be found.
        //
        // No canonical check is needed here: $id comes from a relation on the canonical entry
        // being exported.
        $element = Craft::$app->getElements()->getElementById($id, $elementType, $this->siteId ?? '*');
        if ($element === null) {
            return null;
        }
        return match ($kind) {
            'asset' => Ref::make('asset', $element->uid, [
                'volume' => $element->getVolume()->handle,
                'folderPath' => (string)($element->getFolder()->path ?? ''),
                'filename' => $element->getFilename(),
            ], ['url' => $element->getUrl(), 'title' => (string)$element->title]),
            'entry' => Ref::make('entry', $element->uid, [
                'section' => $element->getSection()?->handle,
                'type' => $element->getType()->handle,
                'slug' => (string)$element->slug,
            ], ['title' => (string)$element->title]),
            'category' => Ref::make('category', $element->uid, [
                'group' => $element->getGroup()->handle,
                'slug' => (string)$element->slug,
            ], ['title' => (string)$element->title]),
            // Tags are unique by title within a group, and their slug is derived from the
            // title, so the natural key is group plus title.
            'tag' => Ref::make('tag', $element->uid, [
                'group' => $element->getGroup()->handle,
                'title' => (string)$element->title,
            ], ['title' => (string)$element->title]),
            'user' => Ref::make('user', $element->uid, ['email' => (string)$element->email]),
            'form' => Ref::make('form', $element->uid, ['handle' => (string)$element->handle]),
            default => null,
        };
    }

    public function fieldsForEntryType(string $typeHandle): array
    {
        $type = Craft::$app->getEntries()->getEntryTypeByHandle($typeHandle);
        if ($type === null) {
            return [];
        }
        return $this->descriptorsFromLayout($type->getFieldLayout()->getCustomFields());
    }

    public function fieldsForNeoBlockType(string $neoFieldHandle, string $blockTypeHandle): array
    {
        $field = Craft::$app->getFields()->getFieldByHandle($neoFieldHandle);
        if ($field === null || !method_exists($field, 'getBlockTypes')) {
            return [];
        }
        foreach ($field->getBlockTypes() as $blockType) {
            if ($blockType->handle === $blockTypeHandle) {
                return $this->descriptorsFromLayout($blockType->getFieldLayout()->getCustomFields());
            }
        }
        return [];
    }

    /**
     * Checks the parts of a reference whose being malformed makes the whole reference
     * unusable: `kind` must be a string, and `uid` and `url` must be strings when present.
     *
     * Every reference passes through resolveRef(), including those nested in field values,
     * so this is the one place malformed references are caught. Values are type-checked
     * rather than cast, because casting an array to a string raises a warning that Craft turns
     * into an exception.
     *
     * @return string|null null when well-formed; otherwise a description of the problem
     */
    public static function refShapeError(array $ref): ?string
    {
        $kind = $ref['kind'] ?? null;
        if (!is_string($kind)) {
            return $kind === null
                ? "its 'kind' is missing"
                : "its 'kind' is a " . get_debug_type($kind) . ', not a string';
        }

        foreach (['uid', 'url'] as $name) {
            $value = $ref[$name] ?? null;
            if ($value !== null && !is_string($value)) {
                return "its '{$name}' is a " . get_debug_type($value) . ', not a string';
            }
        }

        return null;
    }

    /**
     * Checks whether a reference's natural keys can be used. Unusable keys only disable the
     * natural-key fallback; the reference can still be matched by UID.
     *
     * Null values are valid: a nested entry (one owned by a Matrix or Neo field) has no
     * section, so its `section` key is null. Any other non-string value is rejected, so a
     * partly malformed key set cannot match on whichever keys happen to be valid.
     *
     * @return string|null null when usable (including absent); otherwise a description of the
     *                     problem
     */
    public static function refKeysError(array $ref): ?string
    {
        $keys = $ref['keys'] ?? null;
        if ($keys === null) {
            return null;
        }
        if (!is_array($keys)) {
            return "its 'keys' is a " . get_debug_type($keys) . ', not an object';
        }
        foreach ($keys as $name => $value) {
            if ($value !== null && !is_string($value)) {
                return "its natural key '" . (is_string($name) ? $name : (string)(int)$name)
                    . "' is a " . get_debug_type($value) . ', not a string';
            }
        }
        return null;
    }

    public function resolveRef(array $ref, Report $report): ?int
    {
        // Validate the reference before anything reads it. A malformed reference is reported
        // and recorded as unresolved, distinct from a valid one that matches nothing.
        $shapeError = self::refShapeError($ref);
        if ($shapeError !== null) {
            $report->warn("A reference in the payload is malformed ({$shapeError}); it was ignored and nothing was linked for it.");
            $report->unresolved[] = $ref;
            return null;
        }

        // Unusable natural keys skip only the natural-key step below.
        $keysError = self::refKeysError($ref);
        if ($keysError !== null) {
            $report->warn("A reference in the payload has an unusable natural-key set ({$keysError}); it can still be matched by UID, but its natural-key fallback was not attempted.");
        }

        // 1. By UID, across all sites, accepting only canonical elements.
        $uid = $ref['uid'] ?? '';
        if ($uid !== '') {
            $id = $this->lookupCanonicalIdByUid($uid, self::KIND_ELEMENT_CLASSES[$ref['kind']] ?? null);
            if ($id !== null) {
                return $id;
            }
        }
        // 2. By natural keys.
        if ($keysError === null) {
            $id = $this->resolveByNaturalKeys($ref);
            if ($id !== null) {
                $report->resolvedByFallback[] = ['ref' => $ref, 'how' => 'natural-key'];
                return $id;
            }
        }
        // 3. For assets, by downloading the file from the source and creating it.
        if (($ref['kind'] ?? '') === 'asset' && $this->createMissingAssets && !empty($ref['url'])) {
            $id = $this->createAssetFromUrl($ref, $report);
            if ($id !== null) {
                $report->resolvedByFallback[] = ['ref' => $ref, 'how' => 'created-asset'];
                return $id;
            }
        }
        $report->unresolved[] = $ref;
        return null;
    }

    /**
     * Looks up a UID and returns the element's ID only if it is canonical. Craft's UID lookup
     * also returns drafts and revisions, which are separate elements and never a valid
     * relation target.
     *
     * Protected so tests can substitute it.
     *
     * @param string|null $elementType the element class named by the reference's kind, or null
     *                                 to let Craft detect it
     * @return int|null the canonical element's ID, or null if nothing canonical holds the UID
     */
    protected function lookupCanonicalIdByUid(string $uid, ?string $elementType): ?int
    {
        $element = Craft::$app->getElements()->getElementByUid($uid, $elementType, '*');
        return ($element !== null && $element->getIsCanonical()) ? $element->id : null;
    }

    /**
     * @return bool whether $keys is an array in which every named key holds a string
     */
    private static function allStrings(mixed $keys, string ...$names): bool
    {
        if (!is_array($keys)) {
            return false;
        }
        foreach ($names as $name) {
            if (!is_string($keys[$name] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /** @return string|null the named key's value when it is a string, else null */
    private static function stringKey(mixed $keys, string $name): ?string
    {
        if (!is_array($keys)) {
            return null;
        }
        $value = $keys[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * Matches a reference by its natural keys.
     *
     * Entries, categories and tags are localized, and the same keys can identify different
     * elements in different sites, so those lookups are scoped to $siteId. Users are not
     * localized, and an asset's keys are unique within its volume, so those are not scoped.
     * Entries match on section, type and slug, since two entry types in one section can share
     * a slug. A nested entry has no section, so it is never matched by natural keys.
     */
    private function resolveByNaturalKeys(array $ref): ?int
    {
        $keys = $ref['keys'] ?? [];
        $siteId = $this->siteId ?? '*';
        $element = match ($ref['kind'] ?? '') {
            'entry' => self::allStrings($keys, 'section', 'type', 'slug')
                ? Entry::find()->section($keys['section'])->type($keys['type'])->slug($keys['slug'])
                    ->siteId($siteId)
                    ->status(null)->drafts(false)->provisionalDrafts(false)->one()
                : null,
            'asset' => self::allStrings($keys, 'volume', 'filename')
                ? $this->findAssetByNaturalKey(
                    $keys['volume'],
                    self::stringKey($keys, 'folderPath') ?? '',
                    $keys['filename'],
                )
                : null,
            'category' => self::allStrings($keys, 'group', 'slug')
                ? Category::find()->group($keys['group'])->slug($keys['slug'])
                    ->siteId($siteId)
                    ->status(null)->one()
                : null,
            // A tag title is free text, and Craft's query syntax reads `,` as OR and `*` as a
            // wildcard, so it is escaped to match only the exact title.
            'tag' => self::allStrings($keys, 'group', 'title')
                ? Tag::find()->group($keys['group'])->title(Db::escapeParam($keys['title']))
                    ->siteId($siteId)
                    ->status(null)->one()
                : null,
            'user' => self::allStrings($keys, 'email')
                ? User::find()->email($keys['email'])->status(null)->one()
                : null,
            'form' => $this->findFormByHandle(self::stringKey($keys, 'handle')),
            default => null,
        };
        return $element?->id;
    }

    /**
     * Finds an asset by volume, folder path and filename.
     *
     * A volume-root asset has an empty folder path, which Craft's asset query treats as no
     * filter at all. In that case the query is limited to the volume's root folder explicitly,
     * so a same-named file in a subfolder cannot match.
     */
    private function findAssetByNaturalKey(string $volumeHandle, string $folderPath, string $filename): ?Asset
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);
        if ($volume === null) {
            return null;
        }
        // The filename and folder path come from the payload, and Craft's query syntax reads
        // `,` as OR and `*` as a wildcard, so both are escaped to match only literally. Real
        // filenames never contain those characters, so this rejects nothing legitimate.
        $query = Asset::find()->volumeId($volume->id)->filename(Db::escapeParam($filename));
        if ($folderPath === '') {
            $rootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
            if ($rootFolder === null) {
                return null;
            }
            $query->folderId($rootFolder->id);
        } else {
            $query->folderPath(Db::escapeParam($folderPath));
        }
        /** @var Asset|null */
        return $query->one();
    }

    private function findFormByHandle(?string $handle): mixed
    {
        if ($handle === null || !class_exists('verbb\\formie\\Formie')) {
            return null;
        }
        $formClass = 'verbb\\formie\\elements\\Form';
        return $formClass::find()->handle($handle)->one();
    }

    /**
     * The volume permissions required to auto-create an asset, matching what Craft's own
     * asset controller requires: `saveAssets` on the volume, plus `createFolders` when the
     * target folder does not exist yet.
     *
     * The entry-level permission checks in the importer cover the section, not volumes, so
     * this check is needed separately. With no user to check ($can is null), creation is
     * refused.
     *
     * @param callable(string): bool|null $can
     * @return string|null null when creation is permitted, otherwise the reason it is not
     */
    public static function assetCreatePermissionError(?callable $can, string $volumeHandle, string $volumeUid, bool $folderExists): ?string
    {
        if ($can === null) {
            return 'no CP user is associated with this import, so there is nobody whose volume permissions could authorize creating it';
        }
        if (!$can('saveAssets:' . $volumeUid)) {
            return "you do not have permission to save assets in the '{$volumeHandle}' volume";
        }
        if (!$folderExists && !$can('createFolders:' . $volumeUid)) {
            return "the folder it belongs in does not exist in the '{$volumeHandle}' volume yet, and you do not have permission to create folders there";
        }
        return null;
    }

    /**
     * Whether $fullPath names an existing folder in $volume. An empty path is the volume
     * root, which always exists.
     *
     * The path comes from the payload and is escaped: Craft's folder lookup escapes commas
     * but not `*`, so an unescaped `*` would match any folder and wrongly skip the
     * `createFolders` permission check.
     */
    private static function folderExistsInVolume(Volume $volume, string $fullPath): bool
    {
        $trimmed = trim($fullPath, '/\\');
        if ($trimmed === '') {
            return true;
        }
        return Craft::$app->getAssets()->findFolder([
            'volumeId' => $volume->id,
            'path' => Db::escapeParam($trimmed . '/'),
        ]) !== null;
    }

    private function createAssetFromUrl(array $ref, Report $report): ?int
    {
        $keys = $ref['keys'] ?? [];
        $filenameForLog = self::stringKey($keys, 'filename') ?? '?';
        $url = self::stringKey($ref, 'url') ?? '';
        $folderPath = self::stringKey($keys, 'folderPath') ?? '';

        // With no user there is no authority to create anything.
        if ($this->importingUser === null) {
            $report->warn("Asset create refused for {$filenameForLog}: "
                . self::assetCreatePermissionError(null, '', '', true)
                . '. Nothing was downloaded and nothing was written; the reference was left unresolved.');
            return null;
        }

        $volume = Craft::$app->getVolumes()->getVolumeByHandle(self::stringKey($keys, 'volume') ?? '');
        if ($volume === null) {
            return null;
        }

        // Check permissions before fetching, so a refused import makes no outbound request.
        $user = $this->importingUser;
        $permissionError = self::assetCreatePermissionError(
            static fn(string $permission): bool => $user->can($permission),
            (string)$volume->handle,
            (string)$volume->uid,
            self::folderExistsInVolume($volume, $folderPath),
        );
        if ($permissionError !== null) {
            $report->warn("Asset create refused for {$filenameForLog}: {$permissionError}. "
                . 'Nothing was downloaded and nothing was written; the reference was left unresolved, so the relation is simply missing on the draft rather than pointing at the wrong file.');
            return null;
        }

        if (!$this->isUrlSafeToFetch($url, $report, $filenameForLog)) {
            return null;
        }
        $tempPath = null;
        try {
            $folder = Craft::$app->getAssets()->ensureFolderByFullPathAndVolume($folderPath, $volume);
            $tempPath = AssetsHelper::tempFilePath(pathinfo($filenameForLog, PATHINFO_EXTENSION));

            if (!$this->downloadToFile($url, $tempPath, $report, $filenameForLog)) {
                @unlink($tempPath);
                return null;
            }

            $asset = new Asset();
            $asset->tempFilePath = $tempPath;
            $asset->setFilename($filenameForLog);
            $asset->newFolderId = $folder->id;
            $asset->setVolumeId($volume->id);
            $asset->avoidFilenameConflicts = true;
            $asset->setScenario(Asset::SCENARIO_CREATE);
            if (!Craft::$app->getElements()->saveElement($asset)) {
                $report->warn("Asset create failed for {$filenameForLog}: " . implode('; ', $asset->getErrorSummary(true)));
                @unlink($tempPath);
                return null;
            }
            return $asset->id;
        } catch (\Throwable $e) {
            if ($tempPath !== null) {
                @unlink($tempPath);
            }
            $report->warn("Asset fetch failed for {$filenameForLog}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Whether a URL taken from a payload is safe for the server to download: the scheme must
     * be http or https, and the host must resolve only to public addresses. The resolved
     * addresses are checked, not the hostname, so a public-looking name that resolves to a
     * private or link-local address (such as a cloud metadata endpoint) is refused.
     *
     * Known limitation: the host is resolved again when the request is made, so a DNS server
     * that answers differently the second time could still redirect the request. Redirect
     * responses themselves are not followed (see downloadToFile()).
     */
    public static function isUrlPubliclyFetchable(string $url): bool
    {
        $parts = parse_url($url) ?: [];
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        return self::hostResolvesToPublicAddress($host);
    }

    private static function hostResolvesToPublicAddress(string $host): bool
    {
        // parse_url() keeps the brackets around an IPv6 literal, but filter_var() needs the
        // bare address.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host);
        }
        $ips = self::resolveHostIps($host);
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return false;
            }
        }
        return true;
    }

    /** @return string[] every A/AAAA address the host resolves to; [] if none/unresolvable. */
    private static function resolveHostIps(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || $records === []) {
            return [];
        }
        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip !== null) {
                $ips[] = $ip;
            }
        }
        return $ips;
    }

    /** First 12 bytes of an IPv4-compatible IPv6 address (`::/96`), as raw bytes. */
    private const IPV4_COMPATIBLE_PREFIX = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";

    /** First 12 bytes of an IPv4-mapped IPv6 address (`::ffff:0:0/96`), as raw bytes. */
    private const IPV4_MAPPED_PREFIX = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";

    /**
     * Whether an IP address is public.
     *
     * filter_var()'s private and reserved range flags exclude RFC 1918 ranges, loopback and
     * link-local addresses, but they are not enough on their own:
     *
     *  - They treat IPv6 addresses that embed an IPv4 address as public whatever that address
     *    is, so `::ffff:169.254.169.254` would pass. IPv4-mapped addresses are therefore
     *    checked as the IPv4 address they contain, and deprecated IPv4-compatible addresses
     *    are refused. The raw bytes are inspected, so every textual spelling of the same
     *    address is handled.
     *  - They treat the RFC 6598 shared address space (100.64.0.0/10) as public, although it
     *    is used for internal addresses in many container and cloud networks. It is refused
     *    in isPublicIpv4().
     *
     * NAT64 (`64:ff9b::/96`) and 6to4 (`2002::/16`) addresses are not specially handled, since
     * reaching an internal host through them requires a gateway or tunnel.
     */
    private static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16) {
            $prefix = substr($packed, 0, 12);
            if ($prefix === self::IPV4_COMPATIBLE_PREFIX) {
                return false;
            }
            if ($prefix === self::IPV4_MAPPED_PREFIX) {
                return self::isPublicIpv4(substr($packed, 12, 4));
            }
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return self::isPublicIpv4($packed);
    }

    /**
     * @param string $packed the four raw bytes of an IPv4 address, as produced by inet_pton()
     */
    private static function isPublicIpv4(string $packed): bool
    {
        // RFC 6598 shared address space, 100.64.0.0/10. Compared on the raw bytes, since
        // ip2long() is signed on 32-bit builds.
        if (ord($packed[0]) === 100 && (ord($packed[1]) & 0xC0) === 0x40) {
            return false;
        }
        return filter_var(inet_ntop($packed), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Refuses URLs that fail isUrlPubliclyFetchable(), and reports, without refusing, an
     * asset hosted somewhere other than the payload's declared source origin. Assets are
     * commonly served from a CDN on a different host, so a mismatch is normal and only
     * reported so the operator can see where a file came from.
     */
    private function isUrlSafeToFetch(string $url, Report $report, string $filenameForLog): bool
    {
        if (!self::isUrlPubliclyFetchable($url)) {
            $report->warn("Asset fetch refused for {$filenameForLog}: '{$url}' is not an http(s) URL resolving to a public address.");
            return false;
        }

        $parts = parse_url($url) ?: [];
        $host = (string)($parts['host'] ?? '');
        $urlOrigin = strtolower((string)($parts['scheme'] ?? '')) . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $declaredOrigin = $this->sourceOrigin !== null ? rtrim($this->sourceOrigin, '/') : null;
        if ($declaredOrigin === null || strcasecmp($urlOrigin, $declaredOrigin) !== 0) {
            $report->warn("Asset fetch for {$filenameForLog}: URL host '{$host}' differs from the payload's declared source origin"
                . ($declaredOrigin === null ? ' (none was set)' : " '{$declaredOrigin}'") . '; fetching anyway.');
        }

        return true;
    }

    /**
     * Streams $url into $tempPath.
     *
     * The status, content type and declared length are checked before any of the body is
     * read. While reading, the download stops as soon as it exceeds the size limit or the time
     * budget. The time budget is enforced here as well as through Guzzle's `timeout` option,
     * because with Guzzle's stream handler that option limits each socket operation rather
     * than the whole transfer. When PHP's `allow_url_fopen` is disabled, Guzzle buffers the
     * whole response before returning, so only the declared-length check can stop an
     * oversized download from being transferred.
     *
     * Redirects are not followed, so a permitted URL cannot redirect to an address that
     * isUrlPubliclyFetchable() would refuse. Every failure removes the temporary file and is
     * reported.
     */
    private function downloadToFile(string $url, string $tempPath, Report $report, string $filenameForLog): bool
    {
        $client = Craft::createGuzzleClient(['timeout' => self::DOWNLOAD_TIMEOUT_SECONDS]);
        $response = $client->get($url, ['stream' => true, 'http_errors' => false, 'allow_redirects' => false]);

        if ($response->getStatusCode() !== 200) {
            $report->warn("Asset fetch failed for {$filenameForLog}: source returned HTTP {$response->getStatusCode()}.");
            @unlink($tempPath);
            return false;
        }

        // An HTML response is usually a login or error page, not the requested file.
        $contentType = $response->getHeaderLine('Content-Type');
        if ($contentType !== '' && stripos($contentType, 'text/html') !== false) {
            $report->warn("Asset fetch failed for {$filenameForLog}: source returned Content-Type '{$contentType}' instead of a file.");
            @unlink($tempPath);
            return false;
        }

        $declaredLength = $response->getHeaderLine('Content-Length');
        if ($declaredLength !== '' && is_numeric($declaredLength) && (int)$declaredLength > self::MAX_ASSET_DOWNLOAD_BYTES) {
            $report->warn("Asset fetch failed for {$filenameForLog}: declared size ({$declaredLength} bytes) exceeds the " . self::MAX_ASSET_DOWNLOAD_BYTES . '-byte limit.');
            @unlink($tempPath);
            return false;
        }

        $body = $response->getBody();
        $out = fopen($tempPath, 'wb');
        if ($out === false) {
            $report->warn("Asset fetch failed for {$filenameForLog}: could not open temp file for writing.");
            @unlink($tempPath);
            return false;
        }

        $total = 0;
        $oversized = false;
        $timedOut = false;
        $deadline = microtime(true) + self::DOWNLOAD_TIMEOUT_SECONDS;
        try {
            while (!$body->eof()) {
                if (microtime(true) > $deadline) {
                    $timedOut = true;
                    break;
                }
                $chunk = $body->read(65536);
                $total += strlen($chunk);
                if ($total > self::MAX_ASSET_DOWNLOAD_BYTES) {
                    $oversized = true;
                    break;
                }
                fwrite($out, $chunk);
            }
        } finally {
            fclose($out);
        }

        if ($timedOut) {
            $report->warn("Asset fetch failed for {$filenameForLog}: download exceeded the " . self::DOWNLOAD_TIMEOUT_SECONDS . '-second time budget.');
            @unlink($tempPath);
            return false;
        }

        if ($oversized) {
            $report->warn("Asset fetch failed for {$filenameForLog}: exceeded the " . self::MAX_ASSET_DOWNLOAD_BYTES . '-byte download limit.');
            @unlink($tempPath);
            return false;
        }

        if ($total === 0) {
            $report->warn("Asset fetch failed for {$filenameForLog}: source returned an empty response body.");
            @unlink($tempPath);
            return false;
        }

        return true;
    }

    private function descriptorsFromLayout(array $customFields): array
    {
        $out = [];
        foreach ($customFields as $field) {
            $out[$field->handle] = new FieldDescriptor(get_class($field), $field->handle);
        }
        return $out;
    }
}
