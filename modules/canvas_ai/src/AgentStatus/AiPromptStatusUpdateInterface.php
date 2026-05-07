<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\AgentStatus;

use Drupal\ai_agents\Service\AgentStatus\Interfaces\AiAgentStatusUpdateInterface;

/**
 * Provides an interface for AI prompt status updates.
 */
interface AiPromptStatusUpdateInterface extends AiAgentStatusUpdateInterface {

  /**
   * Sets an error message for the prompt.
   *
   * @param string $message
   *   The error message.
   */
  public function setMessage(string $message): void;

  /**
   * Gets the error message, if any.
   *
   * @return string|null
   *   The error message or NULL if none.
   */
  public function getMessage(): ?string;

  /**
   * Sets the status of the prompt.
   *
   * @param bool $success
   *   TRUE if successful, FALSE otherwise.
   */
  public function setStatus(bool $success): void;

  /**
   * Gets the status of the prompt.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function getStatus(): bool;

}
