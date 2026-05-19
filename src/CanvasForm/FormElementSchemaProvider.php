<?php

declare(strict_types=1);

namespace Drupal\canvas\CanvasForm;

/**
 * Defines available form element types and their configurable properties.
 *
 * Properties use their Drupal Form API names (without the # prefix).
 * Each property has: type, label, group, and optionally default/description.
 */
class FormElementSchemaProvider {

  public function getElementSchemas(): array {
    return [
      'textfield' => $this->textfield(),
      'textarea' => $this->textarea(),
      'email' => $this->email(),
      'tel' => $this->tel(),
      'number' => $this->number(),
      'url' => $this->url(),
      'date' => $this->date(),
      'select' => $this->select(),
      'radios' => $this->radios(),
      'checkboxes' => $this->checkboxes(),
      'checkbox' => $this->checkbox(),
      'hidden' => $this->hidden(),
      'container' => $this->container(),
      'fieldset' => $this->fieldset(),
      'details' => $this->details(),
      'submit' => $this->submit(),
      'button' => $this->button(),
    ];
  }

  public function getElementSchema(string $type): ?array {
    return $this->getElementSchemas()[$type] ?? NULL;
  }

  private function commonInputProperties(): array {
    return [
      'title' => ['type' => 'string', 'label' => 'Label', 'group' => 'basic', 'required' => TRUE],
      'description' => ['type' => 'text', 'label' => 'Help text', 'group' => 'basic'],
      'default_value' => ['type' => 'string', 'label' => 'Default value', 'group' => 'basic'],
      'required' => ['type' => 'boolean', 'label' => 'Required', 'group' => 'validation', 'default' => FALSE],
      'disabled' => ['type' => 'boolean', 'label' => 'Disabled', 'group' => 'advanced', 'default' => FALSE],
      'attributes' => ['type' => 'attributes', 'label' => 'HTML attributes', 'group' => 'advanced'],
      'prefix' => ['type' => 'string', 'label' => 'Prefix', 'group' => 'advanced'],
      'suffix' => ['type' => 'string', 'label' => 'Suffix', 'group' => 'advanced'],
    ];
  }

  private function textfield(): array {
    return [
      'type' => 'textfield',
      'label' => 'Text field',
      'category' => 'text',
      'icon' => 'type',
      'canContainChildren' => FALSE,
      'properties' => array_merge($this->commonInputProperties(), [
        'placeholder' => ['type' => 'string', 'label' => 'Placeholder', 'group' => 'basic'],
        'maxlength' => ['type' => 'number', 'label' => 'Max length', 'group' => 'validation'],
        'pattern' => ['type' => 'string', 'label' => 'Pattern (regex)', 'group' => 'validation'],
        'size' => ['type' => 'number', 'label' => 'Display size', 'group' => 'advanced', 'default' => 60],
      ]),
    ];
  }

  private function textarea(): array {
    return [
      'type' => 'textarea',
      'label' => 'Textarea',
      'category' => 'text',
      'icon' => 'align-left',
      'canContainChildren' => FALSE,
      'properties' => array_merge($this->commonInputProperties(), [
        'placeholder' => ['type' => 'string', 'label' => 'Placeholder', 'group' => 'basic'],
        'rows' => ['type' => 'number', 'label' => 'Rows', 'group' => 'advanced', 'default' => 5],
        'maxlength' => ['type' => 'number', 'label' => 'Max length', 'group' => 'validation'],
        'resizable' => ['type' => 'select', 'label' => 'Resizable', 'group' => 'advanced', 'options' => ['vertical' => 'Vertical', 'horizontal' => 'Horizontal', 'both' => 'Both', 'none' => 'None'], 'default' => 'vertical'],
      ]),
    ];
  }

  private function email(): array {
    return [
      'type' => 'email',
      'label' => 'Email',
      'category' => 'text',
      'icon' => 'at-sign',
      'canContainChildren' => FALSE,
      'properties' => array_merge($this->commonInputProperties(), [
        'placeholder' => ['type' => 'string', 'label' => 'Placeholder', 'group' => 'basic'],
        'maxlength' => ['type' => 'number', 'label' => 'Max length', 'group' => 'validation'],
      ]),
    ];
  }

  private function tel(): array {
    return [
      'type' => 'tel',
      'label' => 'Phone',
      'category' => 'text',
      'icon' => 'phone',
      'canContainChildren' => FALSE,
      'properties' => array_merge($this->commonInputProperties(), [
        'placeholder' => ['type' => 'string', 'label' => 'Placeholder', 'group' => 'basic'],
        'pattern' => ['type' => 'string', 'label' => 'Pattern (regex)', 'group' => 'validation'],
      ]),
    ];
  }

  private function number(): array {
    return [
      'type' => 'number',
      'label' => 'Number',
      'category' => 'text',
      'icon' => 'hash',
      'canContainChildren' => FALSE,
      'properties' => array_merge($this->commonInputProperties(), [
        'placeholder' => ['type' => 'string', 'label' => 'Placeholder', 'group' => 'basic'],
        'min' => ['type' => 'number', 'label' => 'Minimum', 'group' => 'validation'],
        'max' => ['type' => 'number', 'label' => 'Maximum', 'group' => 'validation'],
        'step' => ['type' => 'number', 'label' => 'Step', 'group' => 'validation'],
      ]),
    ];
  }

  private function url(): array {
    return [
      'type' => 'url',
      'label' => 'URL',
      'category' => 'text',
      'icon' => 'link',
      'canContainChildren' => FALSE,
      'properties' => array_merge($this->commonInputProperties(), [
        'placeholder' => ['type' => 'string', 'label' => 'Placeholder', 'group' => 'basic'],
        'maxlength' => ['type' => 'number', 'label' => 'Max length', 'group' => 'validation'],
      ]),
    ];
  }

  private function date(): array {
    return [
      'type' => 'date',
      'label' => 'Date',
      'category' => 'text',
      'icon' => 'calendar',
      'canContainChildren' => FALSE,
      'properties' => array_merge($this->commonInputProperties(), [
        '#date_date_format' => ['type' => 'string', 'label' => 'Date format', 'group' => 'advanced'],
      ]),
    ];
  }

  private function select(): array {
    return [
      'type' => 'select',
      'label' => 'Select',
      'category' => 'selection',
      'icon' => 'chevron-down',
      'canContainChildren' => FALSE,
      'properties' => array_merge($this->commonInputProperties(), [
        'options' => ['type' => 'options', 'label' => 'Options', 'group' => 'basic', 'required' => TRUE],
        'empty_option' => ['type' => 'string', 'label' => 'Empty option label', 'group' => 'basic'],
        'multiple' => ['type' => 'boolean', 'label' => 'Allow multiple', 'group' => 'basic', 'default' => FALSE],
      ]),
    ];
  }

  private function radios(): array {
    return [
      'type' => 'radios',
      'label' => 'Radio buttons',
      'category' => 'selection',
      'icon' => 'circle-dot',
      'canContainChildren' => FALSE,
      'properties' => array_merge($this->commonInputProperties(), [
        'options' => ['type' => 'options', 'label' => 'Options', 'group' => 'basic', 'required' => TRUE],
      ]),
    ];
  }

  private function checkboxes(): array {
    return [
      'type' => 'checkboxes',
      'label' => 'Checkboxes',
      'category' => 'selection',
      'icon' => 'list-checks',
      'canContainChildren' => FALSE,
      'properties' => array_merge($this->commonInputProperties(), [
        'options' => ['type' => 'options', 'label' => 'Options', 'group' => 'basic', 'required' => TRUE],
      ]),
    ];
  }

  private function checkbox(): array {
    return [
      'type' => 'checkbox',
      'label' => 'Checkbox',
      'category' => 'selection',
      'icon' => 'square-check',
      'canContainChildren' => FALSE,
      'properties' => [
        'title' => ['type' => 'string', 'label' => 'Label', 'group' => 'basic', 'required' => TRUE],
        'description' => ['type' => 'text', 'label' => 'Help text', 'group' => 'basic'],
        'default_value' => ['type' => 'boolean', 'label' => 'Checked by default', 'group' => 'basic', 'default' => FALSE],
        'return_value' => ['type' => 'string', 'label' => 'Value when checked', 'group' => 'advanced', 'default' => '1'],
        'required' => ['type' => 'boolean', 'label' => 'Required', 'group' => 'validation', 'default' => FALSE],
        'disabled' => ['type' => 'boolean', 'label' => 'Disabled', 'group' => 'advanced', 'default' => FALSE],
        'attributes' => ['type' => 'attributes', 'label' => 'HTML attributes', 'group' => 'advanced'],
      ],
    ];
  }

  private function hidden(): array {
    return [
      'type' => 'hidden',
      'label' => 'Hidden field',
      'category' => 'text',
      'icon' => 'eye-off',
      'canContainChildren' => FALSE,
      'properties' => [
        'default_value' => ['type' => 'string', 'label' => 'Value', 'group' => 'basic'],
      ],
    ];
  }

  private function container(): array {
    return [
      'type' => 'container',
      'label' => 'Container',
      'category' => 'structural',
      'icon' => 'square',
      'canContainChildren' => TRUE,
      'properties' => [
        'attributes' => ['type' => 'attributes', 'label' => 'HTML attributes', 'group' => 'basic'],
      ],
    ];
  }

  private function fieldset(): array {
    return [
      'type' => 'fieldset',
      'label' => 'Fieldset',
      'category' => 'structural',
      'icon' => 'square-dashed',
      'canContainChildren' => TRUE,
      'properties' => [
        'title' => ['type' => 'string', 'label' => 'Legend', 'group' => 'basic', 'required' => TRUE],
        'description' => ['type' => 'text', 'label' => 'Description', 'group' => 'basic'],
        'attributes' => ['type' => 'attributes', 'label' => 'HTML attributes', 'group' => 'advanced'],
      ],
    ];
  }

  private function details(): array {
    return [
      'type' => 'details',
      'label' => 'Details',
      'category' => 'structural',
      'icon' => 'chevrons-down',
      'canContainChildren' => TRUE,
      'properties' => [
        'title' => ['type' => 'string', 'label' => 'Summary', 'group' => 'basic', 'required' => TRUE],
        'description' => ['type' => 'text', 'label' => 'Description', 'group' => 'basic'],
        'open' => ['type' => 'boolean', 'label' => 'Open by default', 'group' => 'basic', 'default' => FALSE],
        'attributes' => ['type' => 'attributes', 'label' => 'HTML attributes', 'group' => 'advanced'],
      ],
    ];
  }

  private function submit(): array {
    return [
      'type' => 'submit',
      'label' => 'Submit button',
      'category' => 'action',
      'icon' => 'send',
      'canContainChildren' => FALSE,
      'properties' => [
        'value' => ['type' => 'string', 'label' => 'Button text', 'group' => 'basic', 'default' => 'Submit'],
        'button_type' => ['type' => 'select', 'label' => 'Style', 'group' => 'basic', 'options' => ['primary' => 'Primary', 'danger' => 'Danger', '' => 'Default'], 'default' => 'primary'],
        'attributes' => ['type' => 'attributes', 'label' => 'HTML attributes', 'group' => 'advanced'],
      ],
    ];
  }

  private function button(): array {
    return [
      'type' => 'button',
      'label' => 'Button',
      'category' => 'action',
      'icon' => 'mouse-pointer-click',
      'canContainChildren' => FALSE,
      'properties' => [
        'value' => ['type' => 'string', 'label' => 'Button text', 'group' => 'basic', 'default' => 'Click me'],
        'button_type' => ['type' => 'select', 'label' => 'Style', 'group' => 'basic', 'options' => ['primary' => 'Primary', 'danger' => 'Danger', '' => 'Default'], 'default' => ''],
        'attributes' => ['type' => 'attributes', 'label' => 'HTML attributes', 'group' => 'advanced'],
      ],
    ];
  }

}
