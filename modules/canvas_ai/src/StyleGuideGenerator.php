<?php

namespace Drupal\canvas_ai;

/**
 * A style guide generator.
 */
class StyleGuideGenerator {
  /**
   * @var array An array to store the extracted style guide rules.
   */
  private array $components;

  /**
   * @var array An array to store the extracted style guide rules.
   */
  private array $styleGuideRules = [
    'css' => [
      'utility_classes' => [
        'prefix' => null,
        'description' => 'Used for applying design tokens directly in HTML for layout, spacing, sizing, and colors.',
        'examples' => [],
      ],
      'component_classes' => [
        'description' => 'Define the core, non-negotiable styles of a specific component. Follow a BEM-like or kebab-case convention.',
        'examples' => [],
      ],
      'state_classes' => [
        'description' => 'Reflect a component\'s state or variant, often based on a prop. Typically uses a `--` modifier.',
        'examples' => [],
      ],
      'categories' => [
        'colors' => [
          'description' => 'Classes related to colors including text, background, borders.',
          'examples' => [],
        ],
        'typography' => [
          'description' => 'Classes controlling font size, weight, line height, and text styles.',
          'examples' => [],
        ],
        'spacing' => [
          'description' => 'Classes controlling margin and padding.',
          'examples' => [],
        ],
        'layouts' => [
          'description' => 'Classes related to layout including grid, flex, block behaviors.',
          'examples' => [],
        ],
        'iconography' => [
          'description' => 'Classes related to icon sizing, positioning, and styling.',
          'examples' => [],
        ],
        'states' => [
          'description' => 'Classes used for UI states like hover, focus, disabled, active.',
          'examples' => [],
        ],
      ],
    ],
  ];

  /**
   * Sets the global CSS content to be included in the style guide.
   *
   * @param string $css
   * The global CSS as a string.
   */
  public function setGlobalCSS(string $css): void {
    $this->styleGuideRules['globalCSS'] = $css;
  }

  /**
   * Analyzes a JSON string containing component data.
   *
   * This method decodes the JSON, iterates through each component,
   * and calls the CSS analyzer to extract style information. It then
   * deduplicates the findings.
   *
   * @param string $jsonString
   * A JSON string representing an array of components.
   *
   * @throws \InvalidArgumentException
   * If the provided string is not valid JSON.
   */
  public function analyze(string $jsonString): void {
    $decodedJson = json_decode($jsonString, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \InvalidArgumentException('Invalid JSON provided: ' . json_last_error_msg());
    }

    $this->components = $decodedJson;

    foreach ($this->components as $component) {
      $this->analyzeCss($component);
    }

    // Deduplicate examples in all categories.
    foreach ($this->styleGuideRules['css'] as $key => &$category) {
      if (is_array($category) && isset($category['examples'])) {
        $category['examples'] = array_values(array_unique($category['examples']));
      }
      if ($key === 'categories') {
        foreach ($category as &$subcat) {
          $subcat['examples'] = array_values(array_unique($subcat['examples']));
        }
      }
    }
  }

  /**
   * Analyzes the CSS classes from a single component's source code.
   *
   * Extracts and categorizes CSS classes from the component's JavaScript
   * source code and populates the style guide rules.
   *
   * @param array $component
   * An associative array representing a single component, including its
   * 'sourceCodeJs' and 'sourceCodeCss'.
   */
  private function analyzeCss(array $component): void {
    if (empty($component['sourceCodeJs'])) {
      return;
    }

    $js = $component['sourceCodeJs'];
    $js = preg_replace('/([\'"])data:[^\'"]+\1/', "''", $js);
    $this->styleGuideRules['components'][] = $component['machineName'];
    $this->styleGuideRules[$component['machineName']]['js'] = $js;

    // Add custom CSS for components.
    $this->styleGuideRules[$component['machineName']]['customCss'] = $component['sourceCodeCss'];

    preg_match_all('/([\'"])(.*?)\1/', $js, $stringMatches);
    preg_match_all('/([A-Za-z0-9_]+)\s*:\s*([\'"])(.*?)\2/', $js, $varMatches);

    $classesRaw = [];

    foreach ($stringMatches[2] as $match) {
      $classesRaw = array_merge($classesRaw, preg_split('/\s+/', $match));
    }

    foreach ($varMatches[3] as $match) {
      $classesRaw = array_merge($classesRaw, preg_split('/\s+/', $match));
    }

    $classesRaw = array_filter(array_unique(array_map('trim', $classesRaw)));

    foreach ($classesRaw as $class) {
      if (empty($class)) {
        continue;
      }

      // Utility classes (prefix present)
      if (strpos($class, ':') !== FALSE) {
        $prefix = substr($class, 0, strpos($class, ':'));
        if (is_null($this->styleGuideRules['css']['utility_classes']['prefix'])) {
          $this->styleGuideRules['css']['utility_classes']['prefix'] = $prefix;
        }
        $this->styleGuideRules['css']['utility_classes']['examples'][] = "`{$class}`";
      }
      // State/Modifier classes (contains --)
      if (strpos($class, '--') !== FALSE) {
        $this->styleGuideRules['css']['state_classes']['examples'][] = "`{$class}`";
        $this->styleGuideRules['css']['categories']['states']['examples'][] = "`{$class}`";
        continue;
      }

      // Further categorize by semantic meaning (tailwind-inspired examples)
      if (preg_match('/^(bg|text|border|from|to|via)-/', $class)) {
        $this->styleGuideRules['css']['categories']['colors']['examples'][] = "`{$class}`";
      }
      elseif (preg_match('/^(text|font|tracking|leading|capitalize|uppercase|lowercase|italic|not-italic|underline|line-through)/', $class)) {
        $this->styleGuideRules['css']['categories']['typography']['examples'][] = "`{$class}`";
      }
      elseif (preg_match('/^(m|p|mt|mb|ml|mr|mx|my|pt|pb|pl|pr|px|py)-/', $class)) {
        $this->styleGuideRules['css']['categories']['spacing']['examples'][] = "`{$class}`";
      }
      elseif (preg_match('/^(grid|col|row|flex|block|inline|hidden|container|gap|justify|items|align|place|overflow|z-)/', $class)) {
        $this->styleGuideRules['css']['categories']['layouts']['examples'][] = "`{$class}`";
      }
      elseif (preg_match('/^(icon|svg|fill|stroke|w-|h-|size|rotate|transform)/', $class)) {
        $this->styleGuideRules['css']['categories']['iconography']['examples'][] = "`{$class}`";
      }
      else {
        // Default to component classes if no match
        $this->styleGuideRules['css']['component_classes']['examples'][] = "`{$class}`";
      }
    }
  }

  /**
   * Generates the final Markdown output.
   *
   * @return string
   * The style guide in Markdown format.
   */
  public function generateMarkdown(): string {
    $markdown = "# Component Style Guide\n\n";
    $markdown .= "This document outlines the principles and conventions for creating components within the design system. Adhere to these rules to ensure consistency, scalability, and maintainability.\n\n";
    $sectionCounter = 1;

    // Section: Source Code for selected components.
    if (!empty($this->styleGuideRules['components'])) {
      $sourceCodeContent = '';
      foreach ($this->styleGuideRules['components'] as $component) {
        if (!empty($this->styleGuideRules[$component]['js'])) {
          $sourceCodeContent .= "**Source Code for (`{$component}.jsx`) **\n";
          $sourceCodeContent .= "```jsx\n";
          $sourceCodeContent .= $this->styleGuideRules[$component]['js'] . "\n";
          $sourceCodeContent .= "```\n\n";
        }
      }
      if (!empty($sourceCodeContent)) {
        $markdown .= "## " . $sectionCounter++ . ". Source Code for Selected Components\n\n";
        $markdown .= $sourceCodeContent;
      }
    }

    // Section: Custom CSS for the selected component.
    if (!empty($this->styleGuideRules['components'])) {
      $customCssContent = '';
      foreach ($this->styleGuideRules['components'] as $component) {
        if (!empty($this->styleGuideRules[$component]['customCss'])) {
          $customCssContent .= "**CSS for (`{$component}.jsx`)**\n";
          $customCssContent .= "```css\n";
          $customCssContent .= $this->styleGuideRules[$component]['customCss'] . "\n";
          $customCssContent .= "```\n\n";
        }
      }
      if (!empty($customCssContent)) {
        $markdown .= "## " . $sectionCounter++ . ". Custom CSS for Selected Components\n\n";
        $markdown .= $customCssContent;
      }
    }

    // Section: Global CSS.
    if (!empty($this->styleGuideRules['globalCSS'])) {
      $markdown .= "## " . $sectionCounter++ . ". Global CSS\n\n";
      $markdown .= "```css\n";
      $markdown .= $this->styleGuideRules['globalCSS'] . "\n";
      $markdown .= "```\n\n";
    }

    // Section: CSS Guidelines.
    $cssGuidelinesContent = '';

    // Utility-First Classes.
    $utilityClasses = $this->styleGuideRules['css']['utility_classes'];
    if (!empty($utilityClasses['examples'])) {
      $utilityPrefix = $utilityClasses['prefix'] ?? 'none';
      $cssGuidelinesContent .= "### Utility-First Classes (Prefix: `" . ($utilityPrefix ? $utilityPrefix . ':' : 'none') . "`)\n\n";
      $cssGuidelinesContent .= $utilityClasses['description'] . "\n";
      $cssGuidelinesContent .= "**Examples:** " . implode(', ', $utilityClasses['examples']) . "\n\n";
    }

    // Component Classes.
    $componentClasses = $this->styleGuideRules['css']['component_classes'];
    if (!empty($componentClasses['examples'])) {
      $cssGuidelinesContent .= "### Component Classes\n\n";
      $cssGuidelinesContent .= $componentClasses['description'] . "\n";
      $cssGuidelinesContent .= "**Examples:** " . implode(', ', $componentClasses['examples']) . "\n\n";
    }

    // State & Modifier Classes.
    $stateClasses = $this->styleGuideRules['css']['state_classes'];
    if (!empty($stateClasses['examples'])) {
      $cssGuidelinesContent .= "### State & Modifier Classes\n\n";
      $cssGuidelinesContent .= $stateClasses['description'] . "\n";
      $cssGuidelinesContent .= "**Examples:** " . implode(', ', $stateClasses['examples']) . "\n\n";
    }

    // CSS Class Categories.
    $categoriesContent = '';
    foreach ($this->styleGuideRules['css']['categories'] as $categoryName => $categoryData) {
      if (!empty($categoryData['examples'])) {
        $categoriesContent .= "#### " . ucfirst($categoryName) . "\n\n";
        $categoriesContent .= $categoryData['description'] . "\n";
        $categoriesContent .= "**Examples:** " . implode(', ', $categoryData['examples']) . "\n\n";
      }
    }

    if (!empty($categoriesContent)) {
      $cssGuidelinesContent .= "### CSS Class Categories for LLM Guidance\n\n";
      $cssGuidelinesContent .= $categoriesContent;
      $cssGuidelinesContent .= "Use these categories to decide which classes to apply when generating new components. For example, use **Colors** classes for color styling, **Typography** for text styles, **Spacing** for margins and padding, **Layouts** for positioning, **Iconography** for icons, and **States** for interactive or conditional styles.\n";
    }

    if (!empty($cssGuidelinesContent)) {
      $markdown .= "## " . $sectionCounter++ . ". CSS Guidelines\n\n";
      $markdown .= "A hybrid approach using utility, component, and state classes is employed.\n\n";
      $markdown .= $cssGuidelinesContent;
    }

    return rtrim($markdown) . "\n";
  }
}

?>
