<?php

declare(strict_types=1);

namespace Drupal\canvas\Form;

use Drupal\canvas\Config\CodeComponentSyncMode;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures Drupal Canvas site-wide settings.
 */
final class CanvasSettingsForm extends ConfigFormBase {

  private const string SETTINGS = 'canvas.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'canvas_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::SETTINGS);

    $form['code_components'] = [
      '#type' => 'details',
      '#title' => $this->t('Code components'),
      '#open' => TRUE,
    ];
    $form['code_components']['sync_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Sync mode'),
      '#default_value' => $config->get('code_components.sync_mode') ?? CodeComponentSyncMode::PreserveProduction->value,
      '#options' => [
        CodeComponentSyncMode::PreserveProduction->value => $this->t('Preserve production-created components (recommended)'),
        CodeComponentSyncMode::Cli->value => $this->t('Manage exclusively via Canvas CLI'),
      ],
      '#options_descriptions' => [
        CodeComponentSyncMode::PreserveProduction->value => $this->t('Code components present in the active site but missing from the config sync directory are kept on import. Components tracked in sync still update from sync.'),
        CodeComponentSyncMode::Cli->value => $this->t('Code components are completely ignored by config import and export. Use this when the Canvas CLI is the sole source of truth.'),
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::SETTINGS)
      ->set('code_components.sync_mode', $form_state->getValue('sync_mode'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
