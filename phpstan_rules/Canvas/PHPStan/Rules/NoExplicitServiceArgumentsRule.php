<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Symfony\Component\Yaml\Yaml;

/**
 * Disallows passing explicit DIC `arguments` in `.services.yml` files.
 *
 * When `_defaults.autowire: true` is set, services should rely on autowiring
 * for their constructor dependencies. Explicit `arguments` keys are only
 * allowed in specific cases:
 * - The service has `decorates` (decorators typically need `@.inner`).
 * - The service has `parent` (parent-based services may need overriding args).
 * - The service has `factory` (factory-based services may need factory args).
 *
 * This rule fires once per analyzed class that appears as an autowired service
 * with explicit `arguments` in a `.services.yml` file within the project.
 *
 * @implements Rule<InClassNode>
 */
final class NoExplicitServiceArgumentsRule implements Rule {

  /**
   * Parsed violations keyed by FQCN.
   *
   * NULL means not yet initialized.
   *
   * @var array<string, array{file: string, service_id: string}>|null
   */
  private static ?array $violations = NULL;

  /**
   * The root directory to scan for .services.yml files.
   */
  private readonly string $servicesYmlRootDir;

  /**
   * @param string $servicesYmlRootDir
   *   The root directory to scan for .services.yml files.
   */
  public function __construct(
    string $servicesYmlRootDir,
  ) {
    $this->servicesYmlRootDir = $servicesYmlRootDir;
  }

  public function getNodeType(): string {
    return InClassNode::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    $violations = $this->getViolations();

    $classReflection = $node->getClassReflection();
    $className = $classReflection->getName();

    if (!isset($violations[$className])) {
      return [];
    }

    $violation = $violations[$className];

    return [
      RuleErrorBuilder::message(
        \sprintf(
          'Service "%s" in %s has explicit "arguments" but autowiring is enabled. Remove the "arguments" key and let autowiring resolve dependencies. If a parameter cannot be autowired, use a #[AutowireService] or #[Autowire] attribute on the constructor parameter.',
          $violation['service_id'],
          $violation['file'],
        )
      )
        ->identifier('canvas.noExplicitServiceArguments')
        ->build(),
    ];
  }

  /**
   * Parses all `.services.yml` files and collects violations.
   *
   * @return array<string, array{file: string, service_id: string}>
   */
  private function getViolations(): array {
    if (self::$violations !== NULL) {
      return self::$violations;
    }

    self::$violations = [];

    $ymlFiles = $this->findServicesYmlFiles($this->servicesYmlRootDir);

    foreach ($ymlFiles as $ymlFile) {
      $this->analyzeServicesYml($ymlFile);
    }

    return self::$violations;
  }

  /**
   * Finds all `.services.yml` files under the given directory.
   *
   * @return list<string>
   */
  private function findServicesYmlFiles(string $directory): array {
    $files = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
      \assert($file instanceof \SplFileInfo);
      if (\str_ends_with($file->getFilename(), '.services.yml')) {
        $files[] = $file->getPathname();
      }
    }

    return $files;
  }

  /**
   * Analyzes a single `.services.yml` file for violations.
   */
  private function analyzeServicesYml(string $filePath): void {
    $content = \file_get_contents($filePath);
    if ($content === FALSE) {
      return;
    }

    $parsed = Yaml::parse($content, Yaml::PARSE_CUSTOM_TAGS);
    if (!\is_array($parsed) || !isset($parsed['services'])) {
      return;
    }

    $services = $parsed['services'];
    $defaults = $services['_defaults'] ?? [];

    // Only check files that have autowire enabled in _defaults.
    $autowireEnabled = !empty($defaults['autowire']);
    if (!$autowireEnabled) {
      return;
    }

    $relativePath = \str_starts_with($filePath, $this->servicesYmlRootDir)
      ? \ltrim(\substr($filePath, \strlen($this->servicesYmlRootDir)), '/')
      : $filePath;

    foreach ($services as $serviceId => $definition) {
      if ($serviceId === '_defaults') {
        continue;
      }

      if (!\is_array($definition)) {
        continue;
      }

      // Skip if the service explicitly sets autowire: false.
      if (isset($definition['autowire']) && $definition['autowire'] === FALSE) {
        continue;
      }

      // Skip services that legitimately need explicit arguments.
      if (isset($definition['decorates'])) {
        continue;
      }
      if (isset($definition['factory'])) {
        continue;
      }

      if (!isset($definition['arguments'])) {
        continue;
      }

      // Determine the class: it's either the explicit `class` key or the
      // service ID itself (when using FQCN-based service IDs).
      $class = $definition['class'] ?? $serviceId;

      // Normalize: strip leading backslash.
      $class = \ltrim($class, '\\');

      self::$violations[$class] = [
        'file' => $relativePath,
        'service_id' => $serviceId,
      ];
    }
  }

}

