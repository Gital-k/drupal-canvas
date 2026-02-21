<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Hook;

use Drupal\canvas\Hook\ReduxIntegratedFieldWidgetsHooks;
use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\canvas\Hook\ReduxIntegratedFieldWidgetsHooks
 * @group canvas
 */
final class ReduxIntegratedFieldWidgetsHooksTest extends TestCase {

  public function testFieldWidgetInfoAlterAddsEntityReferenceAutocompleteTransforms(): void {
    $hooks = new ReduxIntegratedFieldWidgetsHooks(
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(LibraryDiscoveryInterface::class),
      $this->createMock(RequestStack::class),
      $this->createMock(ThemeManagerInterface::class),
    );

    $info = [
      'entity_reference_autocomplete' => [],
      'string_textfield' => [],
      'unknown_widget' => [],
    ];

    $hooks->fieldWidgetInfoAlter($info);

    $this->assertSame(
      ['mainProperty' => ['name' => 'target_id']],
      $info['entity_reference_autocomplete']['canvas']['transforms']
    );
    $this->assertSame(
      ['mainProperty' => []],
      $info['string_textfield']['canvas']['transforms']
    );
    $this->assertArrayNotHasKey('canvas', $info['unknown_widget']);
  }

}
