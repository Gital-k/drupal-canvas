# Workspaces Integration — Summary

This work adds a developer-mode workspaces feature to Canvas, backed by a lightweight mock Drupal module (`canvas_workspaces_dev`) and a full frontend implementation. It is entirely additive: every new code path is gated on the `devWorkspaces` permission flag and has zero impact on sites that do not enable the dev module.

---

## What was built

### Backend — `canvas_workspaces_dev` module

A Drupal test/dev module that provides six REST-style JSON routes backed by the `KeyValue` store. It seeds three workspaces with associated changes on first request.

| Route | Method | Purpose |
|---|---|---|
| `/canvas/api/v0/workspaces-dev` | GET | List all workspaces |
| `/canvas/api/v0/workspaces-dev` | POST | Create a workspace |
| `/canvas/api/v0/workspaces-dev/{id}` | PATCH | Rename / update a workspace |
| `/canvas/api/v0/workspaces-dev/{id}` | DELETE | Delete a workspace |
| `/canvas/api/v0/workspaces-dev/{id}/changes` | GET | List pending changes for a workspace |
| `/canvas/api/v0/workspaces-dev/{id}/publish-action` | POST | Publish, schedule, or send for review |

The live workspace (`id: 'live'`) is protected from modification (returns 403). `publishAction` mutates stored state: `publish` sets status to `published` and clears changes; `schedule` sets status to `scheduled`; `review` sets status to `in_review` and stores reviewers.

`CanvasController.php` exposes `devWorkspaces: true` in `drupalSettings` when the module is enabled, which the frontend uses to gate all workspace UI.

### Frontend

**Types** (`ui/src/types/Workspace.ts`) — `Workspace`, `WorkspacePendingChange`, `WorkspaceStatus`, `WorkspaceReviewer`, `CreateWorkspacePayload`, `PublishActionPayload`.

**RTK Query service** (`ui/src/services/workspacesApi.ts`) — Four endpoints: `getWorkspaces`, `createWorkspace`, `getWorkspaceChanges`, `publishWorkspaceAction`. Uses the plain `baseQuery` (not auto-saves).

**Redux slice** (`ui/src/features/workspaces/workspacesSlice.ts`) — Tracks `activeWorkspaceId` (persisted to `localStorage`) and `isAddWorkspaceModalOpen`.

**Workspace Switcher** (`WorkspaceSwitcher.tsx` + `WorkspaceDropdown.tsx`) — A `<Popover>` trigger in the Topbar center section, grouped alongside `<PageInfo>`. The dropdown lists all workspaces with status badges, a search field, a per-item `⋮` context menu (Publish now / Schedule / Send for review, with Rename and Delete as disabled stubs), and empty states for no workspaces and no search results.

**Add Workspace Modal** (`AddWorkspaceModal.tsx`) — Opened by dispatching `setAddWorkspaceModalOpen(true)`. Collects a workspace name and source workspace, calls `createWorkspace`, then switches the active workspace to the newly created one.

**Workspace-scoped Unpublished Changes** — When a non-main workspace is active, `UnpublishedChanges.tsx` fetches from the workspace changes endpoint instead of the global pending-changes endpoint. The "Review N changes" button count reflects the workspace's changes. The workspace name is shown in the `PublishReview` popover header.

**Workspace publish dropbutton** — When in a non-main workspace, the review button grows a caret `<DropdownMenu>` offering: Publish now, Schedule publish (stub — fires the action with no date picker yet), and Send for review. Actions are gated on the `publishChanges` permission where appropriate.

**Send for Review panel** (`ReviewConfigurationPanel.tsx`) — Selecting "Send for review" from the dropbutton replaces the change list inside the `PublishReview` popover with a reviewer input form. After submission the popover returns to the change list.

---

## New files

| File | Purpose |
|---|---|
| `canvas_workspaces_dev.info.yml` | Module declaration |
| `canvas_workspaces_dev.routing.yml` | Six CRUD routes |
| `src/Controller/WorkspacesMockController.php` | KV-backed CRUD controller |
| `ui/src/types/Workspace.ts` | TypeScript models |
| `ui/src/services/workspacesApi.ts` | RTK Query API |
| `ui/src/features/workspaces/workspacesSlice.ts` | Redux state + localStorage persistence |
| `ui/src/components/workspaces/WorkspaceSwitcher.tsx` | Topbar trigger + popover shell |
| `ui/src/components/workspaces/WorkspaceDropdown.tsx` | Popover content |
| `ui/src/components/workspaces/WorkspaceDropdown.module.css` | Styles |
| `ui/src/components/workspaces/AddWorkspaceModal.tsx` | Create workspace dialog |
| `ui/src/components/workspaces/ReviewConfigurationPanel.tsx` | Send-for-review form |
| `ui/src/components/workspaces/ReviewConfigurationPanel.module.css` | Send-for-review styles |

## Modified files

| File | Change |
|---|---|
| `src/Controller/CanvasController.php` | Add `devWorkspaces` flag to permissions array |
| `ui/src/app/store.ts` | Add `workspacesApi` and `workspacesSlice` |
| `ui/src/components/topbar/Topbar.tsx` | Wrap `WorkspaceSwitcher` + `PageInfo` in inner `<Flex>` |
| `ui/src/components/review/UnpublishedChanges.tsx` | Workspace-scoped fetch; pass workspace props to `PublishReview` |
| `ui/src/components/review/PublishReview.tsx` | `workspaceName` prop, `workspaceActions` split button, `onSendForReview` panel switch |

---

## Out of scope (follow-up work)

- **Conflict notification** — requires `hasConflicts` polling and diff viewer
- **Preview dropdown** — depends on workspace-scoped preview URL strategy
- **Full reviewer combobox** — avatar tokens and user/group search
- **Workspace ⋮ actions** — Rename, Delete, Duplicate (stubs exist)
- **Schedule date picker** — stub fires action; full UX is TBD
- **Real Workspaces module integration** — swap `workspacesApi` base path, remove dev module dependency
