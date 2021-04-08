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

class AutoloadExcludePlugin implements
    PluginInterface,
    EventSubscriberInterface
{
    const EXCLUDE_FILES_PROPERTY = 'exclude-from-files';
    const EXCLUDE_PSR4_PROPERTY = 'exclude-from-psr4';
    const EXCLUDE_CLASSMAP_PROPERTY = 'exclude-from-classmap';

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
            ScriptEvents::PRE_AUTOLOAD_DUMP => 'parseAutoloads',
        );
    }

    /**
     * Parse the vendor 'files' to be included before the autoloader is dumped.
     *
     * Note: The double realpath() calls fixes failing Windows realpath() implementation.
     * See https://bugs.php.net/bug.php?id=72738
     * See \Composer\Autoload\AutoloadGenerator::dump()
     *
     * @return void
     */
    public function parseAutoloads()
    {
        $composer = $this->composer;

        $package = $composer->getPackage();
        if (!$package) {
            return;
        }

        $excludedFiles = $this->parseExcludedFiles($this->getExcludedFiles($package));
        $excludedPsr4 = $this->getExcludedPsr4($package);
        $excludedClassmap = $this->getExcludedClassmap($package);
        if (!$excludedFiles && !$excludedFiles && !$excludedClassmap) {
            return;
        }

        $generator = $composer->getAutoloadGenerator();
        $packages = $composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages();
        $packageMap = $generator->buildPackageMap($composer->getInstallationManager(), $package, $packages);

        $this->filterAutoloads($packageMap, $package, compact('excludedFiles', 'excludedPsr4', 'excludedClassmap'));
    }

    /**
     * Alters packages to exclude files required in "autoload.files" by "extra.exclude-from-files".
     *
     * @param array $packageMap Array of `[ package, installDir-relative-to-composer.json) ]`.
     * @param PackageInterface $mainPackage Root package instance.
     * @param string[] $excludedFiles The files to exclude from the "files" autoload mechanism.
     * @return void
     */
    private function filterAutoloads(array $packageMap, PackageInterface $mainPackage, array $excludedItems)
    {
        extract($excludedItems);
        $excludedFiles = array_flip($excludedFiles);

        foreach ($packageMap as $item) {
            list($package, $installPath) = $item;

            // Skip root package
            if ($package === $mainPackage) {
                continue;
            }

            $autoload = $package->getAutoload();

            foreach (array_keys($autoload) as $type) {
                switch ($type) {
                    case 'files':
                        $this->handleFiles($autoload, $excludedFiles, $package, $installPath);
                        break;
                    /*
                    case 'classmap':
                        $this->handleClassmap($autoload, $excludedClassmap, $package);
                        break;
                    */
                    case 'psr-4':
                        $this->handlePsr4($autoload, $excludedPsr4, $package);
                        break;
                }
            }

            $package->setAutoload($autoload);
        }
    }

    /**
     * Loop through the package classmap and see if they should be excluded.
     *
     * @param $autoload
     * @param $excludedClassmap
     * @param $package
     */
    private function handleClassmap(&$autoload, $excludedClassmap, $package)
    {
        // TODO: Add handling for classmap
        /*
        foreach ($autoload['classmap'] as $key => $path) {
        }
        */
    }

    /**
     * Check if given file should be excluded.
     *
     * @param $excludedFiles
     * @param $resolvedPath
     * @return bool
     */
    private function shouldExcludeFile($excludedFiles, $resolvedPath)
    {
        if (isset($excludedFiles[$resolvedPath]) || $this->doFilesWildcardMatch($excludedFiles, $resolvedPath)) {
            return true;
        }
        return false;
    }

    /**
     * Loop through the package files and see if they should be excluded.
     *
     * @param $autoload
     * @param $installPath
     * @param $excludedFiles
     * @param $package
     */
    private function handleFiles(&$autoload, $excludedFiles, $package, $installPath)
    {
        if (null !== $package->getTargetDir()) {
            $installPath = substr($installPath, 0, -strlen('/' . $package->getTargetDir()));
        }
        foreach ($autoload['files'] as $key => $path) {
            if ($package->getTargetDir() && !is_readable($installPath . '/' . $path)) {
                // add target-dir from file paths that don't have it
                $path = $package->getTargetDir() . '/' . $path;
            }
            $resolvedPath = $installPath . '/' . $path;
            $resolvedPath = strtr($resolvedPath, '\\', '/');
            if ($this->shouldExcludeFile($excludedFiles, $resolvedPath)) {
                unset($autoload['files'][$key]);
            }
        }
    }

    /**
     * Check if given namespace should be excluded.
     *
     * @param $excludedPsr4
     * @param $namespace
     * @param $path
     * @return bool
     */
    private function shouldExcludePsr4($excludedPsr4, $namespace, $path)
    {
        foreach ($excludedPsr4 as $match) {
            $isWildcard = strpos($match, '*') !== false;
            if ($isWildcard) {
                $match = rtrim($match, '*');
                if (preg_match('/^' . preg_quote($match) . '/', $namespace)) {
                    return true;
                }
            } else {
                if ($namespace == $match) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Loop through the package psr4 namespaces and see if they should be excluded.
     *
     * @param $autoload
     * @param $excludedPsr4
     * @param $package
     */
    private function handlePsr4(&$autoload, $excludedPsr4, $package)
    {
        foreach ($autoload['psr-4'] as $namespace => $path) {
            if ($this->shouldExcludePsr4($excludedPsr4, $namespace, $path)) {
                unset($autoload['psr-4'][$namespace]);
            }
        }
    }

    /**
     * Wildcard match install path vs excluded file path.
     *
     * @param $excludedFiles
     * @param $installPath
     * @return bool
     */
    private function doFilesWildcardMatch($excludedFiles, $installPath)
    {
        $excludedWildcardPaths = array_filter(array_flip($excludedFiles), function ($item) {
            return strpos($item, '*') !== false;
        });
        $excludedWildcardPaths = array_map(function ($item) {
            return trim($item, '*');
        }, $excludedWildcardPaths);
        foreach ($excludedWildcardPaths as $excludedWildcardPath) {
            if (strpos($installPath, $excludedWildcardPath) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get plugin settings.
     *
     * @param $package
     * @return array|false
     */
    private function getPluginSettings($package)
    {
        $extra = $package->getExtra();
        $property = 'autoload-exclude';

        if (isset($extra[$property]) && is_array($extra[$property])) {
            return $extra[$property];
        }

        return false;
    }

    /**
     * Gets a list of files the root package wants to exclude.
     *
     * @param  PackageInterface $package Root package instance.
     * @return string[] Retuns the list of excluded files otherwise NULL if misconfigured or undefined.
     */
    private function getExcludedFiles(PackageInterface $package)
    {
        $extra = $this->getPluginSettings($package);
        $property = self::EXCLUDE_FILES_PROPERTY;

        if (isset($extra[$property]) && is_array($extra[$property])) {
            return $extra[$property];
        }

        return array();
    }

    /**
     * Gets a list of psr4-namespaces the root package wants to exclude.
     *
     * @param  PackageInterface $package Root package instance.
     * @return string[] Retuns the list of excluded files otherwise NULL if misconfigured or undefined.
     */
    private function getExcludedPsr4(PackageInterface $package)
    {
        $property = self::EXCLUDE_PSR4_PROPERTY;
        $extra = $this->getPluginSettings($package);

        if (isset($extra[$property]) && is_array($extra[$property])) {
            return $extra[$property];
        }

        return array();
    }

    /**
     * Gets a list of classmaps the root package wants to exclude.
     *
     * @param  PackageInterface $package Root package instance.
     * @return string[] Retuns the list of excluded files otherwise NULL if misconfigured or undefined.
     */
    private function getExcludedClassmap(PackageInterface $package)
    {
        $property = self::EXCLUDE_CLASSMAP_PROPERTY;
        $extra = $this->getPluginSettings($package);

        if (isset($extra[$property]) && is_array($extra[$property])) {
            return $extra[$property];
        }

        return array();
    }

    /**
     * Prepends the vendor directory to each path in "extra.exclude-from-files".
     *
     * @param  string[] $paths Array of paths relative to the composer manifest.
     * @return string[] Retuns the array of paths, prepended with the vendor directory.
     */
    private function parseExcludedFiles(array $paths)
    {
        if (empty($paths)) {
            return $paths;
        }

        $filesystem = new Filesystem();
        $config     = $this->composer->getConfig();
        $vendorPath = $filesystem->normalizePath(realpath(realpath($config->get('vendor-dir'))));

        foreach ($paths as &$path) {
            $path = preg_replace('{/+}', '/', trim(strtr($path, '\\', '/'), '/'));
            $path = $vendorPath . '/' . $path;
        }

        return $paths;
    }
}
