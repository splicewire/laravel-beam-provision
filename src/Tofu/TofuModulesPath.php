<?php

namespace Splicewire\Beam\Provision\Tofu;

/**
 * The one place that knows where this package's own OpenTofu modules live on disk
 * (`tofu/modules/`, relative to the package root) — every renderer/`Destination` binding that
 * needs `$modulesDir` resolves it through here instead of re-deriving the `dirname(__DIR__, N)`
 * path independently.
 */
final class TofuModulesPath
{
    public static function dir(): string
    {
        return dirname(__DIR__, 2).'/tofu/modules';
    }
}
