<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\AutoSave;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\Core\Url;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests auto-save conflict handling for page variants.
 *
 * @see \Drupal\canvas\Entity\PageVariant
 * @legacy-covers \Drupal\canvas\Controller\ApiLayoutController::get
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class AutoSaveConflictPageVariantLayoutTest extends AutoSaveConflictLayoutTestBase {

  protected function setUpEntity(): void {
    $marker = Component::load(Marker::PAGE_CONTENT_COMPONENT_ID);
    \assert($marker instanceof Component);
    $messages_block = Component::load('block.system_messages_block');
    \assert($messages_block instanceof Component);
    $variant = PageVariant::create([
      'id' => 'test_variant',
      'label' => 'Test variant',
      'component_tree' => [
        // A page variant tree must contain exactly one "Page content" marker.
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => Marker::PAGE_CONTENT_COMPONENT_ID,
          'component_version' => $marker->getActiveVersion(),
          'inputs' => [],
        ],
        // The system messages block provides a label input to edit.
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => 'block.system_messages_block',
          'component_version' => $messages_block->getActiveVersion(),
          'inputs' => [
            'label' => '',
            'label_display' => '0',
          ],
        ],
      ],
    ]);
    $variant->save();
    $this->entity = $variant;
  }

  protected static function getPermissions(): array {
    return [
      PageVariant::ADMIN_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
    ];
  }

  protected function modifyJsonToSendAsAutoSave(array &$json, string $text): void {
    // A page variant's layout consists of a single content region.
    $regions = array_filter($json['layout'], fn ($region) =>
      $region['nodeType'] === 'region'
      && $region['id'] === CanvasPageVariant::MAIN_CONTENT_REGION
    );
    self::assertCount(1, $regions);
    $region = reset($regions);
    // Find the system messages block among the region's components.
    $blocks = array_filter($region['components'], fn (array $component) => str_starts_with($component['type'], 'block.system_messages_block@'));
    self::assertCount(1, $blocks);
    $uuid = reset($blocks)['uuid'];
    // The system messages block should have a label we can update.
    \assert(isset($json['model'][$uuid]['resolved']['label']));
    $json['model'][$uuid]['resolved']['label'] = $text;
  }

  protected function assertCurrentAutoSaveText(string $text): void {
    $variant = $this->getAutoSaveManager()->getAutoSaveEntity($this->entity)->entity;
    \assert($variant instanceof PageVariant);
    $variantTree = $variant->getComponentTree()->getValue();
    // Find the system messages block, which is the component whose label we
    // updated.
    // @see ::modifyJsonToSendAsAutoSave()
    $blocks = array_filter($variantTree, fn (array $item) => $item['component_id'] === 'block.system_messages_block');
    self::assertCount(1, $blocks);
    self::assertSame([
      'label' => $text,
      'label_display' => '0',
    ], reset($blocks)['inputs']);
  }

  public function testMissingAutoSaveEntryConflicts(): void {
    // A user without the page variant admin permission cannot reach the
    // variant's layout routes at all.
    $this->drupalLogin($this->createUser(array_diff(self::getPermissions(), [PageVariant::ADMIN_PERMISSION])));
    $this->csrfToken = $this->drupalGet('session/token');
    $response = $this->makeApiRequest('GET', $this->getAutoSaveUrl(), $this->getRequestOptions());
    // The layout routes require update access to the page variant.
    self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), (string) $response->getBody());

    $this->drupalLogin($this->createUser(self::getPermissions()));
    $this->csrfToken = $this->drupalGet('session/token');
    $getJson = $this->requestAutoSaveGet();

    // Posting back the GET response with the variant's own `autoSaves` entry
    // removed must conflict.
    $autoSaveKey = AutoSaveManager::getAutoSaveKey($this->entity);
    self::assertArrayHasKey($autoSaveKey, $getJson['autoSaves']);
    $getJsonMissingVariant = $getJson;
    unset($getJsonMissingVariant['autoSaves'][$autoSaveKey]);
    $this->assertAutoSaveConflict($getJsonMissingVariant);
  }

  protected function getAutoSaveUrl(): Url {
    return Url::fromRoute('canvas.api.layout.get', [
      'entity' => $this->entity->id(),
      'entity_type' => PageVariant::ENTITY_TYPE_ID,
    ]);
  }

}
