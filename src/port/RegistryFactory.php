<?php

namespace chaseburklund\entryporter\port;

use chaseburklund\entryporter\transformers\FallbackTransformer;
use chaseburklund\entryporter\transformers\HtmlFieldTransformer;
use chaseburklund\entryporter\transformers\HyperTransformer;
use chaseburklund\entryporter\transformers\KnownSafeTransformer;
use chaseburklund\entryporter\transformers\LenzLinkTransformer;
use chaseburklund\entryporter\transformers\LinkTransformer;
use chaseburklund\entryporter\transformers\MatrixTransformer;
use chaseburklund\entryporter\transformers\NeoTransformer;
use chaseburklund\entryporter\transformers\RelationTransformer;
use chaseburklund\entryporter\transformers\SeoSettingsTransformer;
use chaseburklund\entryporter\transformers\SimpleMapTransformer;
use chaseburklund\entryporter\transformers\SkipTransformer;
use chaseburklund\entryporter\transformers\UnportableTransformer;

/**
 * Builds the field transformer registry.
 *
 * Classes in port/ and transformers/ do not depend on Craft, so the registry can be built and
 * tested without a running Craft app.
 */
final class RegistryFactory
{
    public static function build(): Registry
    {
        $registry = new Registry(new FallbackTransformer());
        $registry->add(new SkipTransformer([
            'nystudio107\\imageoptimize\\fields\\OptimizedImages',
        ]));
        // Field types known to hold element IDs that cannot be remapped (none at present).
        // Registered ahead of the other transformers so that no passthrough can claim such a
        // field first.
        $registry->add(new UnportableTransformer([]));
        $registry->add(new SeoSettingsTransformer());
        $registry->add(new RelationTransformer());
        $registry->add(new LinkTransformer());
        $registry->add(new LenzLinkTransformer());
        $registry->add(new MatrixTransformer($registry));
        $registry->add(new NeoTransformer($registry));
        $registry->add(new HyperTransformer());
        $registry->add(new HtmlFieldTransformer());
        $registry->add(new SimpleMapTransformer());
        $registry->add(new KnownSafeTransformer());
        return $registry;
    }
}
