<?php

/**
 * @file
 * Definition of Drupal\Core\ExtensionHandler.
 */

namespace Drupal\Core;

use Drupal\Component\Graph\Graph;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;

class ExtensionHandler {

  protected $config_factory;

  protected $cache;

  protected $bootstrap_cache;

  protected $lists;

  protected $module_list;

  protected $loaded = FALSE;

  protected $rebuilding = FALSE;

  protected $implementations;

  protected $hook_info;

  protected $alter_functions;

  public function __construct(ConfigFactory $config_factory, CacheBackendInterface $cache, CacheBackendInterface $bootstrap_cache) {
    $this->config_factory = $config_factory;
    $this->cache = $cache;
    $this->bootstrap_cache = $bootstrap_cache;
    // @todo Remove this once the rest of module.inc has been refactored.
    include_once DRUPAL_ROOT . '/core/includes/module.inc';
  }

  /**
   * Loads all the modules that have been enabled in the system table.
   *
   * @param $bootstrap
   *   Whether to load only the reduced set of modules loaded in "bootstrap mode"
   *   for cached pages. See bootstrap.inc.
   *
   * @return
   *   If $bootstrap is NULL, return a boolean indicating whether all modules
   *   have been loaded.
   */
  public function loadAll($bootstrap = FALSE) {
    if (isset($bootstrap) && !$this->loaded) {
      $type = $bootstrap ? 'bootstrap' : 'module_enabled';
      foreach ($this->moduleList($type) as $module) {
        drupal_load('module', $module);
      }
      // $has_run will be TRUE if $bootstrap is FALSE.
      $this->loaded = !$bootstrap;
    }
    return $this->loaded;
  }

  /**
   * Returns a list of currently active modules.
   *
   * Acts as a wrapper around system_list(), returning either a list of all
   * enabled modules, or just modules needed for bootstrap.
   *
   * The returned module list is always based on system_list(). The only exception
   * to that is when a fixed list of modules has been passed in previously, in
   * which case system_list() is omitted and the fixed list is always returned in
   * subsequent calls until manually reverted via module_list_reset().
   *
   * @param string $type
   *   The type of list to return:
   *   - module_enabled: All enabled modules.
   *   - bootstrap: All enabled modules required for bootstrap.
   * @param array $fixed_list
   *   (optional) An array of module names to override the list of modules. This
   *   list will persist until the next call with a new $fixed_list passed in.
   *   Primarily intended for internal use (e.g., in install.php and update.php).
   *   Use module_list_reset() to undo the $fixed_list override.
   * @param bool $reset
   *   (optional) Whether to reset/remove the $fixed_list.
   *
   * @return array
   *   An associative array whose keys and values are the names of the modules in
   *   the list.
   *
   * @see module_list_reset()
   */
  public function moduleList($type = 'module_enabled', array $fixed_list = NULL, $reset = FALSE) {
    if ($reset) {
      $this->module_list = NULL;
      // Do nothing if no $type and no $fixed_list have been passed.
      if (!isset($type) && !isset($fixed_list)) {
        return;
      }
    }

    // The list that will be be returned. Separate from $module_list in order
    // to not duplicate the static cache of system_list().
    $list = $this->module_list;
  
    if (isset($fixed_list)) {
      $this->module_list = array();
      foreach ($fixed_list as $name => $module) {
        drupal_get_filename('module', $name, $module['filename']);
        $this->module_list[$name] = $name;
      }
      $list = $this->module_list;
    }
    elseif (!isset($this->module_list)) {
      $list = $this->systemList($type);
    }
    return $list;
  }

  /**
   * Reverts an enforced fixed list of self::moduleList().
   *
   * Subsequent calls to moduleList() will no longer use a fixed list.
   */
  public function moduleListReset() {
    $this->moduleList(NULL, NULL, TRUE);
  }

  /**
   * Builds a list of bootstrap modules and enabled modules and themes.
   *
   * @param $type
   *   The type of list to return:
   *   - module_enabled: All enabled modules.
   *   - bootstrap: All enabled modules required for bootstrap.
   *   - theme: All themes.
   *
   * @return
   *   An associative array of modules or themes, keyed by name. For $type
   *   'bootstrap' and 'module_enabled', the array values equal the keys.
   *   For $type 'theme', the array values are objects representing the
   *   respective database row, with the 'info' property already unserialized.
   *
   * @see self::moduleList()
   * @see list_themes()
   */
  public function systemList($type) {

    // For bootstrap modules, attempt to fetch the list from cache if possible.
    // if not fetch only the required information to fire bootstrap hooks
    // in case we are going to serve the page from cache.
    if ($type == 'bootstrap') {
      if (isset($this->lists['bootstrap'])) {
        return $this->lists['bootstrap'];
      }
      if ($cached = cache('bootstrap')->get('bootstrap_modules')) {
        $this->lists['bootstrap'] = $cached->data;
        // To avoid a separate database lookup for the filepath, prime the
        // drupal_get_filename() static cache for bootstrap modules only.
        // The rest is stored separately to keep the bootstrap module cache small.
        foreach ($this->lists['bootstrap'] as $module => $filename) {
          $this->systemListWarm('module', $module, $filename);
        }
      }
      else {
        $this->lists = $thi->systemListRebuild();
      }
      // We only return the module names here since module_list() doesn't need
      // the filename itself.
      $this->lists['bootstrap'] = array_keys($this->lists['bootstrap']);
    }
    // Otherwise build the list for enabled modules and themes.
    elseif (!isset($this->lists['module_enabled'])) {
      if ($cached = $this->bootstrap_cache->get('system_list')) {
        $this->lists = $cached->data;
      }
      else {
        if ($this->systemListRebuild(TRUE) && $type == 'module_enabled') {
          return drupal_map_assoc(array_keys($this->config_factory->get('system.module')->load()->get()));
        }
        $this->lists = $this->systemListRebuild();
        $this->lists['bootstrap'] = array_keys($this->lists['bootstrap']);
      }
      foreach ($this->lists['filepaths'] as $item) {
        $this->systemListWarm($item['type'], $item['name'], $item['filepath']);
      }
    }
    return $this->lists[$type];
  }

  /**
   * Prepares a module for loading and optionally calls drupal_load().
   *
   * @param string $type
   *   The type of the extension (i.e. theme, theme_engine, module, profile).
   * @param string $name
   *   The name of the extension.
   * @param string $filename
   *   The filename of the extension.
   * @param bool $load
   *   (optional) Call drupal_load() for the extension. Defaults to FALSE.
   */
  protected function systemListWarm($type, $name, $filename, $load = FALSE) {
    drupal_classloader_register($name, dirname($filename));
    drupal_get_filename($type, $name, $filename);
    if ($load) {
      drupal_load($type, $name);
    }
  }

  /**
   * Resets all system_list() caches.
   */
  public function systemListReset() {
    $this->lists = NULL;
    drupal_static_reset('system_rebuild_module_data');
    drupal_static_reset('list_themes');
    $this->bootstrap_cache->deleteMultiple(array('bootstrap_modules', 'system_list'));
    $this->cache->delete('system_info');
  }

  /**
   * Rebuild the system list data structure.
   */
  protected function systemListRebuild($test_only = FALSE) {
    if ($test_only) {
      return $this->rebuilding;
    }
    $this->rebuilding = TRUE;
    // The module name (rather than the filename) is used as the fallback
    // weighting in order to guarantee consistent behavior across different
    // Drupal installations, which might have modules installed in different
    // locations in the file system. The ordering here must also be
    // consistent with the one used in $this->moduleImplements().
    $enabled_themes = $this->config_factory->get('system.theme')->load()->get();
    $enabled_modules = $this->config_factory->get('system.module')->load()->get();
    $this->systemListWarm('module', 'system', 'core/modules/system.module', TRUE);
    $this->systemListReset();
    $module_data = system_rebuild_module_data(FALSE, $this, $this->config_factory);
    $theme_data = system_rebuild_theme_data(FALSE, $this, $this->config_factory, $this->bootstrap_cache);
    $lists = array(
      'bootstrap' => array(),
      'module_enabled' => array(),
      'theme' => array(),
      'filepaths' => array(),
    );
    foreach ($theme_data as $key => $theme) {
      $lists['theme'][$key] = $theme;
      $status = isset($enabled_themes[$key]);
      $lists['theme'][$key]->status = $status;
      $lists['theme'][$key]->name = $key;
      // Build a list of filenames so drupal_get_filename can use it.
      if ($status) {
        $lists['filepaths'][] = array(
          'type' => 'theme',
          'name' => $key,
          'filepath' => $theme->filename
        );
      }
    }
    foreach ($lists['theme'] as $key => $theme) {
      if (!empty($theme->info['base theme'])) {
        // Make a list of the theme's base themes.
        require_once DRUPAL_ROOT . '/core/includes/theme.inc';
        $lists['theme'][$key]->base_themes = drupal_find_base_themes($lists['theme'], $key);
        // Don't proceed if there was a problem with the root base theme.
        if (!current($lists['theme'][$key]->base_themes)) {
          continue;
        }
        // Determine the root base theme.
        $base_key = key($lists['theme'][$key]->base_themes);
        // Add to the list of sub-themes for each of the theme's base themes.
        foreach (array_keys($lists['theme'][$key]->base_themes) as $base_theme) {
          $lists['theme'][$base_theme]->sub_themes[$key] = $lists['theme'][$key]->info['name'];
        }
        // Add the base theme's theme engine info.
        $lists['theme'][$key]->info['engine'] = $lists['theme'][$base_key]->info['engine'];
      }
      else {
        // A plain theme is its own base theme.
        $base_key = $key;
      }
      // Set the theme engine prefix.
      $lists['theme'][$key]->prefix = ($lists['theme'][$key]->info['engine'] == 'theme') ? $base_key : $lists['theme'][$key]->info['engine'];
    }
  
    foreach ($enabled_modules as $name => $weight) {
      // Build a list of all enabled modules.
      $lists['module_enabled'][$name] = $name;
      $filename = $module_data[$name]->filename;
      // Build a list of filenames so drupal_get_filename can use it.
      $lists['filepaths'][] = array(
        'type' => 'module',
        'name' => $name,
        'filepath' => $filename,
      );
      $this->systemListWarm('module', $name, $filename, TRUE);
      foreach (bootstrap_hooks() as $hook) {
        if (function_exists($name .'_' . $hook)) {
          $lists['bootstrap'][$name] = $filename;
        }
      }
    }
    $this->bootstrap_cache->set('system_list', $lists);
    $this->bootstrap_cache->set('bootstrap_modules', $lists['bootstrap']);
    $in_rebuild = FALSE;
    return $lists;
  }

  /**
   * Determines which modules require and are required by each module.
   *
   * @param $files
   *   The array of filesystem objects used to rebuild the cache.
   *
   * @return
   *   The same array with the new keys for each module:
   *   - requires: An array with the keys being the modules that this module
   *     requires.
   *   - required_by: An array with the keys being the modules that will not work
   *     without this module.
   */
  public function buildModuleDependencies($files) {
    foreach ($files as $filename => $file) {
      $graph[$file->name]['edges'] = array();
      if (isset($file->info['dependencies']) && is_array($file->info['dependencies'])) {
        foreach ($file->info['dependencies'] as $dependency) {
          $dependency_data = drupal_parse_dependency($dependency);
          $graph[$file->name]['edges'][$dependency_data['name']] = $dependency_data;
        }
      }
    }
    $graph_object = new Graph($graph);
    $graph = $graph_object->searchAndSort();
    foreach ($graph as $module => $data) {
      $files[$module]->required_by = isset($data['reverse_paths']) ? $data['reverse_paths'] : array();
      $files[$module]->requires = isset($data['paths']) ? $data['paths'] : array();
      $files[$module]->sort = $data['weight'];
    }
    return $files;
  }

  /**
   * Determines whether a given module exists.
   *
   * @param $module
   *   The name of the module (without the .module extension).
   *
   * @return
   *   TRUE if the module is both installed and enabled.
   */
  function moduleExists($module) {
    return isset($this->module_list[$module]);
  }

  /**
   * Loads an include file for each module enabled in the {system} table.
   */
  public function loadAllIncludes($type, $name = NULL) {
    $modules = $this->moduleList();
    foreach ($modules as $module) {
      module_load_include($type, $module, $name);
    }
  }


  /**
   * Determines which modules are implementing a hook.
   *
   * @param $hook
   *   The name of the hook (e.g. "help" or "menu").
   *
   * @return
   *   An array with the names of the modules which are implementing this hook.
   *
   * @see module_implements_write_cache()
   */
  public function moduleImplements($hook) {
    // Fetch implementations from cache.
    if (empty($this->implementations)) {
      $implementations = $this->bootstrap_cache->get('module_implements');
      if ($implementations === FALSE) {
        $this->implementations = array();
      }
      else {
        $this->implementations = $implementations->data;
      }
    }

    if (!isset($this->implementations[$hook])) {
      // The hook is not cached, so ensure that whether or not it has
      // implementations, that the cache is updated at the end of the request.
      $this->implementations['#write_cache'] = TRUE;
      $hook_info = $this->moduleHookInfo();
      $this->implementations[$hook] = array();
      foreach ($this->moduleList() as $module) {
        $include_file = isset($hook_info[$hook]['group']) && module_load_include('inc', $module, $module . '.' . $hook_info[$hook]['group']);
        // Since module_hook() may needlessly try to load the include file again,
        // function_exists() is used directly here.
        if (function_exists($module . '_' . $hook)) {
          $this->implementations[$hook][$module] = $include_file ? $hook_info[$hook]['group'] : FALSE;
        }
      }
      // Allow modules to change the weight of specific implementations but avoid
      // an infinite loop.
      if ($hook != 'module_implements_alter') {
        $this->alter('module_implements', $this->implementations[$hook], $hook);
      }
    }
    else {
      foreach ($this->implementations[$hook] as $module => $group) {
        // If this hook implementation is stored in a lazy-loaded file, so include
        // that file first.
        if ($group) {
          module_load_include('inc', $module, "$module.$group");
        }
        // It is possible that a module removed a hook implementation without the
        // implementations cache being rebuilt yet, so we check whether the
        // function exists on each request to avoid undefined function errors.
        // Since module_hook() may needlessly try to load the include file again,
        // function_exists() is used directly here.
        if (!function_exists($module . '_' . $hook)) {
          // Clear out the stale implementation from the cache and force a cache
          // refresh to forget about no longer existing hook implementations.
          unset($this->implementations[$hook][$module]);
          $this->implementations['#write_cache'] = TRUE;
        }
      }
    }
  
    return array_keys($this->implementations[$hook]);
  }
  
  /**
   * Regenerates the stored list of hook implementations.
   */
  public function moduleImplementsReset() {
    // We maintain a persistent cache of hook implementations in addition to the
    // static cache to avoid looping through every module and every hook on each
    // request. Benchmarks show that the benefit of this caching outweighs the
    // additional database hit even when using the default database caching
    // backend and only a small number of modules are enabled. The cost of the
    // cache('bootstrap')->get() is more or less constant and reduced further when
    // non-database caching backends are used, so there will be more significant
    // gains when a large number of modules are installed or hooks invoked, since
    // this can quickly lead to module_hook() being called several thousand times
    // per request.
    $this->implementations = NULL;
    $this->bootstrap_cache->set('module_implements', array());
    $this->hook_info = NULL;
    $this->alter_functions = NULL;
    $this->bootstrap_cache->delete('hook_info');
  }
  
  /**
   * Retrieves a list of what hooks are explicitly declared.
   */
  public function moduleHookInfo() {
    // When this function is indirectly invoked from bootstrap_invoke_all() prior
    // to all modules being loaded, we do not want to cache an incomplete
    // hook_hook_info() result, so instead return an empty array. This requires
    // bootstrap hook implementations to reside in the .module file, which is
    // optimal for performance anyway.
    if (!$this->loadAll(NULL)) {
      return array();
    }
  
    if (!isset($this->hook_info)) {
      $this->hook_info = array();
      $cache = $this->bootstrap_cache->get('hook_info');
      if ($cache === FALSE) {
        // Rebuild the cache and save it.
        // We can't use $this->moduleInvokeAll() here or it would cause an infinite
        // loop.
        foreach ($this->moduleList() as $module) {
          $function = $module . '_hook_info';
          if (function_exists($function)) {
            $result = $function();
            if (isset($result) && is_array($result)) {
              $this->hook_info = array_merge_recursive($this->hook_info, $result);
            }
          }
        }
        // We can't use $this->alter() for the same reason as above.
        foreach ($this->moduleList() as $module) {
          $function = $module . '_hook_info_alter';
          if (function_exists($function)) {
            $function($this->hook_info);
          }
        }
        $this->bootstrap_cache->set('hook_info', $this->hook_info);
      }
      else {
        $this->hook_info = $cache->data;
      }
    }
  
    return $this->hook_info;
  }
  
  /**
   * Writes the hook implementation cache.
   *
   * @see $this->moduleImplements()
   */
  public function moduleImplementsWriteCache() {
    // Check whether we need to write the cache. We do not want to cache hooks
    // which are only invoked on HTTP POST requests since these do not need to be
    // optimized as tightly, and not doing so keeps the cache entry smaller.
    if (isset($this->implementations['#write_cache']) && ($_SERVER['REQUEST_METHOD'] == 'GET' || $_SERVER['REQUEST_METHOD'] == 'HEAD')) {
      unset($this->implementations['#write_cache']);
      $this->bootstrap_cache->set('module_implements', $this->implementations);
    }
  }

  /**
   * Invokes a hook in all enabled modules that implement it.
   *
   * @param $hook
   *   The name of the hook to invoke.
   * @param ...
   *   Arguments to pass to the hook.
   *
   * @return
   *   An array of return values of the hook implementations. If modules return
   *   arrays from their implementations, those are merged into one array.
   */
  public function moduleInvokeAll($hook) {
    $args = func_get_args();
    // Remove $hook from the arguments.
    unset($args[0]);
    $return = array();
    foreach ($this->moduleImplements($hook) as $module) {
      $function = $module . '_' . $hook;
      if (function_exists($function)) {
        $result = call_user_func_array($function, $args);
        if (isset($result) && is_array($result)) {
          $return = array_merge_recursive($return, $result);
        }
        elseif (isset($result)) {
          $return[] = $result;
        }
      }
    }
  
    return $return;
  }

  /**
   * Passes alterable variables to specific hook_TYPE_alter() implementations.
   *
   * This dispatch function hands off the passed-in variables to type-specific
   * hook_TYPE_alter() implementations in modules. It ensures a consistent
   * interface for all altering operations.
   *
   * A maximum of 2 alterable arguments is supported. In case more arguments need
   * to be passed and alterable, modules provide additional variables assigned by
   * reference in the last $context argument:
   * @code
   *   $context = array(
   *     'alterable' => &$alterable,
   *     'unalterable' => $unalterable,
   *     'foo' => 'bar',
   *   );
   *   $this->alter('mymodule_data', $alterable1, $alterable2, $context);
   * @endcode
   *
   * Note that objects are always passed by reference in PHP5. If it is absolutely
   * required that no implementation alters a passed object in $context, then an
   * object needs to be cloned:
   * @code
   *   $context = array(
   *     'unalterable_object' => clone $object,
   *   );
   *   $this->alter('mymodule_data', $data, $context);
   * @endcode
   *
   * @param $type
   *   A string describing the type of the alterable $data. 'form', 'links',
   *   'node_content', and so on are several examples. Alternatively can be an
   *   array, in which case hook_TYPE_alter() is invoked for each value in the
   *   array, ordered first by module, and then for each module, in the order of
   *   values in $type. For example, when Form API is using $this->alter() to
   *   execute both hook_form_alter() and hook_form_FORM_ID_alter()
   *   implementations, it passes array('form', 'form_' . $form_id) for $type.
   * @param $data
   *   The variable that will be passed to hook_TYPE_alter() implementations to be
   *   altered. The type of this variable depends on the value of the $type
   *   argument. For example, when altering a 'form', $data will be a structured
   *   array. When altering a 'profile', $data will be an object.
   * @param $context1
   *   (optional) An additional variable that is passed by reference.
   * @param $context2
   *   (optional) An additional variable that is passed by reference. If more
   *   context needs to be provided to implementations, then this should be an
   *   associative array as described above.
   */
  public function alter($type, &$data, &$context1 = NULL, &$context2 = NULL) {
    // Most of the time, $type is passed as a string, so for performance,
    // normalize it to that. When passed as an array, usually the first item in
    // the array is a generic type, and additional items in the array are more
    // specific variants of it, as in the case of array('form', 'form_FORM_ID').
    if (is_array($type)) {
      $cid = implode(',', $type);
      $extra_types = $type;
      $type = array_shift($extra_types);
      // Allow if statements in this function to use the faster isset() rather
      // than !empty() both when $type is passed as a string, or as an array with
      // one item.
      if (empty($extra_types)) {
        unset($extra_types);
      }
    }
    else {
      $cid = $type;
    }
  
    // Some alter hooks are invoked many times per page request, so statically
    // cache the list of functions to call, and on subsequent calls, iterate
    // through them quickly.
    if (!isset($this->alter_functions[$cid])) {
      $this->alter_functions[$cid] = array();
      $hook = $type . '_alter';
      $modules = $this->moduleImplements($hook);
      if (!isset($extra_types)) {
        // For the more common case of a single hook, we do not need to call
        // function_exists(), since $this->moduleImplements() returns only modules with
        // implementations.
        foreach ($modules as $module) {
          $this->alter_functions[$cid][] = $module . '_' . $hook;
        }
      }
      else {
        // For multiple hooks, we need $modules to contain every module that
        // implements at least one of them.
        $extra_modules = array();
        foreach ($extra_types as $extra_type) {
          $extra_modules = array_merge($extra_modules, $this->moduleImplements($extra_type . '_alter'));
        }
        // If any modules implement one of the extra hooks that do not implement
        // the primary hook, we need to add them to the $modules array in their
        // appropriate order. $this->moduleImplements() can only return ordered
        // implementations of a single hook. To get the ordered implementations
        // of multiple hooks, we mimic the $this->moduleImplements() logic of first
        // ordering by $this->moduleList(), and then calling
        // $this->alter('module_implements').
        if (array_diff($extra_modules, $modules)) {
          // Merge the arrays and order by moduleList().
          $modules = array_intersect($this->moduleList(), array_merge($modules, $extra_modules));
          // Since $this->moduleImplements() already took care of loading the necessary
          // include files, we can safely pass FALSE for the array values.
          $implementations = array_fill_keys($modules, FALSE);
          // Let modules adjust the order solely based on the primary hook. This
          // ensures the same module order regardless of whether this if block
          // runs. Calling $this->alter() recursively in this way does not result
          // in an infinite loop, because this call is for a single $type, so we
          // won't end up in this code block again.
          $this->alter('module_implements', $implementations, $hook);
          $modules = array_keys($implementations);
        }
        foreach ($modules as $module) {
          // Since $modules is a merged array, for any given module, we do not
          // know whether it has any particular implementation, so we need a
          // function_exists().
          $function = $module . '_' . $hook;
          if (function_exists($function)) {
            $this->alter_functions[$cid][] = $function;
          }
          foreach ($extra_types as $extra_type) {
            $function = $module . '_' . $extra_type . '_alter';
            if (function_exists($function)) {
              $this->alter_functions[$cid][] = $function;
            }
          }
        }
      }
      // Allow the theme to alter variables after the theme system has been
      // initialized.
      global $theme, $base_theme_info;
      if (isset($theme)) {
        $theme_keys = array();
        foreach ($base_theme_info as $base) {
          $theme_keys[] = $base->name;
        }
        $theme_keys[] = $theme;
        foreach ($theme_keys as $theme_key) {
          $function = $theme_key . '_' . $hook;
          if (function_exists($function)) {
            $this->alter_functions[$cid][] = $function;
          }
          if (isset($extra_types)) {
            foreach ($extra_types as $extra_type) {
              $function = $theme_key . '_' . $extra_type . '_alter';
              if (function_exists($function)) {
                $this->alter_functions[$cid][] = $function;
              }
            }
          }
        }
      }
    }
  
    foreach ($this->alter_functions[$cid] as $function) {
      $function($data, $context1, $context2);
    }
  }
}