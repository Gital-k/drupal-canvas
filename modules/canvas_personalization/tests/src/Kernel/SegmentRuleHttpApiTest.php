<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_personalization\Kernel;

use Drupal\canvas_personalization\Entity\Segment;
use Drupal\canvas_personalization\Form\SegmentRuleSettingsForm;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Condition\ConditionManager;
use Drupal\Core\Executable\ExecutableManagerInterface;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Http\Exception\CacheableUnauthorizedHttpException;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the internal HTTP API for this site's available segmentation rules.
 *
 * @see \Drupal\canvas_personalization\Controller\ApiSegmentRuleControllers
 * @see \Drupal\canvas_personalization\Form\SegmentRuleSettingsForm
 * @see \Drupal\Tests\canvas_personalization\Functional\SegmentHttpApiTest
 */
#[Group('canvas')]
#[Group('canvas_personalization')]
#[RunTestsInSeparateProcesses]
final class SegmentRuleHttpApiTest extends CanvasKernelTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use RequestTrait;
  use UserCreationTrait;

  private const string LIST_PATH = '/canvas/api/v0/personalization/segment-rules';

  private const string FORM_PATH_PREFIX = '/canvas/api/v0/form/segment-rule/';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_personalization',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['system']);
    // Canvas renders the forms it hands to its UI in this theme, to guarantee
    // predictable markup.
    // @see \Drupal\canvas\Theme\CanvasThemeNegotiator
    $this->container->get(ThemeInstallerInterface::class)->install(['canvas_stark']);
  }

  /**
   * Only the condition plugins personalization allows are listed.
   */
  public function testList(): void {
    $this->setUpCurrentUser(permissions: [Segment::ADMIN_PERMISSION]);

    $response = $this->request(Request::create(self::LIST_PATH));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame([
      'current_theme' => [
        'id' => 'current_theme',
        'label' => 'Current Theme',
      ],
      'user_role' => [
        'id' => 'user_role',
        'label' => 'User Role',
      ],
      'utm_parameters' => [
        'id' => 'utm_parameters',
        'label' => 'UTM Parameters',
      ],
    ], static::decodeResponse($response));
  }

  /**
   * A condition plugin that exists but is not allowed is not listed.
   *
   * This is the same boundary the `SequenceKeysAreFilteredPluginIds` constraint
   * enforces on a stored Segment's `rules`: a Segmentation Rule this HTTP API
   * offered but validation then rejected would be unusable.
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\SequenceKeysAreFilteredPluginIdsConstraint
   */
  public function testListOmitsFilteredOutConditionPlugins(): void {
    $this->setUpCurrentUser(permissions: [Segment::ADMIN_PERMISSION]);
    $condition_manager = $this->container->get(ExecutableManagerInterface::class);
    self::assertInstanceOf(ConditionManager::class, $condition_manager);
    self::assertArrayHasKey('request_path', $condition_manager->getDefinitions());

    $response = $this->request(Request::create(self::LIST_PATH));
    self::assertArrayNotHasKey('request_path', static::decodeResponse($response));
  }

  /**
   * The listing is cacheable, and invalidated when the module list changes.
   */
  public function testListCacheability(): void {
    $this->setUpCurrentUser(permissions: [Segment::ADMIN_PERMISSION]);

    $response = $this->request(Request::create(self::LIST_PATH));
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    $cacheability = $response->getCacheableMetadata();
    // Installing or uninstalling a module changes which condition plugins
    // exist and which modules narrow them down for personalization.
    self::assertSame(['config:core.extension', 'http_response'], $cacheability->getCacheTags());
    // Themes can narrow them down too, and only the active theme's
    // implementations run.
    self::assertSame(['theme', 'user.permissions'], $cacheability->getCacheContexts());
    self::assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge());
  }

  /**
   * Listing requires the permission for administering Segments.
   */
  public function testListRequiresPermission(): void {
    $this->setUpCurrentUser(permissions: ['access content']);

    $this->expectException(CacheableAccessDeniedHttpException::class);
    $this->expectExceptionMessage("The 'administer personalization segments' permission is required.");
    $this->request(Request::create(self::LIST_PATH));
  }

  /**
   * Listing requires an authenticated user.
   */
  public function testListRequiresAuthentication(): void {
    $this->setCurrentUser(new AnonymousUserSession());

    $this->expectException(CacheableUnauthorizedHttpException::class);
    $this->expectExceptionMessage('You must be logged in to access this resource.');
    $this->request(Request::create(self::LIST_PATH));
  }

  /**
   * The settings form for a segmentation rule carries the settings' defaults.
   */
  public function testSettingsForm(): void {
    $this->setUpCurrentUser(permissions: [Segment::ADMIN_PERMISSION]);

    $response = $this->request(Request::create(self::FORM_PATH_PREFIX . 'utm_parameters'));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $json = static::decodeResponse($response);

    // The response is shaped like the component instance form's: markup for the
    // client to transform into React components, plus the assets it needs.
    // @see \Drupal\canvas\Render\MainContent\CanvasTemplateRenderer::renderResponse()
    self::assertSame(['html', 'css', 'js', 'settings', 'transforms'], \array_keys($json));

    $crawler = new Crawler($json['html']);
    self::assertCount(1, $crawler->filter('template[data-hyperscriptify]'));
    // Rendering in `canvas_stark` is what turns Drupal's form markup into the
    // custom elements the client turns into React components.
    // @see \Drupal\canvas\Theme\CanvasThemeNegotiator
    $form = $crawler->filter('drupal-canvas-form');
    self::assertCount(1, $form);
    $form_attributes = \json_decode((string) $form->attr('attributes'), TRUE, flags: \JSON_THROW_ON_ERROR);
    // The client routes form fields to the right Redux integration based on
    // this attribute.
    // @see ui/src/components/form/components/drupal/DrupalForm.tsx
    self::assertSame(SegmentRuleSettingsForm::FORM_ID, $form_attributes['data-form-id']);
    // Like a component instance form, this one is never submitted to Drupal.
    self::assertSame('dialog', $form_attributes['method']);

    // Every setting the condition plugin declares is present, named so that the
    // values the client collects map onto what a Segment stores for this rule.
    // @see \Drupal\canvas_personalization\Plugin\Condition\UtmParameters::buildConfigurationForm()
    // @see \Drupal\Core\Condition\ConditionPluginBase::buildConfigurationForm()
    self::assertSame([
      'settings[parameters][_new_parameter][key]',
      'settings[parameters][_new_parameter][custom_key]',
      'settings[parameters][_new_parameter][value]',
      'settings[parameters][_new_parameter][matching]',
      'settings[negate]',
    ], $crawler->filter('drupal-canvas-form-element')->each(
      static fn (Crawler $element): ?string => $element->attr('name'),
    ));

    // …and carries the default the condition plugin declares for it.
    $matching = self::filterByFormElementName($crawler, 'drupal-canvas-select', 'settings[parameters][_new_parameter][matching]');
    $options = \json_decode((string) $matching->attr('options'), TRUE, flags: \JSON_THROW_ON_ERROR);
    self::assertSame(
      ['exact'],
      \array_column(\array_filter($options, static fn (array $option): bool => $option['selected']), 'value'),
    );
    // `negate` is declared by the condition plugin base class, and defaults to
    // FALSE.
    // @see \Drupal\Core\Condition\ConditionPluginBase::defaultConfiguration()
    $negate = self::filterByFormElementName($crawler, 'drupal-canvas-input', 'settings[negate]');
    $element = \json_decode((string) $negate->attr('element'), TRUE, flags: \JSON_THROW_ON_ERROR);
    self::assertFalse($element['#default_value']);
  }

  /**
   * A segmentation rule that does not exist has no settings form.
   */
  public function testSettingsFormForNonExistentSegmentRule(): void {
    $this->setUpCurrentUser(permissions: [Segment::ADMIN_PERMISSION]);

    $this->expectException(NotFoundHttpException::class);
    $this->expectExceptionMessage("The 'non_existing_plugin' segmentation rule does not exist.");
    $this->request(Request::create(self::FORM_PATH_PREFIX . 'non_existing_plugin'));
  }

  /**
   * A condition plugin that exists but is not allowed has no settings form.
   *
   * It is deliberately indistinguishable from one that does not exist: offering
   * its settings would let the client build a Segment that validation rejects.
   *
   * @see self::testListOmitsFilteredOutConditionPlugins()
   */
  public function testSettingsFormForFilteredOutConditionPlugin(): void {
    $this->setUpCurrentUser(permissions: [Segment::ADMIN_PERMISSION]);
    $condition_manager = $this->container->get(ExecutableManagerInterface::class);
    self::assertInstanceOf(ConditionManager::class, $condition_manager);
    self::assertArrayHasKey('request_path', $condition_manager->getDefinitions());

    $this->expectException(NotFoundHttpException::class);
    $this->expectExceptionMessage("The 'request_path' segmentation rule does not exist.");
    $this->request(Request::create(self::FORM_PATH_PREFIX . 'request_path'));
  }

  /**
   * The settings form requires the permission for administering Segments.
   */
  public function testSettingsFormRequiresPermission(): void {
    $this->setUpCurrentUser(permissions: ['access content']);

    $this->expectException(CacheableAccessDeniedHttpException::class);
    $this->expectExceptionMessage("The 'administer personalization segments' permission is required.");
    $this->request(Request::create(self::FORM_PATH_PREFIX . 'utm_parameters'));
  }

  /**
   * Filters to the one form element with the given form element name.
   *
   * `canvas_stark` renders a form element's HTML attributes as a JSON blob on
   * its custom element, so they cannot be matched with a CSS selector.
   *
   * @param \Symfony\Component\DomCrawler\Crawler $crawler
   *   The crawler to filter.
   * @param string $element
   *   The custom element name, for example `drupal-canvas-select`.
   * @param string $name
   *   The form element name, for example `settings[negate]`.
   *
   * @return \Symfony\Component\DomCrawler\Crawler
   *   The matching element.
   */
  private static function filterByFormElementName(Crawler $crawler, string $element, string $name): Crawler {
    $matches = $crawler->filter($element)->reduce(
      static fn (Crawler $node): bool => (\json_decode((string) $node->attr('attributes'), TRUE)['name'] ?? NULL) === $name,
    );
    self::assertCount(1, $matches);
    return $matches;
  }

}
