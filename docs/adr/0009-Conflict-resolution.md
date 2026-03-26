# 9. Conflict resolution

Date: 2026-03-18

Issue: https://www.drupal.org/project/canvas/issues/3492059

## Status

Proposed

## Context

When concurrent changes occur between the updated entity user has in the autosave
and published version of the same entity, users lack visibility into conflicting
versions. Currently, users reviewing changes to publish are unaware if the
published content has been updated outside of Canvas, risking silent overwrites
of concurrent work.

Users don’t have a convenient and quick way to visually review changes of except
using “preview” functionality for individual entities.

### Functional requirements

1. Allow users to review their changes using side-by-side visual comparison of changed entity in autosave vs currently published version of the same entity.
2. Add ability to review changes using "Review changes" panel, without having to navigate to individual entities and use "preview" functionality.
3. Detect when conflicts occur and surface that information to the Canvas UI. Conflict here refers to when after the user starts modifying an entity and changes to such entity are stored in the autosave, the underlying entity is updated and saved via other means before the user gets to publish their changes.
4. Add ability for users to review conflicting entities using side-by-side visual comparison.
5. Add ability for users to resolve conflict by selecting which version of the entity should be published, discarding the other.

### Non-functional requirements
1. Multiple users editing the same entity in Canvas and having competing changes in autosave is not considered a Conflict in the context of this ADR.
2. Only changes that affect the visual output of the entity should be considered a conflict. For example, "updated" timestamp and "reviesion_id" change alone should not result in detection of conflict.
3. Visual side-by-side comparison must be reasonably fast.
4. Visual side-by-side comparison must support complex component trees.
5. Side-by-side comparison must work with screen sizes currently supported by Canvas.
6. Visual comparison UI should have functionality that allows it to fill viewport and adjust the zoom level of the rendered entity.

## Consequences

**Benefits:**
- Ability to visually review changes to entities with side-by-side visual comparison will speed up the workflow for users using Canvas UI to build components, create or modify layouts or simply edit content.
- Providing a universal preview for all changes, while specifically highlighting conflicts, ensures a consistent and safe publishing workflow.
- Preventing users from publishing their changes when a conflict is detected will prevent data loss caused by overwriting changes user was not aware of.

**Future considerations:**
- Conflict resolution could pair well with Notifications in Canvas
- If Canvas will be integrated with Workspaces, the expectation is for conflict resolution to continue work as described in this ADR in the Canvas UI. Conflict detection logic in the back-end likely will have to be updated to use revision id changes of the published entity.
