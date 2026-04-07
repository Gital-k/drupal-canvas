<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Unit;

use Drupal\canvas_ai\Service\ComponentSchemaLoaderInterface;
use Drupal\canvas_ai\Service\DirectEditMatcher;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the DirectEditMatcher service.
 *
 * @group canvas_ai
 * @coversDefaultClass \Drupal\canvas_ai\Service\DirectEditMatcher
 */
#[CoversClass(DirectEditMatcher::class)]
#[Group('canvas')]
#[Group('canvas_direct_edit')]
class DirectEditMatcherTest extends UnitTestCase {

  /**
   * The matcher under test.
   */
  private DirectEditMatcher $matcher;

  /**
   * Prop alias map used by the schema loader mock.
   *
   * Keyed by SDC component name; values are alias => prop_name maps.
   *
   * @var array<string, array<string, string>>
   */
  private static array $propAliases = [
    'sdc.byte_theme.heading' => [
      'heading' => 'heading_text',
      'title' => 'heading_text',
      'text' => 'heading_text',
      'level' => 'level',
      'heading level' => 'level',
      'size' => 'text_size',
      'text size' => 'text_size',
      'font size' => 'text_size',
      'color' => 'text_color',
      'text color' => 'text_color',
      'alignment' => 'align',
      'align' => 'align',
    ],
    'sdc.byte_theme.button' => [
      'label' => 'label',
      'text' => 'label',
      'button text' => 'label',
      'style' => 'variant',
      'variant' => 'variant',
      'size' => 'size',
      'icon' => 'icon',
      'link' => 'href',
      'url' => 'href',
      'href' => 'href',
    ],
    'sdc.byte_theme.card-icon' => [
      'title' => 'text',
      'heading' => 'text',
      'text' => 'text',
      'description' => 'description',
      'icon' => 'icon',
      'background' => 'background_color',
      'background color' => 'background_color',
    ],
    'sdc.byte_theme.badge' => [
      'label' => 'label',
      'text' => 'label',
    ],
    'sdc.byte_theme.icon' => [
      'icon' => 'icon',
      'name' => 'icon',
      'size' => 'size',
      'color' => 'color',
    ],
    'sdc.byte_theme.section' => [
      'header' => 'section_header',
      'show header' => 'section_header',
      'footer' => 'section_footer',
      'show footer' => 'section_footer',
    ],
    // Collision component: group has overlapping enum values.
    'sdc.byte_theme.group' => [
      'gap' => 'flex_gap',
      'flex gap' => 'flex_gap',
      'radius' => 'radius',
      'corner radius' => 'radius',
      'padding' => 'padding',
    ],
  ];

  /**
   * Enum value map used by the schema loader mock.
   *
   * Keyed by SDC component name, then prop name; values are alias => canonical.
   *
   * @var array<string, array<string, array<string, string>>>
   */
  private static array $enumValues = [
    'sdc.byte_theme.heading' => [
      'text_color' => [
        'default' => 'default',
        'white' => 'inverted',
        'inverted' => 'inverted',
        'light' => 'inverted',
        'primary' => 'primary',
        'blue' => 'primary',
      ],
      'align' => [
        'default' => 'default',
        'left' => 'left',
        'center' => 'center',
        'centered' => 'center',
        'middle' => 'center',
        'right' => 'right',
      ],
    ],
    'sdc.byte_theme.button' => [
      'variant' => [
        'primary' => 'primary',
        'secondary' => 'secondary',
        'primary inverted' => 'primary-inverted',
        'secondary inverted' => 'secondary-inverted',
      ],
      'size' => [
        'small' => 'small',
        'medium' => 'medium',
        'large' => 'large',
      ],
    ],
    // Collision: sm/md/lg/xl map to 3 props each.
    'sdc.byte_theme.group' => [
      'flex_gap' => [
        'sm' => 'sm',
        'md' => 'md',
        'lg' => 'lg',
        'xl' => 'xl',
      ],
      'radius' => [
        'sm' => 'sm',
        'md' => 'md',
        'lg' => 'lg',
        'xl' => 'xl',
      ],
      'padding' => [
        'sm' => 'sm',
        'md' => 'md',
        'lg' => 'lg',
        'xl' => 'xl',
      ],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $schemaLoader = $this->createMock(ComponentSchemaLoaderInterface::class);

    $schemaLoader->method('getPropAliases')
      ->willReturnCallback(static function (string $componentName): array {
        return self::$propAliases[$componentName] ?? [];
      });

    $schemaLoader->method('getEnumValues')
      ->willReturnCallback(static function (string $propName, string $componentName): ?array {
        return self::$enumValues[$componentName][$propName] ?? NULL;
      });

    $schemaLoader->method('getSupportedComponents')
      ->willReturn(array_keys(self::$propAliases));

    // Boolean props mock.
    $booleanProps = [
      'sdc.byte_theme.heading' => [],
      'sdc.byte_theme.button' => [
        'disabled' => ['aliases' => ['disabled'], 'inverted' => TRUE],
        'icon_first' => ['aliases' => ['icon_first', 'icon first'], 'inverted' => FALSE],
      ],
      'sdc.byte_theme.card-icon' => [],
      'sdc.byte_theme.badge' => [],
      'sdc.byte_theme.icon' => [],
      'sdc.byte_theme.group' => [],
      'sdc.byte_theme.section' => [
        'section_header' => ['aliases' => ['section_header', 'show header', 'header'], 'inverted' => FALSE],
        'section_footer' => ['aliases' => ['section_footer', 'show footer', 'footer'], 'inverted' => FALSE],
      ],
    ];
    $schemaLoader->method('getBooleanProps')
      ->willReturnCallback(static function (string $componentName) use ($booleanProps): array {
        return $booleanProps[$componentName] ?? [];
      });

    // Enum ordinals mock.
    $enumOrdinals = [
      'sdc.byte_theme.heading' => [
        'text_size' => [
          'values' => [
            'default',
            'heading-responsive-8xl',
            'heading-responsive-7xl',
            'heading-responsive-6xl',
            'heading-responsive-5xl',
            'heading-responsive-4xl',
            'heading-responsive-3xl',
            'heading-responsive-2xl',
            'heading-responsive-xl',
          ],
          'direction' => 'descending',
        ],
        'text_color' => [
          'values' => ['default', 'inverted', 'primary'],
          'direction' => 'ascending',
        ],
        'align' => [
          'values' => ['left', 'center', 'right'],
          'direction' => 'ascending',
        ],
      ],
      'sdc.byte_theme.button' => [
        'variant' => [
          'values' => ['primary', 'secondary', 'primary-inverted', 'secondary-inverted'],
          'direction' => 'ascending',
        ],
        'size' => [
          'values' => ['small', 'medium', 'large'],
          'direction' => 'ascending',
        ],
      ],
    ];
    $schemaLoader->method('getEnumOrdinals')
      ->willReturnCallback(static function (string $componentName) use ($enumOrdinals): array {
        return $enumOrdinals[$componentName] ?? [];
      });

    // Integer enum values mock.
    $integerEnums = [
      'sdc.byte_theme.heading' => [
        'level' => [1, 2, 3, 4, 5, 6],
      ],
    ];
    $schemaLoader->method('getIntegerEnumValues')
      ->willReturnCallback(static function (string $propName, string $componentName) use ($integerEnums): ?array {
        return $integerEnums[$componentName][$propName] ?? NULL;
      });

    $schemaLoader->method('getReverseEnumIndex')
      ->willReturnCallback(static function (string $componentName): array {
        $enums = self::$enumValues[$componentName] ?? [];
        $reverse = [];
        foreach ($enums as $propName => $valueMap) {
          foreach ($valueMap as $alias => $canonical) {
            $reverse[$alias][] = $propName;
          }
        }
        // Deduplicate props per value.
        foreach ($reverse as $value => $props) {
          $reverse[$value] = array_values(array_unique($props));
        }
        return $reverse;
      });

    $schemaLoader->method('getReverseAliasIndex')
      ->willReturnCallback(static function (string $componentName): array {
        $enums = self::$enumValues[$componentName] ?? [];
        // Build the full reverse map (all aliases including natural ones).
        $fullReverse = [];
        foreach ($enums as $propName => $valueMap) {
          foreach ($valueMap as $alias => $canonical) {
            $fullReverse[$alias][] = $propName;
          }
        }
        // Determine raw enum values (alias === canonical, case-insensitive).
        $rawValues = [];
        foreach ($enums as $propName => $valueMap) {
          foreach ($valueMap as $alias => $canonical) {
            if ($alias === mb_strtolower($canonical)) {
              $rawValues[$alias] = TRUE;
            }
          }
        }
        // Alias index = aliases NOT in the raw enum values set.
        $aliasIndex = [];
        foreach ($fullReverse as $alias => $props) {
          if (!isset($rawValues[$alias])) {
            $aliasIndex[$alias] = array_values(array_unique($props));
          }
        }
        return $aliasIndex;
      });

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static function (string $key) {
      if ($key === 'edit_verbs') {
        return ['change', 'set', 'update', 'modify', 'make', 'turn', 'switch', 'put'];
      }
      return NULL;
    });
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('canvas_ai.direct_edit.settings')->willReturn($config);

    $this->matcher = new DirectEditMatcher($schemaLoader, $configFactory);
  }

  /**
   * @covers ::match
   * @dataProvider singlePropMatchProvider
   */
  public function testSinglePropMatches(string $message, string $component, string $expectedProp, mixed $expectedValue): void {
    $result = $this->matcher->match($message, $component);
    $this->assertNotNull($result, "Expected match for: \"$message\"");
    $this->assertSame($expectedProp, $result['prop']);
    $this->assertSame($expectedValue, $result['value']);
  }

  /**
   * @covers ::match
   * @dataProvider compoundMatchProvider
   */
  public function testCompoundMatches(string $message, string $component, array $expectedChanges): void {
    $result = $this->matcher->match($message, $component);
    $this->assertNotNull($result, "Expected compound match for: \"$message\"");
    $this->assertArrayHasKey('changes', $result);
    $this->assertSame($expectedChanges, $result['changes']);
  }

  /**
   * Data provider for single-prop matches.
   */
  public static function singlePropMatchProvider(): array {
    return [
      // Heading text changes.
      'change heading text' => [
        'change the heading to Welcome to Drupal',
        'sdc.byte_theme.heading',
        'heading_text',
        'Welcome to Drupal',
      ],
      'set title' => [
        'set the title to Hello World',
        'sdc.byte_theme.heading',
        'heading_text',
        'Hello World',
      ],

      // Enum resolution — text_color.
      'set color primary' => [
        'set the color to primary',
        'sdc.byte_theme.heading',
        'text_color',
        'primary',
      ],
      'color alias blue' => [
        'change the color to blue',
        'sdc.byte_theme.heading',
        'text_color',
        'primary',
      ],
      'color alias white' => [
        'set the color to white',
        'sdc.byte_theme.heading',
        'text_color',
        'inverted',
      ],

      // Enum resolution — align.
      'set alignment center' => [
        'set the alignment to center',
        'sdc.byte_theme.heading',
        'align',
        'center',
      ],
      'align alias centered' => [
        'set the alignment to centered',
        'sdc.byte_theme.heading',
        'align',
        'center',
      ],

      // Numeric prop — level.
      'set level 3' => [
        'set the level to 3',
        'sdc.byte_theme.heading',
        'level',
        3,
      ],
      'set level 1' => [
        'change the level to 1',
        'sdc.byte_theme.heading',
        'level',
        1,
      ],

      // Button component.
      'button label' => [
        'change the label to Get Started',
        'sdc.byte_theme.button',
        'label',
        'Get Started',
      ],
      'button variant' => [
        'set the variant to secondary',
        'sdc.byte_theme.button',
        'variant',
        'secondary',
      ],
      'button size' => [
        'set the size to large',
        'sdc.byte_theme.button',
        'size',
        'large',
      ],

      // "make" as edit verb.
      'make color blue' => [
        'make the color to blue',
        'sdc.byte_theme.heading',
        'text_color',
        'primary',
      ],

      // Colon format.
      'colon format heading' => [
        'heading: New Title Here',
        'sdc.byte_theme.heading',
        'heading_text',
        'New Title Here',
      ],

      // Equals format.
      'equals format color' => [
        'set color = primary',
        'sdc.byte_theme.heading',
        'text_color',
        'primary',
      ],

      // Bare value type inference.
      'bare value blue on heading' => [
        'blue',
        'sdc.byte_theme.heading',
        'text_color',
        'primary',
      ],
      'bare value center on heading' => [
        'center',
        'sdc.byte_theme.heading',
        'align',
        'center',
      ],
      'bare value inverted on heading' => [
        'inverted',
        'sdc.byte_theme.heading',
        'text_color',
        'inverted',
      ],
      'make it blue on heading' => [
        'make it blue',
        'sdc.byte_theme.heading',
        'text_color',
        'primary',
      ],
      'make this centered on heading' => [
        'make this centered',
        'sdc.byte_theme.heading',
        'align',
        'center',
      ],
      'make the primary on heading' => [
        'make the primary',
        'sdc.byte_theme.heading',
        'text_color',
        'primary',
      ],
      'bare value secondary on button' => [
        'secondary',
        'sdc.byte_theme.button',
        'variant',
        'secondary',
      ],
      'make it large on button' => [
        'make it large',
        'sdc.byte_theme.button',
        'size',
        'large',
      ],

      // Bare alias inference (natural aliases not in raw enum).
      'bare alias white resolves to text_color' => [
        'white',
        'sdc.byte_theme.heading',
        'text_color',
        'inverted',
      ],
      'make it white resolves via alias' => [
        'make it white',
        'sdc.byte_theme.heading',
        'text_color',
        'inverted',
      ],

      // Boolean toggle matches.
      'show header on section' => [
        'show the header',
        'sdc.byte_theme.section',
        'section_header',
        TRUE,
      ],
      'hide footer on section' => [
        'hide the footer',
        'sdc.byte_theme.section',
        'section_footer',
        FALSE,
      ],
      'enable icon first on button' => [
        'enable icon first',
        'sdc.byte_theme.button',
        'icon_first',
        TRUE,
      ],
      'disable icon first on button' => [
        'disable icon first',
        'sdc.byte_theme.button',
        'icon_first',
        FALSE,
      ],
      // Inverted polarity: "enable" on "disabled" = false.
      'enable disabled button (inverted)' => [
        'enable disabled',
        'sdc.byte_theme.button',
        'disabled',
        FALSE,
      ],
      'disable disabled button (inverted)' => [
        'disable disabled',
        'sdc.byte_theme.button',
        'disabled',
        TRUE,
      ],
      'turn on header' => [
        'turn on the header',
        'sdc.byte_theme.section',
        'section_header',
        TRUE,
      ],
      'turn off footer' => [
        'turn off the footer',
        'sdc.byte_theme.section',
        'section_footer',
        FALSE,
      ],

      // Edge case: unicode in text value.
      'unicode heading text' => [
        'change the heading to Bienvenue chez nous',
        'sdc.byte_theme.heading',
        'heading_text',
        'Bienvenue chez nous',
      ],

      // Reset/clear/remove patterns.
      'reset color to default' => [
        'reset the color',
        'sdc.byte_theme.heading',
        'text_color',
        'default',
      ],
      'clear the link on button' => [
        'clear the link',
        'sdc.byte_theme.button',
        'href',
        '',
      ],
      'remove the url on button' => [
        'remove the url',
        'sdc.byte_theme.button',
        'href',
        '',
      ],

      // Synonym verbs (config-driven).
      'turn color to blue' => [
        'turn the color to blue',
        'sdc.byte_theme.heading',
        'text_color',
        'primary',
      ],
      'switch alignment to center' => [
        'switch the alignment to center',
        'sdc.byte_theme.heading',
        'align',
        'center',
      ],
      'put size to large' => [
        'put the size to large',
        'sdc.byte_theme.button',
        'size',
        'large',
      ],
    ];
  }

  /**
   * Data provider for compound deterministic edits.
   */
  public static function compoundMatchProvider(): array {
    return [
      'heading and color' => [
        'change the heading to Welcome and set the color to blue',
        'sdc.byte_theme.heading',
        [
          ['prop' => 'heading_text', 'value' => 'Welcome'],
          ['prop' => 'text_color', 'value' => 'primary'],
        ],
      ],
      'alignment level and color' => [
        'set alignment to center, change the level to 3, and make the color to white',
        'sdc.byte_theme.heading',
        [
          ['prop' => 'align', 'value' => 'center'],
          ['prop' => 'level', 'value' => 3],
          ['prop' => 'text_color', 'value' => 'inverted'],
        ],
      ],
      'semicolon split' => [
        'change the heading to Welcome; set the alignment to right',
        'sdc.byte_theme.heading',
        [
          ['prop' => 'heading_text', 'value' => 'Welcome'],
          ['prop' => 'align', 'value' => 'right'],
        ],
      ],

      // Edge case: edit verb in text value must not be split.
      'compound with edit verb in text value' => [
        'change the heading to Set Your Goals and set the color to blue',
        'sdc.byte_theme.heading',
        [
          ['prop' => 'heading_text', 'value' => 'Set Your Goals'],
          ['prop' => 'text_color', 'value' => 'primary'],
        ],
      ],
    ];
  }

  /**
   * @covers ::match
   * @dataProvider rejectProvider
   */
  public function testRejects(string $message, string $component, string $reason): void {
    $result = $this->matcher->match($message, $component);
    $this->assertNull($result, "Expected NULL (reject) for: \"$message\" ($reason)");
  }

  /**
   * Data provider for messages that should NOT match.
   */
  public static function rejectProvider(): array {
    return [
      // Add/create keywords.
      'add keyword' => ['add a new section below', 'sdc.byte_theme.heading', 'add keyword'],
      'create keyword' => ['create a heading', 'sdc.byte_theme.heading', 'create keyword'],
      'insert keyword' => ['insert a card here', 'sdc.byte_theme.heading', 'insert keyword'],
      'generate keyword' => ['generate a better title', 'sdc.byte_theme.heading', 'generate keyword'],
      'build keyword' => ['build a new section', 'sdc.byte_theme.heading', 'build keyword'],

      // "make" with add-intent phrases.
      'make a new' => ['make a new heading', 'sdc.byte_theme.heading', 'make-a-new phrase'],
      'make me a' => ['make me a section', 'sdc.byte_theme.heading', 'make-me-a phrase'],
      'make another' => ['make another card below', 'sdc.byte_theme.heading', 'another keyword'],

      // Ambiguous — no prop/value match.
      'ambiguous improve' => ['make this look better', 'sdc.byte_theme.heading', 'no prop match'],
      'ambiguous rewrite' => ['rewrite this to be more engaging', 'sdc.byte_theme.heading', 'no pattern match'],
      'vague request' => ['fix this', 'sdc.byte_theme.heading', 'no pattern match'],

      // Unknown component.
      'unknown component' => ['change the heading to Hello', 'sdc.unknown_theme.widget', 'unknown component'],

      // Invalid enum value.
      'invalid enum' => ['set the color to rainbow', 'sdc.byte_theme.heading', 'invalid enum value'],

      // Invalid level (out of range).
      'level too high' => ['set the level to 7', 'sdc.byte_theme.heading', 'level out of range'],
      'level zero' => ['set the level to 0', 'sdc.byte_theme.heading', 'level out of range'],
      'level text' => ['set the level to big', 'sdc.byte_theme.heading', 'level non-numeric'],

      // Compound rejections.
      'compound duplicate prop' => [
        'set the color to blue and set the color to white',
        'sdc.byte_theme.heading',
        'same prop set twice',
      ],
      'compound partial deterministic' => [
        'change the heading to Welcome and add a card below',
        'sdc.byte_theme.heading',
        'all-or-nothing compound rejection',
      ],
      'compound false positive guard' => [
        'change the heading to Welcome and set the color to blue',
        'sdc.byte_theme.button',
        'do not treat compound as a single raw text update',
      ],

      // Bare value rejections.
      'bare value collision on group' => [
        'lg',
        'sdc.byte_theme.group',
        'ambiguous: lg maps to flex_gap, radius, padding',
      ],
      'bare value collision sm on group' => [
        'sm',
        'sdc.byte_theme.group',
        'ambiguous: sm maps to 3 props',
      ],
      'make it lg on group' => [
        'make it lg',
        'sdc.byte_theme.group',
        'ambiguous even with prefix strip',
      ],
      'bare value unknown' => [
        'rainbow',
        'sdc.byte_theme.heading',
        'value not in any enum',
      ],
      'multi-word bare value rejected' => [
        'something entirely different',
        'sdc.byte_theme.heading',
        'multi-word messages not treated as bare values',
      ],
      'make it look better' => [
        'make it look better',
        'sdc.byte_theme.heading',
        'stripped value has spaces, not a bare enum',
      ],

      // Boolean toggle rejections — non-toggle boolean props.
      'show align rejected (non-toggle boolean)' => [
        'show the alignment',
        'sdc.byte_theme.heading',
        'align is not a show/hide toggle',
      ],
      'enable align rejected' => [
        'enable align',
        'sdc.byte_theme.heading',
        'align is not a show/hide toggle',
      ],

      // Empty and too-long messages.
      'empty message' => ['', 'sdc.byte_theme.heading', 'empty message'],
      'too long message' => [str_repeat('x', 501), 'sdc.byte_theme.heading', 'exceeds 500 chars'],

      // Edge case: bare "default" is ambiguous (maps to text_color and align).
      'bare default is ambiguous (multiple props have default)' => [
        'default',
        'sdc.byte_theme.heading',
        'default maps to multiple props (text_color, align both have default)',
      ],

      // Edge case: empty value after extraction — regex (.+?) requires >=1 char.
      'empty value after extraction' => [
        'change the heading to ',
        'sdc.byte_theme.heading',
        'trailing space produces empty value, (.+?) does not match',
      ],

      // Reset/clear/remove rejections.
      'remove this section (structural, not prop reset)' => [
        'remove this section',
        'sdc.byte_theme.heading',
        'structural operation, not prop reset',
      ],
      'clear with no prop reference' => [
        'clear',
        'sdc.byte_theme.heading',
        'no prop reference after verb',
      ],
    ];
  }

  /**
   * @covers ::match
   * @dataProvider relativeAdjustmentMatchProvider
   */
  public function testRelativeAdjustmentMatches(string $message, string $component, array $currentValues, string $expectedProp, mixed $expectedValue): void {
    $result = $this->matcher->match($message, $component, $currentValues);
    $this->assertNotNull($result, "Expected relative match for: \"$message\"");
    $this->assertSame($expectedProp, $result['prop']);
    $this->assertSame($expectedValue, $result['value']);
  }

  /**
   * Data provider for relative adjustment matches.
   */
  public static function relativeAdjustmentMatchProvider(): array {
    return [
      // Heading text_size is descending: 8xl(biggest) -> xl(smallest).
      // "bigger" on heading at 5xl should go toward 6xl (lower index).
      'bigger heading from 5xl' => [
        'bigger',
        'sdc.byte_theme.heading',
        ['text_size' => 'heading-responsive-5xl', 'text_color' => 'default'],
        'text_size',
        'heading-responsive-6xl',
      ],
      'smaller heading from 5xl' => [
        'smaller',
        'sdc.byte_theme.heading',
        ['text_size' => 'heading-responsive-5xl', 'text_color' => 'default'],
        'text_size',
        'heading-responsive-4xl',
      ],
      'make it bigger heading' => [
        'make it bigger',
        'sdc.byte_theme.heading',
        ['text_size' => 'heading-responsive-3xl', 'text_color' => 'default'],
        'text_size',
        'heading-responsive-4xl',
      ],
      // Button size is ascending: small -> medium -> large.
      'larger button from small' => [
        'larger',
        'sdc.byte_theme.button',
        ['size' => 'small', 'variant' => 'primary'],
        'size',
        'medium',
      ],
      'smaller button from large' => [
        'smaller',
        'sdc.byte_theme.button',
        ['size' => 'large', 'variant' => 'primary'],
        'size',
        'medium',
      ],
    ];
  }

  /**
   * @covers ::match
   * @dataProvider relativeAdjustmentRejectProvider
   */
  public function testRelativeAdjustmentRejects(string $message, string $component, ?array $currentValues, string $reason): void {
    $result = $this->matcher->match($message, $component, $currentValues);
    $this->assertNull($result, "Expected NULL (reject) for: \"$message\" ($reason)");
  }

  /**
   * Data provider for relative adjustment rejections.
   */
  public static function relativeAdjustmentRejectProvider(): array {
    return [
      'bigger at max (8xl is biggest non-default)' => [
        'bigger',
        'sdc.byte_theme.heading',
        ['text_size' => 'heading-responsive-8xl', 'text_color' => 'default'],
        'at upper boundary',
      ],
      'smaller at min (xl is smallest)' => [
        'smaller',
        'sdc.byte_theme.heading',
        ['text_size' => 'heading-responsive-xl', 'text_color' => 'default'],
        'at lower boundary',
      ],
      'bigger without current values' => [
        'bigger',
        'sdc.byte_theme.heading',
        NULL,
        'no current prop values available',
      ],
      'bigger on component without size prop' => [
        'bigger',
        'sdc.byte_theme.card-icon',
        ['text' => 'Card One'],
        'no matching ordinal prop',
      ],
      'unknown adjective' => [
        'fancier',
        'sdc.byte_theme.heading',
        ['text_size' => 'heading-responsive-5xl'],
        'not a recognized comparative',
      ],
    ];
  }

  /**
   * @covers ::getSupportedComponents
   */
  public function testGetSupportedComponents(): void {
    $components = $this->matcher->getSupportedComponents();
    $this->assertContains('sdc.byte_theme.heading', $components);
    $this->assertContains('sdc.byte_theme.button', $components);
    $this->assertContains('sdc.byte_theme.card-icon', $components);
    $this->assertGreaterThanOrEqual(5, count($components));
  }

  /**
   * Performance regression: individual matches must complete under 50ms each.
   *
   * @covers ::match
   */
  public function testIndividualMatchLatencyUnder50ms(): void {
    $cases = [
      ['change the heading to Welcome', 'sdc.byte_theme.heading', NULL],
      ['heading: New Title', 'sdc.byte_theme.heading', NULL],
      ['change the heading to Hi and set the color to blue', 'sdc.byte_theme.heading', NULL],
      ['blue', 'sdc.byte_theme.heading', NULL],
      ['show the header', 'sdc.byte_theme.section', NULL],
      ['bigger', 'sdc.byte_theme.heading', ['text_size' => 'heading-responsive-5xl', 'text_color' => 'default']],
    ];

    foreach ($cases as [$message, $component, $currentValues]) {
      $start = microtime(TRUE);
      $this->matcher->match($message, $component, $currentValues);
      $elapsed = (microtime(TRUE) - $start) * 1000;
      $this->assertLessThan(50.0, $elapsed, "Match for \"$message\" took {$elapsed}ms (budget: 50ms)");
    }
  }

}
