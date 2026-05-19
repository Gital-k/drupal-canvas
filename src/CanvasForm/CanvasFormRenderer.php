<?php

declare(strict_types=1);

namespace Drupal\canvas\CanvasForm;

use Drupal\canvas\Entity\CanvasForm;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

class CanvasFormRenderer extends FormBase {

  public function __construct(
    private readonly CanvasFormSubmissionHandler $submissionHandler,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get(CanvasFormSubmissionHandler::class),
    );
  }

  public function getFormId(): string {
    $form = $this->getRequest()->attributes->get('canvas_form');
    return 'canvas_form_' . ($form instanceof CanvasForm ? $form->id() : 'unknown');
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?CanvasForm $canvas_form = NULL): array {
    if (!$canvas_form) {
      return $form;
    }

    $form_state->set('canvas_form', $canvas_form);
    $form += $canvas_form->toRenderArray();

    $settings = $canvas_form->getSubmitSettings();
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $settings['button_text'] ?: 'Submit',
        '#button_type' => 'primary',
      ],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $canvas_form = $form_state->get('canvas_form');
    if (!$canvas_form instanceof CanvasForm) {
      return;
    }

    $values = $form_state->cleanValues()->getValues();
    $this->submissionHandler->save($canvas_form, $values);

    $settings = $canvas_form->getSubmitSettings();
    $this->messenger()->addStatus($settings['confirmation_message'] ?: $this->t('Thank you for your submission.'));

    if (!empty($settings['redirect_url'])) {
      $form_state->setRedirectUrl(Url::fromUserInput($settings['redirect_url']));
    }
  }

}
