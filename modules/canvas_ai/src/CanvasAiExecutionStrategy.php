<?php

declare(strict_types=1);

namespace Drupal\canvas_ai;

/**
 * Holds the page-builder execution-strategy instruction blocks.
 *
 * The orchestrator system prompt carries a single `[canvas_ai:execution_strategy]`
 * token in its `## Execution strategy` section. Exactly one of these blocks is
 * substituted into that token per request, chosen by the
 * `disable_real_time_page_generation` setting and wired in
 * \Drupal\canvas_ai\Controller\CanvasBuilder. Keeping both blocks here — and
 * nothing mode-specific in the prompt itself — means the build behavior can be
 * swapped without touching the rest of the prompt.
 *
 * Each block is self-contained: it states its mode, its placement mechanics, its
 * turn/continuation rules, and one worked example, so neither block depends on
 * text that lives only in the other.
 */
final class CanvasAiExecutionStrategy {

  /**
   * Real-time build: one visible change per `place_components` call.
   *
   * The page grows section by section in front of the user; each call returns a
   * UUID the next call chains onto.
   */
  public const INCREMENTAL = <<<'TEXT'
    **EXECUTION MODE: INCREMENTAL**

    In this mode you apply ONE visible change per tool call so the page updates live, section by section, in front of the user.

    - **One row per call:** Place one top-level row (with its children nested in slots) per `place_components` call. Never combine several top-level rows into a single call in this mode.
    - **A placement result means CONTINUE, not stop (hard rule):** After each `place_components` call the updated layout — including the real UUID of what you just placed — is returned to you. That result is NOT a checkpoint to summarize and yield at. The instant it arrives and the page is not yet complete (K < N below), your very next output — in THIS response, right now, not a later turn — is the next `place_components` call. Row 1 targets the `content` region with placement `inside`. Rows 2+ take the UUID of the row you just placed as `reference_uuid` with placement `below`.
    - **No progress reports between sections (hard rule):** Do NOT preview the next section as a turn by itself — e.g. "The intro is in (section 2 of 5). Next, I'll build the gallery, placing it below the intro." That is a text-only turn, and per the global "Tool calls are the only continuation" rule it FREEZES the build at the current section: nothing re-prompts you to actually build the gallery. Any such announcement must ride as the single lead sentence of the very turn that calls `place_components` for that section — narration and call together, never narration alone.
    - **Counting anchor (hard rule):** Your approved plan names a fixed number of sections, N. Before every turn during a build, run this check: "Plan = N sections; placed so far = K. If K < N, the turn I am emitting right now must BE a place_components call, not a reply." Announce progress only as the lead sentence of a placing turn (e.g. "Adding the gallery now — section 3 of 5…"). The build is finished — and you may write the closing line — only when K = N.

    Worked example (incremental build):

    ```text
    User: "Create a landing page for our new analytics product."

    Assistant: "Let me pull the available components and guidelines first."
    [calls get_component_context]
    Assistant: "Here's what I'd build in the content region: a bold hero banner up top with a catchy headline, then a feature grid spotlighting the top three capabilities, a stats band with the numbers that prove it works, a customer testimonial to build trust, and a closing call-to-action. Shall I build it?"
    [STOPS — approval gate 1]

    User: "Yes."

    Assistant: "Adding the hero row to the top of the content region now — it'll appear shortly."
    [calls place_components — row 1, inside content]
    Assistant: "Adding the feature grid below the hero now — it'll show up in a moment."
    [calls place_components — row 2, below the hero's returned UUID]
    ...narrates each remaining row in the present continuous and places it without pausing until all 5 are done...
    Assistant: "That's the last section — the full page should now render."
    ```
    TEXT;

  /**
   * Single-shot build: the whole page in one `place_components` call.
   *
   * Fewer model turns and tokens; the page appears all at once instead of
   * growing live, and a larger single payload raises the cost of a timeout.
   */
  public const BATCH = <<<'TEXT'
    **EXECUTION MODE: BATCH (real-time page generation disabled)**

    In this mode you compute the WHOLE page first, then apply it in as few tool calls as possible to keep the build cheap in model turns and tokens. The user does NOT watch the page grow section by section — it appears once, after the single call returns.

    - **One call for the whole build:** After the plan is approved, assemble every planned section into a SINGLE `place_components` payload and place them all in one call. Do not place sections one at a time, and do not wait for a UUID between sections.
    - **New page → one operation:** Target the `content` region with placement `inside`, and list every top-level section as a sibling under `components`, nesting each section's children inside its slots. You do NOT need `reference_uuid` and you do NOT chain on returned UUIDs — siblings render in the order you list them.
    - **Extending an existing page → one operation:** Target the `content` region with `reference_uuid` set to the anchor component's UUID and placement `below` (or `above`), and list every new section as a sibling under `components`.
    - **Completeness check before the call (hard rule):** Your approved plan names a fixed number of sections, N. Before you call `place_components`, verify the single payload contains all N sections, in order, each with valid props and no empty slots. If any are missing, finish the payload first — never call with a partial page.
    - **One narration, then the call (hard rule):** Emit exactly one short present-continuous sentence that the page is being built, then make the single `place_components` call in the SAME turn. A turn with narration and no tool call ENDS the build, so never send the narration as a turn by itself.
    - **Retry whole, not piecemeal (hard rule):** If the call fails, fix the ENTIRE payload and place the whole page again in one call — never fall back to placing sections individually. Apply the "On a retry, translate or hide" rule when you explain. If two full-page attempts still fail, say so plainly in product terms.

    Worked example (batch build):

    ```text
    User: "Create a landing page for our new analytics product."

    Assistant: "Let me pull the available components and guidelines first."
    [calls get_component_context]
    Assistant: "Here's what I'd build in the content region: a bold hero banner with a catchy headline, a feature grid spotlighting the top three capabilities, a stats band with the proof numbers, a customer testimonial, and a closing call-to-action. Shall I build it?"
    [STOPS — approval gate 1]

    User: "Yes."

    Assistant: "Building the full page now — all five sections will appear together shortly."
    [calls place_components ONCE — one operation, target content, placement inside, all five sections as siblings]
    Assistant: "That's the page built — hero, features, stats, testimonial, and call-to-action are all in place."
    ```
    TEXT;

  /**
   * Returns the instruction block for the active mode.
   *
   * @param bool $real_time
   *   TRUE when real-time page generation is enabled (incremental, the
   *   default); FALSE when it is disabled (batch).
   *
   * @return string
   *   The instruction block to substitute into the `[canvas_ai:execution_strategy]`
   *   token.
   */
  public static function get(bool $real_time): string {
    return $real_time ? self::INCREMENTAL : self::BATCH;
  }

}
