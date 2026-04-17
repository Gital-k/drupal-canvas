<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Requires an @see tag for class names referenced in backticks in docblocks.
 *
 * A docblock mentioning `Component` must include
 * "@see \Drupal\canvas\Entity\Component" so readers and tooling can follow
 * the reference to the canonical class. Only short names that match a class
 * under the Drupal\canvas namespace (or a sub-namespace) are flagged.
 *
 * @implements Rule<InClassNode>
 */
final class DocCommentClassReferenceRule implements Rule {

  /**
   * Short class name => list of FQCNs under Drupal\canvas.
   *
   * @var array<string, list<string>>|null
   */
  private ?array $classIndex = NULL;

  public function getNodeType(): string {
    return InClassNode::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    $classNode = $node->getOriginalNode();
    $errors = $this->inspect($classNode);
    foreach ($classNode->stmts as $stmt) {
      $errors = array_merge($errors, $this->inspect($stmt));
    }
    return $errors;
  }

  /**
   * @return list<\PHPStan\Rules\IdentifierRuleError>
   */
  private function inspect(Node $node): array {
    $docComment = $node->getDocComment();
    if ($docComment === NULL) {
      return [];
    }

    $text = $docComment->getText();

    if (preg_match_all('/`([A-Z][A-Za-z0-9_]*)`/', $text, $backtickMatches) === 0) {
      return [];
    }

    preg_match_all('/@see\s+(\\\\?[A-Za-z0-9_\\\\]+)/', $text, $seeMatches);
    $seenFqcns = [];
    foreach ($seeMatches[1] as $reference) {
      $seenFqcns[ltrim($reference, '\\')] = TRUE;
    }

    $errors = [];
    $processed = [];
    foreach ($backtickMatches[1] as $shortName) {
      if (isset($processed[$shortName])) {
        continue;
      }
      $processed[$shortName] = TRUE;

      $candidates = $this->getClassIndex()[$shortName] ?? [];
      if ($candidates === []) {
        continue;
      }

      $matched = FALSE;
      foreach ($candidates as $fqcn) {
        if (isset($seenFqcns[$fqcn])) {
          $matched = TRUE;
          break;
        }
      }
      if ($matched) {
        continue;
      }

      $expected = $candidates[0];
      $errors[] = RuleErrorBuilder::message(sprintf(
        'Doc comment references class `%s` in backticks but is missing "@see \\%s".',
        $shortName,
        $expected,
      ))
        ->identifier('canvas.docComment.missingSee')
        ->line($docComment->getStartLine())
        ->build();
    }

    return $errors;
  }

  /**
   * @return array<string, list<string>>
   */
  private function getClassIndex(): array {
    if ($this->classIndex !== NULL) {
      return $this->classIndex;
    }
    $srcDir = dirname(__DIR__, 4) . '/src';
    $index = [];
    if (!is_dir($srcDir)) {
      return $this->classIndex = [];
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
      if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
      }
      $relative = substr($file->getPathname(), strlen($srcDir) + 1, -4);
      $fqcn = 'Drupal\\canvas\\' . str_replace('/', '\\', $relative);
      $shortName = $file->getBasename('.php');
      $index[$shortName][] = $fqcn;
    }
    $this->classIndex = $index;
    return $index;
  }

}
