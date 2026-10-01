<?php

declare(strict_types=1);

namespace Drupal\canvas\Form;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filters the content template listing by label and status.
 *
 * @see \Drupal\canvas\EntityHandlers\ContentTemplateListBuilder
 */
final class ContentTemplateListFilterForm extends FormBase {

  public const string SEARCH = 'search';
  public const string STATUS = 'status';

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Before Drupal 11.4, FormBase::create() passes no constructor arguments.
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get(ModuleHandlerInterface::class));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'canvas_content_template_list_filter_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $query = $this->getRequest()->query;

    // Reuse the Views exposed form markup so admin themes style this form like
    // the Pages listing's filters, which is a View.
    // @see \Drupal\claro\Hook\ClaroHooks::preprocessViewsExposedForm()
    $form['#attributes']['class'][] = 'views-exposed-form';
    $form['#attributes']['class'][] = 'form--inline';
    if ($this->moduleHandler->moduleExists('views')) {
      $form['#attached']['library'][] = 'views/views.module';
    }
    $form[self::SEARCH] = [
      '#type' => 'search',
      '#title' => $this->t('Template'),
      '#default_value' => $query->get(self::SEARCH, ''),
      '#size' => 30,
      '#wrapper_attributes' => ['class' => ['views-exposed-form__item']],
    ];
    $form[self::STATUS] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#options' => [
        '' => $this->t('- Any -'),
        '1' => $this->t('Enabled'),
        '0' => $this->t('Disabled'),
      ],
      '#default_value' => $query->get(self::STATUS, ''),
      '#wrapper_attributes' => [
        'class' => [
          'views-exposed-form__item',
          'views-exposed-form__item--preceding-actions',
        ],
      ],
    ];
    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => [
        'class' => [
          'views-exposed-form__item',
          'views-exposed-form__item--actions',
        ],
      ],
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply'),
    ];
    if ($query->has(self::SEARCH) || $query->has(self::STATUS)) {
      $form['actions']['reset'] = [
        '#type' => 'submit',
        '#value' => $this->t('Reset'),
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $query = [];
    if (($form_state->getTriggeringElement()['#parents'] ?? NULL) !== ['reset']) {
      $query = array_filter([
        self::SEARCH => trim((string) $form_state->getValue(self::SEARCH)),
        self::STATUS => (string) $form_state->getValue(self::STATUS),
      ], static fn (string $value): bool => $value !== '');
    }
    // Redirecting to a GET request lets filtered listings be linked to.
    $form_state->setRedirectUrl(Url::fromRoute('entity.content_template.collection', [], ['query' => $query]));
  }

}
