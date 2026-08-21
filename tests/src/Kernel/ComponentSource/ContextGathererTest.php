<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ComponentSource;

use Drupal\canvas\ComponentSource\ContextGatherer;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Canvas context gathering service.
 */
#[CoversClass(ContextGatherer::class)]
#[Group('canvas')]
final class ContextGathererTest extends CanvasKernelTestBase {

  /**
   * The context id of the current-language global context.
   */
  private const string CURRENT_LANGUAGE_CONTEXT_ID = '@language.current_language_context:language_interface';

  /**
   * The context id of the current-user global context.
   */
  private const string CURRENT_USER_CONTEXT_ID = '@user.current_user_context:current_user';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'node',
  ];

  private ContextGatherer $contextGatherer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The current-user global context loads the current user entity even when
    // reporting availability, so its storage must exist.
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->contextGatherer = $this->container->get(ContextGatherer::class);
  }

  /**
   * Tests that unpopulated global contexts are filtered out, populated kept.
   */
  public function testUnpopulatedContextsAreFiltered(): void {
    $available = $this->container->get(ContextRepositoryInterface::class)->getAvailableContexts();
    $contexts = $this->contextGatherer->getPopulatedContexts();

    // The current language is a populated global context, so it is returned.
    $this->assertArrayHasKey(self::CURRENT_LANGUAGE_CONTEXT_ID, $contexts);
    // Every returned context carries a value.
    foreach ($contexts as $context) {
      $this->assertTrue($context->hasContextValue());
    }

    // The current-user context is available, but with no current user loaded it
    // carries no value, so it is filtered out instead of returned.
    $this->assertArrayHasKey(self::CURRENT_USER_CONTEXT_ID, $available);
    $this->assertArrayNotHasKey(self::CURRENT_USER_CONTEXT_ID, $contexts);

    // Without a host entity, the host entity context is absent.
    $this->assertArrayNotHasKey(ContextGatherer::HOST_ENTITY_CONTEXT_ID, $contexts);
  }

  /**
   * Tests that the host entity is exposed as an entity context when given.
   */
  public function testHostEntityContext(): void {
    $node = Node::create(['type' => 'article', 'title' => 'Test']);
    $node->save();

    $contexts = $this->contextGatherer->getPopulatedContexts($node);

    $this->assertArrayHasKey(ContextGatherer::HOST_ENTITY_CONTEXT_ID, $contexts);
    $host_context = $contexts[ContextGatherer::HOST_ENTITY_CONTEXT_ID];
    $this->assertInstanceOf(EntityContext::class, $host_context);
    $this->assertSame($node, $host_context->getContextValue());
    // The populated global contexts are still present alongside the host.
    $this->assertArrayHasKey(self::CURRENT_LANGUAGE_CONTEXT_ID, $contexts);
  }

  /**
   * Tests satisfiability of required context definitions against a context set.
   */
  public function testRequiredContextSatisfiability(): void {
    $node = Node::create(['type' => 'article', 'title' => 'Test']);
    $node->save();

    $with_host = $this->contextGatherer->getPopulatedContexts($node);
    $globals_only = $this->contextGatherer->getPopulatedContexts();

    $required_node = ['entity' => EntityContextDefinition::fromEntityTypeId('node')];
    // A required node context is satisfied only when a node host is present.
    $this->assertTrue($this->contextGatherer->hasRequiredContextsSatisfied($required_node, $with_host));
    $this->assertFalse($this->contextGatherer->hasRequiredContextsSatisfied($required_node, $globals_only));

    // An optional context definition never blocks placement, even unmatched.
    $optional_node = ['entity' => EntityContextDefinition::fromEntityTypeId('node')->setRequired(FALSE)];
    $this->assertTrue($this->contextGatherer->hasRequiredContextsSatisfied($optional_node, $globals_only));

    // A block with no context definitions is always satisfiable.
    $this->assertTrue($this->contextGatherer->hasRequiredContextsSatisfied([], $globals_only));
  }

  /**
   * Tests satisfiability using a real context-aware block plugin's definitions.
   */
  public function testSatisfiabilityWithBlockPluginDefinitions(): void {
    $this->enableModules(['canvas_test_block']);
    $node = Node::create(['type' => 'article', 'title' => 'Test']);
    $node->save();

    $block_manager = $this->container->get(BlockManagerInterface::class);
    $block = $block_manager->createInstance('canvas_test_block_requires_contexts');
    \assert($block instanceof ContextAwarePluginInterface);
    $definitions = $block->getContextDefinitions();

    // The fixture block requires a bare "name" context, which any context can
    // satisfy — so a populated set satisfies it, an empty set does not.
    $this->assertTrue($this->contextGatherer->hasRequiredContextsSatisfied($definitions, $this->contextGatherer->getPopulatedContexts($node)));
    $this->assertFalse($this->contextGatherer->hasRequiredContextsSatisfied($definitions, []));
  }

}
