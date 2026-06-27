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
    return ['canvas.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['code_components'] = [
      '#type' => 'details',
      '#title' => $this->t('Code components'),
      '#open' => TRUE,
    ];
    $form['code_components']['sync_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Sync mode'),
      '#config_target' => 'canvas.settings:' . CodeComponentSyncMode::CONFIG_KEY,
      '#options' => [
        CodeComponentSyncMode::PreserveProduction->value => $this->t('Preserve production-created components (recommended)'),
        CodeComponentSyncMode::Cli->value => $this->t('Manage exclusively via Canvas CLI'),
      ],
      '#options_descriptions' => [
        CodeComponentSyncMode::PreserveProduction->value => $this->t('On import, code components in the active site that are missing from the config sync directory are kept. Components tracked in sync still update from sync. Export is unchanged: running <code>drush config:export</code> writes every active code component to the sync directory, so review the diff before committing.'),
        CodeComponentSyncMode::Cli->value => $this->t('Code components are completely ignored by config import and export. Use this when the Canvas CLI is the sole source of truth.'),
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

}
