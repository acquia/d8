<?php

/**
 * @file
 * Hooks provided by the Field UI module.
 */

/**
 * @addtogroup field_types
 * @{
 */

/**
 * Add settings to a field settings form.
 *
 * Invoked from field_ui_field_settings_form() to allow the module defining the
 * field to add global settings (i.e. settings that do not depend on the bundle
 * or instance) to the field settings form. If the field already has data, only
 * include settings that are safe to change.
 *
 * @todo: Only the field type module knows which settings will affect the
 * field's schema, but only the field storage module knows what schema
 * changes are permitted once a field already has data. Probably we need an
 * easy way for a field type module to ask whether an update to a new schema
 * will be allowed without having to build up a fake $prior_field structure
 * for hook_field_update_forbid().
 *
 * @param $field
 *   The field structure being configured.
 * @param $instance
 *   The instance structure being configured.
 * @param $has_data
 *   TRUE if the field already has data, FALSE if not.
 *
 * @return
 *   The form definition for the field settings.
 */
function hook_field_settings_form($field, $instance, $has_data) {
  $settings = $field['settings'];
  $form['max_length'] = array(
    '#type' => 'number',
    '#title' => t('Maximum length'),
    '#default_value' => $settings['max_length'],
    '#required' => FALSE,
    '#min' => 1,
    '#description' => t('The maximum length of the field in characters. Leave blank for an unlimited size.'),
  );
  return $form;
}

/**
 * Add settings to an instance field settings form.
 *
 * Invoked from field_ui_field_edit_form() to allow the module defining the
 * field to add settings for a field instance.
 *
 * @param $field
 *   The field structure being configured.
 * @param $instance
 *   The instance structure being configured.
 *
 * @return
 *   The form definition for the field instance settings.
 */
function hook_field_instance_settings_form($field, $instance) {
  $settings = $instance['settings'];

  $form['text_processing'] = array(
    '#type' => 'radios',
    '#title' => t('Text processing'),
    '#default_value' => $settings['text_processing'],
    '#options' => array(
      t('Plain text'),
      t('Filtered text (user selects text format)'),
    ),
  );
  if ($field['type'] == 'text_with_summary') {
    $form['display_summary'] = array(
      '#type' => 'select',
      '#title' => t('Display summary'),
      '#options' => array(
        t('No'),
        t('Yes'),
      ),
      '#description' => t('Display the summary to allow the user to input a summary value. Hide the summary to automatically fill it with a trimmed portion from the main post.'),
      '#default_value' => !empty($settings['display_summary']) ? $settings['display_summary'] :  0,
    );
  }

  return $form;
}

/**
 * Specify the form elements for a formatter's settings.
 *
 * @param $field
 *   The field structure being configured.
 * @param $instance
 *   The instance structure being configured.
 * @param $view_mode
 *   The view mode being configured.
 * @param $form
 *   The (entire) configuration form array, which will usually have no use here.
 * @param $form_state
 *   The form state of the (entire) configuration form.
 *
 * @return
 *   The form elements for the formatter settings.
 */
function hook_field_formatter_settings_form($field, $instance, $view_mode, $form, &$form_state) {
  $display = $instance['display'][$view_mode];
  $settings = $display['settings'];

  $element = array();

  if ($display['type'] == 'text_trimmed' || $display['type'] == 'text_summary_or_trimmed') {
    $element['trim_length'] = array(
      '#title' => t('Length'),
      '#type' => 'number',
      '#default_value' => $settings['trim_length'],
      '#min' => 1,
      '#required' => TRUE,
    );
  }

  return $element;

}

/**
 * Alter the formatter settings form.
 *
 * @param $element
 *   Form array as returned by hook_field_formatter_settings_form().
 * @param $form_state
 *   The form state of the (entire) configuration form.
 * @param $context
 *   An associative array with the following elements:
 *   - 'module': The module that contains the definition of this formatter.
 *   - 'formatter': The formatter type description array.
 *   - 'field': The field structure being configured.
 *   - 'instance': The instance structure being configured.
 *   - 'view_mode': The view mode being configured.
 *   - 'form': The (entire) configuration form array.
 */
function hook_field_formatter_settings_form_alter(&$element, &$form_state, $context) {
  // Add a mysetting checkbox to the settings form for foo_field fields.
  if ($context['field']['type'] == 'foo_field') {
    $display = $context['instance']['display'][$context['view_mode']];
    $element['mysetting'] = array(
      '#type' => 'checkbox',
      '#title' => t('My setting'),
      '#default_value' => $display['settings']['mysetting'],
    );
  }
}

/**
 * Alter the field formatter settings summary.
 *
 * @param $summary
 *   The summary as returned by hook_field_formatter_settings_summary().
 * @param $context
 *   An associative array with the following elements:
 *   - 'field': The field structure being configured.
 *   - 'instance': The instance structure being configured.
 *   - 'view_mode': The view mode being configured.
 */
function hook_field_formatter_settings_summary_alter(&$summary, $context) {
  // Append a message to the summary when an instance of foo_field has
  // mysetting set to TRUE for the current view mode.
  if ($context['field']['type'] == 'foo_field') {
    $display = $context['instance']['display'][$context['view_mode']];
    if ($display['settings']['mysetting']) {
      $summary .= '<br />' . t('My setting enabled.');
    }
  }
}

/**
 * Return a short summary for the current formatter settings of an instance.
 *
 * If an empty result is returned, the formatter is assumed to have no
 * configurable settings, and no UI will be provided to display a settings
 * form.
 *
 * @param $field
 *   The field structure.
 * @param $instance
 *   The instance structure.
 * @param $view_mode
 *   The view mode for which a settings summary is requested.
 *
 * @return
 *   A string containing a short summary of the formatter settings.
 */
function hook_field_formatter_settings_summary($field, $instance, $view_mode) {
  $display = $instance['display'][$view_mode];
  $settings = $display['settings'];

  $summary = '';

  if ($display['type'] == 'text_trimmed' || $display['type'] == 'text_summary_or_trimmed') {
    $summary = t('Length: @chars chars', array('@chars' => $settings['trim_length']));
  }

  return $summary;
}

/**
 * @} End of "addtogroup field_types".
 */
