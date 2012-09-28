<?php

/**
 * @file
 * API for loading and interacting with Drupal modules.
 */

use Drupal\Component\Graph\Graph;
use Drupal\Core\KeyValueStore\KeyValueFactory;

/**
 * Loads a module's installation hooks.
 *
 * @param $module
 *   The name of the module (without the .module extension).
 *
 * @return
 *   The name of the module's install file, if successful; FALSE otherwise.
 */
function module_load_install($module) {
  // Make sure the installation API is available
  include_once DRUPAL_ROOT . '/core/includes/install.inc';

  return module_load_include('install', $module);
}

/**
 * Loads a module include file.
 *
 * Examples:
 * @code
 *   // Load node.admin.inc from the node module.
 *   module_load_include('inc', 'node', 'node.admin');
 *   // Load content_types.inc from the node module.
 *   module_load_include('inc', 'node', 'content_types');
 * @endcode
 *
 * Do not use this function to load an install file, use module_load_install()
 * instead. Do not use this function in a global context since it requires
 * Drupal to be fully bootstrapped, use require_once DRUPAL_ROOT . '/path/file'
 * instead.
 *
 * @param $type
 *   The include file's type (file extension).
 * @param $module
 *   The module to which the include file belongs.
 * @param $name
 *   (optional) The base file name (without the $type extension). If omitted,
 *   $module is used; i.e., resulting in "$module.$type" by default.
 *
 * @return
 *   The name of the included file, if successful; FALSE otherwise.
 */
function module_load_include($type, $module, $name = NULL) {
  if (!isset($name)) {
    $name = $module;
  }

  if (function_exists('drupal_get_path')) {
    $file = DRUPAL_ROOT . '/' . drupal_get_path('module', $module) . "/$name.$type";
    if (is_file($file)) {
      require_once $file;
      return $file;
    }
  }
  return FALSE;
}

/**
 * Enables or installs a given list of modules.
 *
 * Definitions:
 * - "Enabling" is the process of activating a module for use by Drupal.
 * - "Disabling" is the process of deactivating a module.
 * - "Installing" is the process of enabling it for the first time or after it
 *   has been uninstalled.
 * - "Uninstalling" is the process of removing all traces of a module.
 *
 * Order of events:
 * - Gather and add module dependencies to $module_list (if applicable).
 * - For each module that is being enabled:
 *   - Install module schema and update system registries and caches.
 *   - If the module is being enabled for the first time or had been
 *     uninstalled, invoke hook_install() and add it to the list of installed
 *     modules.
 *   - Invoke hook_enable().
 * - Invoke hook_modules_installed().
 * - Invoke hook_modules_enabled().
 *
 * @param $module_list
 *   An array of module names.
 * @param $enable_dependencies
 *   If TRUE, dependencies will automatically be added and enabled in the
 *   correct order. This incurs a significant performance cost, so use FALSE
 *   if you know $module_list is already complete and in the correct order.
 *
 * @return
 *   FALSE if one or more dependencies are missing, TRUE otherwise.
 *
 * @see hook_install()
 * @see hook_enable()
 * @see hook_modules_installed()
 * @see hook_modules_enabled()
 */
function module_enable($module_list, $enable_dependencies = TRUE) {
  // Get all module data so we can find dependencies, sort and copy to the
  // installed modules config.
  $all_modules = system_rebuild_module_data();
  if ($enable_dependencies) {
    // Create an associative array with weights as values.
    $module_list = array_flip(array_values($module_list));

    while (list($module) = each($module_list)) {
      if (!isset($all_modules[$module])) {
        // This module is not found in the filesystem, abort.
        return FALSE;
      }
      if ($all_modules[$module]->status) {
        // Skip already enabled modules.
        unset($module_list[$module]);
        continue;
      }
      $module_list[$module] = $all_modules[$module]->sort;

      // Add dependencies to the list, with a placeholder weight.
      // The new modules will be processed as the while loop continues.
      foreach (array_keys($all_modules[$module]->requires) as $dependency) {
        if (!isset($module_list[$dependency])) {
          $module_list[$dependency] = 0;
        }
      }
    }

    if (!$module_list) {
      // Nothing to do. All modules already enabled.
      return TRUE;
    }

    // Sort the module list by pre-calculated weights.
    arsort($module_list);
    $module_list = array_keys($module_list);
  }

  // Required for module installation checks.
  include_once DRUPAL_ROOT . '/core/includes/install.inc';

  $modules_installed = array();
  $modules_enabled = array();
  $schema_store = drupal_container()->get('keyvalue')->get('system.module.schema');
  foreach ($module_list as $module) {
    $config = config('system.module');
    $disabled_config = config('system.module.disabled');
    // Only process modules that are not already enabled.
    $enabled = TRUE;
    $weight = 0;
    if (!$schema_store->get($module)) {
      $enabled = FALSE;
    }
    elseif (!$config->get($module)) {
      $enabled = FALSE;
      $weight = $disabled_config->get($module);
    }
    if (!$enabled) {
      $config
        ->set($module, $weight)
        ->save('module_config_sort');
      if ($weight) {
        $disabled_config
          ->clear($module)
          ->save();
      }
      // Load the module's code.
      drupal_load('module', $module);
      module_load_install($module);

      // Refresh the module list to include it.
      drupal_container()->get('extension_handler')->systemListReset();
      drupal_container()->get('extension_handler')->moduleImplementsReset();
      // Refresh the schema to include it.
      drupal_get_schema(NULL, TRUE);
      // Update the theme registry to include it.
      drupal_theme_rebuild();

      // Allow modules to react prior to the installation of a module.
      drupal_container()->get('extension_handler')->moduleInvokeAll('modules_preinstall', array($module));

      // Clear the entity info cache before importing new configuration.
      entity_info_cache_clear();

      // Now install the module if necessary.
      if (drupal_get_installed_schema_version($module, TRUE) == SCHEMA_UNINSTALLED) {
        drupal_install_schema($module);

        // Set the schema version to the number of the last update provided
        // by the module.
        $versions = drupal_get_schema_versions($module);
        $version = $versions ? max($versions) : SCHEMA_INSTALLED;

        // Install default configuration of the module.
        config_install_default_config('module', $module);

        // If the module has no current updates, but has some that were
        // previously removed, set the version to the value of
        // hook_update_last_removed().
        if ($last_removed = module_invoke($module, 'update_last_removed')) {
          $version = max($version, $last_removed);
        }
        drupal_set_installed_schema_version($module, $version);
        // Allow the module to perform install tasks.
        module_invoke($module, 'install');
        // Record the fact that it was installed.
        $modules_installed[] = $module;
        watchdog('system', '%module module installed.', array('%module' => $module), WATCHDOG_INFO);
      }

      // Allow modules to react prior to the enabling of a module.
      drupal_container()->get('extension_handler')->moduleInvokeAll('modules_preenable', array($module));

      // Enable the module.
      module_invoke($module, 'enable');

      // Record the fact that it was enabled.
      $modules_enabled[] = $module;
      watchdog('system', '%module module enabled.', array('%module' => $module), WATCHDOG_INFO);
    }
  }

  // If any modules were newly installed, invoke hook_modules_installed().
  if (!empty($modules_installed)) {
    drupal_container()->get('extension_handler')->moduleInvokeAll('modules_installed', $modules_installed);
  }

  // If any modules were newly enabled, invoke hook_modules_enabled().
  if (!empty($modules_enabled)) {
    drupal_container()->get('extension_handler')->moduleInvokeAll('modules_enabled', $modules_enabled);
  }

  return TRUE;
}

/**
 * Disables a given set of modules.
 *
 * @param $module_list
 *   An array of module names.
 * @param $disable_dependents
 *   If TRUE, dependent modules will automatically be added and disabled in the
 *   correct order. This incurs a significant performance cost, so use FALSE
 *   if you know $module_list is already complete and in the correct order.
 */
function module_disable($module_list, $disable_dependents = TRUE) {
  $extension_handler = drupal_container()->get('extension_handler');
  if ($disable_dependents) {
    // Get all module data so we can find dependents and sort.
    $module_data = system_rebuild_module_data();
    // Create an associative array with weights as values.
    $module_list = array_flip(array_values($module_list));

    $profile = drupal_get_profile();
    while (list($module) = each($module_list)) {
      if (!isset($module_data[$module]) || !$module_data[$module]->status) {
        // This module doesn't exist or is already disabled, skip it.
        unset($module_list[$module]);
        continue;
      }
      $module_list[$module] = $module_data[$module]->sort;

      // Add dependent modules to the list, with a placeholder weight.
      // The new modules will be processed as the while loop continues.
      foreach ($module_data[$module]->required_by as $dependent => $dependent_data) {
        if (!isset($module_list[$dependent]) && $dependent != $profile) {
          $module_list[$dependent] = 0;
        }
      }
    }

    // Sort the module list by pre-calculated weights.
    asort($module_list);
    $module_list = array_keys($module_list);
  }

  $invoke_modules = array();

  foreach ($module_list as $module) {
    if (module_exists($module)) {
      $config = config('system.module');
      $disabled_config = config('system.module.disabled');
      module_load_install($module);
      module_invoke($module, 'disable');
      if ($weight = $config->get($module)) {
        $disabled_config
          ->set($module, $weight)
          ->save();
      }
      $config
        ->clear($module)
        ->save('module_config_sort');
      $invoke_modules[] = $module;
      watchdog('system', '%module module disabled.', array('%module' => $module), WATCHDOG_INFO);
    }
  }

  if (!empty($invoke_modules)) {
    // Refresh the module list to exclude the disabled modules.
    $extension_handler->systemListReset();
    drupal_container()->get('extension_handler')->moduleImplementsReset();
    entity_info_cache_clear();
    // Invoke hook_modules_disabled before disabling modules,
    // so we can still call module hooks to get information.
    drupal_container()->get('extension_handler')->moduleInvokeAll('modules_disabled', $invoke_modules);
    // Update the theme registry to remove the newly-disabled module.
    drupal_theme_rebuild();
  }
}

/**
 * Uninstalls a given list of modules.
 *
 * @param $module_list
 *   The modules to uninstall.
 * @param $uninstall_dependents
 *   If TRUE, the function will check that all modules which depend on the
 *   passed-in module list either are already uninstalled or contained in the
 *   list, and it will ensure that the modules are uninstalled in the correct
 *   order. This incurs a significant performance cost, so use FALSE if you
 *   know $module_list is already complete and in the correct order.
 *
 * @return
 *   FALSE if one or more dependent modules are missing from the list, TRUE
 *   otherwise.
 */
function module_uninstall($module_list = array(), $uninstall_dependents = TRUE) {
  if ($uninstall_dependents) {
    // Get all module data so we can find dependents and sort.
    $module_data = system_rebuild_module_data();
    // Create an associative array with weights as values.
    $module_list = array_flip(array_values($module_list));

    $profile = drupal_get_profile();
    while (list($module) = each($module_list)) {
      if (!isset($module_data[$module]) || drupal_get_installed_schema_version($module) == SCHEMA_UNINSTALLED) {
        // This module doesn't exist or is already uninstalled, skip it.
        unset($module_list[$module]);
        continue;
      }
      $module_list[$module] = $module_data[$module]->sort;

      // If the module has any dependents which are not already uninstalled and
      // not included in the passed-in list, abort. It is not safe to uninstall
      // them automatically because uninstalling a module is a destructive
      // operation.
      foreach (array_keys($module_data[$module]->required_by) as $dependent) {
        if (!isset($module_list[$dependent]) && drupal_get_installed_schema_version($dependent) != SCHEMA_UNINSTALLED && $dependent != $profile) {
          return FALSE;
        }
      }
    }

    // Sort the module list by pre-calculated weights.
    asort($module_list);
    $module_list = array_keys($module_list);
  }

  $storage = drupal_container()->get('config.storage');
  $schema_store = drupal_container()->get('keyvalue')->get('system.module.schema');
  $disabled_config = config('system.module.disabled');
  foreach ($module_list as $module) {
    // Uninstall the module.
    module_load_install($module);
    module_invoke($module, 'uninstall');
    drupal_uninstall_schema($module);

    // Remove all configuration belonging to the module.
    $config_names = $storage->listAll($module . '.');
    foreach ($config_names as $config_name) {
      config($config_name)->delete();
    }

    watchdog('system', '%module module uninstalled.', array('%module' => $module), WATCHDOG_INFO);
    $schema_store->delete($module);
    $disabled_config->clear($module);
  }
  $disabled_config->save();
  drupal_get_installed_schema_version(NULL, TRUE);

  if (!empty($module_list)) {
    // Call hook_module_uninstall to let other modules act
    drupal_container()->get('extension_handler')->moduleInvokeAll('modules_uninstalled', $module_list);
  }

  return TRUE;
}

/**
 * @defgroup hooks Hooks
 * @{
 * Allow modules to interact with the Drupal core.
 *
 * Drupal's module system is based on the concept of "hooks". A hook is a PHP
 * function that is named foo_bar(), where "foo" is the name of the module
 * (whose filename is thus foo.module) and "bar" is the name of the hook. Each
 * hook has a defined set of parameters and a specified result type.
 *
 * To extend Drupal, a module need simply implement a hook. When Drupal wishes
 * to allow intervention from modules, it determines which modules implement a
 * hook and calls that hook in all enabled modules that implement it.
 *
 * The available hooks to implement are explained here in the Hooks section of
 * the developer documentation. The string "hook" is used as a placeholder for
 * the module name in the hook definitions. For example, if the module file is
 * called example.module, then hook_help() as implemented by that module would
 * be defined as example_help().
 *
 * The example functions included are not part of the Drupal core, they are
 * just models that you can modify. Only the hooks implemented within modules
 * are executed when running Drupal.
 *
 * See also @link themeable the themeable group page. @endlink
 */

/**
 * Determines whether a module implements a hook.
 *
 * @param $module
 *   The name of the module (without the .module extension).
 * @param $hook
 *   The name of the hook (e.g. "help" or "menu").
 *
 * @return
 *   TRUE if the module is both installed and enabled, and the hook is
 *   implemented in that module.
 */
function module_hook($module, $hook) {
  $function = $module . '_' . $hook;
  if (function_exists($function)) {
    return TRUE;
  }
  // If the hook implementation does not exist, check whether it may live in an
  // optional include file registered via hook_hook_info().
  $hook_info = drupal_container()->get('extension_handler')->moduleHookInfo();
  if (isset($hook_info[$hook]['group'])) {
    module_load_include('inc', $module, $module . '.' . $hook_info[$hook]['group']);
    if (function_exists($function)) {
      return TRUE;
    }
  }
  return FALSE;
}

/**
 * Invokes a hook in a particular module.
 *
 * @param $module
 *   The name of the module (without the .module extension).
 * @param $hook
 *   The name of the hook to invoke.
 * @param ...
 *   Arguments to pass to the hook implementation.
 *
 * @return
 *   The return value of the hook implementation.
 */
function module_invoke($module, $hook) {
  $args = func_get_args();
  // Remove $module and $hook from the arguments.
  unset($args[0], $args[1]);
  if (module_hook($module, $hook)) {
    return call_user_func_array($module . '_' . $hook, $args);
  }
}

/**
 * @} End of "defgroup hooks".
 */

/**
 * Returns an array of modules required by core.
 */
function drupal_required_modules() {
  $files = drupal_system_listing('/^' . DRUPAL_PHP_FUNCTION_PATTERN . '\.info$/', 'modules', 'name', 0);
  $required = array();

  // An install profile is required and one must always be loaded.
  $required[] = drupal_get_profile();

  foreach ($files as $name => $file) {
    $info = drupal_parse_info_file($file->uri);
    if (!empty($info) && !empty($info['required']) && $info['required']) {
      $required[] = $name;
    }
  }

  return $required;
}


/**
 * Sets weight of a particular module.
 *
 * @param string $module
 *   The name of the module (without the .module extension).
 * @param int $weight
 *   An integer representing the weight of the module.
 */
function module_set_weight($module, $weight) {
  $config = config('system.module');

  // If the module is enabled it will be in the system.module config file.
  if ($config->get($module) !== NULL) {
    $config
      ->set($module, $weight)
      ->save('module_config_sort');
  }
  // Before writing to the system.module.disabled file ensure that the module
  // has been previously enabled.
  else if (drupal_container()->get('keyvalue')->get('system.module.schema')->get($module) !== FALSE) {
    $config_disabled = config('system.module.disabled');
    // Only save non-zero weights to the system.module.disabled config file.
    if ($weight) {
      $config_disabled
        ->set($module, $weight)
        ->save();
    }
    else {
      $config_disabled
        ->clear($module)
        ->save();
    }
  }
}

/**
 * Helper function for Drupal\Core\Config\Config::save() to sort module data.
 *
 * @param array $data
 *   An array of module configuration data.
 *
 * @return array
 *   An array of module configuration data sorted by weight.
 */
function module_config_sort($data) {
  $sort = array();
  foreach ($data as $name => $weight) {
    // We can't use the sign directly because + (ASCII 43) is before
    // - (ASCII 45). So negative nubmers get a 0, non-negative numbers
    // a 1 prefix.
    $prefix = (int) ($weight >= 0);
    // PHP_INT_MAX is at most 19 characters so make every number equally
    // 19 digits long.
    $sort[] = $prefix . sprintf('%019d', abs($weight)) . $name;
  }
  array_multisort($sort, SORT_STRING, $data);
  return $data;
}
