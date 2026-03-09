<?php

declare(strict_types=1);

namespace Drupal\canvas\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Exposed filter form for the Component list page.
 *
 * Rendered as a GET form so all filter state lives in the URL query string —
 * bookmarkable, shareable, and page-cache-friendly. Matches the markup that
 * Views generates for its exposed filter forms so admin themes style it
 * consistently without any custom CSS.
 *
 * This is intentionally a separate FormBase rather than part of
 * ComponentListBuilder, keeping the stateless GET filter form and the
 * stateful POST bulk-action form cleanly decoupled.
 */
final class ComponentFilterForm extends FormBase {

  public function __construct(
    private readonly ComponentSourceManager $componentSourceManager,
    private readonly Request $request,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(ComponentSourceManager::class),
      $container->get('request_stack')->getCurrentRequest(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'component_filter_form';
  }

  /**
   * {@inheritdoc}
   *
   * Uses the Form API properly — #type textfield and #type select — instead
   * of hand-crafting html_tag render arrays. The Views exposed-form CSS
   * classes are applied via #attributes so the admin theme renders this
   * identically to a Views exposed filter form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Emit a GET form, not POST, so the filters appear in the URL and the
    // bulk-action POST form above is not affected.
    $form['#method'] = 'get';
    $form['#action'] = Url::fromRoute('<current>')->toString();

    // Suppress the form token: GET forms must not carry CSRF tokens since the
    // URL would be un-bookmarkable and caching would break.
    $form['#token'] = FALSE;

    $form['#attributes']['class'][] = 'views-exposed-form';
    $form['#attributes']['aria-label'] = $this->t('Filter the contents of the Components list');
    $form['#attributes']['data-drupal-selector'] = 'views-exposed-form-component-list';

    $form['#attached']['library'][] = 'views/views.exposed-form';

    // --- ID text filter ------------------------------------------------------
    $form['id'] = [
      '#type'            => 'textfield',
      '#title'           => $this->t('ID'),
      '#default_value'   => $this->request->query->get('id', ''),
      '#size'            => 30,
      '#maxlength'       => 128,
      // Views wraps each filter in views-exposed-form__item; the type-specific
      // BEM modifier classes are added by the Views theme layer. We replicate
      // them here via wrapper_attributes so the output is identical.
      '#wrapper_attributes' => [
        'class' => [
          'views-exposed-form__item',
          'js-form-item',
          'js-form-type-textfield',
          'form-type--textfield',
          'js-form-item-id',
          'form-item--id',
        ],
      ],
      '#label_attributes' => ['class' => ['form-item__label']],
    ];

    // --- Component type multi-select -----------------------------------------
    $source_options = [];
    foreach ($this->componentSourceManager->getDefinitions() as $id => $definition) {
      $source_options[$id] = (string) $definition['label'];
    }
    \asort($source_options);

    $form['source'] = [
      '#type'               => 'select',
      '#title'              => $this->t('Component type'),
      '#options'            => $source_options,
      '#default_value'      => (array) ($this->request->query->all()['source'] ?? []),
      '#wrapper_attributes' => [
        'class' => [
          'views-exposed-form__item',
          'js-form-item',
          'js-form-type-select',
          'form-type--select',
          'js-form-item-source',
          'form-item--source',
        ],
      ],
      '#label_attributes' => ['class' => ['form-item__label']],
    ];

    // --- Status select -------------------------------------------------------
    $form['status'] = [
      '#type'    => 'select',
      '#title'   => $this->t('Status'),
      '#options' => [
        'all'      => $this->t('- Any -'),
        'enabled'  => $this->t('Enabled'),
        'disabled' => $this->t('Disabled'),
      ],
      '#default_value'      => $this->request->query->get('status', 'all'),
      '#wrapper_attributes' => [
        'class' => [
          'views-exposed-form__item',
          'views-exposed-form__item--preceding-actions',
          'js-form-item',
          'js-form-type-select',
          'form-type--select',
          'js-form-item-status',
          'form-item--status',
        ],
      ],
      '#label_attributes' => ['class' => ['form-item__label']],
    ];

    // --- Actions -------------------------------------------------------------
    // Match Views' div.form-actions.views-exposed-form__item--actions wrapper.
    $form['actions'] = [
      '#type'       => 'actions',
      '#attributes' => [
        'class' => [
          'views-exposed-form__item',
          'views-exposed-form__item--actions',
        ],
      ],
      'submit' => [
        '#type'        => 'submit',
        '#value'       => $this->t('Apply'),
        '#button_type' => 'primary',
      ],
      'reset' => [
        '#type'  => 'link',
        '#title' => $this->t('Reset'),
        '#url'   => Url::fromRoute('<current>'),
        '#attributes' => ['class' => ['button']],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * No-op: this form submits via GET, so Drupal's form submission cycle is not
   * used. The browser encodes the values as query parameters on submit, and
   * ComponentListBuilder::load() reads them directly from the request.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

}
