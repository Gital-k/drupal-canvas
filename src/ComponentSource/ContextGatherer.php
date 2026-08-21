<?php

declare(strict_types=1);

namespace Drupal\canvas\ComponentSource;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Plugin\Context\ContextDefinitionInterface;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Drupal\Core\Plugin\Context\ContextInterface;
use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Gathers the contexts available for editing or rendering a component tree.
 *
 * This is the single source of truth for two questions that the instance form,
 * the render path, and the component library all must answer the same way:
 * - Which populated contexts are available for this editing or rendering
 *   situation?
 * - Can a block's required contexts be satisfied by that set?
 *
 * The available contexts are the populated global contexts (for example the
 * current user), plus an entity context for the host entity whose component
 * tree is being edited or rendered. The host entity is always passed in
 * explicitly; nothing here is derived from the route, because Canvas editing
 * happens on API routes that are not the live page, so a route-derived context
 * would silently differ between the editor and live rendering.
 *
 * Core solves the same gathering problem with a trait
 * (\Drupal\layout_builder\Context\LayoutBuilderContextTrait), which works there
 * because only Layout Builder classes need it. Canvas needs the exact same
 * answer in three unrelated places — the component source (rendering), the
 * instance form, and the component-library controller (filtering) — and a
 * controller cannot inherit a trait that assumes a `contextRepository`
 * property. A single autowired service is shared by all three without
 * duplicating the wiring, and keeps the satisfiability check next to the
 * gathering it depends on. There is no core service that already does this;
 * this one composes core's `context.repository` and `context.handler`.
 *
 * @see \Drupal\layout_builder\Context\LayoutBuilderContextTrait::getPopulatedContexts()
 *
 * @internal
 */
final class ContextGatherer {

  use StringTranslationTrait;

  /**
   * Context id under which the host entity is exposed.
   *
   * The form, render, and library-filtering layers all key the host entity
   * context by this id, so a stored context mapping resolves the same way
   * everywhere.
   */
  public const string HOST_ENTITY_CONTEXT_ID = 'canvas.host_entity';

  public function __construct(
    private readonly ContextRepositoryInterface $contextRepository,
    private readonly ContextHandlerInterface $contextHandler,
  ) {}

  /**
   * Returns the populated contexts available for a host entity, if any.
   *
   * @param \Drupal\Core\Entity\EntityInterface|null $host_entity
   *   The host entity whose component tree is being edited or rendered, or NULL
   *   when there is no host entity (for example a site-wide PageRegion tree).
   *
   * @return array<string, \Drupal\Core\Plugin\Context\ContextInterface>
   *   The available contexts, keyed by context id. Only contexts that actually
   *   carry a value are included.
   */
  public function getPopulatedContexts(?EntityInterface $host_entity = NULL): array {
    // Start from every globally available context, then keep only the ones that
    // carry a value — an available context id is not a promise of a value.
    $available_context_ids = \array_keys($this->contextRepository->getAvailableContexts());
    $contexts = $this->contextRepository->getRuntimeContexts($available_context_ids);
    $contexts = \array_filter($contexts, fn (ContextInterface $context): bool => $context->hasContextValue());

    // Add the host entity as a context so blocks that need the entity being
    // edited (for example a field block) can be satisfied.
    if ($host_entity) {
      $label = $this->t('@entity-type being edited', ['@entity-type' => $host_entity->getEntityType()->getSingularLabel()]);
      $contexts[self::HOST_ENTITY_CONTEXT_ID] = EntityContext::fromEntity($host_entity, (string) $label);
    }

    return $contexts;
  }

  /**
   * Checks whether a plugin's required contexts are all satisfiable.
   *
   * Optional context definitions are ignored: a block that only declares
   * optional contexts is always placeable, it just renders without them.
   *
   * @param array<string, \Drupal\Core\Plugin\Context\ContextDefinitionInterface> $context_definitions
   *   The context definitions of a block plugin, keyed by context slot name.
   * @param array<string, \Drupal\Core\Plugin\Context\ContextInterface> $contexts
   *   The available contexts to satisfy the required definitions against, as
   *   returned by ::getPopulatedContexts().
   *
   * @return bool
   *   TRUE when every required context definition has at least one matching
   *   context in the given set, FALSE otherwise.
   */
  public function hasRequiredContextsSatisfied(array $context_definitions, array $contexts): bool {
    $required_definitions = \array_filter($context_definitions, fn (ContextDefinitionInterface $definition): bool => $definition->isRequired());
    return \array_all($required_definitions, fn (ContextDefinitionInterface $definition): bool => $this->contextHandler->getMatchingContexts($contexts, $definition) !== []);
  }

}
