<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\FunctionalTests\Update\UpdatePathTestBase;
use Symfony\Component\Validator\ConstraintViolation;

abstract class CanvasUpdatePathTestBase extends UpdatePathTestBase {

  /**
   * Disable entity schema check; dumps predate the canvas_form entity type.
   *
   * The canvas_update_11201 hook installs it, but tests not specifically
   * testing that hook should not assert entity schema completeness.
   *
   * @var bool
   * @phpstan-ignore shipmonk.deadProperty.neverRead
   */
  protected $checkEntityFieldDefinitionUpdates = FALSE;

  protected static function assertEntityIsValid(ConfigEntityInterface $entity): void {
    $violations = $entity->getTypedData()->validate();
    self::assertCount(0,
      $violations,
      \sprintf(
        'Violations exist for %s %s: %s',
        $entity->getEntityType()->getLabel(),
        $entity->id(),
        \implode(\PHP_EOL, \array_map(
          // @phpstan-ignore-next-line
            static fn(ConstraintViolation $violation): string => \sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage()),
            \iterator_to_array($violations))
        )
      )
    );
  }

}
