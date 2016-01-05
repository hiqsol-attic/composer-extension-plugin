<?php

/*
 * Composer plugin for Yii extensions
 *
 * @link      https://github.com/hiqdev/composer-extension-plugin
 * @package   composer-extension-plugin
 * @license   BSD-3-Clause
 * @copyright Copyright (c) 2016, HiQDev (http://hiqdev.com/)
 */

namespace hiqdev\composerextensionplugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * Plugin class.
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    const EXTRA_EXTENSION = 'extension';
    const EXTRA_BOOTSTRAP = 'bootstrap';
    const EXTENSIONS_FILE = 'yiisoft/extensions.php';

    /**
     * @var PackageInterface[] the array of active composer packages
     */
    protected $packages;

    /**
     * @var Composer instance
     */
    protected $composer;

    /**
     * @var IOInterface
     */
    public $io;

    /**
     * Initializes the plugin object with the passed $composer and $io.
     * @param Composer $composer
     * @param IOInterface $io
     * @void
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * Returns list of events the plugin is subscribed to.
     * @return array list of events
     */
    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::POST_AUTOLOAD_DUMP => [
                ['onPostAnything', 0],
            ],
        ];
    }

    /**
     * Simply rewrites extensions file from scratch.
     * @param Event $event
     * @void
     */
    public function onPostAnything(Event $event)
    {
        $this->io->writeError('<info>' . $event->getName() . ' ...</info>');
        foreach ($this->getPackages() as $package) {
            if ($package instanceof \Composer\Package\CompletePackageInterface) {
                $this->processPackage($package);
            }
        }
    }

    /**
     * Scans the given package and collects extensions data.
     *
     * @param CompletePackageInterface $package
     * @void
     */
    public function processPackage($package)
    {
        $this->io->writeError($package->getName() . ' ');
        $extra = $package->getExtra();
    }

    /**
     * Sets [[packages]].
     * @param PackageInterface[] $packages
     * @void
     */
    public function setPackages(array $packages)
    {
        $this->packages = $packages;
    }

    /**
     * Gets [[packages]].
     * @return \Composer\Package\PackageInterface[]
     */
    public function getPackages()
    {
        if ($this->packages === null) {
            $this->packages = $this->composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages();
            $this->packages[] = $this->composer->getPackage();
        }

        return $this->packages;
    }
}
