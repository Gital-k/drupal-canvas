<?php

namespace Drupal\canvas_ai;

/**
 * A style guide generator.
 */
class StyleGuideGenerator
{
  /**
   * @var array An array holding the parsed component definitions.
   */
  private array $components;

  /**
   * @var array An array to store the extracted style guide rules.
   */
  private array $styleGuideRules = [
    'preamble' => [
      'title' => '#Component Generation Guide',
      'introduction' => "You are an expert React component generator. Your task is to create new components based on a user's request. You MUST adhere strictly to the principles, conventions, and examples provided in this guide to ensure all generated components are consistent with the existing design system.",
    ],
    'principles' => [
      'Component-Based Architecture: The UI is built from self-contained, reusable components defined in JSX.',
      'Utility-First CSS: Styling is primarily achieved using Tailwind CSS utility classes directly within the JSX. This is the preferred method for layout, spacing, and styling.',
      'Semantic & Accessible HTML: Markup must be meaningful and adhere to accessibility standards (e.g., `alt` text for images, `<label>` for forms, correct use of `<nav>`, `<button>`, etc.).',
      'Structured Component API: Component properties (`props`) must be clearly defined with types, defaults, examples, and JSON schemas. Variants and states are managed via props.',
    ],
    'tooling' => [
      '**React/JSX:** All components are written in JSX.',
      '**Tailwind CSS:** A utility-first CSS framework used for all styling. Do not write separate CSS files.',
      '**`class-variance-authority` (`cva`):** Used to manage component variants and styles based on props (e.g., button variants, heading sizes).',
      '**`@/lib/utils/cn`:** A utility function to conditionally combine CSS classes. Always use `cn()` when applying classes that might be combined with external class names or variants from `cva`.',
    ],
    'html' => [
      'rules' => [
        'Use semantic tags (`<nav>`, `<footer>`, `<button>`) over generic `<div>`s where appropriate.',
        'All form inputs MUST have a corresponding `<label>` or an `aria-label`.',
      ],
    ],
    'javascript' => [
      'rules' => [
        'Decouple logic from styling. Use `data-*` attributes for JavaScript hooks if necessary, rather than relying on CSS classes.',
        'Use React hooks (`useState`, `useEffect`) for managing state and side effects.',
      ]
    ],
    'component_library' => [],
  ];

  /**
   * Constructor.
   *
   */
  public function __construct()
  { }

  /**
   * Analyzes the components and builds the style guide rules.
   */
  public function analyze($jsonString): void
  {
    $decodedJson = json_decode($jsonString, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \InvalidArgumentException('Invalid JSON provided: ' . json_last_error_msg());
    }
    $this->components = $decodedJson;
    $componentIds = array_keys($this->components);

    foreach ($this->components as $id => $component) {
      $this->analyzeComponentDetails($id, $component, $componentIds);
    }
  }

  /**
   * Analyzes and stores the full details of a component for the reference library.
   *
   * @param string $id The component machine name.
   * @param array $component The component definition.
   * @param array $allComponentIds A list of all available component machine names.
   */
  private function analyzeComponentDetails(string $id, array $component, array $allComponentIds): void
  {
    $sourceCode = $component['sourceCodeJs'] ?? '';
    // Clean the source code for better LLM parsing (remove trailing commas, etc.)
    $cleanedSourceCode = rtrim(trim($sourceCode), ',');

    $details = [
      'name' => $component['name'] ?? $id,
      'props_table' => 'This component has no configurable properties.',
      'slots' => 'This component does not define any slots.',
      'composition' => 'This component is self-contained and does not use other library components.',
      'sourceCodeJs' => $cleanedSourceCode ?: '// No source code available.',
    ];

    // Analyze Props with more detail
    if (!empty($component['props'])) {
      $propsTable = "| Prop Name    | Type   | Required | Default | Description & Examples |\n";
      $propsTable .= "|:-------------|:-------|:---------|:--------|:-----------------------|\n";
      $requiredProps = $component['required'] ?? [];

      foreach ($component['props'] as $propName => $propDetails) {
        $type = $propDetails['type'] ?? 'N/A';
        if (!empty($propDetails['enum'])) {
          $type = 'enum';
        }
        $required = in_array($propName, $requiredProps) ? 'Yes' : 'No';
        $description = $propDetails['title'] ?? 'No description.';

        // Extract default value from source code if possible
        $default = '`N/A`';
        if (preg_match("/{$propName}\s*=\s*['\"]([^'\"]+)['\"]/", $sourceCode, $matches)) {
          $default = "`\"{$matches[1]}\"`";
        } elseif (preg_match("/{$propName}\s*=\s*(\w+)/", $sourceCode, $matches) && !in_array($matches[1], ['true', 'false', 'null'])) {
          // handles cases like height = "Small"
        }  elseif (preg_match("/{$propName}\s*=\s*([true|false|null|\d]+)/", $sourceCode, $matches)) {
          $default = "`{$matches[1]}`";
        }


        $examples = '';
        if (!empty($propDetails['examples'])) {
          $exampleValues = array_map(fn($ex) => is_array($ex) ? json_encode($ex) : $ex, $propDetails['examples']);
          $examples = ' Example: `' . implode('`, `', $exampleValues) . '`';
        }

        if ($type === 'enum') {
          $description .= ' (Options: `' . implode('`, `', $propDetails['enum']) . '`)';
        }

        $propsTable .= "| `{$propName}` | `{$type}` | {$required} | {$default} | {$description}{$examples} |\n";
      }
      $details['props_table'] = $propsTable;
    }

    // Analyze Slots
    if (!empty($component['slots'])) {
      $slotsList = "This component accepts content in the following slots:\n";
      foreach ($component['slots'] as $slotName => $slotDetails) {
        $title = $slotDetails['title'] ?? $slotName;
        $slotsList .= "- **`{$slotName}`**: {$title}\n";
      }
      $details['slots'] = $slotsList;
    }

    // Analyze Composition
    $usedComponents = [];
    foreach ($allComponentIds as $componentId) {
      // Look for usage like: <Component... or import Component from '@/components/component_name'
      $pascalCaseComponent = str_replace('_', '', ucwords($componentId, '_'));
      if (preg_match("/<{$pascalCaseComponent}|from\s*['\"]@[^'\"]+\/{$componentId}['\"]/", $sourceCode)) {
        if ($id !== $componentId) {
          $usedComponents[] = "`{$componentId}`";
        }
      }
    }

    if (!empty($usedComponents)) {
      $details['composition'] = 'This component uses the following library components: ' . implode(', ', $usedComponents) . '.';
    }

    $this->styleGuideRules['component_library'][$id] = $details;
  }

  /**
   * Generates the final Markdown output.
   *
   * @return string The style guide in Markdown format.
   */
  public function generateMarkdown(): string
  {
    $markdown = $this->styleGuideRules['preamble']['title'] . "\n\n";
    $markdown .= $this->styleGuideRules['preamble']['introduction'] . "\n\n";

    $markdown .= "## 1. Guiding Principles\n\n";
    foreach ($this->styleGuideRules['principles'] as $principle) {
      $markdown .= "- **" . $principle . "**\n";
    }
    $markdown .= "\n";

    $markdown .= "## 2. Tooling & Libraries\n\n";
    $markdown .= "You MUST use the following stack for component development:\n\n";
    foreach ($this->styleGuideRules['tooling'] as $tool) {
      $markdown .= "- {$tool}\n";
    }
    $markdown .= "\n";

    $markdown .= "## 4. HTML, JSX & JS Guidelines\n\n";
    $markdown .= "**HTML & JSX:**\n";
    foreach ($this->styleGuideRules['html']['rules'] as $rule) {
      $markdown .= "- {$rule}\n";
    }
    $markdown .= "\n**JavaScript:**\n";
    foreach ($this->styleGuideRules['javascript']['rules'] as $rule) {
      $markdown .= "- {$rule}\n";
    }
    $markdown .= "\n";

    $markdown .= "## 5. Component Reference Library\n\n";
    $markdown .= "The following components serve as the ground truth for our design system. Study their props, structure, composition, and source code carefully before generating new components.\n\n";

    ksort($this->styleGuideRules['component_library']); // Sort components alphabetically

    foreach ($this->styleGuideRules['component_library'] as $id => $details) {
      $markdown .= "---\n\n";
      $markdown .= "### Component: `{$id}` (\"{$details['name']}\")\n\n";

      $markdown .= "**Props Definition**\n";
      $markdown .= $details['props_table'] . "\n";

      $markdown .= "**Slots**\n";
      $markdown .= $details['slots'] . "\n\n";

      $markdown .= "**Composition**\n";
      $markdown .= $details['composition'] . "\n\n";

      $markdown .= "**Source Code (`{$id}.jsx`)**\n";
      $markdown .= "```jsx\n";
      $markdown .= $details['sourceCodeJs'] . "\n";
      $markdown .= "```\n\n";
    }

    $markdown .= "---\n\n## 6. Your Task\n\n";
    $markdown .= "Now, using the rules and examples above, please fulfill the following request. Ensure the output is a single, complete JSX file that is ready to be used.\n";

    return $markdown;
  }
}

?>
