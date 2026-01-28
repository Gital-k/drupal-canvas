<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\Tests\system\Functional\Recipe\GenericRecipeTestBase;

/**
 * Test for 3552121.
 */
class RecipeTest extends GenericRecipeTestBase {

  protected function getRecipePath(): string {
    $path = (new \ReflectionObject($this))->getFileName();
    self::assertIsString($path);
    return dirname($path, 4) . '/tests/fixtures/recipes/3552121';
  }

}
