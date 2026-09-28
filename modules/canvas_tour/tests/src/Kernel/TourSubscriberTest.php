<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_tour\Kernel;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Url;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tour\Entity\Tour;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that Tour entities targeting the Canvas editor shell get attached.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_tour')]
final class TourSubscriberTest extends CanvasKernelTestBase {

  use PageTrait;
  use RequestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'tour',
    'canvas_tour',
    ...self::PAGE_TEST_MODULES,
  ];

  /**
   * Tests that a Tour entity's library and settings reach the response.
   */
  public function testTourAttachesToEditorShell(): void {
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->setUpCurrentUser([], [
      'access content',
      Page::CREATE_PERMISSION,
      Page::EDIT_PERMISSION,
      'access tour',
    ]);
    $this->createTestTour('canvas_tour_test_1', 'canvas-tour-test-tip-1');

    $response = $this->request(Request::create($this->canvasUrl()));

    self::assertStringContainsString('canvas-tour-test-tip-1', (string) $response->getContent());
    self::assertContains('canvas_tour/canvas-tour-bridge', $response->getAttachments()['library'] ?? []);
  }

  /**
   * Tests that Tour tips are withheld without the `access tour` permission.
   */
  public function testTourWithheldWithoutPermission(): void {
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->setUpCurrentUser([], [
      'access content',
      Page::CREATE_PERMISSION,
      Page::EDIT_PERMISSION,
    ]);
    $this->createTestTour('canvas_tour_test_2', 'canvas-tour-test-tip-2');

    $response = $this->request(Request::create($this->canvasUrl()));

    self::assertStringNotContainsString('canvas-tour-test-tip-2', (string) $response->getContent());
  }

  /**
   * Creates a single-tip Tour entity targeting the Canvas editor shell.
   */
  private function createTestTour(string $tour_id, string $tip_id): void {
    Tour::create([
      'id' => $tour_id,
      'label' => 'Canvas tour test',
      'routes' => [
        ['route_name' => 'canvas.boot.empty'],
      ],
      'tips' => [
        $tip_id => [
          'id' => $tip_id,
          'plugin' => 'text',
          'label' => 'Test tip',
          'weight' => 0,
          'position' => 'bottom',
          'selector' => '#canvas',
          'body' => 'This is a test tip.',
        ],
      ],
    ])->save();
  }

  /**
   * Builds the Canvas editor shell URL.
   */
  private function canvasUrl(): string {
    return Url::fromRoute('canvas.boot.empty', [
      'entity_type' => '',
      'entity' => '',
    ])->toString();
  }

}
