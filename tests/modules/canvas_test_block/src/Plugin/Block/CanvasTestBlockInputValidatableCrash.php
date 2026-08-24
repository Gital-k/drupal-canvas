<?php

declare(strict_types=1);

namespace Drupal\canvas_test_block\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[Block(
  id: "canvas_test_block_input_validatable_crash",
  admin_label: new TranslatableMarkup("Test Block with settings, crashes when 'crash' setting is TRUE"),
)]
class CanvasTestBlockInputValidatableCrash extends CanvasTestBlockInputUnvalidatable {

  /**
   * When TRUE, this block crashes regardless of its 'crash' setting.
   *
   * This lets a test crash this block using its *default* settings (where
   * 'crash' is FALSE), mirroring a block — such as a views block whose display
   * needs a URL argument — that crashes while building its preview.
   *
   * @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\BlockComponentTest::testCrashingPreviewDoesNotBreakComponentList()
   */
  public static bool $forceCrash = FALSE;

  /**
   * When set, this block crashes with this exception instead of a generic one.
   *
   * This lets a test crash this block with an exception that carries HTTP
   * semantics of its own — mirroring a block whose rendering triggers, say,
   * core's "broken POST request" guard.
   *
   * @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\BlockComponentTest::testHttpExceptionDuringRenderIsNotSwallowed()
   */
  public static ?\Throwable $crashWith = NULL;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'name' => 'Canvas',
      // Do not crash by default 😇
      // @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\ComponentSourceTestBase::testRenderComponentFailure()
      'crash' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    if ($this->configuration['crash'] || self::$forceCrash || self::$crashWith !== NULL) {
      throw self::$crashWith ?? new \Exception('Intentional test exception.');
    }
    return [
      '#markup' => $this->t('<div>Hello, :name!</div>', [':name' => $this->configuration['name']]),
    ];
  }

}
