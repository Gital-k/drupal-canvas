<?php

declare(strict_types=1);

namespace Drupal\Tests\navigation\Functional;

use Drupal\Tests\system\Functional\Recipe\GenericRecipeTestBase;

class RecipeTest extends GenericRecipeTestBase {

  protected function getRecipePath(): string {
    // Assume this test in located in RECIPE_DIR/tests/src/Functional.
    return dirname((new \ReflectionObject($this))->getFileName(), 4) . '/tests/fixtures/recipes/3552121';
  }

}
