<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Canvas AI settings form.
 */
final class CanvasAiSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'canvas_ai_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['canvas_ai.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $agents = $this->entityTypeManager->getStorage('ai_agent')->loadMultiple();
    $agent_options = \array_map(static fn ($agent) => $agent->label(), $agents);
    asort($agent_options);

    $form['main_agent'] = [
      '#type' => 'select',
      '#title' => $this->t('Main agent'),
      '#options' => $agent_options,
      '#default_value' => $this->config('canvas_ai.settings')->get('main_agent'),
      '#description' => $this->t('The AI agent the user chats with in the Canvas editor.'),
      '#required' => TRUE,
    ];

    $form['file_upload_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum upload size'),
      '#default_value' => $this->config('canvas_ai.settings')->get('file_upload_size'),
      '#description' => $this->t('Maximum image upload size (MB)'),
      '#min' => 1,
    ];

    $form['chat_history_max_messages'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum chat history messages'),
      '#default_value' => $this->config('canvas_ai.settings')->get('chat_history_max_messages'),
      '#description' => $this->t('The maximum number of previous messages passed to the AI as conversation history. Use -1 to include the full history, or 0 to send no history at all (every request starts fresh).'),
      '#min' => -1,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['disable_real_time_page_generation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable real-time page generation'),
      '#default_value' => $this->config('canvas_ai.settings')->get('disable_real_time_page_generation'),
      '#description' => $this->t('By default the AI builds a page one section at a time, so each part appears in the editor as it is created. Enable this to have the AI plan the whole page and place it in a single step instead. This uses fewer tokens and fewer requests, but the page appears all at once rather than progressively, takes longer before anything is visible, and — because it sends one large request — is more likely to hit a request timeout on big pages.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('canvas_ai.settings')
      ->set('main_agent', $form_state->getValue('main_agent'))
      ->set('file_upload_size', $form_state->getValue('file_upload_size'))
      ->set('chat_history_max_messages', (int) $form_state->getValue('chat_history_max_messages'))
      ->set('disable_real_time_page_generation', (bool) $form_state->getValue('disable_real_time_page_generation'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
