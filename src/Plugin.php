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
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;

/**
 * Plugin class.
 *
 * @author Andrii Vasyliev <sol@hiqdev.com>
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    const EXTRA_CONFIG     = 'yii2-extraconfig';
    const EXTRA_BOOTSTRAP  = 'bootstrap';
    const EXTENSIONS_FILE  = 'yiisoft/extensions.php';
    const EXTRACONFIG_FILE = 'yiisoft/extraconfig.php';

    /**
     * @var PackageInterface[] the array of active composer packages
     */
    protected $packages;

    /**
     * @var string absolute path to vendor directory.
     */
    protected $vendorDir;

    /**
     * @var Filesystem utility
     */
    protected $filesystem;

    /**
     * @var array aliases
     */
    protected $aliases = [];

    /**
     * @var array bootstrap
     */
    protected $bootstrap = [];

    /**
     * @var array extra configuration
     */
    protected $extraconfig = [
        'aliases'   => [],
        'bootstrap' => [],
    ];

    /**
     * @var array extensions
     */
    protected $extensions;

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

        foreach (['aliases', 'bootstrap'] as $k) {
            $this->extraconfig[$k] = array_merge($this->$k, $this->extraconfig[$k]);
            if (empty($this->extraconfig[$k])) {
                unset($this->extraconfig[$k]);
            }
        }

        $this->saveFile(static::EXTENSIONS_FILE, $this->extensions);
        $this->saveFile(static::EXTRACONFIG_FILE, $this->extraconfig);
    }

    /**
     * Writes file.
     * @param string $file
     * @param array $data
     * @void
     */
    protected function saveFile($file, array $data)
    {
        $path = $this->getVendorDir() . '/' . $file;
        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        $array = str_replace("'<vendor-dir>", '$vendorDir . \'', var_export($data, true));
        file_put_contents($path, "<?php\n\n\$vendorDir = dirname(__DIR__);\n\nreturn $array;\n");
    }

    /**
     * Scans the given package and collects extensions data.
     * @param PackageInterface $package
     * @void
     */
    public function processPackage(PackageInterface $package)
    {
        $this->io->writeError($package->getName() . ' ');
        $extra = $package->getExtra();
        $extension = [
            'name'    => $package->getName(),
            'version' => $package->getVersion(),
        ];
        if ($package->getVersion() === '9999999-dev') {
            $reference = $package->getSourceReference() ?: $package->getDistReference();
            if ($reference) {
                $extension['reference'] = $reference;
            }
        }

        $this->aliases = array_merge($this->aliases,
            $this->prepareAliases($package, 'psr-0'),
            $this->prepareAliases($package, 'psr-4')
        );

        if (isset($extra[static::EXTRA_BOOTSTRAP])) {
            $this->bootstrap[] = $extra[static::EXTRA_BOOTSTRAP];
        }

        if (isset($extra[static::EXTRA_CONFIG])) {
            $this->extraconfig = array_merge($this->extraconfig, $this->readExtraConfig($package, $extra[static::EXTRA_CONFIG]));
        }

        $this->extensions[$package->getName()] = $extension;
    }

    /**
     * Read extraConf
     * @param string $file
     * @return array
     */
    protected function readExtraConfig(PackageInterface $package, $file)
    {
        $path = $this->preparePath($package, $file);
        if (!file_exists($path)) {
            $this->io->writeError('<error>Non existent extraconfig file</error> ' . $file . ' in ' . $package->getName());
            exit(1);
        }
        return require($path);
    }

    /**
     * Prepare aliases.
     *
     * @param PackageInterface $package
     * @param string 'psr-0' or 'psr-4'
     * @return array
     */
    protected function prepareAliases(PackageInterface $package, $psr)
    {
        $autoload = $package->getAutoload();
        if (empty($autoload[$psr])) {
            return [];
        }

        $aliases = [];
        foreach ($autoload[$psr] as $name => $path) {
            if (is_array($path)) {
                // ignore psr-4 autoload specifications with multiple search paths
                // we can not convert them into aliases as they are ambiguous
                continue;
            }
            $name = str_replace('\\', '/', trim($name, '\\'));
            $path = $this->preparePath($package, $path);
            if (strpos($path . '/', $this->getVendorDir() . '/') === 0) {
                $path = '<vendor-dir>' . substr($path, strlen($this->getVendorDir()));
            }
            if ('psr-0' === $psr) {
                $path .= '/' . $name;
            }
            $aliases["@$name"] = $path;
        }

        return $aliases;
    }

    public function preparePath(PackageInterface $package, $path)
    {
        if (!$this->getFilesystem()->isAbsolutePath($path)) {
            $path = $this->getVendorDir() . '/' . $package->getPrettyName() . '/' . $path;
        }

        return $this->getFilesystem()->normalizePath($path);
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
        }

        return $this->packages;
    }

    /**
     * Get absolute path to composer vendor dir.
     * @return string
     */
    public function getVendorDir()
    {
        if ($this->vendorDir === null) {
            $dir = $this->composer->getConfig()->get('vendor-dir', '/');
            $this->vendorDir = $this->getFilesystem()->normalizePath($dir);
        }

        return $this->vendorDir;
    }

    /**
     * Getter for filesystem utility
     * @return Filesystem
     */
    public function getFilesystem()
    {
        if ($this->filesystem === null) {
            $this->filesystem = new Filesystem();
        }

        return $this->filesystem;
    }
}
