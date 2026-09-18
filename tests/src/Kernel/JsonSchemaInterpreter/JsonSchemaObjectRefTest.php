<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\JsonSchemaInterpreter;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests identifying Canvas-provided object shapes from prop schemas.
 */
#[CoversClass(JsonSchemaObjectRef::class)]
#[Group('canvas')]
final class JsonSchemaObjectRefTest extends CanvasKernelTestBase {

  public function testTryFromSchema(): void {
    foreach (JsonSchemaObjectRef::cases() as $case) {
      $resolved = $case->asPropShape()->resolvedSchema;
      $this->assertSame($case, JsonSchemaObjectRef::tryFromSchema($case->asPropShapeArray()), "$case->name: raw \$ref");
      $this->assertSame($case, JsonSchemaObjectRef::tryFromSchema($resolved), "$case->name: resolved");
      $this->assertSame($case, JsonSchemaObjectRef::tryFromSchema([
        'title' => 'Some title',
        'description' => 'Some description.',
        ...$case->asPropShapeArray(),
        'examples' => [],
      ]), "$case->name: raw \$ref with sibling keywords");
      $this->assertSame($case, JsonSchemaObjectRef::tryFromSchema(\array_reverse($resolved, TRUE)), "$case->name: resolved with reordered keys");
    }

    $document = JsonSchemaObjectRef::Document->asPropShape()->resolvedSchema;
    $this->assertNull(JsonSchemaObjectRef::tryFromSchema([
      ...$document,
      'properties' => [
        ...$document['properties'],
        'pages' => ['type' => 'integer'],
      ],
    ]), 'resolved document with an extra object property');
    $this->assertNull(JsonSchemaObjectRef::tryFromSchema(['type' => 'object', 'properties' => ['foo' => ['type' => 'string']]]), 'plain object');
    $this->assertNull(JsonSchemaObjectRef::tryFromSchema(['type' => 'string']), 'string');
  }

}
