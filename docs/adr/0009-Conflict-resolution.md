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

For the purposes of this ADR, a "conflict" in Canvas describes the following situation:
1. User starts making changes to an entity in Canvas UI, which results in the entry for such an entity being added to autosave.
2. Another user (or automated process) updates the published version of the same entity outside of Canvas UI.
3. The entity version the in autosave entry with changes made by user "A" no longer matches the published version of the same entity.

A discrepancy between "initial state" of the entity in Canvas autosave and the published version of that entity is considered to be a conflict.

### Functional requirements
Conflict resolution in Canvas must:

1. Add side-by-side visual comparison of changed entity in autosave vs currently published version of the same entity.
2. Add ability to review changes using "Review changes" panel, without having to navigate to individual entities and use "preview" functionality.
3. Detect when conflicts occur and surface that information to the Canvas UI.
4. Add ability for users to review conflicting entities using side-by-side visual comparison.
5. Add ability for users to resolve conflict by selecting which version of the entity should be kept, discarding the other.
6. Track which entity and which conflict the user has resolved, in case additional conflicts are detected later.

### Non-functional requirements

1. Any changes to the entity should be considered conflict.
2. Visual side-by-side comparison must be reasonably fast.
3. Visual side-by-side comparison must support complex component trees.
4. Side-by-side comparison must work with screen sizes currently supported by Canvas.
5. Visual comparison UI should have functionality that allows it to fill viewport and adjust the zoom level of the rendered entity.
6. Resolving conflict in favour of the published version of the entity should remove the entry for this entity from autosave.
7. Resolving conflict in favour of the entity version in autosave, additional changes to the published version of the entity should trigger new conflicts.

### What is out of scope:
1. Multiple users editing the same entity in Canvas and having competing changes in autosave is not considered a conflict in the context of this ADR.
2. Fine-grained conflict detection (For example, does updating "revision id" and "changed" property of a Canvas Page entity counts as a conflict).
3. If certain entity updates should be not be detected as a conflict (fine-grained conflict detection), we should update the autosave entry of the entity with changes to its data fields

## Decision

We should store initial entity state in the autosave when user starts editing an entity in Canvas UI.
Initial state should be tracked using entity revision id for revisionable entities and using a hash for config entities.
We already store serialized version of Canvas page, so we can use that or add dedicated property for storing revision id in autosave.
To detect changes of config entities we should compute hash based on the contents of the entire config entity.
During autosave publishing, validation should perform revision id and a hash comparison of entries in autosave vs published versions of the same entities.
If any discrepancies found, validation should fail and publishing should be prevented.
Autosave then should be updated to mark which entries have conflicts, including the revision id or hash of the conflicting published entity.
This will allow Canvas UI to flag which entries have conflicts.
If conflict is resolved by choosing the published version of the entity, the autosave entry representing this entity should be removed, discarding any changes user has made in Canvas UI.
If conflict is resolved by choosing the version of the entity in autosave, autosave entry representing this entity should be marked using combination of conflict status and conflict id:

Example of a config entity conflict:
```json
{
  "conflict": {
    "status": false,
    "conflictHash": "593a4b9df1890d9ead3d701eb6721d4b",
    "conflictId": null
  }
}
```
Example of a content entity conflict:
```json
{
  "conflict": {
    "status": false,
    "conflictHash": null,
    "conflictId": 13
  }
}
```
By default `status` property of conflict should be false.

This also need to detect conflicts without having user attempt publishing their changes.
In order to achieve that, we should implement post save hooks targeting entities we want to detect conflicts for. If the entity in post save hook is represented by an entry in the autosave, we should mark the autosave entry with the conflict status and revision id or hash.
To prevent issues when a valid entity without conflicts is being published via Canvas UI we could change the logic handling entity saving in `\Drupal\canvas\Controller\ApiAutoSaveController::post()` to include the following:
```php
foreach ($entities as $entity) {
        $lastEntityEvaluated = $entity;
        // Even though the entities are being validated before, there is a
        // possibility where, when multiple entities are being saved together,
        // the first entity collides with some of the following entities. So
        // we need to validate right before saving the entity.
        self::ensureEntityIsValid($entity);
        // 💁‍♂️💁‍♂️💁‍♂️💁‍♂️ This would be a new method.
        $this->autoSaveManager->setPublishing($entity, TRUE);
        $entity->save();
      }
      foreach ($entities as $entity) {
        $this->autoSaveManager->delete($entity);
      }

```
Then in our hook we could check `$this->autoSaveManager->isPublishing($entity)`.
We don't need to call `$this->autoSaveManager->setPublishing($entity, FALSE);` because either the publishing would succeed, in which case we will delete the autoSave entry completely, or it will fail in which case we will do a DB transaction rollback.
Later on we could add `$this->autoSaveManager->publish($entity);` to handle this internally.

## Consequences

**Benefits:**
- Ability to visually review changes to entities with side-by-side visual comparison will speed up the workflow for users using Canvas UI to build components, create or modify layouts or simply edit content.
- Providing a universal preview for all changes, while specifically highlighting conflicts, ensures a consistent and safe publishing workflow.
- Preventing users from publishing their changes when a conflict is detected will prevent data loss caused by overwriting changes user was not aware of.

**Future considerations:**
- Conflict resolution could pair well with Notifications in Canvas
- If Canvas will be integrated with Workspaces, the expectation is for conflict resolution to continue work as described in this ADR in the Canvas UI. Conflict detection logic in the back-end likely will have to be updated to use revision id changes of the published entity.
