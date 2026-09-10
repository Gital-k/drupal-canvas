<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Utility;

use Drupal\canvas\Utility\DateResolver;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Drupal\canvas\Utility\DateResolver.
 */
#[CoversClass(DateResolver::class)]
#[Group('canvas')]
final class DateResolverTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [];

  /**
   * Creates a DateResolver wired to a mock language manager returning $langcode.
   *
   * @param string $langcode
   *   The langcode the mock should return.
   *
   * @return \Drupal\canvas\Utility\DateResolver
   *   A freshly constructed resolver.
   */
  private function makeResolver(string $langcode): DateResolver {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn($langcode);
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getCurrentLanguage')->willReturn($language);
    return new DateResolver($languageManager);
  }

  /**
   * Empty string passes through unchanged.
   */
  public function testResolveEmptyString(): void {
    $resolver = $this->makeResolver('en');
    self::assertSame('', $resolver->resolveDate(''));
    self::assertSame('', $resolver->resolveDateTime(''));
    self::assertSame('', $resolver->resolveTime(''));
  }

  /**
   * Invalid ISO string passes through unchanged.
   */
  public function testResolveInvalidIso(): void {
    $resolver = $this->makeResolver('en');
    self::assertSame('not-a-date', $resolver->resolveDate('not-a-date'));
    self::assertSame('not-a-date', $resolver->resolveDateTime('not-a-date'));
    self::assertSame('not-a-date', $resolver->resolveTime('not-a-date'));
  }

  /**
   * ResolveDate returns a non-empty string different from the ISO input for English.
   */
  public function testResolveDateEnglish(): void {
    $resolver = $this->makeResolver('en');
    $result = $resolver->resolveDate('2026-01-15');
    self::assertNotSame('2026-01-15', $result);
    self::assertNotEmpty($result);
  }

  /**
   * ResolveDate returns a non-empty string different from the ISO input for French.
   */
  public function testResolveDateFrench(): void {
    $resolver = $this->makeResolver('fr');
    $result = $resolver->resolveDate('2026-01-15');
    self::assertNotSame('2026-01-15', $result);
    self::assertNotEmpty($result);
  }

  /**
   * ResolveDate returns a non-empty string different from the ISO input for Japanese.
   */
  public function testResolveDateJapanese(): void {
    $resolver = $this->makeResolver('ja');
    $result = $resolver->resolveDate('2026-01-15');
    self::assertNotSame('2026-01-15', $result);
    self::assertNotEmpty($result);
  }

  /**
   * ResolveDate returns a non-empty string different from the ISO input for British English.
   */
  public function testResolveDateEnglishGb(): void {
    $resolver = $this->makeResolver('en-gb');
    $result = $resolver->resolveDate('2026-01-15');
    self::assertNotSame('2026-01-15', $result);
    self::assertNotEmpty($result);
  }

  /**
   * ResolveDateTime returns a formatted string for English.
   */
  public function testResolveDateTime(): void {
    $resolver = $this->makeResolver('en');
    $result = $resolver->resolveDateTime('2026-01-15T14:30:00Z');
    self::assertNotSame('2026-01-15T14:30:00Z', $result);
    self::assertNotEmpty($result);
  }

  /**
   * ResolveTime returns a formatted time string for English.
   */
  public function testResolveTime(): void {
    $resolver = $this->makeResolver('en');
    $result = $resolver->resolveTime('2026-01-15T14:30:00Z');
    self::assertNotSame('2026-01-15T14:30:00Z', $result);
    self::assertNotEmpty($result);
  }

  /**
   * ResolveDateRange formats both from and to fields.
   */
  public function testResolveDateRange(): void {
    $resolver = $this->makeResolver('en');
    $result = $resolver->resolveDateRange([
      'from' => '2026-01-15',
      'to' => '2026-03-20',
    ]);
    self::assertIsArray($result);
    self::assertNotSame('2026-01-15', $result['from']);
    self::assertNotEmpty($result['from']);
    self::assertNotSame('2026-03-20', $result['to']);
    self::assertNotEmpty($result['to']);
  }

  /**
   * ResolveDateRange handles null and absent fields without error.
   */
  public function testResolveDateRangeWithNullFields(): void {
    $resolver = $this->makeResolver('en');
    $result = $resolver->resolveDateRange([
      'from' => '2026-01-15',
      'to' => NULL,
    ]);
    self::assertNotSame('2026-01-15', $result['from']);
    self::assertNull($result['to']);
  }

}
