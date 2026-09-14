<?php

declare(strict_types=1);

namespace Drupal\canvas\Access;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Http\Exception\CacheableUnauthorizedHttpException;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Requires an authenticated user, and challenges anonymous requests.
 *
 * Anonymous requests are answered with a 401 carrying a `Basic` authentication
 * challenge named after the site, instead of the 403 that returning a forbidden
 * access result would produce. This lets non-browser clients authenticate.
 */
class AuthenticationAccessChecker implements AccessInterface {

  public function __construct(
    private ConfigFactoryInterface $configFactory,
    private RequestStack $requestStack,
  ) {}

  public function access(AccountInterface $account): AccessResultInterface {
    if ($account->isAnonymous()) {
      $site_config = $this->configFactory->get('system.site');
      $site_name = $site_config->get('name');
      $request = $this->requestStack->getCurrentRequest();
      $challenge = new FormattableMarkup('Basic realm="@realm"', [
        '@realm' => !empty($site_name) ? $site_name : 'Access restricted',
      ]);
      $cacheability = CacheableMetadata::createFromObject($site_config)
        ->addCacheTags(['config:user.role.anonymous'])
        ->addCacheContexts(['user.roles:anonymous']);
      if ($request?->isMethodCacheable()) {
        throw new CacheableUnauthorizedHttpException($cacheability, (string) $challenge, 'You must be logged in to access this resource.');
      }
      else {
        throw new UnauthorizedHttpException((string) $challenge, 'You must be logged in to access this resource.');
      }
    }
    return AccessResult::allowed();
  }

}
