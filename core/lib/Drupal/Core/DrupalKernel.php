<?php

/**
 * @file
 * Definition of Drupal\Core\DrupalKernel.
 */

namespace Drupal\Core;

use Drupal\Core\Cache\CacheFactory;
use Drupal\Core\CoreBundle;
use Drupal\Core\Database\Database;
use Symfony\Component\HttpKernel\Kernel;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

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

  protected $lists = array();

  /**
   * Overrides Kernel::init().
   */
  public function init() {
    // Intentionally empty. The sole purpose is to not execute Kernel::init(),
    // since that overrides/breaks Drupal's current error handling.
    // @todo Investigate whether it is possible to migrate Drupal's error
    //   handling to the one of Kernel without losing functionality.
  }

  public function systemList($type) {
    $bootstrap_cache = CacheFactory::get('cache_bootstrap');
    $db_connection = Database::getConnection();
    // For bootstrap modules, attempt to fetch the list from cache if possible.
    // if not fetch only the required information to fire bootstrap hooks
    // in case we are going to serve the page from cache.
    if ($type == 'bootstrap') {
      if (isset($this->lists['bootstrap'])) {
        return $this->lists['bootstrap'];
      }
      if ($cached = $bootstrap_cache->get('bootstrap_modules')) {
        $bootstrap_list = $cached->data;
      }
      else {
        $bootstrap_list = $db_connection->query("SELECT name, filename FROM {system} WHERE status = 1 AND bootstrap = 1 AND type = 'module' ORDER BY weight ASC, name ASC")->fetchAllAssoc('name');
        $bootstrap_cache->set('bootstrap_modules', $bootstrap_list);
      }
      // To avoid a separate database lookup for the filepath, prime the
      // drupal_get_filename() static cache for bootstrap modules only.
      // The rest is stored separately to keep the bootstrap module cache small.
      foreach ($bootstrap_list as $module) {
        drupal_classloader_register($module->name, dirname($module->filename));
        drupal_get_filename('module', $module->name, $module->filename);
      }
      // We only return the module names here since module_list() doesn't need
      // the filename itself.
      $this->lists['bootstrap'] = array_keys($bootstrap_list);
    }
    // Otherwise build the list for enabled modules and themes.
    elseif (!isset($this->lists['module_enabled'])) {
      if ($cached = $bootstrap_cache->get('system_list')) {
        $this->lists = $cached->data;
      }
      else {
        $this->lists = array(
          'module_enabled' => array(),
          'theme' => array(),
          'filepaths' => array(),
        );
        // The module name (rather than the filename) is used as the fallback
        // weighting in order to guarantee consistent behavior across different
        // Drupal installations, which might have modules installed in different
        // locations in the file system. The ordering here must also be
        // consistent with the one used in module_implements().
        $result = $db_connection->query("SELECT * FROM {system} WHERE type = 'theme' OR (type = 'module' AND status = 1) ORDER BY weight ASC, name ASC");
        foreach ($result as $record) {
          // Build a list of all enabled modules.
          if ($record->type == 'module') {
            $this->lists['module_enabled'][$record->name] = $record->name;
          }
          // Build a list of themes.
          if ($record->type == 'theme') {
            $record->info = unserialize($record->info);
            $this->lists['theme'][$record->name] = $record;
          }
          // Build a list of filenames so drupal_get_filename can use it.
          if ($record->status) {
            $this->lists['filepaths'][] = array('type' => $record->type, 'name' => $record->name, 'filepath' => $record->filename);
          }
        }
        foreach ($this->lists['theme'] as $key => $theme) {
          if (!empty($theme->info['base theme'])) {
            // Make a list of the theme's base themes.
            require_once DRUPAL_ROOT . '/core/includes/theme.inc';
            $this->lists['theme'][$key]->base_themes = drupal_find_base_themes($this->lists['theme'], $key);
            // Don't proceed if there was a problem with the root base theme.
            if (!current($this->lists['theme'][$key]->base_themes)) {
              continue;
            }
            // Determine the root base theme.
            $base_key = key($this->lists['theme'][$key]->base_themes);
            // Add to the list of sub-themes for each of the theme's base themes.
            foreach (array_keys($this->lists['theme'][$key]->base_themes) as $base_theme) {
              $this->lists['theme'][$base_theme]->sub_themes[$key] = $this->lists['theme'][$key]->info['name'];
            }
            // Add the base theme's theme engine info.
            $this->lists['theme'][$key]->info['engine'] = $this->lists['theme'][$base_key]->info['engine'];
          }
          else {
            // A plain theme is its own base theme.
            $base_key = $key;
          }
          // Set the theme engine prefix.
          $this->lists['theme'][$key]->prefix = ($this->lists['theme'][$key]->info['engine'] == 'theme') ? $base_key : $this->lists['theme'][$key]->info['engine'];
        }
        $bootstrap_cache->set('system_list', $this->lists);
      }
      // To avoid a separate database lookup for the filepath, prime the
      // drupal_get_filename() static cache with all enabled modules and themes.
      foreach ($this->lists['filepaths'] as $item) {
        drupal_get_filename($item['type'], $item['name'], $item['filepath']);
        drupal_classloader_register($item['name'], dirname($item['filepath']));
      }
    }
    return $this->lists[$type];
  }

  /**
   * Overrides Kernel::boot().
   */
  public function boot() {
    parent::boot();
    // We bootstrap code here rather than init() so that we don't do the heavy
    // code-load during a cache hit.
    drupal_bootstrap(DRUPAL_BOOTSTRAP_CODE);
  }

  /**
   * Returns an array of available bundles.
   */
  public function registerBundles() {
    $bundles = array(
      new CoreBundle(),
    );

    $modules = array_keys($this->systemList('module_enabled'));
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

    // Merge in the minimal bootstrap container.
    if ($bootstrap_container = drupal_container()) {
      $container->merge($bootstrap_container);
    }
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
