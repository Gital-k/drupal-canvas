<?php

declare(strict_types=1);

namespace Drupal\canvas\Form;

use Drupal\Core\Action\ActionManager;
use Drupal\Core\Config\Entity\DraggableListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\canvas\Audit\ComponentAudit;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @todo Figure out if UX of "create page builder component to see what components are available" is good enough. We might want to add "tree view" of all the components, with ability to quickly "add page builder component" definition for any of them.
 */
final class ComponentListBuilder extends DraggableListBuilder {

  /**
   * {@inheritdoc}
   *
   * Restore the default limit from EntityListBuilder (50) so the pager works
   * when no filters are active. DraggableListBuilder overrides this to FALSE
   * for draggable tables; we restore it since we are not a draggable list.
   * When filters ARE active we set limit=FALSE in load() before calling
   * parent::load() so the full set is loaded for PHP-level filtering.
   */
  protected $limit = 50;

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    private readonly ComponentAudit $audit,
    private readonly ComponentSourceManager $componentSourceManager,
    private readonly ActionManager $actionManager,
    FormBuilderInterface $formBuilder,
  ) {
    parent::__construct($entity_type, $storage);
    // DraggableListBuilder exposes $formBuilder as a protected property and
    // populates it in its constructor via \Drupal::formBuilder(). Overwrite it
    // with the properly injected instance.
    $this->formBuilder = $formBuilder;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get(EntityTypeManagerInterface::class)->getStorage($entity_type->id()),
      $container->get(ComponentAudit::class),
      $container->get(ComponentSourceManager::class),
      $container->get('plugin.manager.action'),
      $container->get(FormBuilderInterface::class),
    );
  }

  // ---------------------------------------------------------------------------
  // FormInterface — DraggableListBuilder::render() calls formBuilder->getForm($this),
  // so buildForm/validateForm/submitForm here are the bulk-action form.
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'component_list_bulk_form';
  }

  /**
   * {@inheritdoc}
   *
   * Builds the bulk-action form. DraggableListBuilder already provides the
   * FormInterface scaffolding and the render() → getForm($this) wiring, so
   * we only need to define the form contents here.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Load the filtered entity list and store it on $this->entities so that
    // parent methods (like submitForm) can reference it if needed.
    $this->entities = $this->load();

    $form['#attached']['library'][] = 'views/views.bulk_form';

    // Build the tableselect options from the filtered entity list.
    $options = [];
    foreach ($this->entities as $entity) {
      \assert($entity instanceof Component);
      $row = $this->buildRow($entity);
      $options[$entity->id()] = \array_map(
        static fn ($cell): array => \is_array($cell) ? $cell : ['data' => $cell],
        $row,
      );
    }

    $form['components'] = [
      '#type'       => 'tableselect',
      '#header'     => $this->buildHeader(),
      '#options'    => $options,
      '#empty'      => $this->t('No components found.'),
      '#js_select'  => TRUE,
      '#attributes' => ['data-drupal-views-bulk-actions' => TRUE],
    ];

    // Action toolbar — a select listing the two Canvas-specific action plugins
    // plus a submit button, matching the Views bulk action toolbar pattern.
    $form['action_toolbar'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['views-bulk-actions', 'form-inline']],
      'action' => [
        '#type'       => 'select',
        '#title'      => $this->t('Action'),
        '#options'    => $this->getBulkActionOptions(),
        '#attributes' => ['class' => ['form-select', 'form-element', 'form-element--type-select']],
      ],
      'apply' => [
        '#type'        => 'submit',
        '#value'       => $this->t('Apply to selected'),
        '#button_type' => 'primary',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (empty(\array_filter($form_state->getValue('components', [])))) {
      $form_state->setError(
        $form['action_toolbar']['apply'],
        $this->t('Please select at least one component.'),
      );
    }
  }

  /**
   * {@inheritdoc}
   *
   * Instantiates the chosen Action plugin and calls executeMultiple() — the
   * canonical Drupal core pattern for bulk entity actions.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $selected  = \array_keys(\array_filter($form_state->getValue('components', [])));
    $action_id = $form_state->getValue('action');

    $entities = $this->storage->loadMultiple($selected);

    /** @var \Drupal\Core\Action\ActionInterface $action */
    $action = $this->actionManager->createInstance($action_id);
    $action->executeMultiple($entities);

    $count = count($entities);
    $label = $action->getPluginDefinition()['label'];
    $this->messenger()->addStatus(
      $this->formatPlural(
        $count,
        '@action applied to 1 component.',
        '@action applied to @count components.',
        ['@action' => $label],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Filter form — a separate FormBase rendered above the bulk table via
  // render(). Kept as a GET form so filter state lives in the URL, consistent
  // with Views exposed filters.
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   *
   * Overrides DraggableListBuilder::render() which only calls getForm($this)
   * when $weightKey is set. Since Component has no weight key we must always
   * call getForm($this) ourselves to get the bulk-action tableselect rendered,
   * then prepend the separate GET filter form above it.
   */
  public function render(): array {
    $build['filters'] = $this->formBuilder->getForm(ComponentFilterForm::class);
    $build['filters']['#weight'] = -10;

    // Always render as a form so buildForm(), validateForm(), and submitForm()
    // are wired up — regardless of whether a weightKey is present.
    $build['bulk_form'] = $this->formBuilder->getForm($this);
    $build['bulk_form']['#weight'] = 0;

    // Add the pager when no filters are active. When filters are active load()
    // disables the DB pager and returns the full filtered set, so there is
    // nothing to page through and the pager element would show page 0 of 0.
    $request   = \Drupal::request();
    $id_filter = \trim($request->query->get('id', ''));
    $sources   = \array_filter((array) ($request->query->all()['source'] ?? []));
    $status    = $request->query->get('status', 'all');
    $filtered  = $id_filter !== '' || !empty($sources) || $status !== 'all';

    if (!$filtered && $this->limit) {
      $build['pager'] = [
        '#type'   => 'pager',
        '#weight' => 10,
      ];
    }

    $build['#cache']['contexts'][] = 'url.query_args';

    return $build;
  }

  // ---------------------------------------------------------------------------
  // Filtering — applied in load() so both the tableselect and the row count
  // reflect the current filter state.
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   *
   * Loads all entities then applies the active filter values from the URL
   * query string. Runs after parent::load() which already handles the
   * config entity query and sorting.
   */
  public function load(): array {
    $request   = \Drupal::request();
    $id_filter = \trim($request->query->get('id', ''));
    $sources   = \array_filter((array) ($request->query->all()['source'] ?? []));
    $status    = $request->query->get('status', 'all');

    $filtered = $id_filter !== '' || !empty($sources) || $status !== 'all';

    // Config entity storage does not support SQL pagers, so parent::load() with
    // $limit set has no effect — it loads everything regardless. We must page
    // manually using PagerManager after loading the full set.
    //
    // Temporarily set limit=FALSE so parent::load() doesn't attempt a no-op
    // pager call, then apply our own paging below.
    $saved_limit = $this->limit;
    $this->limit = FALSE;
    $entities    = parent::load();
    $this->limit = $saved_limit;

    if ($id_filter !== '') {
      $needle   = \strtolower($id_filter);
      $entities = \array_filter(
        $entities,
        static fn (Component $c): bool => \str_contains(\strtolower($c->id()), $needle),
      );
    }

    if (!empty($sources)) {
      $entities = \array_filter(
        $entities,
        static fn (Component $c): bool => \in_array($c->get('source'), $sources, TRUE),
      );
    }

    if ($status !== 'all') {
      $enabled  = $status === 'enabled';
      $entities = \array_filter(
        $entities,
        static fn (Component $c): bool => $c->status() === $enabled,
      );
    }

    // Apply manual paging when no filters are active. Filtered results always
    // show everything (no point paging 3 results).
    if (!$filtered && $this->limit) {
      $total  = count($entities);
      $pager  = \Drupal::service('pager.manager')->createPager($total, $this->limit);
      $page   = $pager->getCurrentPage();
      $entities = \array_slice($entities, $page * $this->limit, $this->limit, TRUE);
    }

    return $entities;
  }

  // ---------------------------------------------------------------------------
  // Standard list builder — header and row definitions unchanged from original.
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['label'] = $this->t('Label');
    $header['component_source'] = $this->t('Component type');
    $header['component_label'] = $this->t('Component name');
    $header['component_version'] = $this->t('Versions');
    $header['component_usage'] = $this->t('Usage<br>(all&nbsp;versions)');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    \assert($entity instanceof Component);

    $row['id'] = $entity->id();
    $row['label'] = $entity->label();
    $source = $entity->getComponentSource();
    $row['component_source'] = $source->getPluginDefinition()['label'];
    $row['component_label'] = $source->getComponentDescription();
    $row['component_version']['data'] = [
      '#prefix' => \sprintf('<code title="%s">', $entity->getActiveVersion()),
      '#plain_text' => count($entity->getVersions()),
      '#suffix' => '</code>',
    ];
    $usage = $this->audit->getConfigEntityUsageCount($entity) +
      // @todo Stop triggering a DB query per row, instead perform a single DB query in ::load() in https://www.drupal.org/i/3522953
      count($this->audit->getContentRevisionsUsingComponent($entity));
    $row['component_usage'] = $usage === 0 ? '' : $usage;

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getOperations(EntityInterface $entity): array {
    return parent::getOperations($entity) + [
      'audit' => [
        'title' => $this->t('Audit'),
        'url' => Url::fromRoute('entity.component.audit', ['component' => $entity->id()]),
      ],
    ];
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Returns only the two Canvas-specific bulk action options.
   *
   * @return array<string, string>
   */
  private function getBulkActionOptions(): array {
    $allowed = ['canvas_component_enable', 'canvas_component_disable'];
    $options = [];
    foreach ($allowed as $id) {
      $definition = $this->actionManager->getDefinition($id, FALSE);
      if ($definition) {
        $options[$id] = (string) $definition['label'];
      }
    }
    return $options;
  }

}
