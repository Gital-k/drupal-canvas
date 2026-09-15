<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests Dx Route Consistency.
 *
 * @see canvas.routing.yml
 * @see openapi.yml
 */
#[Group('canvas')]
final class DxRouteConsistencyTest extends UnitTestCase {

  /**
   * Route paths whose config entity type openapi.yml leaves dynamic.
   *
   * Most Canvas API routes accepting several config entity types are documented
   * in openapi.yml as one path per accepted type, even though
   * canvas.routing.yml defines a single route for all of them. That is done
   * because their operation specs differ per type. For example, the single
   * `/canvas/api/v0/auto-saves/css/{canvas_config_entity_type_id}/{canvas_config_entity}`
   * route is documented as three paths, each with its own summary and example:
   * @code
   * /canvas/api/v0/auto-saves/css/asset_library/{configEntityId}: …
   * /canvas/api/v0/auto-saves/css/brand_kit/{configEntityId}: …
   * /canvas/api/v0/auto-saves/css/js_component/{configEntityId}: …
   * @endcode
   *
   * The paths listed here are the exception: their operation spec is the same
   * for every config entity type they accept, because both the request and the
   * response body are opaque auto-save states (`type: object`). One path per
   * type would repeat that spec verbatim, so openapi.yml documents them with
   * the config entity type left dynamic instead. Consequently
   * ::resolveRouteDefinitionsToOperations() must not resolve them.
   *
   * The trade-off: openapi.yml cannot express that these routes accept a
   * different set of config entity types per method — GET additionally accepts
   * `staged_config_update`, PATCH does not.
   *
   * @see \Drupal\canvas\Controller\ApiConfigAutoSaveControllers
   * @see ::resolveRouteDefinitionsToOperations()
   */
  private const array PATHS_WITH_UNRESOLVED_CONFIG_ENTITY_TYPE = [
    '/canvas/api/v0/config/auto-save/{canvas_config_entity_type_id}/{canvas_config_entity}',
  ];

  public function testRoutingYmlDx(): array {
    $routes = Yaml::parseFile(__DIR__ . '/../../../canvas.routing.yml');
    \assert(\is_array($routes));

    // All route definitions must be alphabetically ordered.
    $actual_routes_order = \array_keys($routes);
    $expected_routers_order = $actual_routes_order;
    sort($expected_routers_order);
    $this->assertSame($expected_routers_order, $actual_routes_order);

    // All Canvas API routes must:
    // - have a `path` that starts with '/canvas/api/v0/'
    // - specify `methods`.
    $canvas_api_routes = array_filter($routes, fn ($k) => str_starts_with($k, 'canvas.api.'), ARRAY_FILTER_USE_KEY);
    foreach ($canvas_api_routes as $canvas_api_route_name => $canvas_api_route) {
      $this->assertStringStartsWith('/canvas/api/v0/', $canvas_api_route['path'], "`$canvas_api_route_name` route path starts with '/canvas/api/v0/'." . print_r($canvas_api_route, TRUE));
      $this->assertArrayHasKey('methods', $canvas_api_route, "`$canvas_api_route_name` route definition specifies `methods`.");
    }

    return $canvas_api_routes;
  }

  /**
 * Tests authentication required permission.
 */
  #[Depends('testRoutingYmlDx')]
  public function testAuthenticationRequiredPermission(array $canvas_api_routes): void {
    foreach ($canvas_api_routes as $canvas_api_route_name => $canvas_api_route) {
      $this->assertArrayHasKey('_canvas_authentication_required', $canvas_api_route['requirements'], "`$canvas_api_route_name` needs to include the _canvas_authentication_required requirement. This is needed to provide useful errors for attempts to access the route unauthenticated.");
    }
  }

  /**
 * Tests open api completeness.
 */
  #[Depends('testRoutingYmlDx')]
  public function testOpenApiCompleteness(array $canvas_api_routes): void {
    // Map Canvas API route definitions keyed by route name to being keyed by path
    // and method, with the path resolved where possible.
    $route_defined_canvas_api_operations = self::resolveRouteDefinitionsToOperations($canvas_api_routes);
    $route_defined_canvas_api_operations = self::includeDynamicPersonalizationPaths($route_defined_canvas_api_operations);

    $normalized_route_defined_canvas_api_operations = self::ignoreDynamicPathPartNames($route_defined_canvas_api_operations);
    // Note: while routes were already guaranteed to be alphabetically sorted,
    // after resolving static path parts that may no longer be true.
    ksort($normalized_route_defined_canvas_api_operations);

    // Extract OpenAPI operations per path to the same key structure as above,
    // but with an OpenAPI operation spec as values.
    $openapi = Yaml::parseFile(__DIR__ . '/../../../openapi.yml');
    $normalized_openapi_paths = self::ignoreDynamicPathPartNames($openapi['paths']);
    // Simplifying the dynamic path parts must not cause two documented paths to
    // become one: that would silently drop one of them from this test's
    // comparison, and hence hide a missing or superfluous route.
    $this->assertSameSize($openapi['paths'], $normalized_openapi_paths, 'Every openapi.yml path is still distinct after simplifying its dynamic path parts.');
    $normalized_openapi_defined_operations = [];
    foreach ($normalized_openapi_paths as $path => $path_spec) {
      foreach (['DELETE', 'GET', 'PATCH', 'POST'] as $method) {
        if (\array_key_exists(strtolower($method), $path_spec)) {
          $normalized_openapi_defined_operations["$path $method"] = $path_spec[strtolower($method)];
        }
      }
    }
    $this->assertSame(\array_keys($normalized_route_defined_canvas_api_operations), \array_keys($normalized_openapi_defined_operations), 'OpenAPI path and operation specs exist for every Canvas API route, and appear in alphabetical order.');
  }

  private static function resolveRouteDefinitionsToOperations(array $routes_by_name): array {
    $operations = [];

    foreach ($routes_by_name as $route) {
      // Resolve each route to all of its operations. Keys are "<path> <method>"
      // and values are route definitions.
      // @see https://swagger.io/docs/specification/v3_0/paths-and-operations/#operations
      $original_path = $route['path'];
      $operations_for_route = [];
      foreach (self::expandOptionalTrailingPathParts($route) as $path) {
        foreach ($route['methods'] as $method) {
          $operations_for_route[$path . ' ' . $method] = $route;
        }
      }

      // Determine static path parts corresponding to route requirements. These
      // need to be resolved, to account for e.g. different request/response
      // body schemas for each config entity type supported by a route.
      // @see https://symfony.com/doc/current/routing.html#route-parameters
      $static_path_part_requirements = \array_keys(array_filter(
        $route['requirements'] ?? [],
          // Ignore special parameters.
          // @see https://symfony.com/doc/current/routing.html#special-parameters
          fn(string $req_name) => !str_starts_with($req_name, '_'),
        ARRAY_FILTER_USE_KEY,
      ));

      // No need to resolve: add the operations for the route.
      if (empty($static_path_part_requirements) || \in_array($original_path, self::PATHS_WITH_UNRESOLVED_CONFIG_ENTITY_TYPE, TRUE)) {
        $operations = [...$operations, ...$operations_for_route];
        continue;
      }

      $operations_to_resolve = \array_keys($operations_for_route);
      foreach ($static_path_part_requirements as $req_name) {
        $req_value = $route['requirements'][$req_name];

        $possible_values = match (str_starts_with($req_value, '(')) {
          // Parse a list of possible values, which in a Symfony route
          // definition looks like this: `(a|b|c|d)`.
          TRUE => explode('|', substr($req_value, 1, -1)),
          // Otherwise, it's a single allowed value.
          FALSE => $req_value,
        };
        $temp = [];
        foreach ($possible_values as $possible_value) {
          $temp = [
            ...$temp,
            ...str_replace('{' . $req_name . '}', $possible_value, $operations_to_resolve),
          ];
        }
        $operations_to_resolve = $temp;
      }
      $operations_for_route = array_fill_keys($operations_to_resolve, $route);

      // Resolving completed: add the operations for the route.
      $operations = [...$operations, ...$operations_for_route];
    }

    ksort($operations);

    return $operations;
  }

  /**
   * Expands a route path to every path the route actually matches.
   *
   * A trailing route parameter that has a value under `defaults` is optional:
   * Symfony then matches the path both with and without it. openapi.yml
   * documents each of those as a path of its own, so the route side must be
   * expanded to match. For example:
   * @code
   * canvas.api.form.content_entity:
   *   path: '/canvas/api/v0/form/content-entity/{entity_type}/{entity}/{entity_form_mode}'
   *   defaults:
   *     entity_form_mode: default
   * @endcode
   * … matches both `…/{entity_type}/{entity}/{entity_form_mode}` and
   * `…/{entity_type}/{entity}`, so both are returned.
   *
   * @see \Symfony\Component\Routing\RouteCompiler::compilePattern()
   */
  private static function expandOptionalTrailingPathParts(array $route): array {
    $path = $route['path'];
    $paths = [$path];
    // Strip optional trailing path parts one by one: a path part can only be
    // optional if every path part after it is optional too.
    while (preg_match('#/\{([^}]+)\}$#', $path, $matches) === 1 && \array_key_exists($matches[1], $route['defaults'] ?? [])) {
      $path = substr($path, 0, -\strlen($matches[0]));
      $paths[] = $path;
    }
    return $paths;
  }

  /**
   * Simplifies `{…}` path parts to just `{}`.
   *
   * The route parameter syntax in *.routing.yml and openapi.yml is similar:
   * both use curly braces. But their contents differ:
   * - in the *.routing.yml file, they may be named in a particular way to
   *   use ParamConverters, or to closely match Drupal internals
   * - in the openapi.yml file are intended for human readers that do not need
   *   to know Drupal internals
   * So: strip their contents to allow for simple comparisons.
   *
   * Each dynamic path part is simplified individually, to not lose how many of
   * them a path has. For example:
   * @code
   * // Two dynamic path parts, so two remain after simplifying.
   * '/canvas/api/some/path/{entity_type}/{entity} GET'
   * // … becomes:
   * '/canvas/api/some/path/{}/{} GET'
   *
   * // One dynamic path part, so one remains: distinct from the above.
   * '/canvas/api/some/path/{entity} GET'
   * // … becomes:
   * '/canvas/api/some/path/{} GET'
   * @endcode
   */
  private static function ignoreDynamicPathPartNames(array $array_with_paths_as_keys): array {
    return array_combine(
      // @phpstan-ignore-next-line
      \array_map(
        fn (string $path) => preg_replace('/\{[^}]*\}/', '{}', $path),
        \array_keys($array_with_paths_as_keys),
      ),
      array_values($array_with_paths_as_keys)
    );
  }

  /**
   * Adds personalization routes.
   *
   * For simplicity, we define personalization paths in openapi, but they are
   * added in Drupal by using a route subscriber.
   * So: statically add these here for now to avoid failures.
   */
  private static function includeDynamicPersonalizationPaths(array $array_with_paths_as_keys): array {
    $routes = array_merge($array_with_paths_as_keys, [
      '/canvas/api/v0/config/segment GET' => ['methods' => ['GET']],
      '/canvas/api/v0/config/segment POST' => ['methods' => ['POST']],
      '/canvas/api/v0/config/segment/{segment} GET' => ['methods' => ['GET']],
      '/canvas/api/v0/config/segment/{segment} PATCH' => ['methods' => ['PATCH']],
      '/canvas/api/v0/config/segment/{segment} DELETE' => ['methods' => ['DELETE']],
    ]);
    ksort($routes);
    return $routes;
  }

}
