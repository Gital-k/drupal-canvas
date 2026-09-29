<?php

declare(strict_types=1);

namespace Drupal\canvas_test_adapter\Plugin\Adapter;

use Drupal\canvas\Plugin\Adapter\Adapter;
use Drupal\canvas\Plugin\Adapter\AdapterBase;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\canvas_test_adapter\Plugin\Adapter\Deriver\StringCaseDeriver;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Changes the case of a string, with the case determined by the derivative.
 *
 * The base plugin leaves the string untouched; each derivative transforms it.
 * This makes it observable which plugin a prop source resolved to.
 *
 * @see \Drupal\canvas_test_adapter\Plugin\Adapter\Deriver\StringCaseDeriver
 */
#[Adapter(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('String case'),
  inputs: [
    'text' => ['type' => 'string'],
  ],
  requiredInputs: ['text'],
  output: ['type' => 'string'],
  deriver: StringCaseDeriver::class,
)]
final class StringCaseAdapter extends AdapterBase {

  public const string PLUGIN_ID = 'canvas_test_string_case';

  protected string $text;

  public function adapt(): EvaluationResult {
    return new EvaluationResult(match ($this->getDerivativeId()) {
      'upper' => \mb_strtoupper($this->text),
      'lower' => \mb_strtolower($this->text),
      // The base plugin is a no-op.
      default => $this->text,
    });
  }

}
