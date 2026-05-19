<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\CanvasForm\CanvasFormSubmissionHandler;
use Drupal\canvas\Entity\CanvasForm;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CanvasFormSubmissionsController extends ControllerBase {

  public function __construct(
    private readonly CanvasFormSubmissionHandler $submissionHandler,
  ) {}

  public function apiList(CanvasForm $canvas_form): JsonResponse {
    $submissions = $this->submissionHandler->getSubmissionsForForm($canvas_form->id());
    usort($submissions, fn($a, $b) => $b['submitted_at'] <=> $a['submitted_at']);
    return new JsonResponse(array_values($submissions));
  }

  public function apiDelete(CanvasForm $canvas_form, string $submission_id): JsonResponse {
    $this->submissionHandler->delete($submission_id);
    return new JsonResponse(null, 204);
  }

  public function exportCsv(CanvasForm $canvas_form): Response {
    $submissions = $this->submissionHandler->getSubmissionsForForm($canvas_form->id());
    usort($submissions, fn($a, $b) => $a['submitted_at'] <=> $b['submitted_at']);

    $columns = ['submitted_at', 'ip'];
    foreach ($submissions as $submission) {
      $columns = array_unique(array_merge($columns, array_keys($submission['values'] ?? [])));
    }

    $response = new StreamedResponse(function () use ($submissions, $columns) {
      $handle = fopen('php://output', 'w');
      fputcsv($handle, $columns);
      foreach ($submissions as $submission) {
        $row = [];
        foreach ($columns as $col) {
          if ($col === 'submitted_at') {
            $row[] = date('Y-m-d H:i:s', $submission['submitted_at']);
          }
          elseif ($col === 'ip') {
            $row[] = $submission['ip'] ?? '';
          }
          else {
            $value = $submission['values'][$col] ?? '';
            $row[] = is_array($value) ? implode(', ', $value) : $value;
          }
        }
        fputcsv($handle, $row);
      }
      fclose($handle);
    });

    $filename = 'canvas-form-' . $canvas_form->id() . '-submissions.csv';
    $response->headers->set('Content-Type', 'text/csv');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    return $response;
  }

}
