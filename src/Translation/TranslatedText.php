<?php

declare(strict_types=1);

namespace Drupal\canvas\Translation;

/**
 * One translated string to write back into a component tree.
 *
 * The write-back counterpart of TranslatableText: it addresses the same
 * component instance UUID + prop path, and carries the translated value.
 * Create it from the extracted TranslatableText via ::createTranslation(), or
 * construct it directly when only the round-tripped identity is at hand (e.g.
 * after a web form submission).
 *
 * @see \Drupal\canvas\Translation\TranslatableText
 * @see \Drupal\canvas\Translation\ComponentTreeTranslator
 *
 * @internal
 */
final readonly class TranslatedText {

  /**
   * @param string $componentInstanceUuid
   *   UUID of the component instance the translation belongs to.
   * @param array $propPath
   *   Structured path to the value within the instance's inputs, matching the
   *   TranslatableText this translates. A list<string|int>.
   * @param string $value
   *   The translated text.
   */
  public function __construct(
    public string $componentInstanceUuid,
    public array $propPath,
    public string $value,
  ) {}

}
