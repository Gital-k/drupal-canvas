<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\canvas\Entity\Page;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for Canvas Page operations.
 */
final class PageController extends ControllerBase {

  /**
   * Creates a new Canvas page and redirects to it.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect response to the newly created page.
   */
  public function addPage(): RedirectResponse {
    // Create a new Canvas page.
    $page = Page::create([
      'title' => $this->t('Untitled page'),
      'status' => 0,
    ]);
    $page->save();

    // Redirect to the edit form of the newly created page.
    $url = Url::fromRoute('entity.canvas_page.edit_form', ['canvas_page' => $page->id()]);
    return new RedirectResponse($url->toString());
  }

}