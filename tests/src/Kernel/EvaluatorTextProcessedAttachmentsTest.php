<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\canvas\PropExpressions\StructuredData\Evaluator;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\FilterProcessResult;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves that #attached assets from FilterProcessResult reach EvaluationResult.
 *
 * Regression test: before the fix in "Collect #attached assets", when
 * evaluating a FieldPropExpression targeting a text_long:processed field, any
 * CSS/JS libraries attached by text filters (via FilterProcessResult) were
 * silently dropped. The Evaluator used CacheableMetadata (which cannot carry
 * #attached) and EvaluationResult had no mechanism to store or merge
 * attachments. As a result, filter libraries such as filter/caption,
 * filter/align, and media view libraries never reached the page response.
 */
#[CoversClass(Evaluator::class)]
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
class EvaluatorTextProcessedAttachmentsTest extends CanvasKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'field'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_body',
      'entity_type' => 'node',
      'type' => 'text_long',
      'cardinality' => 1,
      'settings' => [],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_body',
      'field_type' => 'text_long',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Body',
      'required' => FALSE,
      'settings' => [],
    ])->save();
  }

  /**
   * Tests that filter libraries bubble up through Evaluator into EvaluationResult.
   *
   * This test proves the bug: without the fix, $result->getAttachments() would
   * return [] because the Evaluator discarded all #attached data. With the fix,
   * the libraries declared by text filters are preserved end-to-end.
   */
  public function testFilterLibrariesFromTextProcessedBubbleUpThroughEvaluator(): void {
    // 'bypass node access' lets Evaluator's access check pass for an unsaved
    // node without querying the node_grants table. This avoids a PHP 8.4
    // database-transaction-destructor issue triggered by $node->save().
    $this->setUpCurrentUser(permissions: ['bypass node access']);

    $node = Node::create([
      'type' => 'article',
      'title' => 'Test article',
      'status' => 1,
      'field_body' => [
        'value' => '<p>Body text with embedded media</p>',
        'format' => NULL,
      ],
    ]);

    // Simulate a FilterProcessResult as produced by a real text filter pipeline
    // (e.g. filter_caption adds filter/caption, filter_align adds filter/align).
    // We inject it directly into the TextProcessed typed data property to
    // isolate Canvas's attachment-propagation logic from the filter pipeline.
    $filter_result = (new FilterProcessResult('<p>Body text with embedded media</p>'))
      ->setAttachments(['library' => ['filter/caption', 'filter/align']]);

    // TextProcessed::setValue() sets $this->processed, so subsequent calls to
    // getCacheTags(), getCacheContexts(), and getAttachments() return early
    // without triggering renderInIsolation(). The Evaluator sees the same
    // property object (field items cache typed data properties by name).
    $node->get('field_body')->get(0)->get('processed')->setValue($filter_result);

    $expr = new FieldPropExpression(
      entityType: BetterEntityDataDefinition::create('node', 'article'),
      fieldName: 'field_body',
      delta: NULL,
      propName: 'processed',
    );

    $result = Evaluator::evaluate($node, $expr, FALSE);

    self::assertInstanceOf(EvaluationResult::class, $result);
    self::assertSame(
      ['library' => ['filter/caption', 'filter/align']],
      $result->getAttachments(),
      'Filter libraries from FilterProcessResult must survive all EvaluationResult wrapping layers in Evaluator::evaluate().',
    );
  }

  /**
   * Tests that a field with no filter attachments yields empty getAttachments().
   */
  public function testFieldWithoutFilterAttachmentsYieldsEmptyAttachments(): void {
    $this->setUpCurrentUser(permissions: ['bypass node access']);

    $node = Node::create([
      'type' => 'article',
      'title' => 'Test article',
      'status' => 1,
      'field_body' => [
        'value' => '<p>Plain text, no filter assets</p>',
        'format' => NULL,
      ],
    ]);

    // A FilterProcessResult with no attachments (default after processing
    // a field through filters that add no library declarations).
    $filter_result = new FilterProcessResult('<p>Plain text, no filter assets</p>');
    $node->get('field_body')->get(0)->get('processed')->setValue($filter_result);

    $expr = new FieldPropExpression(
      entityType: BetterEntityDataDefinition::create('node', 'article'),
      fieldName: 'field_body',
      delta: NULL,
      propName: 'processed',
    );

    $result = Evaluator::evaluate($node, $expr, FALSE);

    self::assertInstanceOf(EvaluationResult::class, $result);
    self::assertSame([], $result->getAttachments());
  }

}