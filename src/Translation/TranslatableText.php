<?php

declare(strict_types=1);

namespace Drupal\canvas\Translation;

/**
 * One translatable string discovered in a component tree.
 *
 * The Canvas-owned, translation-system-agnostic unit of translation: a single
 * author-entered string of a component instance's input, with enough context to
 * translate it well. Consumers (e.g. an editor UI, a machine translator) read
 * the source value plus its metadata, and submit a translation as a
 * TranslatedText — most easily via ::createTranslation(), which copies the
 * identity so it cannot be mismatched.
 *
 * Identity is explicit: the component instance's UUID plus the prop path
 * within its inputs. It is stable across structural edits (a UUID does not
 * change when components are reordered), so a translation collected against
 * one revision still lands on the right component instance when applied to
 * another.
 *
 * @see \Drupal\canvas\Translation\TranslatedText
 * @see \Drupal\canvas\Translation\ComponentTreeTranslator
 *
 * @internal
 */
final readonly class TranslatableText {

  /**
   * @param string $componentInstanceUuid
   *   UUID of the component instance this string belongs to.
   * @param array $propPath
   *   Structured path to the value within the instance's inputs, e.g. ['text'],
   *   ['href', 'uri'], ['tags', 0]. A list<string|int>.
   * @param string $label
   *   Human-readable label for this string (the component's prop title).
   * @param string $sourceValue
   *   The source-language text. The empty string for an optional prop that is
   *   translatable but unpopulated in the source.
   * @param string|null $format
   *   Text format machine name (e.g. 'full_html') when the value is rich text,
   *   or NULL for plain text.
   */
  public function __construct(
    public string $componentInstanceUuid,
    public array $propPath,
    public string $label,
    public string $sourceValue,
    public ?string $format = NULL,
  ) {}

  /**
   * Creates the TranslatedText that translates this string.
   *
   * @param string $value
   *   The translated text.
   */
  public function createTranslation(string $value): TranslatedText {
    return new TranslatedText($this->componentInstanceUuid, $this->propPath, $value);
  }

}
