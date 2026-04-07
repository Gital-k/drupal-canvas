<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Matches user messages against deterministic edit patterns.
 *
 * When a component is selected and the user's message matches a pattern
 * like "change the heading to X" or "set the color to primary", this service
 * extracts the prop name and value without invoking the LLM agent chain.
 *
 * Only matches unambiguous, single-prop edits. Returns NULL for anything
 * that requires LLM reasoning (multi-prop changes, ambiguous references,
 * content generation, add/remove operations).
 *
 * Prop aliases and enum value maps are loaded dynamically from the active
 * default theme's SDC component YAML schemas via ComponentSchemaLoader.
 *
 * @internal
 *   This service is for internal use by canvas_ai only.
 */
final class DirectEditMatcher {

  /**
   * Markers used to conservatively split compound deterministic edits.
   */
  private const COMPOUND_DELIMITER = "\n__DIRECT_EDIT_SPLIT__\n";

  /**
   * Compound split patterns.
   *
   * Only split when the next fragment begins with an edit verb to avoid
   * splitting ordinary text values like "apples and oranges".
   */
  private const COMPOUND_SPLIT_PATTERNS = [
    '/,\s*(?:and\s+)?(?=(?:change|set|update|modify|make|turn|switch|put)\b)/i',
    '/;\s*(?=(?:change|set|update|modify|make|turn|switch|put)\b)/i',
    '/\s+(?:and|also|plus|then)\s+(?=(?:change|set|update|modify|make|turn|switch|put)\b)/i',
  ];

  /**
   * Keywords that indicate the user wants to ADD or CREATE — not a simple edit.
   *
   * Note: "make" is intentionally excluded. It's a common edit verb
   * ("make it blue", "make this bigger") and single-word blocking would
   * reject valid edits. Add-intent with "make" is caught by the phrase
   * patterns in ADD_PHRASES instead.
   */
  private const ADD_KEYWORDS = [
    'add', 'create', 'insert', 'generate', 'build',
    'another', 'below', 'above', 'after', 'before',
  ];

  /**
   * Phrase patterns that indicate add/create intent.
   *
   * These catch phrases where context-dependent words ("make", "new")
   * indicate creation rather than editing. Single-word blocking would
   * reject valid edits like "make it blue" or "heading: New Title".
   */
  private const ADD_PHRASES = [
    '/\bmake\s+(?:a|me|us)\s+(?:new\b|another\b)/i',
    '/\bmake\s+(?:a|an|some)\b/i',
    '/\b(?:a|an)\s+new\b/i',
    '/\bnew\s+(?:section|component|card|heading|button|image|row|column|block)\b/i',
  ];

  /**
   * Comparative adjective map for relative adjustments.
   *
   * Maps adjective stems to direction (+1 = next in ordinal, -1 = previous).
   */
  private const RELATIVE_ADJECTIVES = [
    'bigger' => +1,
    'larger' => +1,
    'smaller' => -1,
    'tinier' => -1,
    'bolder' => +1,
    'lighter' => -1,
    'darker' => +1,
  ];

  /**
   * Maps relative adjective categories to which prop types they target.
   *
   * When a user says "bigger", we need to know which prop to adjust.
   * This maps adjective stems to the prop name categories they apply to.
   */
  private const RELATIVE_PROP_CATEGORIES = [
    'bigger' => ['text_size', 'size', 'icon_size', 'tile_size', 'image_size'],
    'larger' => ['text_size', 'size', 'icon_size', 'tile_size', 'image_size'],
    'smaller' => ['text_size', 'size', 'icon_size', 'tile_size', 'image_size'],
    'tinier' => ['text_size', 'size', 'icon_size', 'tile_size', 'image_size'],
    'bolder' => ['text_size'],
    'lighter' => ['text_color', 'background_color'],
    'darker' => ['text_color', 'background_color'],
  ];

  /**
   * Constructs a DirectEditMatcher.
   *
   * @param \Drupal\canvas_ai\Service\ComponentSchemaLoaderInterface $schemaLoader
   *   The component schema loader, providing dynamic prop alias and enum maps.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, used to read the configurable edit verb list from
   *   canvas_ai.direct_edit.settings.
   */
  public function __construct(
    private readonly ComponentSchemaLoaderInterface $schemaLoader,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Attempts to match a user message to a deterministic prop edit.
   *
   * @param string $message
   *   The user's chat message.
   * @param string $componentName
   *   The SDC component name (e.g., 'sdc.theme_name.heading').
   * @param array|null $currentPropValues
   *   Current prop values for the selected component, keyed by prop name.
   *   Needed for relative adjustments. NULL if unavailable.
   *
   * @return array{prop: string, value: mixed}|array{changes: array<int, array{prop: string, value: mixed}>}|null
   *   A single matched prop change, a list of matched changes for a compound
   *   deterministic edit, or NULL if no deterministic match.
   */
  public function match(string $message, string $componentName, ?array $currentPropValues = NULL): ?array {
    $message = trim($message);
    // Deterministic edit commands are short. Messages beyond 500 chars are
    // almost certainly content generation or multi-paragraph instructions
    // that need LLM reasoning. This limit is intentionally lower than the
    // controller's 2000-char validation to fast-reject verbose messages
    // before running regex patterns.
    if ($message === '' || mb_strlen($message) > 500) {
      return NULL;
    }

    $fragments = $this->splitCompoundMessage($message);
    if (count($fragments) > 1) {
      /** @var list<array{prop: string, value: mixed}> $changes */
      $changes = [];
      foreach ($fragments as $fragment) {
        $result = $this->matchSingle($fragment, $componentName, $currentPropValues);
        if ($result === NULL) {
          return NULL;
        }
        $changes[] = $result;
      }

      $props = array_column($changes, 'prop');
      if (count($props) !== count(array_unique($props))) {
        return NULL;
      }

      return ['changes' => $changes];
    }

    return $this->matchSingle($message, $componentName, $currentPropValues);
  }

  /**
   * Returns a regex alternation of recognized edit verbs.
   *
   * Reads from canvas_ai.direct_edit.settings config so site builders can
   * extend or replace the verb list for non-English deployments without
   * patching.
   */
  private function getEditVerbPattern(): string {
    $config = $this->configFactory->get('canvas_ai.direct_edit.settings');
    $verbs = $config->get('edit_verbs');
    if (!is_array($verbs) || empty($verbs)) {
      $verbs = ['change', 'set', 'update', 'modify', 'make', 'turn', 'switch', 'put'];
    }
    return implode('|', array_map(static fn(string $v): string => preg_quote($v, '/'), $verbs));
  }

  /**
   * Attempts to match a single deterministic prop edit.
   *
   * @return array{prop: string, value: mixed}|null
   *   Resolved prop and value, or NULL if no deterministic match.
   */
  private function matchSingle(string $message, string $componentName, ?array $currentPropValues = NULL): ?array {
    // Reject if the message contains add/create keywords or phrases.
    $messageLower = mb_strtolower($message);
    foreach (self::ADD_KEYWORDS as $keyword) {
      // Match as whole word to avoid false positives
      // (e.g., "address" contains "add").
      if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $messageLower)) {
        return NULL;
      }
    }
    foreach (self::ADD_PHRASES as $pattern) {
      if (preg_match($pattern, $messageLower)) {
        return NULL;
      }
    }

    // Try to match "change/set/update X to Y" patterns (Tier 1).
    $verbPattern = $this->getEditVerbPattern();
    $patterns = [
      // "change/turn/switch the heading to New Title".
      '/(?:' . $verbPattern . ')\s+(?:the\s+)?(.+?)\s+to\s+["\']?(.+?)["\']?\s*$/i',
      // "heading: New Title".
      '/^(.+?):\s+["\']?(.+?)["\']?\s*$/i',
      // "set X = Y".
      '/(?:set|change)\s+(.+?)\s*=\s*["\']?(.+?)["\']?\s*$/i',
    ];

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $message, $matches)) {
        $propAlias = trim(mb_strtolower($matches[1]));
        $value = trim($matches[2]);

        $result = $this->resolveEdit($propAlias, $value, $componentName);
        if ($result !== NULL) {
          return $result;
        }
      }
    }

    // Phase 1: Bare value type inference.
    // If the message is a bare value or "make it/this {value}", attempt to
    // resolve by scanning all enum props on the component. Only resolves
    // when exactly one prop accepts the value (unambiguous).
    $result = $this->matchBareValue($messageLower, $componentName);
    if ($result !== NULL) {
      return $result;
    }

    // Phase 2: Boolean toggle patterns.
    // "show the header", "hide the footer", "enable overlap", "disable it".
    $result = $this->matchBooleanToggle($messageLower, $componentName);
    if ($result !== NULL) {
      return $result;
    }

    // Phase 2b: Reset/clear/remove patterns.
    // "reset the color", "clear the link", "remove the icon".
    $result = $this->matchResetPattern($messageLower, $componentName);
    if ($result !== NULL) {
      return $result;
    }

    // Phase 3: Relative adjustments.
    // "bigger", "smaller", "make it bigger" — navigate enum ordinals.
    // Requires current prop values to know which direction to move.
    if ($currentPropValues !== NULL) {
      $result = $this->matchRelativeAdjustment($messageLower, $componentName, $currentPropValues);
      if ($result !== NULL) {
        return $result;
      }
    }

    return NULL;
  }

  /**
   * Matches relative adjustment patterns (bigger/smaller/lighter/darker).
   *
   * Navigates enum ordinals based on the current prop value. Direction is
   * determined by the adjective and the enum's ascending/descending metadata.
   *
   * @param string $messageLower
   *   Lowercased, trimmed user message.
   * @param string $componentName
   *   The SDC component name.
   * @param array $currentPropValues
   *   Current prop values keyed by prop name.
   *
   * @return array{prop: string, value: mixed}|null
   *   Resolved prop and new value, or NULL if no match.
   */
  private function matchRelativeAdjustment(string $messageLower, string $componentName, array $currentPropValues): ?array {
    // Strip "make it/this/the" prefix.
    $stripped = preg_replace('/^(?:make\s+(?:it|this|the)\s+)/i', '', $messageLower) ?? '';
    $stripped = trim($stripped);

    // Check if the (possibly stripped) message is a known comparative.
    $direction = self::RELATIVE_ADJECTIVES[$stripped] ?? NULL;
    if ($direction === NULL) {
      return NULL;
    }

    // Find which prop categories this adjective targets.
    $targetProps = self::RELATIVE_PROP_CATEGORIES[$stripped] ?? [];
    if (empty($targetProps)) {
      return NULL;
    }

    // Get the ordinals for this component.
    $ordinals = $this->schemaLoader->getEnumOrdinals($componentName);
    if (empty($ordinals)) {
      return NULL;
    }

    // Find a matching prop: must be in the target category AND have a current
    // value.
    $matchedProp = NULL;
    $matchedOrdinal = NULL;
    foreach ($targetProps as $propName) {
      if (isset($ordinals[$propName]) && array_key_exists($propName, $currentPropValues)) {
        if ($matchedProp !== NULL) {
          // Ambiguous: multiple target props exist on this component.
          return NULL;
        }
        $matchedProp = $propName;
        $matchedOrdinal = $ordinals[$propName];
      }
    }

    if ($matchedProp === NULL || $matchedOrdinal === NULL) {
      return NULL;
    }

    $values = $matchedOrdinal['values'] ?? [];
    $ordinalDirection = $matchedOrdinal['direction'] ?? 'ascending';
    $currentValue = $currentPropValues[$matchedProp];

    // Find current position in the ordinal sequence.
    $currentIndex = array_search($currentValue, $values, TRUE);
    if ($currentIndex === FALSE) {
      return NULL;
    }

    // For descending ordinals (e.g., text_size: 8xl first = biggest),
    // "bigger" means moving toward index 0 (lower index = bigger).
    // For ascending ordinals (e.g., button size: small first),
    // "bigger" means moving toward higher index.
    $step = $direction;
    if ($ordinalDirection === 'descending') {
      $step = -$direction;
    }

    $newIndex = $currentIndex + $step;

    // Skip the 'default' value in ordinal navigation — it's a reset,
    // not a position in the scale.
    if (isset($values[$newIndex]) && $values[$newIndex] === 'default') {
      $newIndex += $step;
    }

    if ($newIndex < 0 || $newIndex >= count($values)) {
      // At boundary — can't go further. Reject.
      return NULL;
    }

    return ['prop' => $matchedProp, 'value' => $values[$newIndex]];
  }

  /**
   * Matches boolean toggle patterns (show/hide/enable/disable).
   *
   * @param string $messageLower
   *   Lowercased, trimmed user message.
   * @param string $componentName
   *   The SDC component name.
   *
   * @return array{prop: string, value: bool}|null
   *   Resolved prop and boolean value, or NULL if no match.
   */
  private function matchBooleanToggle(string $messageLower, string $componentName): ?array {
    $booleanProps = $this->schemaLoader->getBooleanProps($componentName);
    if (empty($booleanProps)) {
      return NULL;
    }

    // Match toggle verb patterns.
    // Group 1: verb (determines true/false)
    // Group 2: optional "the" article
    // Group 3: the prop reference.
    $pattern = '/^(show|hide|enable|disable|turn\s+on|turn\s+off|activate|deactivate)\s+(?:the\s+)?(.+?)\s*$/i';
    if (!preg_match($pattern, $messageLower, $matches)) {
      return NULL;
    }

    $verb = mb_strtolower(trim($matches[1]));
    $propRef = mb_strtolower(trim($matches[2]));

    // Determine intent from verb.
    $enableVerbs = ['show', 'enable', 'turn on', 'activate'];
    $wantsEnabled = in_array($verb, $enableVerbs, TRUE);

    // Find which boolean prop matches the reference.
    foreach ($booleanProps as $propName => $meta) {
      $aliases = $meta['aliases'] ?? [];
      if (in_array($propRef, $aliases, TRUE) || $propRef === $propName) {
        // Apply polarity inversion (e.g., "enable" on "disabled" = false).
        $inverted = $meta['inverted'] ?? FALSE;
        $value = $inverted ? !$wantsEnabled : $wantsEnabled;
        return ['prop' => $propName, 'value' => $value];
      }
    }

    return NULL;
  }

  /**
   * Attempts to resolve a bare value or "make it/this {value}" pattern.
   *
   * Strips implicit prefixes ("make it", "make this", "make the"),
   * then checks the component's reverse enum index for unambiguous matches.
   *
   * @param string $messageLower
   *   Lowercased, trimmed user message.
   * @param string $componentName
   *   The SDC component name.
   *
   * @return array{prop: string, value: mixed}|null
   *   Resolved prop and value, or NULL if ambiguous or no match.
   */
  private function matchBareValue(string $messageLower, string $componentName): ?array {
    // Strip "make/use it/this/the" prefix to extract the bare value.
    // "make it blue" → "blue", "use this primary" → "primary".
    // Must not match "make a"/"make me" (those are ADD_PHRASES, already rejected).
    $bareValue = preg_replace(
      '/^(?:(?:make|use)\s+(?:it|this|the)\s+)/i',
      '',
      $messageLower
    ) ?? '';
    $bareValue = trim($bareValue);

    if ($bareValue === '' || $bareValue === $messageLower) {
      // If nothing was stripped and the message has multiple words with
      // spaces, it's likely a sentence — don't treat it as a bare value.
      // Single words or hyphenated values (like "extra-large") are fine.
      if (str_contains($messageLower, ' ')) {
        return NULL;
      }
      $bareValue = $messageLower;
    }

    return $this->resolveByTypeInference($bareValue, $componentName);
  }

  /**
   * Resolves a value by scanning the component's reverse enum index.
   *
   * If the value maps to exactly one prop, it's unambiguous — resolve.
   * If it maps to zero or multiple props, reject.
   *
   * @param string $value
   *   Normalized (lowercase, trimmed) value string.
   * @param string $componentName
   *   The SDC component name.
   *
   * @return array{prop: string, value: mixed}|null
   *   Resolved prop and value, or NULL if ambiguous or no match.
   */
  private function resolveByTypeInference(string $value, string $componentName): ?array {
    $reverseIndex = $this->schemaLoader->getReverseEnumIndex($componentName);
    if (empty($reverseIndex)) {
      return NULL;
    }

    $matchingProps = $reverseIndex[$value] ?? [];

    if (count($matchingProps) !== 1) {
      // Check reverse alias index for natural language aliases.
      $aliasIndex = $this->schemaLoader->getReverseAliasIndex($componentName);
      $aliasMatchingProps = $aliasIndex[$value] ?? [];
      if (count($aliasMatchingProps) === 1) {
        $matchingProps = $aliasMatchingProps;
      }
      else {
        // Zero matches (unknown value) or multiple matches (ambiguous) —
        // reject.
        return NULL;
      }
    }

    $propName = $matchingProps[0];

    // Resolve to the canonical enum value via the existing enum map.
    $enumValues = $this->schemaLoader->getEnumValues($propName, $componentName);
    if ($enumValues === NULL) {
      return NULL;
    }

    $canonicalValue = $enumValues[$value] ?? NULL;
    if ($canonicalValue === NULL) {
      return NULL;
    }

    return ['prop' => $propName, 'value' => $canonicalValue];
  }

  /**
   * Splits a compound deterministic edit into fragments.
   *
   * @return string[]
   *   One or more trimmed fragments. A single-fragment result means "do not
   *   treat this as a compound edit".
   */
  private function splitCompoundMessage(string $message): array {
    $normalized = preg_replace(
      self::COMPOUND_SPLIT_PATTERNS,
      self::COMPOUND_DELIMITER,
      $message
    );

    if (!is_string($normalized) || $normalized === $message) {
      return [$message];
    }

    $fragments = array_values(
      array_filter(
        array_map('trim', explode(self::COMPOUND_DELIMITER, $normalized)),
        static fn(string $fragment): bool => $fragment !== ''
      )
    );

    return count($fragments) > 1 ? $fragments : [$message];
  }

  /**
   * Resolves a prop alias and value to a canonical prop edit.
   *
   * @param string $propAlias
   *   The normalized prop alias from the user message.
   * @param string $rawValue
   *   The raw value string from the user message.
   * @param string $componentName
   *   The SDC component name.
   *
   * @return array{prop: string, value: mixed}|null
   *   Resolved prop and value, or NULL if unresolvable.
   */
  private function resolveEdit(string $propAlias, string $rawValue, string $componentName): ?array {
    $aliases = $this->schemaLoader->getPropAliases($componentName);
    if (empty($aliases)) {
      return NULL;
    }

    $propName = $aliases[$propAlias] ?? NULL;
    if ($propName === NULL) {
      return NULL;
    }

    // For integer-typed enum props (e.g., heading level), validate against
    // the schema's actual enum values instead of hardcoded ranges.
    $integerValues = $this->schemaLoader->getIntegerEnumValues($propName, $componentName);
    if ($integerValues !== NULL) {
      $numericValue = (int) $rawValue;
      if ((string) $numericValue === trim($rawValue) && in_array($numericValue, $integerValues, TRUE)) {
        return ['prop' => $propName, 'value' => $numericValue];
      }
      return NULL;
    }

    // If the prop has enum constraints, resolve the value.
    $enumValues = $this->schemaLoader->getEnumValues($propName, $componentName);
    if ($enumValues !== NULL) {
      $normalizedValue = mb_strtolower(trim($rawValue));
      $canonicalValue = $enumValues[$normalizedValue] ?? NULL;
      if ($canonicalValue === NULL) {
        // Value doesn't match any known enum — can't resolve deterministically.
        return NULL;
      }
      return ['prop' => $propName, 'value' => $canonicalValue];
    }

    // For string props (heading_text, label, etc.), accept the raw value.
    return ['prop' => $propName, 'value' => $rawValue];
  }

  /**
   * Matches reset/clear/remove patterns for prop values.
   *
   * "reset the color" → set to first enum value (default).
   * "clear the link" → set string prop to empty string.
   * "remove the icon" → set string prop to empty string.
   *
   * @param string $messageLower
   *   Lowercased, trimmed user message.
   * @param string $componentName
   *   The SDC component name.
   *
   * @return array{prop: string, value: mixed}|null
   *   Resolved prop and reset value, or NULL if no match.
   */
  private function matchResetPattern(string $messageLower, string $componentName): ?array {
    // Match: reset/clear/remove [the] <prop reference>.
    $pattern = '/^(reset|clear|remove)\s+(?:the\s+)?(.+?)\s*$/i';
    if (!preg_match($pattern, $messageLower, $matches)) {
      return NULL;
    }

    $verb = mb_strtolower($matches[1]);
    $propRef = mb_strtolower(trim($matches[2]));

    // Don't match structural operations like "remove this section".
    $structuralWords = ['section', 'component', 'block', 'card', 'element', 'page', 'this'];
    foreach ($structuralWords as $word) {
      if (str_contains($propRef, $word)) {
        return NULL;
      }
    }

    // Resolve the prop reference using aliases.
    $aliases = $this->schemaLoader->getPropAliases($componentName);
    $propName = $aliases[$propRef] ?? NULL;
    if ($propName === NULL) {
      return NULL;
    }

    // For "reset": set to default enum value (first in the list).
    if ($verb === 'reset') {
      $enumValues = $this->schemaLoader->getEnumValues($propName, $componentName);
      if ($enumValues !== NULL) {
        // First value in the enum map is typically 'default'.
        $firstValue = array_values($enumValues)[0] ?? NULL;
        if ($firstValue !== NULL) {
          return ['prop' => $propName, 'value' => $firstValue];
        }
      }
      return NULL;
    }

    // For "clear"/"remove": set string props to empty, reject enum props.
    $enumValues = $this->schemaLoader->getEnumValues($propName, $componentName);
    if ($enumValues !== NULL) {
      // Can't "clear" an enum prop — use "reset" instead.
      return NULL;
    }

    return ['prop' => $propName, 'value' => ''];
  }

  /**
   * Returns the list of component names that support deterministic editing.
   *
   * @return string[]
   *   Component SDC names.
   */
  public function getSupportedComponents(): array {
    return $this->schemaLoader->getSupportedComponents();
  }

}
