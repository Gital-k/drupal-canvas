<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\CanvasForm\FormElementSchemaProvider;
use Drupal\canvas\Entity\CanvasForm;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\JsonResponse;

class CanvasFormEditorController extends ControllerBase {

  public function __construct(
    private readonly CanvasController $canvasController,
    private readonly FormElementSchemaProvider $schemaProvider,
  ) {}

  public function edit(CanvasForm $canvas_form): HtmlResponse {
    $response = ($this->canvasController)(NULL, NULL);

    $attachments = $response->getAttachments();
    $attachments['drupalSettings']['canvas']['base'] = Url::fromRoute('canvas.boot.empty')->getInternalPath();
    $attachments['drupalSettings']['canvasFormBuilder'] = [
      'formId' => $canvas_form->id(),
      'formData' => $canvas_form->normalizeForClientSide()->values,
      'elementSchemas' => $this->schemaProvider->getElementSchemas(),
    ];
    $response->setAttachments($attachments);

    return $response;
  }

  public function schemas(): JsonResponse {
    return new JsonResponse($this->schemaProvider->getElementSchemas());
  }

}
