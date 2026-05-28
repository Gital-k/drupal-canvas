<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\image\Plugin\Field\FieldType\ImageItem;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\block\Traits\BlockCreationTrait;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Kernel\Traits\VfsPublicStreamUrlTrait;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\canvas\Traits\AutoSaveManagerTestTrait;
use Drupal\Tests\canvas\Traits\CanvasFieldCreationTrait;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\canvas\Traits\CreateTestJsComponentTrait;
use Drupal\Tests\canvas\Traits\DataProviderWithComponentTreeTrait;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\TestFileCreationTrait;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\canvas\Entity\Component
 */
abstract class ApiLayoutControllerTestBase extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
  ];

  use AutoSaveManagerTestTrait {
    setUpCurrentUser as traitSetUpCurrentUser;
  }
  use BlockCreationTrait;
  use CanvasFieldCreationTrait;
  use ConstraintViolationsTestTrait;
  use CreateTestJsComponentTrait;
  use DataProviderWithComponentTreeTrait;
  use ImageFieldCreationTrait;
  use MediaTypeCreationTrait;
  use TestFileCreationTrait;

  const REGION_PATTERN = '/<!-- canvas-region-start-%1$s -->([\n\s\S]*)<!-- canvas-region-end-%1$s -->/';

  use RequestTrait {
    request as parentRequest;
  }

  use VfsPublicStreamUrlTrait;

  protected ?ContentEntityInterface $previewEntity;
  protected Node $testNode;
  protected Node $emptyNode;
  protected Page $emptyPage;
  protected ContentTemplate $testContentTemplate;

  /**
   * Sets up the full fixture needed by Get/Post/Patch layout controller tests.
   *
   * Mirrors what CanvasTestSetup::setup(TRUE) used to do for kernel tests, but
   * using the proper KernelTestBase APIs (no runtime module_installer->install).
   */
  protected function setUpFixture(bool $createContentTemplate = TRUE): void {
    $root = $this->container->getParameter('app.root');
    \assert(\is_string($root));
    $this->root = $root;

    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installEntitySchema('filter_format');
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('page_region');
    $this->installEntitySchema('content_template');
    $this->installConfig(['node', 'media', 'user']);
    $this->config('system.site')->set('name', 'Drupal')->save();

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->createImageField('field_hero', 'node', 'article', storage_settings: [
      'display_default' => TRUE,
    ]);
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);

    // generateComponents() must be called after the image media type exists so
    // the media_library hook produces the updated version hash.
    $this->container->get(ComponentSourceManager::class)->generateComponents();

    $this->createComponentTreeField('node', 'article', 'field_canvas_demo');
    $this->createMyCtaComponentFromSdc();
    $this->createTestCodeComponent();

    // Add a Media Library field to the article content type so we can
    // confirm it works in both page data and context forms (required by
    // PatchTest::test which checks entity_form_fields keys).
    FieldStorageConfig::create([
      'field_name' => 'media_image_field',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
    ])->save();
    FieldConfig::create([
      'label' => 'A Media Image Field',
      'field_name' => 'media_image_field',
      'entity_type' => 'node',
      'bundle' => 'article',
      'field_type' => 'entity_reference',
      'required' => FALSE,
      'settings' => [
        'handler' => 'default',
        'handler_settings' => ['target_bundles' => ['image']],
      ],
    ])->save();
    \Drupal::service('entity_display.repository')
      ->getFormDisplay('node', 'article')
      ->setComponent('media_image_field', [
        'type' => 'media_library_widget',
        'region' => 'content',
        'settings' => [],
      ])
      ->save();

    // Create media + file fixtures.
    // Creation order matters: PatchTest uses Media::load(3) and Media::load(4),
    // which must resolve to heroMedia and media4 respectively.
    $test_image_files = $this->getTestFiles('image');
    // @phpstan-ignore-next-line
    $file1 = File::create(['uri' => $test_image_files[0]->uri]);
    $file1->setPermanent();
    $file1->save();
    // @phpstan-ignore-next-line
    $file2 = File::create(['uri' => $test_image_files[1]->uri]);
    $file2->setPermanent();
    $file2->save();

    $media1 = Media::create([
      'bundle' => 'image',
      'name' => 'The bones are their money',
      'field_media_image' => [['target_id' => $file1->id(), 'alt' => 'The bones equal dollars', 'title' => 'Bones are the skeletons money']],
    ]);
    $media1->save();
    $media2 = Media::create([
      'bundle' => 'image',
      'name' => 'Sorry I resemble a dog',
      'field_media_image' => [['target_id' => $file2->id(), 'alt' => 'My barber may have been looking at a picture of a dog', 'title' => 'When he gave me this haircut']],
    ]);
    $media2->save();

    // Hero image media (id=3) — used by PatchTest::test() as the PATCH target.
    // UUID_STATIC_IMAGE intentionally starts with media1 (id=1) so that patching
    // to heroMedia (id=3) is a genuine change and triggers auto-save.
    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'article');
    $image_field_sample_value = ImageItem::generateSampleValue($field_definitions['field_hero']);
    $image_field_sample_value['title'] = '';
    $heroMedia = Media::create([
      'bundle' => 'image',
      'name' => 'Hero image',
      'field_media_image' => $image_field_sample_value,
    ]);
    $heroMedia->save();
    // The file URL used for CARD3 to distinguish it from CARD2's drupal.org URL.
    // @phpstan-ignore-next-line
    $fileUrl = File::load($image_field_sample_value['target_id'])->createFileUrl(FALSE);

    // media4 (id=4) — used by UUID_STATIC_IMAGE2 and PatchTest::test().
    $image_media_source_field_definition = \Drupal::service('entity_field.manager')->getFieldDefinitions('media', 'image')['field_media_image'];
    $media4 = Media::create([
      'uuid' => CanvasTestSetup::UUID_MEDIA_IMAGE4,
      'bundle' => 'image',
      'name' => 'The fourth image media item',
      'field_media_image' => ImageItem::generateSampleValue($image_media_source_field_definition),
    ]);
    $media4->save();

    // Build the component tree using the same UUIDs as CanvasTestSetup.
    $use_uri = $this->container->get('module_handler')->moduleExists('canvas_test_storable_prop_shape_alter');
    $items = self::populateActiveComponentVersionPlaceholders([
      [
        'component_id' => 'sdc.canvas_test_sdc.two_column',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'uuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
        'inputs' => ['width' => 50],
      ],
      [
        'parent_uuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
        'slot' => 'column_one',
        'component_id' => 'sdc.canvas_test_sdc.image',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'uuid' => CanvasTestSetup::UUID_STATIC_IMAGE,
        'inputs' => ['image' => ['target_id' => $media1->id()]],
      ],
      [
        'parent_uuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
        'slot' => 'column_one',
        'component_id' => 'sdc.canvas_test_sdc.my-hero',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'uuid' => CanvasTestSetup::UUID_STATIC_CARD1,
        'inputs' => [
          'heading' => 'hello, world!',
          'cta1href' => $use_uri ? 'https://drupal.org' : ['uri' => 'https://drupal.org', 'options' => []],
        ],
      ],
      [
        'parent_uuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
        'slot' => 'column_one',
        'component_id' => 'js.test-code-component',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'uuid' => CanvasTestSetup::UUID_CODE_COMPONENT,
        'inputs' => [
          'heading' => 'Test Code Component Heading',
          'content' => 'This is a test code component for testing the Edit component action',
        ],
      ],
      [
        'parent_uuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
        'slot' => 'column_one',
        'uuid' => CanvasTestSetup::UUID_ALL_SLOTS_EMPTY,
        'component_id' => 'sdc.canvas_test_sdc.one_column',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => ['width' => 'full'],
      ],
      [
        'parent_uuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
        'slot' => 'column_two',
        'uuid' => CanvasTestSetup::UUID_STATIC_CARD2,
        'component_id' => 'sdc.canvas_test_sdc.my-hero',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [
          'heading' => 'Canvas Needs This For The Time Being',
          'cta1href' => $use_uri ? 'https://drupal.org' : ['uri' => 'https://drupal.org', 'options' => []],
        ],
      ],
      [
        'parent_uuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
        'slot' => 'column_two',
        'uuid' => CanvasTestSetup::UUID_STATIC_CARD3,
        'component_id' => 'sdc.canvas_test_sdc.my-hero',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [
          'heading' => 'Canvas Needs This For The Time Being',
          'cta1href' => $use_uri ? $fileUrl : ['uri' => $fileUrl, 'options' => []],
        ],
      ],
      [
        'parent_uuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
        'slot' => 'column_two',
        'uuid' => CanvasTestSetup::UUID_STATIC_IMAGE2,
        'component_id' => 'sdc.canvas_test_sdc.image',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => ['image' => ['target_id' => $media4->id()]],
        'label' => 'Magnificent image!',
      ],
    ]);

    // Node save triggers component tree validation which checks 'view media'.
    $this->setUpCurrentUser(permissions: ['view media', 'access content']);
    $this->testNode = Node::create([
      'type' => 'article',
      'title' => 'Canvas Needs This For The Time Being',
      // Anonymous ownership matches CanvasTestSetup; tests assert uid === 0.
      'uid' => 0,
      'field_hero' => $image_field_sample_value,
      'field_canvas_demo' => $items,
    ]);
    $this->testNode->save();

    if ($createContentTemplate) {
      $this->testContentTemplate = ContentTemplate::create([
        'content_entity_type_id' => 'node',
        'content_entity_type_bundle' => 'article',
        'content_entity_type_view_mode' => 'full',
        'component_tree' => $items,
      ]);
      $this->testContentTemplate->save();
    }

    $this->emptyNode = Node::create([
      'type' => 'article',
      'title' => 'I am an empty node',
      'path' => ['alias' => '/i-am-an-empty-node'],
      'uid' => 0,
    ]);
    $this->emptyNode->save();

    Page::create([
      'title' => 'Homepage',
      'description' => 'This is the homepage',
      'path' => ['alias' => '/homepage'],
    ])->save();

    $this->emptyPage = Page::create([
      'title' => 'Empty Page',
      'description' => 'This is an empty page',
      'path' => ['alias' => '/test-page'],
    ]);
    $this->emptyPage->save();

    Page::create([
      'title' => 'Page without a path',
      'description' => 'This is a page without a path',
    ])->save();

    $this->placeBlock('page_title_block', ['region' => 'highlighted']);
    $this->placeBlock('system_messages_block');
    $this->placeBlock('system_main_block');
  }

  /**
   * {@inheritdoc}
   *
   * Always grants 'access content' and 'view media' so node/media access checks
   * don't fail when tests create users with only the minimal permissions they
   * intend to test.
   */
  protected function setUpCurrentUser(array $values = [], array $permissions = [], bool $admin = FALSE): UserInterface {
    if (!$admin) {
      foreach (['access content', 'view media'] as $baseline) {
        if (!\in_array($baseline, $permissions, TRUE)) {
          $permissions[] = $baseline;
        }
      }
    }
    return $this->traitSetUpCurrentUser($values, $permissions, $admin);
  }

  protected static function getAdminPermission(EntityInterface $entity): string {
    if ($entity instanceof Node) {
      return 'edit any ' . $entity->bundle() . ' content';
    }
    if ($entity instanceof ContentTemplate) {
      return ContentTemplate::ADMIN_PERMISSION;
    }
    throw new \LogicException('Unsupported entity type: ' . $entity->getEntityTypeId());
  }

  /**
   * Unwrap the JSON response so we can perform assertions on it.
   */
  protected function request(Request $request): Response {
    $request->headers->set('Content-Type', 'application/json');
    $response = $this->parentRequest($request);
    $decodedResponse = static::decodeResponse($response);
    if (isset($decodedResponse['html'])) {
      $this->setRawContent($decodedResponse['html']);
    }
    return $response;
  }

  /**
   * Omit information received in the GET response that cannot be POSTed.
   */
  protected function filterLayoutForPost(string $content): string {
    $json = \json_decode($content, TRUE);
    unset($json['isNew'], $json['isPublished'], $json['html'], $json['hasUnsavedStatusChange']);
    $json += ['clientInstanceId' => $this->randomString(100)];
    return \json_encode($json, JSON_THROW_ON_ERROR);
  }

  protected function getLayoutUrl(EntityInterface $entity): Url {
    if ($entity instanceof ContentTemplate) {
      $route_name = 'canvas.api.layout.get.content_template';
      self::assertInstanceOf(ContentEntityInterface::class, $this->previewEntity);
      $url_args = [
        'entity' => $entity->id(),
        'preview_entity' => $this->previewEntity->id(),
      ];
    }
    else {
      $url_args = [
        'entity' => $entity->id(),
        'entity_type' => $entity->getEntityTypeId(),
      ];
      $route_name = 'canvas.api.layout.get';
    }
    return Url::fromRoute($route_name, $url_args);
  }

  /**
   * Uses regex to find regions "wrapped" by inline HTML comments in content.
   *
   * @param string $region
   *
   * @return ?string
   */
  protected function getRegion(string $region): ?string {
    $matches = [];

    $content = $this->getRawContent() ?: '';
    // Covers 'application/json' endpoint responses with 'html' property.
    if (json_validate($content)) {
      $decoded = \json_decode($content, TRUE);
      $content = $decoded['html'] ?? $content;
    }

    \preg_match_all(\sprintf(self::REGION_PATTERN, $region), $content, $matches);
    return \array_key_exists(0, $matches[1]) ? $matches[1][0] : NULL;
  }

  /**
   * Uses regex to find component instances "wrapped" by inline HTML comments.
   *
   * @param ?string $html
   *   The HTML to search; if none provided will use the current raw content.
   *
   * @return array
   */
  protected function getComponentInstances(?string $html): array {
    $html ??= $this->getRawContent();
    // Covers 'application/json' endpoint responses with 'html' property.
    if (json_validate($html)) {
      $decoded = \json_decode($html, TRUE);
      $html = $decoded['html'] ?? $html;
    }
    $matches = [];
    \preg_match_all('/(canvas-start-)(.*?)[\/ \t](.*?)(-->)(.*?)/', $html, $matches);
    return $matches[2];
  }

  protected function assertResponseAutoSaves(Response $response, array $expectedEntities, bool $expectRegions = FALSE): void {
    if ($expectRegions) {
      $expectedEntities += PageRegion::loadForActiveTheme();
    }
    $data = self::decodeResponse($response);
    self::assertArrayHasKey('autoSaves', $data);
    self::assertIsArray($data['autoSaves']);
    self::assertCount(\count($expectedEntities), $data['autoSaves']);
    self::assertCount(\count($expectedEntities), array_filter($data['autoSaves']));
    foreach ($expectedEntities as $entity) {
      self::assertArrayHasKey(AutoSaveManager::getAutoSaveKey($entity), $data['autoSaves']);
      self::assertSame(
        $data['autoSaves'][AutoSaveManager::getAutoSaveKey($entity)],
        $this->container->get(AutoSaveManager::class)->getClientAutoSaveData($entity),
      );
    }
  }

  /**
   * Gets a test entity of the given type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|\Drupal\canvas\Entity\ContentTemplate
   *   The test entity.
   */
  protected function getTestEntity(string $entity_type_id): ContentEntityInterface|ContentTemplate {
    if ($entity_type_id === 'node') {
      $entity = $this->testNode;
      $this->previewEntity = NULL;
    }
    elseif ($entity_type_id === ContentTemplate::ENTITY_TYPE_ID) {
      $entity = $this->testContentTemplate;
      $this->previewEntity = $this->testNode;
    }
    else {
      throw new \InvalidArgumentException('Unsupported entity type: ' . $entity_type_id);
    }
    return $entity;
  }

  public static function providerEntityTypes(): array {
    return [
      'Content entity type with component tree: Node' => ['node'],
      'Config entity type with component tree: ContentTemplate' => [ContentTemplate::ENTITY_TYPE_ID],
    ];
  }

}
