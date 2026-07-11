<?php

declare(strict_types=1);

namespace Drupal\canvas_test_state_api\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for canvas_test_state_api.
 */
class CanvasTestStateApiHooks {
  use StringTranslationTrait;

  /**
   * Implements hook_form_alter().
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if ($form_id === 'component_instance_form') {
      $form['states_api_test'] = [
        '#type' => 'container',
        '#weight' => 99,
      ];
      // Checkboxes default unchecked.
      // Checkbox controlling visibility.
      $form['states_api_test']['show_extra_field'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Checkbox A: Toggle conditionally visible field'),
        '#default_value' => FALSE,
      ];
      $form['states_api_test']['conditional_visible_field'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Visible when Checkbox A is checked'),
        '#states' => [
          'visible' => [
            ':input[name="show_extra_field"]' => [
              'checked' => TRUE,
            ],
          ],
        ],
      ];
      // Checkbox controlling enabled state.
      $form['states_api_test']['enable_extra_field'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Checkbox B: Toggle conditionally enabled field'),
        '#default_value' => FALSE,
      ];
      $form['states_api_test']['conditional_enabled_field'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Enabled when Checkbox B is checked'),
        '#states' => [
          'disabled' => [
            ':input[name="enable_extra_field"]' => [
              'checked' => FALSE,
            ],
          ],
        ],
      ];
      // Checkbox controlling another checkbox's visibility.
      $form['states_api_test']['toggle_checkbox'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Checkbox C: Toggle visibility of another checkbox'),
        '#default_value' => FALSE,
      ];
      $form['states_api_test']['secondary_checkbox'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Visible when Checkbox C is checked'),
        '#default_value' => FALSE,
        '#states' => [
          'visible' => [
            ':input[name="toggle_checkbox"]' => [
              'checked' => TRUE,
            ],
          ],
        ],
      ];
      // Checkbox controlling another checkbox's visibility.
      $form['states_api_test']['toggle_checkbox_checked'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Checkbox D: Toggle checked state of another checkbox'),
        '#default_value' => FALSE,
      ];
      $form['states_api_test']['secondary_checkbox_checked'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Checked when Checkbox D is checked'),
        '#default_value' => FALSE,
        '#states' => [
          'checked' => [
            ':input[name="toggle_checkbox_checked"]' => [
              'checked' => TRUE,
            ],
          ],
        ],
      ];
      // Checkboxes default checked.
      // Checkbox controlling visibility.
      $form['states_api_test']['rev_show_extra_field'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('[REV] Checkbox A: Toggle conditionally visible field'),
        '#default_value' => TRUE,
      ];
      $form['states_api_test']['rev_conditional_visible_field'] = [
        '#type' => 'textfield',
        '#title' => $this->t('[REV] Visible when Checkbox A is checked'),
        '#states' => [
          'visible' => [
            ':input[name="rev_show_extra_field"]' => [
              'checked' => TRUE,
            ],
          ],
        ],
      ];
      // Checkbox controlling enabled state.
      $form['states_api_test']['rev_enable_extra_field'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('[REV] Checkbox B: Toggle conditionally enabled field'),
        '#default_value' => TRUE,
      ];
      $form['states_api_test']['rev_conditional_enabled_field'] = [
        '#type' => 'textfield',
        '#title' => $this->t('[REV] Enabled when Checkbox B is checked'),
        '#states' => [
          'disabled' => [
            ':input[name="rev_enable_extra_field"]' => [
              'checked' => FALSE,
            ],
          ],
        ],
      ];
      // Checkbox controlling another checkbox's visibility.
      $form['states_api_test']['rev_toggle_checkbox'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('[REV] Checkbox C: Toggle visibility of another checkbox'),
        '#default_value' => TRUE,
      ];
      $form['states_api_test']['rev_secondary_checkbox'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('[REV] Visible when Checkbox C is checked'),
        '#default_value' => FALSE,
        '#states' => [
          'visible' => [
            ':input[name="rev_toggle_checkbox"]' => [
              'checked' => TRUE,
            ],
          ],
        ],
      ];
      // Checkbox controlling another checkbox's visibility.
      $form['states_api_test']['rev_toggle_checkbox_checked'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('[REV] Checkbox D: Toggle checked state of another checkbox'),
        '#default_value' => TRUE,
      ];
      $form['states_api_test']['rev_secondary_checkbox_checked'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('[REV] Checked when Checkbox D is checked'),
        '#default_value' => FALSE,
        '#states' => [
          'checked' => [
            ':input[name="rev_toggle_checkbox_checked"]' => [
              'checked' => TRUE,
            ],
          ],
        ],
      ];
      $form['states_api_test']['text_states'] = [
        '#type' => 'container',
        '#title' => $this->t('Text Input States'),
        '#markup' => '<strong>Test the State API with text inputs.</strong>',
      ];
      // Text field controlling another field's visibility based on emptiness.
      $form['states_api_test']['text_states']['controlling_text'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Control Text Input'),
        '#default_value' => '',
      ];
      // Enabled by default, becomes disabled
      $form['states_api_test']['text_states']['default_enabled'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Text Target: Default enabled field'),
        '#states' => [
          'disabled' => [
            ':input[name="controlling_text"]' => [
              'value' => 'make default enabled disabled',
            ],
          ],
        ],
      ];
      // Disabled by default, becomes enabled
      $form['states_api_test']['text_states']['default_disabled'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Text Target: Default disabled field'),
        '#disabled' => TRUE,
        '#states' => [
          'enabled' => [
            ':input[name="controlling_text"]' => [
              'value' => 'make default disabled enabled',
            ],
          ],
        ],
      ];
      // Required by default, becomes optional
      $form['states_api_test']['text_states']['default_required'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Text Target: Default required field'),
        '#required' => TRUE,
        '#states' => [
          'optional' => [
            ':input[name="controlling_text"]' => [
              'value' => 'make required optional',
            ],
          ],
        ],
      ];
      // Optional by default, becomes required
      $form['states_api_test']['text_states']['default_optional'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Text Target: Default optional field'),
        '#states' => [
          'required' => [
            ':input[name="controlling_text"]' => [
              'value' => 'make optional required',
            ],
          ],
        ],
      ];
      // Visible by default, becomes invisible
      $form['states_api_test']['text_states']['default_visible'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Text Target: Default visible field'),
        '#states' => [
          'invisible' => [
            ':input[name="controlling_text"]' => [
              'value' => 'make visible invisible',
            ],
          ],
        ],
      ];
      // Invisible by default, becomes visible
      $form['states_api_test']['text_states']['default_invisible'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Text Target: Default invisible field'),
        '#states' => [
          'visible' => [
            ':input[name="controlling_text"]' => [
              'value' => 'make invisible visible',
            ],
          ],
        ],
      ];
      // Checked by default, becomes unchecked
      $form['states_api_test']['text_states']['default_checked'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Text Target: Default checked checkbox'),
        '#default_value' => TRUE,
        '#states' => [
          'unchecked' => [
            ':input[name="controlling_text"]' => [
              'value' => 'make checked unchecked',
            ],
          ],
        ],
      ];
      // Unchecked by default, becomes checked
      $form['states_api_test']['text_states']['default_unchecked'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Text Target: Default unchecked checkbox'),
        '#default_value' => FALSE,
        '#states' => [
          'checked' => [
            ':input[name="controlling_text"]' => [
              'value' => 'make unchecked checked',
            ],
          ],
        ],
      ];
      $form['states_api_test']['text_states']['hello_filled_world'] = [
        '#type' => 'container',
        '#attributes' => [
          'data-visible-when-text-not-empty' => 'true',
        ],
        'markup' => [
          '#markup' => '<p>Hello world I am visible when text not empty</p>',
        ],
        '#states' => [
          'visible' => [
            ':input[name="controlling_text"]' => [
              'filled' => TRUE,
            ],
          ],
        ],
      ];
      $form['states_api_test']['text_states']['hello_empty_world'] = [
        '#type' => 'container',
        '#attributes' => [
          'data-visible-when-text-only-empty' => 'true',
        ],
        'markup' => [
          '#markup' => '<p>Hello world I am visible when text is empty</p>',
        ],
        '#states' => [
          'visible' => [
            ':input[name="controlling_text"]' => [
              'empty' => TRUE,
            ],
          ],
        ],
      ];
      $form['states_api_test']['select_states'] = [
        '#type' => 'container',
        '#title' => $this->t('Select States'),
        '#markup' => '<strong>Test the State API with a select element.</strong>',
      ];
      // Add select element with options
      $form['states_api_test']['select_states']['state_control'] = [
        '#type' => 'select',
        '#title' => $this->t('Select to change states'),
        '#default_value' => '',
        '#options' => [
          '' => $this->t('- Select -'),
          'make_default_enabled_disabled' => $this->t('Make default enabled disabled'),
          'make_default_disabled_enabled' => $this->t('Make default disabled enabled'),
          'make_required_optional' => $this->t('Make required optional'),
          'make_optional_required' => $this->t('Make optional required'),
          'make_visible_invisible' => $this->t('Make visible invisible'),
          'make_invisible_visible' => $this->t('Make invisible visible'),
          'make_checked_unchecked' => $this->t('Make checked unchecked'),
          'make_unchecked_checked' => $this->t('Make unchecked checked'),
          'just_a_value' => $this->t('A value that does nothing but be a value'),
        ],
      ];
      // Enabled by default, becomes disabled
      $form['states_api_test']['select_states']['default_enabled'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Select: Default enabled field'),
        '#states' => [
          'disabled' => [
            ':input[name="state_control"]' => [
              'value' => 'make_default_enabled_disabled',
            ],
          ],
        ],
      ];
      // Disabled by default, becomes enabled
      $form['states_api_test']['select_states']['default_disabled'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Select: Default disabled field'),
        '#disabled' => TRUE,
        '#states' => [
          'enabled' => [
            ':input[name="state_control"]' => [
              'value' => 'make_default_disabled_enabled',
            ],
          ],
        ],
      ];
      // Required by default, becomes optional
      $form['states_api_test']['select_states']['default_required'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Select: Default required field'),
        '#required' => TRUE,
        '#states' => [
          'optional' => [
            ':input[name="state_control"]' => [
              'value' => 'make_required_optional',
            ],
          ],
        ],
      ];
      // Optional by default, becomes required
      $form['states_api_test']['select_states']['default_optional'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Select: Default optional field'),
        '#states' => [
          'required' => [
            ':input[name="state_control"]' => [
              'value' => 'make_optional_required',
            ],
          ],
        ],
      ];
      // Visible by default, becomes invisible
      $form['states_api_test']['select_states']['default_visible'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Select: Default visible field'),
        '#states' => [
          'invisible' => [
            ':input[name="state_control"]' => [
              'value' => 'make_visible_invisible',
            ],
          ],
        ],
      ];
      // Invisible by default, becomes visible
      $form['states_api_test']['select_states']['default_invisible'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Select: Default invisible field'),
        '#states' => [
          'visible' => [
            ':input[name="state_control"]' => [
              'value' => 'make_invisible_visible',
            ],
          ],
        ],
      ];
      // Checked by default, becomes unchecked
      $form['states_api_test']['select_states']['default_checked'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Select: Default checked checkbox'),
        '#default_value' => TRUE,
        '#states' => [
          'unchecked' => [
            ':input[name="state_control"]' => [
              'value' => 'make_checked_unchecked',
            ],
          ],
        ],
      ];
      // Unchecked by default, becomes checked
      $form['states_api_test']['select_states']['default_unchecked'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Select: Default unchecked checkbox'),
        '#default_value' => FALSE,
        '#states' => [
          'checked' => [
            ':input[name="state_control"]' => [
              'value' => 'make_unchecked_checked',
            ],
          ],
        ],
      ];
      // Container that appears when any option is selected
      $form['states_api_test']['select_states']['hello_world'] = [
        '#type' => 'container',
        '#attributes' => [
          'data-when-not-empty' => 'true',
        ],
        'markup' => [
          '#markup' => '<p>Hello world</p>',
        ],
        '#states' => [
          'visible' => [
            ':input[name="state_control"]' => [
              'filled' => TRUE,
            ],
          ],
        ],
      ];
      $form['states_api_test']['radio_states'] = [
        '#type' => 'container',
        '#title' => $this->t('Radio States'),
        '#markup' => '<strong>Test the State API with a radio element.</strong>',
      ];
      $radio_options = [
        0 => $this->t('Radio: Make default enabled disabled'),
        1 => $this->t('Radio: Make default disabled enabled'),
        2 => $this->t('Radio: Make required optional'),
        3 => $this->t('Radio: Make optional required'),
        4 => $this->t('Radio: Make visible invisible'),
        5 => $this->t('Radio: Make invisible visible'),
        6 => $this->t('Radio: Make checked unchecked'),
        7 => $this->t('Radio: Make unchecked checked'),
        8 => $this->t('Radio: A value that does nothing but be a value'),
      ];
      $form['states_api_test']['radio_states']['test_the_radio'] = [
        '#type' => 'radios',
        '#title' => $this->t('Test the radio states'),
        '#default_value' => 8,
        '#options' => $radio_options,
      ];
      // Disabled by default, becomes enabled
      $form['states_api_test']['radio_states']['default_enabled'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Radio: Default enabled field'),
        '#states' => [
          'disabled' => [
            ':input[name="test_the_radio"]' => [
              'value' => 0,
            ],
          ],
        ],
      ];
      $form['states_api_test']['radio_states']['default_disabled'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Radio: Default disabled field'),
        '#disabled' => TRUE,
        '#states' => [
          'enabled' => [
            ':input[name="test_the_radio"]' => [
              'value' => 1,
            ],
          ],
        ],
      ];
      // Required by default, becomes optional
      $form['states_api_test']['radio_states']['default_required'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Radio: Default required field'),
        '#required' => TRUE,
        '#states' => [
          'optional' => [
            ':input[name="test_the_radio"]' => [
              'value' => 2,
            ],
          ],
        ],
      ];
      // Optional by default, becomes required
      $form['states_api_test']['radio_states']['default_optional'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Radio: Default optional field'),
        '#states' => [
          'required' => [
            ':input[name="test_the_radio"]' => [
              'value' => 3,
            ],
          ],
        ],
      ];
      // Visible by default, becomes invisible
      $form['states_api_test']['radio_states']['default_visible'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Radio: Default visible field'),
        '#states' => [
          'invisible' => [
            ':input[name="test_the_radio"]' => [
              'value' => 4,
            ],
          ],
        ],
      ];
      // Invisible by default, becomes visible
      $form['states_api_test']['radio_states']['default_invisible'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Radio: Default invisible field'),
        '#states' => [
          'visible' => [
            ':input[name="test_the_radio"]' => [
              'value' => 5,
            ],
          ],
        ],
      ];
      // Checked by default, becomes unchecked
      $form['states_api_test']['radio_states']['default_checked'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Radio: Default checked checkbox'),
        '#default_value' => TRUE,
        '#states' => [
          'unchecked' => [
            ':input[name="test_the_radio"]' => [
              'value' => 6,
            ],
          ],
        ],
      ];
      // Unchecked by default, becomes checked
      $form['states_api_test']['radio_states']['default_unchecked'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Radio: Default unchecked checkbox'),
        '#default_value' => FALSE,
        '#states' => [
          'checked' => [
            ':input[name="test_the_radio"]' => [
              'value' => 7,
            ],
          ],
        ],
      ];
      // Container that appears when any radio option is selected
      $form['states_api_test']['radio_states']['hello_world'] = [
        '#type' => 'container',
        '#attributes' => [
          'data-when-any-radio' => 'true',
        ],
        'markup' => [
          '#markup' => '<p>Hello world</p>',
        ],
        '#states' => [
          'visible' => [
            ':input[name="test_the_radio"]' => [
              'filled' => TRUE,
            ],
          ],
        ],
      ];
    }
  }

}
