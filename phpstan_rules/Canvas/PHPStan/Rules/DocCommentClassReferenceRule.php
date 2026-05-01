<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Requires an @see tag for class names referenced in backticks in comments.
 *
 * A comment mentioning `Component` must include
 * "@see \Drupal\canvas\Entity\Component" so readers and tooling can follow
 * the reference to the canonical class. Applies to every comment (docblock,
 * block, or line), on every node. Only short names that match a class under
 * the Drupal\canvas namespace (or a sub-namespace) are flagged.
 *
 * @implements Rule<FileNode>
 */
final class DocCommentClassReferenceRule implements Rule {

  /**
   * Short class name => list of FQCNs under Drupal\canvas.
   *
   * @var array<string, list<string>>|null
   */
  private ?array $classIndex = NULL;

  public function getNodeType(): string {
    return FileNode::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    $errors = [];
    $this->walk($node->getNodes(), NULL, $errors);
    return $errors;
  }

  /**
   * @param \PhpParser\Node[] $nodes
   * @param list<\PHPStan\Rules\IdentifierRuleError> $errors
   */
  private function walk(array $nodes, ?string $currentClass, array &$errors): void {
    foreach ($nodes as $node) {
      $comments = $node->getComments();
      if ($comments !== []) {
        $text = '';
        $line = $comments[0]->getStartLine();
        foreach ($comments as $comment) {
          $text .= $comment->getText() . "\n";
        }
        foreach ($this->inspect($text, $line, $currentClass) as $error) {
          $errors[] = $error;
        }
      }

      $nextClass = $currentClass;
      if ($node instanceof ClassLike && isset($node->namespacedName)) {
        $nextClass = $node->namespacedName->toString();
      }

      foreach ($node->getSubNodeNames() as $subNodeName) {
        $subNode = $node->$subNodeName;
        if ($subNode === NULL) {
          continue;
        }
        if (\is_array($subNode)) {
          $childNodes = [];
          foreach ($subNode as $item) {
            if ($item instanceof Node) {
              $childNodes[] = $item;
            }
          }
          if ($childNodes !== []) {
            $this->walk($childNodes, $nextClass, $errors);
          }
        }
        elseif ($subNode instanceof Node) {
          $this->walk([$subNode], $nextClass, $errors);
        }
      }
    }
  }

  /**
   * @return list<\PHPStan\Rules\IdentifierRuleError>
   */
  private function inspect(string $text, int $line, ?string $currentClass): array {
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

      $selfReference = FALSE;
      foreach ($candidates as $fqcn) {
        if ($fqcn === $currentClass) {
          $selfReference = TRUE;
          break;
        }
      }
      if ($selfReference) {
        continue;
      }

      $shortNameSuffix = '\\' . $shortName;
      $matched = FALSE;
      foreach ($seenFqcns as $fqcn => $unused) {
        if ($fqcn === $shortName || str_ends_with($fqcn, $shortNameSuffix)) {
          $matched = TRUE;
          break;
        }
      }
      if ($matched) {
        continue;
      }

      $expected = $candidates[0];
      $errors[] = RuleErrorBuilder::message(\sprintf(
        'Comment references class `%s` in backticks but is missing "@see \\%s".',
        $shortName,
        $expected,
      ))
        ->identifier('canvas.docComment.missingSee')
        ->line($line)
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
