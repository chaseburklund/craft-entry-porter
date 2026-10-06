<?php

namespace chaseburklund\entryporter;

use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry as EntryElement;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\services\Utilities;
use chaseburklund\entryporter\port\Registry;
use chaseburklund\entryporter\port\RegistryFactory;
use chaseburklund\entryporter\services\CraftResolver;
use chaseburklund\entryporter\services\Exporter;
use chaseburklund\entryporter\services\Importer;
use chaseburklund\entryporter\models\Settings;
use chaseburklund\entryporter\utilities\PorterUtility;
use chaseburklund\entryporter\web\assets\porter\PorterAsset;
use yii\base\Event;

/**
 * @property-read CraftResolver $resolver
 * @property-read Exporter $exporter
 * @property-read Importer $importer
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = false;

    public function init(): void
    {
        parent::init();

        if (\Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'chaseburklund\\entryporter\\console\\controllers';
        } else {
            $this->controllerNamespace = 'chaseburklund\\entryporter\\controllers';
        }

        $registry = self::buildRegistry();
        $resolver = new CraftResolver();
        $settings = $this->getSettings();
        if ($settings instanceof Settings) {
            $resolver->createMissingAssets = $settings->createMissingAssets;
        }
        // The exporter and importer share one resolver. Each restores the resolver's site and
        // source origin when it finishes.
        $this->set('resolver', $resolver);
        $this->set('exporter', new Exporter($registry, $resolver));
        $this->set('importer', new Importer($registry, $resolver));

        // None of these handlers run during an element save. Importer treats a TypeError or
        // ErrorException thrown while saving as a bad payload value, which is only accurate
        // while the plugin registers no save-time handlers of its own.
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function (RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => 'Entry Porter',
                    'permissions' => [
                        'entryPorter-export' => ['label' => 'Export entries as Porter JSON'],
                        'entryPorter-import' => ['label' => 'Import entries from Porter JSON'],
                    ],
                ];
            }
        );

        Event::on(
            EntryElement::class,
            Element::EVENT_DEFINE_ADDITIONAL_BUTTONS,
            static function (DefineHtmlEvent $event): void {
                /** @var EntryElement $entry */
                $entry = $event->sender;
                // An unpublished draft has no canonical entry to export, so it gets no button.
                if (!$entry->id || $entry->getIsUnpublishedDraft() || !\Craft::$app->getUser()->checkPermission('entryPorter-export')) {
                    return;
                }
                $view = \Craft::$app->getView();
                $view->registerAssetBundle(PorterAsset::class);
                $event->html .= $view->renderTemplate('entry-porter/_buttons', [
                    'entryId' => $entry->getCanonicalId(),
                    'siteId' => $entry->siteId,
                    // The export always copies the published entry. These flags let the button
                    // say so when the screen shows unsaved changes or a named draft instead.
                    'isProvisionalDraft' => $entry->isProvisionalDraft,
                    'isNamedDraftOfCanonical' => $entry->getIsDraft() && !$entry->getIsUnpublishedDraft() && !$entry->isProvisionalDraft,
                ]);
            }
        );

        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            static function (RegisterComponentTypesEvent $event): void {
                $event->types[] = PorterUtility::class;
            }
        );
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Builds the field transformer registry. See RegistryFactory, which can be tested without
     * a running Craft app.
     */
    public static function buildRegistry(): Registry
    {
        return RegistryFactory::build();
    }

    public function getResolver(): CraftResolver
    {
        return $this->get('resolver');
    }

    public function getExporter(): Exporter
    {
        return $this->get('exporter');
    }

    public function getImporter(): Importer
    {
        return $this->get('importer');
    }
}
