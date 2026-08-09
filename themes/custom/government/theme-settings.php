<?php

/**
 * @file
 * Custom theme setting form elements for the Government theme.
 */

use Drupal\Core\Form\FormStateInterface;

/**
 * Implements hook_form_system_theme_settings_alter().
 */
function government_form_system_theme_settings_alter(&$form, FormStateInterface &$form_state, $form_id = NULL) {

  // Header logo height control.
  if (isset($form['header_style_fieldset'])) {
    $form['header_style_fieldset']['uswds_header_logo_height'] = [
      '#type' => 'textfield',
      '#title' => t('Max Header Logo Height'),
      '#description' => t('Set max height for the header logo (e.g., 40px, 50px, 2.5rem). Default is 40px.'),
      '#default_value' => theme_get_setting('uswds_header_logo_height') ?? '40px',
    ];
  }

  // Footer agency logo toggle.
  if (isset($form['footer_style_fieldset'])) {
    $form['footer_style_fieldset']['uswds_footer_agency_show_logo'] = [
      '#type' => 'checkbox',
      '#title' => t('Display footer agency logo?'),
      '#default_value' => theme_get_setting('uswds_footer_agency_show_logo') ?? TRUE,
      '#states' => [
        'visible' => [
          ':input[name="uswds_footer_agency"]' => ['checked' => TRUE],
        ],
      ],
    ];
  }




  // Identifier Settings.
  $form['identifier_style_fieldset'] = [
    '#type' => 'details',
    '#title' => t('Identifier Settings'),
    '#open' => TRUE,
    '#description' => t('Configure settings for the official <a href="@url" target="_blank">USWDS Identifier</a> component.', ['@url' => 'https://designsystem.digital.gov/components/identifier/']),
    'uswds_identifier_enabled' => [
      '#type' => 'checkbox',
      '#title' => t('Display USWDS Identifier component?'),
      '#default_value' => theme_get_setting('uswds_identifier_enabled') ?? TRUE,
    ],
    'uswds_identifier_variant' => [
      '#type' => 'select',
      '#title' => t('Identifier Variant'),
      '#description' => t('Select the USWDS Identifier layout variant.'),
      '#options' => [
        'default' => t('Default (Single parent agency logo)'),
        'multiple_parents' => t('Multiple parents and logos'),
        'no_logo' => t('No logos (Text-only masthead)'),
        'taxpayer_disclaimer' => t('Taxpayer disclaimer (Includes "Produced and published at taxpayer expense")'),
      ],
      '#default_value' => theme_get_setting('uswds_identifier_variant') ?? 'default',
      '#states' => [
        'visible' => [
          ':input[name="uswds_identifier_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ],
    'uswds_identifier_show_logos' => [
      '#type' => 'checkbox',
      '#title' => t('Display agency logo(s)?'),
      '#description' => t('Uncheck to hide logo images in the Identifier masthead.'),
      '#default_value' => theme_get_setting('uswds_identifier_show_logos') ?? TRUE,
      '#states' => [
        'visible' => [
          ':input[name="uswds_identifier_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ],




    'identity' => [
      '#type' => 'fieldset',
      '#title' => t('Primary Agency Identity & Domain'),
      '#states' => [
        'visible' => [
          ':input[name="uswds_identifier_enabled"]' => ['checked' => TRUE],
        ],
      ],
      'uswds_identifier_domain' => [
        '#type' => 'textfield',
        '#title' => t('Domain name'),
        '#description' => t('Example: 3tech.gov'),
        '#default_value' => theme_get_setting('uswds_identifier_domain') ?? '3tech.gov',
      ],
      'uswds_identifier_agency_name' => [
        '#type' => 'textfield',
        '#title' => t('Parent Agency / Department Name'),
        '#description' => t('Example: Department of Technology'),
        '#default_value' => theme_get_setting('uswds_identifier_agency_name') ?? '3 Tech Agency',
      ],
      'uswds_identifier_agency_short_name' => [
        '#type' => 'textfield',
        '#title' => t('Agency Acronym / Short Name'),
        '#description' => t('Example: 3Tech'),
        '#default_value' => theme_get_setting('uswds_identifier_agency_short_name') ?? '3Tech',
      ],
      'uswds_identifier_agency_url' => [
        '#type' => 'textfield',
        '#title' => t('Parent Agency URL'),
        '#description' => t('Example: https://www.agency.gov'),
        '#default_value' => theme_get_setting('uswds_identifier_agency_url') ?? '#',
      ],
      'uswds_identifier_agency_logo' => [
        '#type' => 'textfield',
        '#title' => t('Primary Agency Logo Path'),
        '#description' => t('Path to logo file relative to theme folder (e.g. logo.svg, logo.png, 3T.svg)'),
        '#default_value' => theme_get_setting('uswds_identifier_agency_logo') ?? 'logo.svg',

        '#states' => [
          'invisible' => [
            ':input[name="uswds_identifier_variant"]' => ['value' => 'no_logo'],
          ],
        ],
      ],
    ],
    'secondary_agency' => [
      '#type' => 'fieldset',
      '#title' => t('Secondary Parent Agency (for Multiple Parents variant)'),
      '#states' => [
        'visible' => [
          ':input[name="uswds_identifier_enabled"]' => ['checked' => TRUE],
          ':input[name="uswds_identifier_variant"]' => ['value' => 'multiple_parents'],
        ],
      ],
      'uswds_identifier_parent2_name' => [
        '#type' => 'textfield',
        '#title' => t('Secondary Parent Agency Name'),
        '#description' => t('Example: General Services Administration'),
        '#default_value' => theme_get_setting('uswds_identifier_parent2_name') ?? 'Parent Department',
      ],
      'uswds_identifier_parent2_url' => [
        '#type' => 'textfield',
        '#title' => t('Secondary Parent Agency URL'),
        '#default_value' => theme_get_setting('uswds_identifier_parent2_url') ?? '#',
      ],
      'uswds_identifier_parent2_logo' => [
        '#type' => 'textfield',
        '#title' => t('Secondary Agency Logo Path'),
        '#description' => t('Path to secondary logo file relative to theme folder.'),
        '#default_value' => theme_get_setting('uswds_identifier_parent2_logo') ?? '',
      ],
    ],
    'links_info' => [
      '#type' => 'fieldset',
      '#title' => t('Identifier Menu Links'),
      '#states' => [
        'visible' => [
          ':input[name="uswds_identifier_enabled"]' => ['checked' => TRUE],
        ],
      ],
      'menu_help' => [
        '#markup' => t('Required government links are managed using the Drupal <strong>Identifier</strong> menu. <a href="@url" target="_blank">Manage Identifier Menu Links</a>.', ['@url' => base_path() . 'admin/structure/menu/manage/identifier']),
      ],
    ],
    'usagov_section' => [
      '#type' => 'fieldset',
      '#title' => t('USA.gov Disclaimer Section'),
      '#states' => [
        'visible' => [
          ':input[name="uswds_identifier_enabled"]' => ['checked' => TRUE],
        ],
      ],
      'uswds_identifier_usagov_enabled' => [
        '#type' => 'checkbox',
        '#title' => t('Display USA.gov section?'),
        '#default_value' => theme_get_setting('uswds_identifier_usagov_enabled') ?? TRUE,
      ],
      'uswds_identifier_usagov_text' => [
        '#type' => 'textfield',
        '#title' => t('USA.gov Disclaimer Text'),
        '#default_value' => theme_get_setting('uswds_identifier_usagov_text') ?? 'Looking for U.S. government information and services?',
      ],
      'uswds_identifier_usagov_url' => [
        '#type' => 'textfield',
        '#title' => t('USA.gov URL'),
        '#default_value' => theme_get_setting('uswds_identifier_usagov_url') ?? 'https://www.usa.gov/',
      ],
    ],
  ];
}
