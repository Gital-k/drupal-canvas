<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Plugin\Validation\Constraint;

use Drupal\canvas\Plugin\Validation\Constraint\UriTargetFileExtensionsConstraint;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Validator\Exception\InvalidArgumentException;

#[Group('canvas')]
#[CoversClass(UriTargetFileExtensionsConstraint::class)]
final class UriTargetFileExtensionsConstraintTest extends UnitTestCase {

  public function testExtensionsAreNormalizedToLowerCase(): void {
    $constraint = new UriTargetFileExtensionsConstraint(['allowedExtensions' => ['PDF', 'Docx', 'pdf']]);
    $this->assertSame(['pdf', 'docx'], $constraint->allowedExtensions);
  }

  public function testIntersectsWithIgnoresCase(): void {
    $shape = new UriTargetFileExtensionsConstraint(['allowedExtensions' => ['pdf', 'docx']]);
    $field = new UriTargetFileExtensionsConstraint(['allowedExtensions' => ['PDF']]);
    $this->assertTrue($shape->intersectsWith($field));
    $this->assertTrue($field->intersectsWith($shape));

    $archive = new UriTargetFileExtensionsConstraint(['allowedExtensions' => ['ZIP']]);
    $this->assertFalse($shape->intersectsWith($archive));
  }

  public function testRejectsEmptyList(): void {
    $this->expectException(InvalidArgumentException::class);
    new UriTargetFileExtensionsConstraint(['allowedExtensions' => []]);
  }

}
