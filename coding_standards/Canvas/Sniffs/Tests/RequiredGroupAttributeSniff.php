<?php

declare(strict_types=1);

namespace Canvas\Sniffs\Tests;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Requires every concrete test class to carry a `#[Group(…)]` attribute.
 *
 * Without a group, a test cannot be selected by `phpunit --group …`, so it
 * silently drops out of every partial test run. PHPUnit does not inherit
 * `#[Group(…)]` from a parent class — `PHPUnit\Metadata\Api\Groups::groups()`
 * only reads the attributes declared on the test class itself — so each
 * concrete test class must repeat the attribute even when its base class has
 * one.
 *
 * Deliberately not flagged:
 * - Abstract classes: PHPUnit never runs them, and a group on them would not
 *   reach their subclasses anyway.
 * - Classes whose name does not end in `Test`: test files also declare test
 *   doubles and other helpers, which PHPUnit does not collect.
 *
 * Only the attribute is accepted, never the `@group` annotation.
 *
 * @see \Canvas\Sniffs\Tests\PhpunitAnnotationsSniff
 * @see https://www.drupal.org/node/3447698
 */
class RequiredGroupAttributeSniff implements Sniff {

  /**
   * The attribute that must be present, as written by PHPUnit.
   */
  private const REQUIRED_ATTRIBUTE = 'Group';

  /**
   * Tokens that can make up an attribute's (possibly namespaced) name.
   */
  private const NAME_TOKENS = [
    T_STRING => T_STRING,
    T_NS_SEPARATOR => T_NS_SEPARATOR,
    T_NAME_QUALIFIED => T_NAME_QUALIFIED,
    T_NAME_FULLY_QUALIFIED => T_NAME_FULLY_QUALIFIED,
  ];

  public function register() {
    return [T_CLASS];
  }

  public function process(File $phpcsFile, $stackPtr) {
    $className = $phpcsFile->getDeclarationName($stackPtr);
    // Test doubles, stubs and other helpers are routinely declared alongside
    // the test class they serve; PHPUnit only collects `*Test` classes.
    // For example: \Drupal\Tests\canvas\Kernel\AutoSaveManagerTestTime.
    if ($className === NULL || !str_ends_with($className, 'Test')) {
      return;
    }

    // Abstract base classes are never run by PHPUnit.
    if ($phpcsFile->getClassProperties($stackPtr)['is_abstract'] === TRUE) {
      return;
    }

    if ($this->hasGroupAttribute($phpcsFile, $stackPtr)) {
      return;
    }

    $phpcsFile->addError(
      "Test class $className must have a #[Group('…')] attribute, otherwise it cannot be run using `phpunit --group …`. PHPUnit does not inherit this attribute from a base class.",
      $stackPtr,
      'MissingGroupAttribute'
    );
  }

  /**
   * Determines whether the class declaration is preceded by `#[Group(…)]`.
   */
  private function hasGroupAttribute(File $phpcsFile, int $stackPtr): bool {
    $tokens = $phpcsFile->getTokens();

    // Walk back over the class' modifiers, docblock and attributes. Attributes
    // may appear before or after the docblock, and in any number.
    $skip = Tokens::$emptyTokens;
    $skip[T_ABSTRACT] = T_ABSTRACT;
    $skip[T_FINAL] = T_FINAL;
    $skip[T_READONLY] = T_READONLY;

    $ptr = $stackPtr;
    while (TRUE) {
      $prev = $phpcsFile->findPrevious($skip, $ptr - 1, NULL, TRUE);
      if ($prev === FALSE || $tokens[$prev]['code'] !== T_ATTRIBUTE_END) {
        return FALSE;
      }
      $opener = $tokens[$prev]['attribute_opener'];
      if ($this->attributeGroupContainsRequiredAttribute($tokens, $opener, $prev)) {
        return TRUE;
      }
      $ptr = $opener;
    }
  }

  /**
   * Determines whether one `#[…]` block names the required attribute.
   *
   * A single block can name several attributes: `#[Group('a'), Group('b')]`.
   */
  private function attributeGroupContainsRequiredAttribute(array $tokens, int $opener, int $closer): bool {
    $name = '';
    for ($i = $opener + 1; $i < $closer; $i++) {
      $code = $tokens[$i]['code'];
      // Names nested in parentheses are arguments, not attribute names.
      if (isset(self::NAME_TOKENS[$code]) && empty($tokens[$i]['nested_parenthesis'])) {
        $name .= $tokens[$i]['content'];
        continue;
      }
      if (isset(Tokens::$emptyTokens[$code])) {
        continue;
      }
      // Anything else terminates the name that was being collected.
      if ($this->isRequiredAttribute($name)) {
        return TRUE;
      }
      $name = '';
    }
    return $this->isRequiredAttribute($name);
  }

  /**
   * Compares an attribute name, which may be namespaced, to the required one.
   */
  private function isRequiredAttribute(string $name): bool {
    if ($name === '') {
      return FALSE;
    }
    $shortName = substr(strrchr("\\$name", '\\') ?: '', 1);
    return $shortName === self::REQUIRED_ATTRIBUTE;
  }

}
