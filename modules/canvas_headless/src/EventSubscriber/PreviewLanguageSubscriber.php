<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\EventSubscriber;

use Drupal\canvas_headless\PreviewTokenInspector;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationManager;
use Drupal\Core\Url;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Plugin\LanguageNegotiation\LanguageNegotiationUrl;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Applies the read-only preview language before routing the content request.
 *
 * @internal
 */
final class PreviewLanguageSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly LanguageManagerInterface $languageManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly TranslationManager $translation,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if (!$event->isMainRequest() || !$request->attributes->has(CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE) || !PreviewTokenInspector::hasPreviewScope($this->currentUser->getAccount())) {
      return;
    }
    $context = $request->attributes->get(CanvasContentApiRequest::API_QUERY_PARAMETERS_KEY, []);
    $langcode = $context[CanvasContentApiRequest::PREVIEW_LANGUAGE_QUERY] ?? NULL;
    if ($langcode === NULL) {
      return;
    }
    $language = $this->languageManager->getLanguage($langcode);
    if ($language === NULL) {
      throw new NotFoundHttpException();
    }
    // The subscriber is registered even on monolingual sites. Guard the
    // optional language module before dereferencing its negotiation class.
    if (!$this->moduleHandler->moduleExists('language')) {
      return;
    }
    if ($this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId() === $langcode) {
      return;
    }

    // Use the site's switch-link query and URL generation, just like coupled
    // previews. Strip an existing prefix before generating a different one.
    $path = $request->getPathInfo();
    $negotiation = $this->configFactory->get('language.negotiation');
    if ($negotiation->get('url.source') === LanguageNegotiationUrl::CONFIG_PATH_PREFIX) {
      foreach ($negotiation->get('url.prefixes') ?? [] as $prefix) {
        if ($prefix !== '' && ($path === '/' . $prefix || str_starts_with($path, '/' . $prefix . '/'))) {
          $path = $path === '/' . $prefix ? '/' : substr($path, strlen($prefix) + 1);
          break;
        }
      }
    }
    $url = Url::fromUri('internal:' . $path);
    $query = $request->query->all();
    $links = $this->languageManager->getLanguageSwitchLinks(LanguageInterface::TYPE_CONTENT, $url);
    if ($links instanceof \stdClass) {
      $query = ($links->links[$langcode]['query'] ?? []) + $query;
    }
    $url->setOption('language', $language)->setOption('query', $query);
    $target = $url->toString();
    // Domain negotiation remains unsupported: never move a bearer credential
    // to another origin or pretend a foreign host is the current request.
    if (!str_starts_with($target, '/') || str_starts_with($target, '//')) {
      throw new NotFoundHttpException('Cross-domain preview language negotiation is not supported.');
    }
    $query_string = (string) (parse_url($target, PHP_URL_QUERY) ?? '');
    parse_str($query_string, $query);
    $headers = $request->headers->all();
    $request->initialize(
      $query,
      $request->request->all(),
      $request->attributes->all(),
      $request->cookies->all(),
      $request->files->all(),
      [...$request->server->all(), 'REQUEST_URI' => $target, 'QUERY_STRING' => $query_string],
      $request->getContent(),
    );
    $request->headers->add($headers);
    $request->setRequestFormat(CanvasContentApiRequest::REQUEST_FORMAT);

    // Negotiation is lazy and cached. Re-negotiate against the updated request
    // before routing, entity conversion, config loading, or rendering occurs.
    $this->languageManager->reset();
    if ($this->languageManager instanceof ConfigurableLanguageManagerInterface) {
      $this->languageManager->setConfigOverrideLanguage($this->languageManager->getCurrentLanguage());
    }
    $this->translation->setDefaultLangcode($this->languageManager->getCurrentLanguage()->getId());
  }

  public static function getSubscribedEvents(): array {
    // After authentication (300) and core language initialization (255), but
    // before routing (32), including translation-aware entity access checks.
    return [KernelEvents::REQUEST => ['onRequest', 250]];
  }

}
