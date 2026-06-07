<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Entity;

use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the "edit canvas global regions" permission via hook_entity_access().
 *
 * @see canvas_entity_access()
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class PageRegionGlobalEditAccessTest extends CanvasKernelTestBase {

  private const EDIT_GLOBAL_REGIONS_PERMISSION = 'edit canvas global regions';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $regions = PageRegion::createFromBlockLayout('stark');
    foreach ($regions as $region) {
      $region->save();
    }
  }

  /**
   * @param string $region_id
   *   The page region config ID (e.g. "stark.sidebar_first", "stark.content").
   * @param string $operation
   *   The operation to check.
   * @param array $permissions
   *   Permissions granted to the account.
   * @param bool $expected_forbidden
   *   Whether canvas_entity_access should return a forbidden result.
   */
  #[DataProvider('accessProvider')]
  public function testGlobalRegionAccess(string $region_id, string $operation, array $permissions, bool $expected_forbidden): void {
    [$theme, $region_name] = explode('.', $region_id, 2);
    // A "content" PageRegion can never be saved (see PageRegion::preSave()).
    $region = PageRegion::load($region_id) ?? PageRegion::create([
      'id' => $region_id,
      'theme' => $theme,
      'region' => $region_name,
    ]);
    self::assertInstanceOf(PageRegion::class, $region);

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(fn ($permission) => \in_array($permission, $permissions, TRUE));

    $result = canvas_entity_access($region, $operation, $account);
    self::assertSame($expected_forbidden, $result->isForbidden());
  }

  public static function accessProvider(): array {
    return [
      // Global (non-content) region without the permission: forbidden on write.
      'sidebar_first update: no permission' => ['stark.sidebar_first', 'update', [], TRUE],
      'sidebar_first edit: no permission' => ['stark.sidebar_first', 'edit', [], TRUE],
      'sidebar_first delete: no permission' => ['stark.sidebar_first', 'delete', [], TRUE],

      // Global region with permission: not forbidden.
      'sidebar_first update: with permission' => ['stark.sidebar_first', 'update', [self::EDIT_GLOBAL_REGIONS_PERMISSION], FALSE],
      'sidebar_first edit: with permission' => ['stark.sidebar_first', 'edit', [self::EDIT_GLOBAL_REGIONS_PERMISSION], FALSE],
      'sidebar_first delete: with permission' => ['stark.sidebar_first', 'delete', [self::EDIT_GLOBAL_REGIONS_PERMISSION], FALSE],

      // View operations are unaffected.
      'sidebar_first view: no permission' => ['stark.sidebar_first', 'view', [], FALSE],

      // Content region: hook is always neutral, regardless of permission.
      'content update: no permission' => ['stark.content', 'update', [], FALSE],
      'content delete: no permission' => ['stark.content', 'delete', [], FALSE],
      'content edit: with permission' => ['stark.content', 'edit', [self::EDIT_GLOBAL_REGIONS_PERMISSION], FALSE],
    ];
  }

  /**
   * Non page_region entities are unaffected by the hook.
   */
  public function testNonPageRegionEntityIsNeutral(): void {
    $this->installEntitySchema('canvas_page');
    $page = Page::create(['title' => 'Test']);
    $page->save();

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);

    $result = canvas_entity_access($page, 'update', $account);
    self::assertTrue($result->isNeutral());
  }

}
