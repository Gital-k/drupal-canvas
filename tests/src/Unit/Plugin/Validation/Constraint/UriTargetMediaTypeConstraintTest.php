<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Plugin\Validation\Constraint;

use Drupal\canvas\Plugin\Validation\Constraint\UriTargetMediaTypeConstraint;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Validator\Exception\InvalidArgumentException;

#[Group('canvas')]
#[CoversClass(UriTargetMediaTypeConstraint::class)]
final class UriTargetMediaTypeConstraintTest extends UnitTestCase {

  #[DataProvider('providerWildcards')]
  public function testIsValidWildCard(string $mime_type, bool $expected): void {
    $this->assertSame($expected, UriTargetMediaTypeConstraint::isValidWildCard($mime_type));
  }

  public static function providerWildcards(): \Generator {
    yield 'image wildcard' => ['image/*', TRUE];
    yield 'application wildcard' => ['application/*', TRUE];
    yield 'trailing junk' => ['application/*junk', FALSE];
    yield 'leading junk' => ['foo application/*', FALSE];
    yield 'any media type' => ['*/*', FALSE];
    yield 'concrete MIME type' => ['application/pdf', FALSE];
    yield 'empty' => ['', FALSE];
  }

  public function testRejectsMalformedWildcardOption(): void {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('The option "mimeType" must be a valid MIME type or wildcard.');
    new UriTargetMediaTypeConstraint(['mimeType' => 'application/*junk']);
  }

}
