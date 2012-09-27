<?php

/**
 * @file
 * A file to test drupal_container()->get('extension_handler')->moduleImplements() loading includes.
 */

/**
 * Implements hook_test_hook().
 */
function module_test_test_hook() {
  return array('module_test' => 'success!');
}
