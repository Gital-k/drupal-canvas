<?php

namespace Drupal\canvas_ai\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Defines an event for AI request log data collection.
 */
class PrepareAiLogEvent extends Event {

  /**
   * The agent instance involved in the AI request.
   *
   * @var mixed
   */
  protected $agent;

  /**
   * The prompt array containing request details.
   *
   * @var array
   */
  protected $prompt;

  /**
   * The error message, if the request failed.
   *
   * @var string|null
   */
  protected $errorMessage;

  /**
   * The current user making the AI request.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The site configuration object for context (e.g., site UUID).
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $siteConfig;

  /**
   * The log array built by the event subscriber.
   *
   * @var array
   */
  protected $logArray = [];

  /**
   * Constructs a new AiRequestLogEvent.
   *
   * @param mixed $agent
   *   The agent instance for the request.
   * @param array $prompt
   *   The prompt array with request data.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The user making the request.
   * @param \Drupal\Core\Config\ImmutableConfig $siteConfig
   *   The site configuration object.
   * @param string|null $errorMessage
   *   The error message, if any.
   */
  public function __construct($agent, array $prompt, $currentUser, $siteConfig, $errorMessage = NULL) {
    $this->agent = $agent;
    $this->prompt = $prompt;
    $this->currentUser = $currentUser;
    $this->siteConfig = $siteConfig;
    $this->errorMessage = $errorMessage;
  }

  /**
   * Gets the prompt array for the AI request.
   *
   * @return array
   *   The prompt array.
   */
  public function getPrompt() {
    return $this->prompt;
  }

  /**
   * Gets the error message, if the request failed.
   *
   * @return string|null
   *   The error message, or NULL if none.
   */
  public function getErrorMessage() {
    return $this->errorMessage;
  }

  /**
   * Gets the user who made the AI request.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The user object.
   */
  public function getCurrentUser() {
    return $this->currentUser;
  }

  /**
   * Gets the site configuration object for the request.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The site configuration object.
   */
  public function getSiteConfig() {
    return $this->siteConfig;
  }

  /**
   * Sets the log array for the AI request.
   *
   * @param array $logArray
   *   The log array to set.
   */
  public function setLogArray(array $logArray) {
    $this->logArray = $logArray;
  }

  /**
   * Gets the build log array for the AI request.
   *
   * @return array
   *   The log array containing all relevant log data.
   */
  public function getLogArray() {
    return $this->logArray;
  }

}
