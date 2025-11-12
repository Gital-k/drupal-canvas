<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\AgentStatus;

use Drupal\ai_agents\Service\AgentStatus\AiAgentStatusUpdate;
use Drupal\ai_agents\Service\AgentStatus\AiAgentStatusUpdateItemFactory;
use Drupal\Component\Serialization\Json;

/**
 * Handles the status update of a Canvas AI prompt.
 */
final class AiPromptStatusUpdate extends AiAgentStatusUpdate implements AiPromptStatusUpdateInterface {

  /**
   * The status of the prompt.
   *
   * @var bool
   */
  protected bool $status;

  /**
   * The error message for the prompt, if any.
   *
   * @var string|null
   */
  protected ?string $message = NULL;

  /**
   * {@inheritdoc}
   */
  public function setStatus(bool $success): void {
    $this->status = $success;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): bool {
    return $this->status ?? TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public static function fromArray(array $data): AiPromptStatusUpdateInterface {
    $instance = new self();
    foreach ($data['items'] as $item_data) {
      $item = AiAgentStatusUpdateItemFactory::createFromArray($item_data);
      $instance->addItem($item);
    }
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public static function fromJson(string $json): AiPromptStatusUpdateInterface {
    $data = Json::decode($json);
    return self::fromArray($data);
  }

  /**
   * {@inheritdoc}
   */
  public function setMessage(string $message): void {
    $this->message = $message;
  }

  /**
   * {@inheritdoc}
   */
  public function getMessage(): ?string {
    return $this->message;
  }

}
