<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\Form;

use Drupal\canvas_ai\CanvasAiPageBuilderHelper;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Override the description of available components.
 */
final class CanvasAiComponentDescriptionSettingsForm extends ConfigFormBase {

  /**
   * Store component context data per source.
   *
   * @var array
   */
  private $componentContextCache = [];

  /**
   * Creates a new CanvasAiComponentDescriptionSettingsForm instance.
   *
   * @param \Drupal\canvas_ai\CanvasAiPageBuilderHelper $pageBuilderHelper
   *   The page builder helper.
   */
  public function __construct(
    protected readonly CanvasAiPageBuilderHelper $pageBuilderHelper,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('canvas_ai.page_builder_helper'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'canvas_ai_component_description_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['canvas_ai.component_description.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['description'] = [
      '#markup' => $this->t('
        <p>Use this form to customize the descriptions of available components, including their props and slots. These descriptions help the AI understand how components should be used when building pages.</p>

        <p><strong>Description:</strong> Write a single clear sentence that explains what the component is and its primary purpose.</p>

        <p><strong>Detailed description:</strong> Provide a concise explanation of when to use the component, how it should be used, and any important constraints.</p>

        <p><strong>Example: Accordion component</strong></p>

        <p><strong>Description:</strong> A collapsible accordion component that groups related content into expandable panels so users can reveal details when needed.</p>

        <p><strong>Detailed description:</strong> Use this component to organize large or dense content into labeled sections that users can expand or collapse.</p>

        <ul>
          <li>Suitable for FAQs, help content, or overview and detail patterns where not all information needs to be visible at once.</li>
          <li>Reduces vertical clutter while keeping all content accessible through user interaction.</li>
          <li>Panels should have clear titles that describe the content inside.</li>
          <li>Avoid using this component when users need to compare multiple sections at the same time or read content sequentially.</li>
          <li>Works best with a limited number of panels, typically 3 to 7, and with well structured content inside each panel.</li>
        </ul>
      '),
    ];
    // Create form elements to add description for each available components
    // and their props and slots.
    $form['component_context'] = $this->buildComponentContextForm();
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Validate that at least one source is enabled.
    $component_context = $form_state->getValue('component_context');
    $any_enabled = FALSE;
    if (\is_array($component_context)) {
      foreach ($component_context as $components) {
        if (!empty($components['enabled'])) {
          $any_enabled = TRUE;
          break;
        }
      }
    }
    if (!$any_enabled) {
      $form_state->setErrorByName('component_context', $this->t('At least one source must be enabled.'));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $component_context = $form_state->getValue('component_context');
    $available_components = $this->pageBuilderHelper->getAllComponentsKeyedBySource();
    $config_data = [];

    foreach ($component_context as $source => $components) {
      // Replace descriptions with the submitted descriptions.
      foreach ($components['components'] as $component_id => $component_data_from_form) {
        if (isset($available_components[$source]['components'][$component_id])) {
          // Replace the component description.
          $available_components[$source]['components'][$component_id]['description'] = $component_data_from_form['description'];
          $available_components[$source]['components'][$component_id]['detailed_description'] = $component_data_from_form['detailed_description'] ?? $component_data_from_form['description'];

          // Replace the props descriptions.
          if (isset($available_components[$source]['components'][$component_id]['props']) && \is_array($available_components[$source]['components'][$component_id]['props'])) {
            foreach ($available_components[$source]['components'][$component_id]['props'] as $prop_id => $prop_data) {
              $available_components[$source]['components'][$component_id]['props'][$prop_id]['description'] = $component_data_from_form['props'][$prop_id]['description'];
            }
          }

          // Replace the slots descriptions.
          if (isset($available_components[$source]['components'][$component_id]['slots']) && \is_array($available_components[$source]['components'][$component_id]['slots'])) {
            foreach ($available_components[$source]['components'][$component_id]['slots'] as $slot_id => $slot_data) {
              $available_components[$source]['components'][$component_id]['slots'][$slot_id]['description'] = $component_data_from_form['slots'][$slot_id]['description'];
            }
          }
        }
      }

      $config_data[$source] = [
        'enabled' => $components['enabled'],
        'data' => Yaml::encode($available_components[$source]['components']),
      ];
    }

    $this->config('canvas_ai.component_description.settings')
      ->set('component_context', $config_data)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Builds the component context form for all available component entities.
   */
  private function buildComponentContextForm(): array {
    $usable_components = $this->pageBuilderHelper->getAllComponentsKeyedBySource();
    $form = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    foreach ($usable_components as $source => $source_data) {
      $form[$source] = [
        '#type' => 'details',
        '#title' => $source_data['label'],
      ];

      $form[$source]['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enabled'),
        '#default_value' => $this->config('canvas_ai.component_description.settings')->get('component_context.' . $source . '.enabled'),
        '#description' => $this->t('If enabled, the component entities of this source will be available in the AI context.'),
      ];
      $components = $source_data['components'] ?? [];
      foreach ($components as $component_id => $component_data) {
        $form[$source]['components'][$component_id] = [
          '#type' => 'details',
          '#title' => $component_data['name'],
        ];

        // Component description element.
        $default_description = $this->getDefaultValue($source, $component_id) ?? $component_data['description'];
        $form[$source]['components'][$component_id]['description'] = [
          '#type' => 'textarea',
          '#title' => $this->t('Description'),
          '#rows' => 2,
          '#default_value' => $default_description,
        ];

        $form[$source]['components'][$component_id]['detailed_description'] = [
          '#type' => 'textarea',
          '#title' => $this->t('Detailed Description'),
          '#default_value' => $this->getDefaultValue($source, $component_id, '', '', 'detailed_description') ?? $default_description,
        ];

        // Description elements for each prop.
        if (isset($component_data['props']) && \is_array($component_data['props'])) {

          $form[$source]['components'][$component_id]['props'] = [
            '#type' => 'details',
            '#title' => $this->t('Props'),
          ];

          foreach ($component_data['props'] as $prop_id => $prop_data) {
            // @phpstan-ignore-next-line
            $form[$source]['components'][$component_id]['props'][$prop_id]['description'] = [
              '#type' => 'textarea',
              '#title' => $prop_data['name'],
              '#default_value' => $this->getDefaultValue($source, $component_id, $prop_id, 'props') ?? $prop_data['description'],
            ];
          }
        }

        // Description elements for each slot.
        if (isset($component_data['slots']) && \is_array($component_data['slots'])) {
          $form[$source]['components'][$component_id]['slots'] = [
            '#type' => 'details',
            '#title' => $this->t('Slots'),
          ];

          foreach ($component_data['slots'] as $slot_id => $slot_data) {
            // @phpstan-ignore-next-line
            $form[$source]['components'][$component_id]['slots'][$slot_id]['description'] = [
              '#type' => 'textarea',
              '#title' => $slot_data['name'],
              '#default_value' => $this->getDefaultValue($source, $component_id, $slot_id, 'slots') ?? $slot_data['description'],
            ];
          }
        }
      }

    }
    return $form;
  }

  /**
   * Gets the decoded component context data for a source.
   *
   * @param string $source
   *   The source plugin id of the component entity.
   *
   * @return array|null
   *   The decoded component context data, or NULL if not found.
   */
  private function getComponentContextData(string $source): ?array {
    if (!isset($this->componentContextCache[$source])) {
      $config = $this->config('canvas_ai.component_description.settings');
      $component_context = $config->get('component_context.' . $source . '.data');
      if ($component_context) {
        $this->componentContextCache[$source] = Yaml::decode($component_context);
      }
      else {
        $this->componentContextCache[$source] = NULL;
      }
    }
    return $this->componentContextCache[$source];
  }

  /**
   * Helper function to get the default value for an element from the config.
   *
   * @param string $source
   *   The source plugin id of the component entity.
   * @param string $component_id
   *   The id of the component entity.
   * @param string $identifier
   *   The id of the prop or slot.
   * @param string $type
   *   The type: 'props' or 'slots'.
   * @param string $value_key
   *   The key to read from config. Defaults to 'description' and can be
   *   overridden for keys like 'detailed_description'.
   *
   * @return string|null
   *   The default description value for the component, prop, or slot.
   */
  private function getDefaultValue(string $source, string $component_id, string $identifier = '', string $type = '', string $value_key = 'description'): ?string {
    $component_context_decoded = $this->getComponentContextData($source);
    if ($component_context_decoded) {
      if ($type && $identifier) {
        // If type and identifier are provided, return the description for that
        // specific prop or slot.
        if (isset($component_context_decoded[$component_id][$type][$identifier][$value_key])) {
          return $component_context_decoded[$component_id][$type][$identifier][$value_key];
        }
      }
      else {
        // For component-level values, use the requested config key.
        // If type and identifier are not provided, return the description for
        // the component.
        if (isset($component_context_decoded[$component_id][$value_key])) {
          return $component_context_decoded[$component_id][$value_key];
        }
      }
    }
    return NULL;
  }

}
