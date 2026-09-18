<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization\Form;

use Drupal\canvas\Form\FormIdPreRender;
use Drupal\Core\Condition\ConditionInterface;
use Drupal\Core\Condition\ConditionManager;
use Drupal\Core\Executable\ExecutableManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The settings — and their defaults — of a single segmentation rule.
 *
 * Segmentation rules are condition plugins, and a condition plugin describes
 * its own settings as a Drupal form. Exposing that form is what lets the Canvas
 * UI render those settings the same way it renders a component instance's
 * props: as a Drupal render array transformed into React components.
 *
 * Only the settings are exposed, never a way to save them: like a component
 * instance form, this form is never submitted to Drupal. The client sends the
 * values it collects to the Segment's auto-save endpoint instead.
 *
 * ⚠️ This is highly experimental and *will* be refactored or even removed.
 *
 * @todo Revisit in https://www.drupal.org/i/3527086
 *
 * @see \Drupal\canvas\Form\ComponentInstanceForm
 * @see \Drupal\canvas_personalization\Form\SegmentRuleForm
 * @see docs/component-and-entity-forms.md
 */
final class SegmentRuleSettingsForm extends FormBase {

  public const string FORM_ID = 'segment_rule_settings_form';

  public function __construct(protected ConditionManager $conditionManager) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $condition_manager = $container->get(ExecutableManagerInterface::class);
    \assert($condition_manager instanceof ConditionManager);
    return new static(
      $condition_manager,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return self::FORM_ID;
  }

  /**
   * {@inheritdoc}
   *
   * @param string $segment_rule
   *   The segmentation rule (condition plugin) ID, from the route.
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $segment_rule = ''): array {
    // A condition plugin that exists but that personalization does not allow as
    // a segmentation rule is deliberately indistinguishable from one that does
    // not exist at all: neither can be stored in a Segment's `rules`.
    // @see \Drupal\canvas_personalization\Hook\SegmentationRulesHooks::conditionPersonalizationAlter()
    // @see \Drupal\canvas\Plugin\Validation\Constraint\SequenceKeysAreFilteredPluginIdsConstraint
    if (!\array_key_exists($segment_rule, $this->conditionManager->getFilteredDefinitions('canvas_personalization'))) {
      throw new NotFoundHttpException(\sprintf("The '%s' segmentation rule does not exist.", $segment_rule));
    }

    // No configuration is passed, so the condition plugin falls back to its
    // ::defaultConfiguration() — which is what makes the built form carry the
    // settings' default values.
    $condition = $this->conditionManager->createInstance($segment_rule);
    \assert($condition instanceof ConditionInterface);
    $form['settings'] = self::buildSettingsElement($condition, $form_state);

    // Prevent form submission: like a component instance's props, a
    // segmentation rule's settings are saved by the client, not by Drupal.
    // @see \Drupal\canvas\Form\ComponentInstanceForm::buildForm()
    $form['#method'] = 'dialog';

    $form['#attributes']['data-form-id'] = self::FORM_ID;
    $form['#pre_render'][] = [FormIdPreRender::class, 'addFormId'];

    return $form;
  }

  /**
   * Builds the form element containing a segmentation rule's settings.
   *
   * Shared with the Drupal UI's segment rule form, so that the settings this
   * HTTP API exposes cannot drift from the settings that form collects.
   *
   * @param \Drupal\Core\Condition\ConditionInterface $condition
   *   The segmentation rule to build the settings element for. Its
   *   configuration provides the settings' default values.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The `settings` form element.
   */
  public static function buildSettingsElement(ConditionInterface $condition, FormStateInterface $form_state): array {
    $form_state->set($condition->getPluginId(), $condition);
    $settings = $condition->buildConfigurationForm([], $form_state);
    $settings['#title'] = new TranslatableMarkup('Settings');
    return [
      '#type' => 'container',
      '#tree' => TRUE,
    ] + $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Intentionally empty: this form is never submitted to Drupal.
    // @see self::buildForm()
  }

}
