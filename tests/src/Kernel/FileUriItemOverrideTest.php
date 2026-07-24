<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Plugin\Field\FieldTypeOverride\FileUriItemOverride;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that a stored file URI validates regardless of its stream wrapper.
 *
 * Regression: canvas overrode the File entity `uri` field to allow only
 * `public://`, so a File entity stored under any other scheme (`private://` or
 * a contrib wrapper) failed validation — breaking recipe content imports and
 * REST uploads on sites that store files anywhere but the public file system.
 *
 * @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\FileUriItemOverride
 */
#[CoversClass(FileUriItemOverride::class)]
#[Group('canvas')]
final class FileUriItemOverrideTest extends CanvasKernelTestBase {

  use ConstraintViolationsTestTrait;

  /**
   * {@inheritdoc}
   *
   * `file_test` registers a `dummy://` stream wrapper, standing in for any
   * contrib scheme (s3fs et al) beyond core's `public`/`private`.
   */
  protected static $modules = [
    'file_test',
  ];

  /**
   * A valid file owner, so whole-entity validation has no unrelated violations.
   */
  private UserInterface $owner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    // Register the private stream wrapper, then rebuild so the field-type
    // override (applied via hook_field_info_alter) is in effect.
    // @see \Drupal\Core\CoreServiceProvider::register()
    $this->setSetting('file_private_path', 'sites/default/private');
    $container = $this->container->get('kernel')->rebuildContainer();
    \assert($container instanceof ContainerBuilder);
    $this->container = $container;
    $this->owner = User::create(['name' => 'owner', 'status' => TRUE]);
    $this->owner->save();
  }

  /**
   * @return array<string, array{0: string}>
   *   Keyed by scenario; each case is [stored file URI].
   */
  public static function providerStoredUri(): array {
    return [
      'public: the default local scheme' => ['public://canvas.png'],
      'private: the opt-in core local scheme' => ['private://canvas.png'],
      // A contrib scheme (registered here by file_test) beyond core's two.
      'dummy: a contrib stream wrapper' => ['dummy://canvas.png'],
    ];
  }

  /**
   * A File entity validates no matter which stream wrapper stores it.
   */
  #[DataProvider('providerStoredUri')]
  public function testStoredUriValidates(string $uri): void {
    $file = File::create([
      'uri' => $uri,
      'filename' => 'canvas.png',
      'filemime' => 'image/png',
      'filesize' => 1024,
      'uid' => $this->owner->id(),
      'status' => FileInterface::STATUS_PERMANENT,
    ]);
    // Validate the whole entity (as default_content and REST do on import)…
    self::assertEntityIsValid($file);
    // …and the `uri` field specifically, where the scheme constraint lives.
    self::assertSame([], self::violationsToArray($file->get('uri')->validate()));
  }

}
