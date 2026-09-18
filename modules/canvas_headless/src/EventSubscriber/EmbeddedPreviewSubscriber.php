<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\EventSubscriber;

use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder;
use Drupal\canvas\PageVariantResolver;
use Drupal\canvas_headless\CanvasContentEntityRenderer;
use Drupal\canvas_headless\FrontendUrl;
use Drupal\canvas_headless\Plugin\DisplayVariant\EmbeddedHeadlessPreviewPageVariant;
use Drupal\canvas_headless\PreviewUrlGeneratorInterface;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Selects the embedded headless preview for Canvas-owned entity routes.
 */
final class EmbeddedPreviewSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly AdminContext $adminContext,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PageVariantResolver $pageVariantResolver,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Canvas selects its page variant at -100. Run afterward so this module
    // can replace the complete Canvas-rendered page body.
    return [
      RenderEvents::SELECT_PAGE_DISPLAY_VARIANT => ['onSelectPageDisplayVariant', -110],
    ];
  }

  /**
   * Embeds authenticated previews on the main request for Canvas-owned routes.
   */
  public function onSelectPageDisplayVariant(PageDisplayVariantSelectionEvent $event): void {
    $route_match = $event->getRouteMatch();
    if ($this->adminContext->isAdminRoute($route_match->getRouteObject())) {
      return;
    }

    $request = $this->requestStack->getCurrentRequest();
    $entity = $request === NULL
      ? NULL
      : self::getCanonicalRouteEntity($route_match, $request->attributes->get('_entity'));
    if (
      !$entity instanceof ContentEntityInterface ||
      $request === NULL ||
      $request !== $this->requestStack->getMainRequest() ||
      $request->attributes->has(CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE)
    ) {
      return;
    }

    $cacheability = (new CacheableMetadata())
      ->addCacheContexts(['user.permissions', 'user.roles:authenticated'])
      ->addCacheableDependency($entity)
      ->addCacheableDependency($this->configFactory->get('canvas.settings'))
      ->addCacheableDependency($this->configFactory->get('canvas_headless.settings'))
      ->addCacheTags(
        $this->entityTypeManager
          ->getDefinition(ContentTemplate::ENTITY_TYPE_ID)
          ->getListCacheTags(),
      )
      ->addCacheTags(
        $this->entityTypeManager
          ->getDefinition(PageVariant::ENTITY_TYPE_ID)
          ->getListCacheTags(),
      );
    $event->addCacheableDependency($cacheability);

    if (
      ($entity instanceof EntityPublishedInterface && !$entity->isPublished()) ||
      !$this->currentUser->isAuthenticated() ||
      !$this->currentUser->hasPermission(PreviewUrlGeneratorInterface::PREVIEW_PERMISSION)
    ) {
      return;
    }

    $content_template = ContentTemplate::loadForEntity($entity, 'full');
    if ($content_template !== NULL) {
      $event->addCacheableDependency($content_template);
    }
    $variant = $this->pageVariantResolver->resolve($entity, $content_template);
    if ($variant !== NULL) {
      $event->addCacheableDependency($variant);
      if (!CanvasContentEntityRenderer::isHeadlessCompatiblePageVariant($variant)) {
        return;
      }
    }

    $canvas_owns_route = $entity instanceof ComponentTreeEntityInterface ||
      ($content_template !== NULL && $content_template->status() &&
        $this->entityTypeManager->hasHandler($entity->getEntityTypeId(), 'view_builder') &&
        $this->entityTypeManager->getViewBuilder($entity->getEntityTypeId()) instanceof ContentTemplateAwareViewBuilder);
    if (!$canvas_owns_route) {
      return;
    }

    $frontends = $this->configFactory
      ->get('canvas_headless.settings')
      ->get('frontends');
    $frontend = FrontendUrl::fromConfig(
      (string) (\is_array($frontends) ? ($frontends[0]['url'] ?? '') : ''),
    );
    if ($frontend === NULL) {
      return;
    }

    $query_string = $request->getQueryString();
    $frontend_path = $request->getPathInfo() . ($query_string === NULL ? '' : '?' . $query_string);
    $event->addCacheContexts(['url.path', 'url.query_args']);
    $event->setPluginId(EmbeddedHeadlessPreviewPageVariant::PLUGIN_ID);
    $event->setPluginConfiguration([
      'can_manage_frontends' => $this->currentUser->hasPermission('administer canvas headless frontends'),
      'content_api_path' => $request->getBaseUrl() . CanvasContentApiRequest::API_PATH,
      'drupal_base_path' => $request->getBaseUrl(),
      'frontend_base_path' => (string) (parse_url($frontend->baseUrl, PHP_URL_PATH) ?? ''),
      'frontend_origin' => $frontend->origin,
      'preview_url' => $frontend->baseUrl . $frontend_path,
    ]);
    $event->stopPropagation();
  }

  /**
   * Returns the entity rendered by a canonical entity route.
   */
  private static function getCanonicalRouteEntity(
    RouteMatchInterface $route_match,
    mixed $enhanced_entity,
  ): ?ContentEntityInterface {
    $route_name = $route_match->getRouteName();
    if (
      !\is_string($route_name) ||
      !preg_match('/^entity\.([a-z0-9_]+)\.canonical$/', $route_name, $matches)
    ) {
      return NULL;
    }
    $entity_type_id = $matches[1];
    if (
      $enhanced_entity instanceof ContentEntityInterface &&
      $enhanced_entity->getEntityTypeId() === $entity_type_id
    ) {
      return $enhanced_entity;
    }

    $entity = $route_match->getParameter($entity_type_id);
    if (
      $entity instanceof ContentEntityInterface &&
      $entity->getEntityTypeId() === $entity_type_id
    ) {
      return $entity;
    }

    $parameter_options = $route_match->getRouteObject()?->getOption('parameters');
    if (!\is_array($parameter_options)) {
      return NULL;
    }
    foreach ($parameter_options as $parameter_name => $options) {
      if (
        \is_array($options) &&
        ($options['type'] ?? NULL) === 'entity:' . $entity_type_id
      ) {
        $entity = $route_match->getParameter((string) $parameter_name);
        return $entity instanceof ContentEntityInterface &&
          $entity->getEntityTypeId() === $entity_type_id
          ? $entity
          : NULL;
      }
    }
    return NULL;
  }

}
