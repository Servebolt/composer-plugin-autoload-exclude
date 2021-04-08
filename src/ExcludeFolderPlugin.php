<?php

/*
 * This file is part of the "composer-exclude-files" plugin.
 *
 * © Chauncey McAskill <chauncey@mcaskill.ca>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace McAskill\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;

class ExcludeFolderPlugin implements
    PluginInterface,
    EventSubscriberInterface
{
    const EXCLUDE_PATHS_PROPERTY = 'paths-to-exclude-from-autoload';
    const TMP_IGNORED_PACKAGES_FOLDER_NAME = 'tmp-ignored-packages';

    /**
     * @var Composer
     */
    private $composer;

    /**
     * Apply plugin modifications to Composer.
     *
     * @param Composer $composer The Composer instance.
     * @param IOInterface $io The Input/Output instance.
     * @return void
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
    }

    /**
     * Remove any hooks from Composer.
     *
     * @codeCoverageIgnore
     *
     * @param Composer $composer The Composer instance.
     * @param IOInterface $io The Input/Output instance.
     * @return void
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
        // no need to deactivate anything
    }

    /**
     * Prepare the plugin to be uninstalled.
     *
     * @codeCoverageIgnore
     *
     * @param Composer $composer The Composer instance.
     * @param IOInterface $io The Input/Output instance.
     * @return void
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
        // no need to uninstall anything
    }

    /**
     * Gets a list of event names this subscriber wants to listen to.
     *
     * @return array The event names to listen to.
     */
    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::PRE_AUTOLOAD_DUMP => 'ignorePackages',
            ScriptEvents::POST_AUTOLOAD_DUMP => 'addPackages',
        );
    }

    public function ignorePackages()
    {
        $composer = $this->composer;

        $package = $composer->getPackage();
        if (!$package) {
            return;
        }

        $pathsToExclude = $this->getPathsToExclude($package);
        if (empty($pathsToExclude)) {
            return;
        }

        $foldersToExclude = $this->resolveFoldersToExclude($pathsToExclude);
        if (empty($foldersToExclude)) {
            return;
        }

        $this->ensureTmpFolder();
        $tmpFolderPath = $this->getTmpFolderPath();
        $filesystem = new Filesystem();

        foreach ($foldersToExclude as $folderToExclude) {
            $folderName = $this->getFolderNameFromPath($folderToExclude);
            $filesystem->copyThenRemove($folderToExclude, $tmpFolderPath . '/' . $folderName);
        }
    }

    public function addPackages()
    {
        $tmpFolderPath = $this->getTmpFolderPath();
        $filesystem = new Filesystem();

        if (!$filesystem->isDirEmpty($tmpFolderPath)) {
            $vendorPath = $this->getVendorFolderPath();
            foreach (glob($tmpFolderPath . '/*') as $folder) {
                if (!is_dir($folder)) {
                    continue;
                }
                $folderName = $this->getFolderNameFromPath($folder);
                $filesystem->copy($folder, $vendorPath . '/' . $folderName);
            }
        }

        $this->removeTmpFolder();
    }

    private function getFolderNameFromPath($path)
    {
        $pathParts = explode('/', $path);
        return end($pathParts);
    }

    private function getVendorFolderPath()
    {
        $filesystem = new Filesystem();
        $config = $this->composer->getConfig();
        return $filesystem->normalizePath(realpath(realpath($config->get('vendor-dir'))));
    }

    private function resolveFoldersToExclude($pathsToExclude)
    {
        $vendorPath = $this->getVendorFolderPath();

        $foldersToExclude = array();

        foreach ($pathsToExclude as $path) {
            $path = preg_replace('{/+}', '/', trim(strtr($path, '\\', '/'), '/'));
            $path = trim($path, '*');
            $path = $vendorPath . '/' . $path;
            if (file_exists($path) && is_dir($path)) {
                $foldersToExclude[] = rtrim($path, '/');
            }
        }

        return $foldersToExclude;
    }

    private function getPathsToExclude(PackageInterface $package)
    {
        $pathToExclude = self::EXCLUDE_PATHS_PROPERTY;

        $extra = $package->getExtra();

        if (isset($extra[$pathToExclude]) && is_array($extra[$pathToExclude])) {
            return $extra[$pathToExclude];
        }

        return array();
    }

    private function getTmpFolderPath()
    {
        $vendorPath = $this->getVendorFolderPath();
        return $vendorPath . '/' . self::TMP_IGNORED_PACKAGES_FOLDER_NAME;
    }

    private function ensureTmpFolder()
    {
        $tmpFolderPath = $this->getTmpFolderPath();
        if (!file_exists($tmpFolderPath)) {
            mkdir($tmpFolderPath);
        }
    }

    private function removeTmpFolder()
    {
        $filesystem = new Filesystem();
        $filesystem->removeDirectory($this->getTmpFolderPath(), false);
    }
}
