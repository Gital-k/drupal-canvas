<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Adapter;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[Adapter(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('UNIX timestamp to date-time'),
  inputs: [
    'unix' => ['type' => 'integer'],
  ],
  requiredInputs: ['unix'],
  output: ['type' => 'string', 'format' => 'date-time'],
)]
final class UnixTimestampToDateTimeAdapter extends AdapterBase {

  public const string PLUGIN_ID = 'unix_to_date_time';

  protected int $unix;

  public function adapt(): EvaluationResult {
    $datetime = new \DateTimeImmutable('@' . $this->unix);
    return new EvaluationResult(
      $datetime
        ->setTimezone(new \DateTimeZone('UTC'))
        ->format('Y-m-d\TH:i:s.v\Z')
    );
  }

}
