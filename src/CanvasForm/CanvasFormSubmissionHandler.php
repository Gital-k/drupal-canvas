<?php

declare(strict_types=1);

namespace Drupal\canvas\CanvasForm;

use Drupal\canvas\Entity\CanvasForm;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class CanvasFormSubmissionHandler {

  private const COLLECTION = 'canvas_form_submissions';

  public function __construct(
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly TimeInterface $time,
    private readonly RequestStack $requestStack,
  ) {}

  public function save(CanvasForm $form, array $values): string {
    $timestamp = $this->time->getCurrentTime();
    $id = $form->id() . '_' . $timestamp . '_' . substr(md5(uniqid()), 0, 8);

    $this->keyValueFactory->get(self::COLLECTION)->set($id, [
      'id' => $id,
      'form_id' => $form->id(),
      'form_label' => $form->label(),
      'submitted_at' => $timestamp,
      'ip' => $this->requestStack->getCurrentRequest()?->getClientIp() ?? '',
      'values' => $values,
    ]);

    return $id;
  }

  public function getSubmissionsForForm(string $form_id): array {
    $all = $this->keyValueFactory->get(self::COLLECTION)->getAll();
    return array_filter($all, fn($s) => ($s['form_id'] ?? '') === $form_id);
  }

  // @phpstan-ignore shipmonk.deadMethod
  public function getSubmission(string $id): ?array {
    return $this->keyValueFactory->get(self::COLLECTION)->get($id);
  }

  public function delete(string $id): void {
    $this->keyValueFactory->get(self::COLLECTION)->delete($id);
  }

}
