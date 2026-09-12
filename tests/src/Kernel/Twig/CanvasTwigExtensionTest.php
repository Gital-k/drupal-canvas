<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Twig;

use Drupal\canvas\Element\AstroIsland;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tests CanvasTwigExtension.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('Twig')]
final class CanvasTwigExtensionTest extends CanvasKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
  }

  /**
   * Tests extension.
   *
   * @legacy-covers \Drupal\canvas\Twig\CanvasTwigExtension
   * @legacy-covers \Drupal\canvas\Twig\CanvasPropVisitor
   */
  #[DataProvider('providerComponents')]
  public function testExtension(
    string $type,
    string $component_id,
    bool $props_handled_by_twig,
    string $slot_selector,
    array $render_array_additions = [],
    bool $is_preview = FALSE,
    array $extra_props = [],
  ): void {
    $heading = $this->randomMachineName();
    $uuid = $this->container->get(UuidInterface::class)->generate();
    (match ($type) {
      AstroIsland::PLUGIN_ID => fn ($component_id) => JavaScriptComponent::create([
        'machineName' => $component_id,
        'name' => $this->getRandomGenerator()->sentences(5),
        'status' => TRUE,
        'required' => [],
        'props' => [
          'heading' => [
            'type' => 'string',
            'title' => 'Heading',
            'examples' => ['A heading'],
          ],
        ],
        'slots' => [
          'the_body' => [
            'title' => 'Body',
            'description' => 'Body content',
            'examples' => [
              'Lorem ipsum',
            ],
          ],
        ],
        'js' => ['original' => '', 'compiled' => ''],
        'css' => ['original' => '', 'compiled' => ''],
        'dataDependencies' => [],
      ])->save(),
      default => fn() => NULL,
    })($component_id);
    $body = $this->getRandomGenerator()->sentences(10);
    $build = [
      '#type' => $type,
      '#component' => $component_id,
      '#props' => [
        'heading' => $heading,
        'canvas_uuid' => $uuid,
        'canvas_slot_ids' => ['the_body'],
        'canvas_is_preview' => $is_preview,
      ] + $extra_props,
      '#slots' => [
        'the_body' => [
          '#markup' => $body,
        ],
      ],
    ] + $render_array_additions;
    $out = (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
    $crawler = new Crawler($out);
    if ($props_handled_by_twig) {
      $h1 = $crawler->filter(\sprintf('h1:contains("%s")', $heading));
      self::assertCount(1, $h1);
      $h1Text = $h1->html();

      if ($is_preview) {
        self::assertMatchesRegularExpression('/^<!-- canvas-prop-start-(.*)\/heading -->/', $h1Text);
        self::assertMatchesRegularExpression('/canvas-prop-end-(.*)\/heading -->$/', $h1Text);
      }
      else {
        self::assertDoesNotMatchRegularExpression('/^<!-- canvas-prop-start-(.*)\/heading -->/', $h1Text);
        self::assertDoesNotMatchRegularExpression('/canvas-prop-end-(.*)\/heading -->$/', $h1Text);
      }
    }

    $bodySlot = $crawler->filter($slot_selector);
    self::assertCount(1, $bodySlot);
    // Normalize whitespace.
    $bodyHtml = \trim(\preg_replace('/\s+/', ' ', $bodySlot->html()) ?? '');
    self::assertStringContainsString($body, $bodyHtml);

    if ($is_preview) {
      self::assertMatchesRegularExpression('/^<!-- canvas-slot-start-(.*)\/the_body -->/', $bodyHtml);
      self::assertMatchesRegularExpression('/canvas-slot-end-(.*)\/the_body -->$/', $bodyHtml);
    }
    else {
      self::assertDoesNotMatchRegularExpression('/^<!-- canvas-slot-start-(.*)\/the_body -->/', $bodyHtml);
      self::assertDoesNotMatchRegularExpression('/canvas-slot-end-(.*)\/the_body -->$/', $bodyHtml);
    }
  }

  public static function providerComponents(): iterable {

    $sdc = [
      'component',
      'canvas_test_sdc:props-slots',
      TRUE,
      '.component--props-slots--body',
      [],
    ];

    yield 'SDC, preview' => [...$sdc, TRUE];
    yield 'SDC, live' => [...$sdc, FALSE];

    // Regression tests for #3569815: a dynamic tag name preceding a prop or
    // slot print must not poison the HTML5 validity check (and the
    // custom-element attribute detection it interacts with) for everything
    // that follows it in the template.
    $dynamic_tag = [
      'component',
      'canvas_test_sdc:dynamic-tag',
      TRUE,
      '.component--dynamic-tag--body',
      [],
    ];

    // Use 'h1' as the dynamic tag name so the h1-based prop assertions above
    // continue to apply unchanged.
    yield 'SDC with dynamic tag, preview' => [...$dynamic_tag, TRUE, ['tag' => 'h1']];
    yield 'SDC with dynamic tag, live' => [...$dynamic_tag, FALSE, ['tag' => 'h1']];

    // `<{{ as }}{{ attributes }}>` - a fully dynamic tag name immediately
    // followed by a second print with no intervening text, mirroring the
    // common "as" prop convention for polymorphic elements.
    $dynamic_tag_as = [
      'component',
      'canvas_test_sdc:dynamic-tag-as',
      TRUE,
      '.component--dynamic-tag-as--body',
      [],
    ];

    yield 'SDC with "as" dynamic tag, preview' => [...$dynamic_tag_as, TRUE, ['as' => 'h1']];
    yield 'SDC with "as" dynamic tag, live' => [...$dynamic_tag_as, FALSE, ['as' => 'h1']];

    // `<h{{ level }}>` - only part of the tag name is dynamic (a literal "h"
    // prefix immediately followed by the print).
    $dynamic_tag_partial = [
      'component',
      'canvas_test_sdc:dynamic-tag-partial',
      TRUE,
      '.component--dynamic-tag-partial--body',
      [],
    ];

    yield 'SDC with partially-dynamic tag, preview' => [...$dynamic_tag_partial, TRUE, ['level' => '1']];
    yield 'SDC with partially-dynamic tag, live' => [...$dynamic_tag_partial, FALSE, ['level' => '1']];

    $js_component = [
      AstroIsland::PLUGIN_ID,
      'trousers',
      FALSE,
      'template[data-astro-template="the_body"]',
      ['#name' => 'trousers', '#component_url' => 'the/wrong/trousers.js'],
    ];

    yield 'JS Component, preview' => [...$js_component, TRUE];
    yield 'JS Component, live' => [...$js_component, FALSE];
  }

}
