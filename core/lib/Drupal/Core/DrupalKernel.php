<?php

/**
 * @file
 * Definition of Drupal\Core\DrupalKernel.
 */

namespace Drupal\Core;

use Drupal\Core\Config\CachedStorage;
use Drupal\Core\Cache\CacheFactory;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\CoreBundle;
use Drupal\Core\Database\Database;
use Drupal\Core\EventSubscriber\ConfigGlobalOverrideSubscriber;
use Drupal\Core\ExtensionHandler;
use Symfony\Component\HttpKernel\Kernel;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * The DrupalKernel class is the core of Drupal itself.
 *
 * This class is responsible for building the Dependency Injection Container and
 * also deals with the registration of bundles. It allows registered bundles to
 * add their services to the container. Core provides the CoreBundle, which adds
 * the services required for all core subsystems. Each module can then add its
 * own bundle, i.e. a subclass of Symfony\Component\HttpKernel\Bundle, to
 * register services to the container.
 */
class DrupalKernel extends Kernel {

  protected $cache_config;

  protected $config_storage;

  protected $extension_handler;

  protected $config_global_override_sub;

  protected $config_factory;

  protected $dispatcher;

  /**
   * Overrides Kernel::init().
   */
  public function init() {
    // Intentionally empty. The sole purpose is to not execute Kernel::init(),
    // since that overrides/breaks Drupal's current error handling.
    // @todo Investigate whether it is possible to migrate Drupal's error
    //   handling to the one of Kernel without losing functionality.
  }

  public function boot() {
    parent::boot();
    drupal_bootstrap(DRUPAL_BOOTSTRAP_CODE);
  }

  /**
   * Returns an array of available bundles.
   */
  public function registerBundles() {
    $bundles = array(
      new CoreBundle(),
    );

    $cache = CacheFactory::get('cache');
    $bootstrap_cache = CacheFactory::get('bootstrap');
    
    $this->config_cached_storage = new FileStorage(config_get_config_directory(CONFIG_ACTIVE_DIRECTORY));
    $this->cache_config = CacheFactory::get('config');
    $this->config_storage = new CachedStorage($this->config_cached_storage, $this->cache_config);
    $this->config_global_override_sub = new ConfigGlobalOverrideSubscriber();
    $this->dispatcher = new EventDispatcher();
    $this->dispatcher->addSubscriber($this->config_global_override_sub);
    $this->config_factory = new ConfigFactory($this->config_storage, $this->dispatcher);

    $this->extension_handler = new ExtensionHandler($this->config_factory, $cache, $bootstrap_cache);

    $modules = array_keys($this->extension_handler->systemList('module_enabled'));
    foreach ($modules as $module) {
      $camelized = ContainerBuilder::camelize($module);
      $class = "Drupal\\{$module}\\{$camelized}Bundle";
      if (class_exists($class)) {
        $bundles[] = new $class();
      }
    }
    return $bundles;
  }

  /**
   * Initializes the service container.
   */
  protected function initializeContainer() {
    // @todo We should be compiling the container and dumping to php so we don't
    //   have to recompile every time. There is a separate issue for this, see
    //   http://drupal.org/node/1668892.
    $this->container = $this->buildContainer();
    $this->container->set('kernel', $this);
    drupal_container($this->container);
  }

  /**
   * Builds the service container.
   *
   * @return ContainerBuilder The compiled service container
   */
  protected function buildContainer() {
    $container = $this->getContainerBuilder();
    // Add the objects we have instantiated as synthetic services to the DIC.
    $container->register('config.cachedstorage.storage', 'Drupal\Core\Config\FileStorage')
      ->setSynthetic(TRUE);
    $container->set('config.cachedstorage.storage', $this->config_cached_storage);

    $container->register('cache.config')->setSynthetic(TRUE);
    $container->set('cache.config', $this->cache_config);

    $container->register('config.storage', 'Drupal\Core\Config\CachedStorage')
      ->setSynthetic(TRUE);
    $container->set('config.storage', $this->config_storage);

    $container->register('config.subscriber.globalconf', 'Drupal\Core\EventSubscriber\ConfigGlobalOverrideSubscriber')
      ->setSynthetic(TRUE);
    $container->set('config.subscriber.globalconf', $this->config_global_override_sub);

    $container->register('dispatcher', 'Symfony\Component\EventDispatcher\EventDispatcher')
      ->setSynthetic(TRUE);
    $container->set('dispatcher', $this->dispatcher);

    $container->register('config.factory', 'Drupal\Core\Config\ConfigFactory')
      ->setSynthetic(TRUE);
    $container->set('config.factory', $this->config_factory);
    
    $container->register('extension_handler', 'Drupal\Core\ExtensionHandler')
      ->setSynthetic(TRUE);
    $container->set('extension_handler', $this->extension_handler);

    foreach ($this->bundles as $bundle) {
      $bundle->build($container);
    }

    // @todo Compile the container: http://drupal.org/node/1706064.
    //$container->compile();

    return $container;
  }

  /**
   * Gets a new ContainerBuilder instance used to build the service container.
   *
   * @return ContainerBuilder
   */
  protected function getContainerBuilder() {
    return new ContainerBuilder(new ParameterBag($this->getKernelParameters()));
  }

  /**
   * Overrides and eliminates this method from the parent class. Do not use.
   *
   * This method is part of the KernelInterface interface, but takes an object
   * implementing LoaderInterface as its only parameter. This is part of the
   * Config compoment from Symfony, which is not provided by Drupal core.
   *
   * Modules wishing to provide an extension to this class which uses this
   * method are responsible for ensuring the Config component exists.
   */
  public function registerContainerConfiguration(LoaderInterface $loader) {
  }
}
