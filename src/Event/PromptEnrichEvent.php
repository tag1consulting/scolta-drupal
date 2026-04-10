<?php

declare(strict_types=1);

namespace Drupal\scolta\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before an AI prompt is sent to the LLM provider.
 *
 * Subscribe to this event to inject site-specific context into prompts.
 * For example, a subscriber could append product catalog information,
 * compliance rules, or tenant-specific instructions.
 *
 * @since 0.2.0
 * @stability experimental
 */
class PromptEnrichEvent extends Event {

  /**
   * Constructs a PromptEnrichEvent.
   *
   * @param string $resolvedPrompt
   *   The prompt text after template resolution.
   * @param string $promptName
   *   The prompt identifier ('expand_query', 'summarize', or 'follow_up').
   * @param array $context
   *   Additional context (e.g., query, search results, messages).
   */
  public function __construct(
    private string $resolvedPrompt,
    private readonly string $promptName,
    private readonly array $context = [],
  ) {}

  /**
   * Get the current prompt text.
   */
  public function getResolvedPrompt(): string {
    return $this->resolvedPrompt;
  }

  /**
   * Set the modified prompt text.
   */
  public function setResolvedPrompt(string $resolvedPrompt): void {
    $this->resolvedPrompt = $resolvedPrompt;
  }

  /**
   * Get the prompt name identifier.
   */
  public function getPromptName(): string {
    return $this->promptName;
  }

  /**
   * Get the additional context.
   */
  public function getContext(): array {
    return $this->context;
  }

}
