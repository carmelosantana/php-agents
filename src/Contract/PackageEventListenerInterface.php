<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

/**
 * Listener for Composer package lifecycle events.
 *
 * Implementors react to package installation and removal — for example,
 * scanning a newly installed package for toolkit declarations or cleaning
 * up a registry entry when a package is removed.
 *
 * Designed to decouple package management tools from framework internals:
 * a Composer toolkit can accept this interface as a constructor dependency
 * without knowing anything about the host application's discovery system.
 */
interface PackageEventListenerInterface
{
    /**
     * Called after a Composer package has been successfully installed.
     *
     * @param string $packageName The full package name (vendor/package).
     */
    public function onPackageInstalled(string $packageName): void;

    /**
     * Called after a Composer package has been successfully removed.
     *
     * @param string $packageName The full package name (vendor/package).
     */
    public function onPackageRemoved(string $packageName): void;
}
