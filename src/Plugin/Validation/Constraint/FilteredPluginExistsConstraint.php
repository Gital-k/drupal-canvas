<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\FilteredPluginManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;
use Symfony\Component\Validator\Exception\InvalidOptionsException;
use Symfony\Component\Validator\Exception\MissingOptionsException;

/**
 * Checks a plugin is available to a particular plugin filter consumer.
 *
 * Drupal core's `PluginExists` constraint only checks that a plugin ID is known
 * to a plugin manager. Plugin managers implementing
 * \Drupal\Core\Plugin\FilteredPluginManagerInterface additionally allow modules
 * and themes to narrow the available plugins per *consumer*, through
 * hook_plugin_filter_TYPE__CONSUMER_alter(). User interfaces built on top of
 * such a manager only offer the narrowed set, so config naming a plugin outside
 * that set is invalid even though the plugin itself exists.
 *
 * This constraint validates against the narrowed set instead: it accepts only
 * plugin IDs that `::getFilteredDefinitions($consumer)` returns.
 *
 * Deliberately *not* extending core's `PluginExists` constraint, even though
 * this is otherwise the same check plus a `consumer` option: that constraint's
 * constructor signature changed in Drupal 11.4 (from a single `$options` array
 * to individual named parameters), and Canvas supports `^11.3`. So a subclass
 * cannot call `parent::__construct()` portably across the supported range.
 *
 * Deliberately *not* supported: core's `allowFallback` option. A fallback
 * plugin ID is by definition returned for a plugin ID the manager does not
 * know, and an unknown plugin ID is never in the filtered definitions, so the
 * option could not affect the outcome.
 *
 * Deliberately *not* validated: whether the plugin is usable in the contexts it
 * declares. Plugin filtering can take contexts into account, but contexts are
 * only known at run time, so they cannot be part of config validation.
 *
 * @see \Drupal\canvas\Plugin\Validation\Constraint\SequenceKeysAreFilteredPluginIdsConstraint
 * @see \Drupal\Core\Plugin\FilteredPluginManagerInterface::getFilteredDefinitions()
 * @see \Drupal\Core\Plugin\Plugin\Validation\Constraint\PluginExistsConstraint
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Filtered plugin exists', [], ['context' => 'Validation']),
  type: ['string'],
)]
class FilteredPluginExistsConstraint extends SymfonyConstraint implements ContainerFactoryPluginInterface {

  public const string PLUGIN_ID = 'FilteredPluginExists';

  /**
   * Constructs a FilteredPluginExistsConstraint object.
   *
   * Final because ::create() uses `new static()`: subclasses exist to give this
   * same check a different constraint plugin ID, never a different signature.
   *
   * @param \Drupal\Core\Plugin\FilteredPluginManagerInterface $pluginManager
   *   The plugin manager whose filtered definitions to validate against.
   * @param string $manager
   *   The ID of the plugin manager service.
   * @param string $consumer
   *   The plugin filter consumer to check availability for.
   * @param string|null $interface
   *   Optional name of the interface that the plugin must implement.
   * @param string $unknownPluginMessage
   *   The error message if a plugin is not available to the consumer.
   * @param string $invalidInterfaceMessage
   *   The error message if a plugin does not implement the expected interface.
   * @param array|null $groups
   *   The groups that the constraint belongs to.
   * @param mixed|null $payload
   *   Domain-specific data attached to a constraint.
   */
  final public function __construct(
    public readonly FilteredPluginManagerInterface $pluginManager,
    protected string $manager,
    public readonly string $consumer,
    public ?string $interface = NULL,
    public string $unknownPluginMessage = "The '@plugin_id' plugin does not exist or is not available to the '@consumer' plugin filter consumer.",
    public string $invalidInterfaceMessage = "The '@plugin_id' plugin must implement or extend @interface.",
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(NULL, $groups, $payload);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    // Note: ::getRequiredOptions() is an instance method on Symfony's
    // Constraint, so the required options are repeated here.
    foreach (['manager', 'consumer'] as $required_option) {
      if (!isset($configuration[$required_option])) {
        throw new MissingOptionsException(\sprintf('The option "%s" must be set for constraint "%s".', $required_option, static::class), [$required_option]);
      }
    }
    $plugin_manager = $container->get($configuration['manager']);
    if (!$plugin_manager instanceof FilteredPluginManagerInterface) {
      throw new InvalidOptionsException(\sprintf('The "%s" plugin manager must implement %s to be used with the "%s" constraint.', $configuration['manager'], FilteredPluginManagerInterface::class, static::PLUGIN_ID), ['manager']);
    }
    return new static($plugin_manager, ...$configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOption(): ?string {
    return 'manager';
  }

  /**
   * {@inheritdoc}
   */
  public function getRequiredOptions(): array {
    return ['manager', 'consumer'];
  }

}
