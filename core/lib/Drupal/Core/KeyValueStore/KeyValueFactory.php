<?php
/**
 * Contains Drupal\Core\KeyValueStore\KeyValueFactory
 */

namespace Drupal\Core\KeyValueStore;

class KeyValueFactory {

  /**
   * @param string $collection
   *   The name of the collection holding key and value pairs.
   *
   * @return Drupal\Core\KeyValueStore\DatabaseStorage
   *   A key/value store implementation for the given $collection.
   */
  static function get($collection) {
    return new DatabaseStorage($collection);
  }
}
