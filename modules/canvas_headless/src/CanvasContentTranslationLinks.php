<?php

declare(strict_types=1);

namespace Drupal\canvas_headless;

use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\language\LanguageNegotiatorInterface;
use Drupal\language\LanguageSwitcherInterface;
use Drupal\language\Plugin\LanguageNegotiation\LanguageNegotiationSession;
use Drupal\language\Plugin\LanguageNegotiation\LanguageNegotiationUI;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds accessible translation request URIs for routed content.
 */
final class CanvasContentTranslationLinks {

  public function __construct(
    private readonly LanguageManagerInterface $languageManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly AccountProxyInterface $currentUser,
    private readonly ?LanguageNegotiatorInterface $negotiator = NULL,
  ) {}

  /**
   * Returns language context and adds all translation dependencies.
   *
   * @return array{negotiatedLanguage: string, translations: list<array{langcode: string, current: bool, url: string, external: bool}>}
   *   Language context for the route.
   */
  public function build(?ContentEntityInterface $entity, Request $request, CacheableMetadata $cacheability): array {
    $result = [
      'negotiatedLanguage' => $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId(),
      'translations' => [],
    ];
    $cacheability->addCacheContexts([
      'languages:language_content',
      'languages:language_interface',
      'languages:language_url',
      'url',
      'oauth2_scopes',
    ])
      ->addCacheTags(['config:configurable_language_list'])
      ->addCacheableDependency($this->configFactory->get('language.types'))
      ->addCacheableDependency($this->configFactory->get('language.negotiation'));
    if (!$this->languageManager->isMultilingual() || $entity === NULL || !$entity->hasLinkTemplate('canonical')) {
      return $result;
    }

    $cacheability->addCacheableDependency($entity);
    $canonical = $entity->toUrl('canonical');
    [$links] = $this->switchLinks(LanguageInterface::TYPE_CONTENT, $canonical, $request);
    $session_enabled = $this->negotiator !== NULL && (
      $this->negotiator->isNegotiationMethodEnabled(LanguageNegotiationSession::METHOD_ID, LanguageInterface::TYPE_CONTENT) ||
      ($this->negotiator->isNegotiationMethodEnabled(LanguageNegotiationUI::METHOD_ID, LanguageInterface::TYPE_CONTENT) &&
        $this->negotiator->isNegotiationMethodEnabled(LanguageNegotiationSession::METHOD_ID, LanguageInterface::TYPE_INTERFACE))
    );
    foreach ($entity->getTranslationLanguages() as $langcode => $language) {
      $translation = $entity->getTranslation($langcode);
      $access = $translation->access('view', $this->currentUser->getAccount(), TRUE);
      $cacheability->addCacheableDependency($translation)->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        continue;
      }
      $link = $links[$langcode] ?? [];
      $target = $link['url'] ?? $canonical;
      if (!$target instanceof Url) {
        continue;
      }
      $url = clone $target;
      $url->setOption('language', $link['language'] ?? $language);
      $query = $link['query'] ?? [];
      // Preview selectors are API implementation details, never public links.
      unset($query[CanvasContentApiRequest::API_QUERY_PARAMETERS_KEY]);
      if ($session_enabled) {
        // An empty URL prefix lets negotiation fall through to the session
        // method even when URL negotiation has priority. Never carry another
        // translation's selector or rely on session state for the current one.
        $parameter = $this->configFactory->get('language.negotiation')->get('session.parameter');
        $query[$parameter] = $langcode;
      }
      $url->setOption('query', $query)->setAbsolute();
      $generated = $url->toString(TRUE);
      $cacheability->addCacheableDependency($generated);
      $uri = CanvasContentUrl::normalize($generated->getGeneratedUrl(), $request);
      $result['translations'][] = [
        'langcode' => $langcode,
        'current' => $langcode === $entity->language()->getId(),
        'url' => $uri,
        'external' => UrlHelper::isExternal($uri),
      ];
    }
    return $result;
  }

  /**
   * Gets switch options in content negotiation order, following UI delegation.
   *
   * @return array{array, ?string}
   *   Switch links and their negotiation method.
   */
  private function switchLinks(string $type, Url $url, Request $request): array {
    if ($this->negotiator === NULL) {
      return [[], NULL];
    }
    $definitions = $this->negotiator->getNegotiationMethods($type);
    // Match LanguageNegotiator::initializeType(): configuration order, not
    // the plugin discovery order retained by getNegotiationMethods().
    $enabled = $this->configFactory->get('language.types')->get('negotiation.' . $type . '.enabled') ?? [];
    foreach (\array_keys($enabled) as $id) {
      if (!\is_string($id) || !isset($definitions[$id])) {
        continue;
      }
      $definition = $definitions[$id];
      if ($id === LanguageNegotiationUI::METHOD_ID && $type === LanguageInterface::TYPE_CONTENT) {
        [$links, $method] = $this->switchLinks(LanguageInterface::TYPE_INTERFACE, $url, $request);
        if ($links !== []) {
          return [$links, $method];
        }
      }
      if (!is_subclass_of($definition['class'], LanguageSwitcherInterface::class)) {
        continue;
      }
      $plugin = $this->negotiator->getNegotiationMethodInstance($id);
      \assert($plugin instanceof LanguageSwitcherInterface);
      $links = $plugin->getLanguageSwitchLinks($request, $type, $url);
      if ($links !== []) {
        $this->moduleHandler->alter('language_switch_links', $links, $type, $url);
        // The manager's boolean URL access filter loses cacheability and, for
        // query negotiators, checks the current rather than target translation.
        // Instead the caller checks every translation with a cacheable result.
        return [$links, $id];
      }
    }
    return [[], NULL];
  }

}
