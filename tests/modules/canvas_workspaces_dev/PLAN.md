# Workspaces Integration — Implementation Plan

## Conventions and constraints
- All JS/TS work must pass `npm run lint` before commit; run `npm run fix` after every JS change.
- All PHP work must pass phpcs and phpstan (see commands below); run phpcbf to auto-fix.
- Never run full test suites. Run only tests that directly exercise changed code.
- Commits follow the repo format: `🤖 <intent ≤160 chars>` + optional body + `Co-authored-by:` line.
- No file is created without a reason traceable to a concrete user-visible behavior.

## Agent notes

This is organized into chunks of discrete work. A new session should be able to review this document and have the necessary context to begin work on a given chunk.

As a chunk is completed, update this doc to indicate that. If, at the completion of a chunk, there is information that would benefit future chunks, add that info. Keep in mind this is an implementation plan, not a historical document — only info that facilitates building.

### PHP linting commands

```bash
# phpcs — run from the Canvas module root:
mkdir -p test-results .cache/phpcs && /Users/ben.mullins/Sites/drupal/vendor/bin/phpcs --standard=$(pwd)/phpcs.xml --report-width=auto --colors -s --report-full --report-summary $(pwd)

# phpcbf — auto-fix a specific file:
/Users/ben.mullins/Sites/drupal/vendor/bin/phpcbf --standard=$(pwd)/phpcs.xml <file>

# phpstan — baseline errors exist for unrelated files; focus only on files you touched:
mkdir -p test-results && /Users/ben.mullins/Sites/drupal/vendor/bin/phpstan --configuration=$(pwd)/phpstan-custom.neon --autoload-file=$(pwd)/phpstan_rules/autoload.php analyze $(pwd)
```

---

## Chunk 1 — Backend scaffolding (`canvas_workspaces_dev` module) ✅

**Goal:** A Drupal developer can enable `canvas_workspaces_dev`, hit the HTTP endpoints, and get valid JSON back. The `devWorkspaces` flag appears in `drupalSettings`. Workspaces and changes persist across requests.

### 1.1 — `CanvasController.php`: add `devWorkspaces` permission flag

File: `src/Controller/CanvasController.php`

In the `permissions` array (L269), one entry after `configureLanguages`:

```php
'devWorkspaces' => $this->moduleHandler->moduleExists('canvas_workspaces_dev'),
```

`$this->moduleHandler` is already injected (`ModuleHandlerInterface`). No other changes to this file.

### 1.2 — `canvas_workspaces_dev` module

```
tests/modules/canvas_workspaces_dev/
├── canvas_workspaces_dev.info.yml
├── canvas_workspaces_dev.routing.yml
└── src/Controller/WorkspacesMockController.php
```

**Storage:** `WorkspacesMockController` injects `KeyValueFactoryInterface` (service `keyvalue`, same pattern as `AutoSaveManager`). All data lives in the `canvas_workspaces_dev` KV collection under two keys:
- `workspaces` — map of workspace objects keyed by workspace ID
- `changes:{workspace_id}` — map of change objects keyed by change ID

Seed data (three workspaces + their changes) is written on the first request when the store is empty.

**Routes:**

| Route name | Path | Method |
|---|---|---|
| `canvas_workspaces_dev.workspaces.list` | `/canvas/api/v0/workspaces-dev` | GET |
| `canvas_workspaces_dev.workspaces.create` | `/canvas/api/v0/workspaces-dev` | POST |
| `canvas_workspaces_dev.workspaces.update` | `/canvas/api/v0/workspaces-dev/{workspace_id}` | PATCH |
| `canvas_workspaces_dev.workspaces.delete` | `/canvas/api/v0/workspaces-dev/{workspace_id}` | DELETE |
| `canvas_workspaces_dev.workspaces.changes` | `/canvas/api/v0/workspaces-dev/{workspace_id}/changes` | GET |
| `canvas_workspaces_dev.workspaces.publish_action` | `/canvas/api/v0/workspaces-dev/{workspace_id}/publish-action` | POST |

All routes use `_canvas_ui_access: 'TRUE'` and `_format: 'json'`. The live workspace (`id: 'live'`) cannot be modified or deleted (returns 403).

**`publishAction`** mutates stored state: `publish` sets status to `published` and clears changes; `schedule` sets status to `scheduled` and stores `scheduledAt`; `review` sets status to `in_review` and stores `reviewers` from `reviewerIds`.

---

## Chunk 2 — Frontend types, RTK service, Redux slice ✅

**Goal:** The JS layer has typed models, a working RTK Query API pointed at the mock endpoints, and a Redux slice tracking `activeWorkspaceId`. No UI yet.

**Status:** Completed. All files created and wired into store. Linting and type-checking pass.

### 2.1 — TypeScript types

File: `ui/src/types/Workspace.ts` (new file)

```typescript
export type WorkspaceStatus = 'draft' | 'in_review' | 'scheduled' | 'published';

export interface WorkspaceReviewer {
  id: string;
  type: 'user' | 'group';
  name: string;
  avatarUrl?: string;
}

export interface Workspace {
  id: string;
  name: string;
  status: WorkspaceStatus;
  isMain: boolean;
  sourceId?: string;
  pendingChangesCount: number;
  reviewers?: WorkspaceReviewer[];
  scheduledAt?: string;
  hasConflicts?: boolean;
  conflictDetails?: {
    count: number;
    conflictingUsers: Pick<WorkspaceReviewer, 'id' | 'avatarUrl'>[];
  };
}

export interface WorkspacePendingChange {
  id: string;
  entityType: string;
  entityId: string;
  label: string;
  updated: number;
  owner: { id: string; name: string; avatarUrl?: string };
}

export interface CreateWorkspacePayload {
  name: string;
  sourceId?: string;
}

export type PublishAction = 'publish' | 'schedule' | 'review';

export interface PublishActionPayload {
  action: PublishAction;
  scheduledAt?: string;
  reviewerIds?: string[];
}
```

No external dependencies. The `WorkspacePendingChange` shape is intentionally separate from the existing `PendingChange` type in `pendingChangesApi.ts` — it belongs to the workspace layer.

### 2.2 — RTK Query service

File: `ui/src/services/workspacesApi.ts` (new file)

Uses the plain `baseQuery` (not `baseQueryWithAutoSaves` — workspace actions are not auto-save mutations). Four endpoints matching the four mock routes from Chunk 1.

Key points:
- `reducerPath: 'workspacesApi'`
- `tagTypes: ['Workspace', 'WorkspaceChanges']`
- `getWorkspaces`: `GET /canvas/api/v0/workspaces-dev`. `providesTags: [{ type: 'Workspace', id: 'LIST' }]`. Transforms response to unwrap `data` array.
- `createWorkspace`: `POST /canvas/api/v0/workspaces-dev`. `invalidatesTags: [{ type: 'Workspace', id: 'LIST' }]`.
- `getWorkspaceChanges(workspaceId: string)`: `GET /canvas/api/v0/workspaces-dev/{workspaceId}/changes`. `providesTags` by `workspaceId`.
- `publishWorkspaceAction({ workspaceId, ...payload })`: `POST /canvas/api/v0/workspaces-dev/{workspaceId}/publish-action`. Invalidates both `Workspace` list and the specific `WorkspaceChanges` tag.

**Wire into `store.ts`:** Add `workspacesApi` to `combineSlices` and to the `middleware` concat array. Same pattern as every other API service in that file.

### 2.3 — Redux slice

File: `ui/src/features/workspaces/workspacesSlice.ts` (new file)

```typescript
interface WorkspacesState {
  activeWorkspaceId: string | null; // null = main workspace
}
```

Actions: `setActiveWorkspace(id: string)`, `clearActiveWorkspace()`.

Selectors: `selectActiveWorkspaceId`.

Persistence: On `setActiveWorkspace`, also write to `localStorage` under key `'canvas.activeWorkspaceId'`. On slice initialization (`initialState`), read from `localStorage` to rehydrate. This mirrors the pattern in `drupal-globals.ts` for the headless frontend. The stored value is just the ID string, not the full object — the object is always fetched fresh from the API.

**Wire into `store.ts`:** Add `workspacesSlice` to `combineSlices`.

**Verify:** In Redux DevTools, dispatch `setActiveWorkspace('ws-001')`, reload the page, confirm the slice re-initializes with `'ws-001'`.

---

## Chunk 3 — Workspace Switcher and Dropdown

**Goal:** A WorkspaceSwitcher button appears in the Topbar center section (grouped with `<PageInfo>`). Clicking it opens a dropdown listing all workspaces with their status badges. The user can search, switch workspaces, and see an empty state.

### 3.1 — `WorkspaceDropdown` component

File: `ui/src/components/workspaces/WorkspaceDropdown.tsx` + `WorkspaceDropdown.module.css`

This is the popover content only (not the trigger). Props:

```typescript
interface WorkspaceDropdownProps {
  workspaces: Workspace[];
  activeWorkspaceId: string | null;
  onSelect: (id: string) => void;
  onAddWorkspace: () => void;
  onPublishNow?: (workspaceId: string) => void;
  onSchedulePublish?: (workspaceId: string) => void;
  onSendForReview?: (workspaceId: string) => void;
  onRename?: (workspaceId: string) => void;
  onDelete?: (workspaceId: string) => void;
}
```

Layout, top to bottom:
1. **Search input** — `<TextField.Root>` with `<MagnifyingGlassIcon>`, same markup pattern as `Navigation.tsx` L414–425. Filters the list client-side.
2. **"+ Add" dropdown button** — `<DropdownMenu.Root>` with a `<Button variant="soft" size="1">` trigger showing `<PlusIcon /> Add <ChevronDownIcon />`, identical to `Navigation.tsx` L428–449. Initially one item: "Add workspace". This trigger calls `onAddWorkspace`.
3. **Workspace list** — `<ScrollArea>` (max-height `175px`, same as `Navigation.tsx`). Each item: a `<Flex>` row with workspace name on the left and a `<Badge>` on the right for status. Active item gets `color="blue"` treatment. Items without status changes (main/live) show no badge. On click, calls `onSelect(workspace.id)`.
4. **Per-item `⋮` menu** (`<DropdownMenu.Root>` on hover, `<DotsVerticalIcon>` trigger) — items:
   - **Publish now** — visible only for non-main workspaces in `draft`, `in_review`, or `scheduled` status; calls `onPublishNow?.(workspace.id)`
   - **Schedule publish** — visible only for non-main workspaces in `draft` or `in_review` status; calls `onSchedulePublish?.(workspace.id)`
   - **Send for review** — visible only for non-main workspaces in `draft` status; calls `onSendForReview?.(workspace.id)`
   - Separator
   - **Rename** — disabled for now (stub with `disabled` prop)
   - Separator  
   - **Delete** — disabled for now (stub with `disabled` prop), styled in red
   
   These menu items exist so the menu is structurally complete. Actions are conditionally shown based on the workspace's current status — non-draft workspaces don't show "Send for review", published workspaces don't show "Publish now" or "Schedule publish".
5. **Empty state** — When `workspaces.length === 1` (only the main workspace exists) AND search is empty: render `<EmptyStateCallout>` with title "No workspaces yet" and a description that renders a clickable "Add workspace" link calling `onAddWorkspace`. This matches the `EmptyStateCallout` pattern already in the codebase.
6. **No search results** — When search has a value but produces no matches: `<EmptyStateCallout title="No workspaces found" />`.

Status color mapping for `<Badge>`:

| Status | Radix color |
|---|---|
| `draft` | `yellow` |
| `in_review` | `pink` |
| `scheduled` | `purple` |
| `published` | no badge |

### 3.2 — `WorkspaceSwitcher` component

File: `ui/src/components/workspaces/WorkspaceSwitcher.tsx`

Reads `devWorkspaces` from `getCanvasSettings().permissions.devWorkspaces`. Returns `null` immediately if false — no hook calls, no API calls. This mirrors the `NotificationBell.tsx` pattern exactly (L34, L100).

When enabled:
- Calls `useGetWorkspacesQuery()`.
- Reads `activeWorkspaceId` from Redux; resolves the active `Workspace` object from the fetched list (falls back to the `isMain` workspace if null or not found).
- Renders a `<Popover.Root>` wrapping a trigger button and `<WorkspaceDropdown>` as content (inside `<Panel className="canvas-app">`), matching the structure in `PageInfo.tsx` L311–394.
- Trigger button: `<Button color="gray" variant="soft" size="1">` containing the workspace name, a `<ChevronDownIcon />`, and a status badge (same color mapping as above, hidden when status is `'published'`). Uses `data-testid="canvas-workspace-switcher"`.
- On item select: dispatch `setActiveWorkspace(id)`, close popover.
- On add workspace: close popover, dispatch `setAddWorkspaceModalOpen(true)` (slice from Chunk 4).

### 3.3 — Topbar integration

File: `ui/src/components/topbar/Topbar.tsx`

The center section currently contains only `<PageInfo />` (and conditionally `<ContentPreviewSelector />`). The change: wrap `<WorkspaceSwitcher />` and `<PageInfo />` together in a `<Flex gap="2" align="center">`, so they move as a unit and the group remains centered in the `1fr` column. The `justify="center"` on the outer `<Flex>` stays.

```tsx
// Before (line 159–168):
<Flex align="center" justify="center" gap="2">
  <PageInfo />
  ...
</Flex>

// After:
<Flex align="center" justify="center" gap="2">
  <Flex align="center" gap="2">
    <WorkspaceSwitcher onAddWorkspace={...} />
    <PageInfo />
  </Flex>
  ...
</Flex>
```

`WorkspaceSwitcher` returns `null` when `devWorkspaces` is false, so there is zero visual impact on sites without the dev module.

**Verify:** Enable dev module, open Canvas, confirm switcher appears, clicking it shows the list, selecting a workspace dispatches the action and updates the button label.

---

## Chunk 4 — Add Workspace Modal ✅

**Goal:** Clicking "+ New → Add workspace" opens a modal. The user names the workspace, picks a source, submits, and the new workspace becomes active.

**Status:** Completed. Modal opens, form collects workspace name and source, creates workspace on submit, and switches to the new workspace.

### 4.1 — Slice addition

Add to `workspacesSlice.ts`:
- State: `isAddWorkspaceModalOpen: boolean` (default `false`)
- Actions: `setAddWorkspaceModalOpen(open: boolean)`
- Selector: `selectIsAddWorkspaceModalOpen`

### 4.2 — `AddWorkspaceModal` component

File: `ui/src/components/workspaces/AddWorkspaceModal.tsx`

Uses the existing `<Dialog>` component (`ui/src/components/Dialog.tsx`). Props: none — reads state from Redux.

- `open` from `selectIsAddWorkspaceModalOpen`.
- On `onOpenChange(false)`: dispatch `setAddWorkspaceModalOpen(false)`, reset local form state.
- **Source dropdown** (`<Select.Root>`): populated from `useGetWorkspacesQuery()`. Only includes workspaces where `isMain: true` or `status: 'published'`. Defaults to the main workspace. Labeled "Source workspace".
- **Name field** (`<TextField.Root>`): required, placeholder "e.g., Q1 Marketing Campaign". Labeled "Workspace name".
- **Footer:** Cancel (closes), "Create workspace" (disabled until name is non-empty; shows `<Spinner>` while loading).
- On confirm: call `createWorkspace({ name, sourceId })`. On success (`.unwrap()`): dispatch `setActiveWorkspace(result.id)`, close modal. On error: the existing RTK error handling middleware surfaced by `rtkQueryErrorHandler` in `store.ts` handles the error display — no custom error UI needed.

`AddWorkspaceModal` must be mounted unconditionally somewhere in the app tree so it can respond to `setAddWorkspaceModalOpen(true)` from `WorkspaceSwitcher`. The right place is alongside the `WorkspaceSwitcher` in `Topbar.tsx` — rendered as a sibling, gated on `isWorkspacesEnabled()`.

**Verify:** Click "+ New → Add workspace", fill the name, submit. Confirm the new workspace appears in the switcher trigger and in the dropdown list.

---

## Chunk 5 — Workspace-scoped Unpublished Changes ✅

**Goal:** When a non-main workspace is active, `UnpublishedChanges` fetches changes from the workspace-specific endpoint instead of the global pending-changes endpoint. The existing "Review N changes" button reflects the workspace's change count. The global endpoint is not called while in a workspace.

**Status:** Completed. UnpublishedChanges conditionally fetches from the workspace endpoint and shows workspace name in the header.

### 5.1 — `UnpublishedChanges.tsx` modifications

The component currently calls `useGetAllPendingChangesQuery` unconditionally. The change:

1. Read `activeWorkspaceId` from Redux and `devWorkspaces` from settings at the top of the component.
2. Determine `isInWorkspace: boolean` = `devWorkspaces && activeWorkspaceId !== null && activeWorkspaceId !== 'live'`.
3. Call `useGetAllPendingChangesQuery(undefined, { skip: isInWorkspace })` — the existing call, now skipped when in a workspace.
4. Call `useGetWorkspaceChangesQuery(activeWorkspaceId ?? 'live', { skip: !isInWorkspace })` — the new call, only active when in a workspace.
5. Build `unpublishedChanges` from whichever source is active. The workspace changes response shape (`WorkspacePendingChange`) differs from `UnpublishedChange` — map it: `pointer` = `'${entityType}:${entityId}'`. All other fields needed by `PublishReview` (`entity_type`, `entity_id`, `label`, `updated`, `owner`) are present or mappable.
6. Polling: the workspace query uses the same `pollingInterval` state variable already in the component. No new polling logic needed.

This is **purely additive**. When `isInWorkspace` is false, the component behaves identically to today.

### 5.2 — `PublishReview.tsx` — workspace context in header

When in a workspace, the popover header currently shows "Unpublished changes" (the `DEFAULT_TITLE` constant). Pass an optional `workspaceName` prop to `PublishReview` (used only when non-null). `UnpublishedChanges` derives this from the active workspace object and passes it down. `PublishReview` renders it as the heading when present, otherwise falls back to `DEFAULT_TITLE`.

No other changes to `PublishReview` in this chunk.

**Verify:** Switch to the draft workspace, open the review popover, confirm the list shows only the 2–3 mock workspace changes (not all global pending changes). Switch back to Live, confirm the original global changes reappear.

---

## Chunk 6 — Workspace publish dropbutton ✅

**Goal:** When in a non-main workspace, the "Review N changes" button grows a caret that opens additional workspace actions (Publish now, Schedule, Send for review). When on the main workspace, the button is unchanged.

**Status:** Completed. Split button appears when in a workspace with actions for publish, schedule, and review.

### 6.1 — `PublishReview.tsx` — split button

Add an optional `workspaceActions` prop:

```typescript
export interface WorkspaceAction {
  label: string;
  onSelect: () => void;
  disabled?: boolean;
}

// Added to PublishReviewProps:
workspaceActions?: WorkspaceAction[];
```

When `workspaceActions` is provided and non-empty, the trigger area renders as a split button: the existing `<Button>` (opens the popover) stays as-is, and a small `<DropdownMenu.Trigger>` `<IconButton>` with `<ChevronDownIcon />` is appended immediately to its right, visually grouped. Use `<Flex gap="0">` with no gap so they read as a unit. Both share the same `variant="solid"` and `disabled` logic.

The dropdown items are the `workspaceActions` array. No hardcoded action labels in `PublishReview` — all action text is passed in from `UnpublishedChanges`.

### 6.2 — `UnpublishedChanges.tsx` — pass workspace actions

When `isInWorkspace`, construct a `workspaceActions` array and pass it to `<PublishReview>`:

- **"Publish now"** — visible only when `hasPermission('publishChanges')` (existing permission, same gate as the publish button already uses). Calls `publishWorkspaceAction({ workspaceId, action: 'publish' })`.
- **"Schedule publish"** — visible only when `hasPermission('publishChanges')`. Calls `publishWorkspaceAction({ workspaceId, action: 'schedule' })`. For now this is a stub (no date picker yet); it fires the action and the mock returns success.
- **"Send for review"** — always visible (disabled for now). Triggers the review config panel (Chunk 7).

When not in a workspace, `workspaceActions` is `undefined`, leaving the button unchanged.

**Verify:** Switch to draft workspace, confirm a caret appears beside the review button. Confirm the dropdown contains the three actions. Confirm users without `publishChanges` see only "Send for review".

---

## Chunk 7 — Send for Review flow inside PublishReview ✅

**Goal:** Selecting "Send for review" from the dropbutton replaces the change list content inside the `PublishReview` popover with a reviewer selection form. Submitting it fires the publish-action endpoint with `action: 'review'` and shows a success state.

**Status:** Completed. ReviewConfigurationPanel renders inside PublishReview when "Send for review" is selected. Submission fires the API call and shows success state before returning to the change list.

### 7.1 — `ReviewConfigurationPanel` component

File: `ui/src/components/workspaces/ReviewConfigurationPanel.tsx` ✅

Props:
```typescript
interface ReviewConfigurationPanelProps {
  onBack: () => void;
  onSend: (reviewerIds: string[]) => void;
  isSending: boolean;
  hasSent: boolean;
}
```

Layout:
1. **Back link** — `<Button variant="ghost" size="1">` with `<ChevronLeftIcon />` and "Back", calls `onBack`.
2. **Reviewer combobox** — For now, a `<TextField.Root>` that accepts comma-separated user IDs. Each confirmed entry renders as a `<Badge>` token with a `<Cross2Icon>` remove button. This is intentionally simple for the mock; full combobox with avatar tokens is marked as follow-up work.
3. **"Send for review"** button — disabled until at least one reviewer is added. While sending, shows `<Spinner>`. After success, shows `<CheckIcon />` and "Sent" (disabled).
4. On success, after a short delay (1.5s), call `onBack()` to return the popover to the normal change list view.

### 7.2 — `PublishReview.tsx` — panel switching ✅

Add local state: `isReviewPanelOpen: boolean` (default `false`).

Add prop: `onSendForReview?: (reviewerIds: string[]) => void`.

When `isReviewPanelOpen` is true, replace the ScrollArea + action footer with `<ReviewConfigurationPanel>`. The header (title + close button) remains visible throughout.

In `UnpublishedChanges.tsx`:
- Wire `onSendForReview` to call `publishWorkspaceAction({ workspaceId, action: 'review', reviewerIds })`.
- Pass the "Send for review" workspace action as a callback that sets `isReviewPanelOpen = true` inside `PublishReview`.

**Verify:** Select "Send for review" from the dropbutton, confirm the panel appears inside the popover with the back button. Add a reviewer token, confirm the Send button enables. Click Send, confirm the spinner shows, then the success state, then the popover returns to the change list.

---

## Summary of new/modified files

### New files
| File | Purpose |
|---|---|
| `tests/modules/canvas_workspaces_dev/canvas_workspaces_dev.info.yml` | Module declaration |
| `tests/modules/canvas_workspaces_dev/canvas_workspaces_dev.routing.yml` | Six CRUD routes |
| `tests/modules/canvas_workspaces_dev/src/Controller/WorkspacesMockController.php` | KV-backed CRUD controller |
| `ui/src/types/Workspace.ts` | TypeScript models |
| `ui/src/services/workspacesApi.ts` | RTK Query API |
| `ui/src/features/workspaces/workspacesSlice.ts` | Redux state + localStorage persistence |
| `ui/src/components/workspaces/WorkspaceSwitcher.tsx` | Topbar trigger + popover shell |
| `ui/src/components/workspaces/WorkspaceDropdown.tsx` | Popover content |
| `ui/src/components/workspaces/WorkspaceDropdown.module.css` | Styles |
| `ui/src/components/workspaces/AddWorkspaceModal.tsx` | Create workspace dialog |
| `ui/src/components/workspaces/ReviewConfigurationPanel.tsx` | Send-for-review form |
| `ui/src/components/workspaces/ReviewConfigurationPanel.module.css` | Send-for-review styles |

### Modified files
| File | Change |
|---|---|
| `src/Controller/CanvasController.php` | One line: add `devWorkspaces` to permissions array |
| `ui/src/app/store.ts` | Add `workspacesApi` and `workspacesSlice` |
| `ui/src/components/topbar/Topbar.tsx` | Wrap `WorkspaceSwitcher` + `PageInfo` in inner `<Flex>` |
| `ui/src/components/review/UnpublishedChanges.tsx` | Workspace-scoped fetch, pass workspace props to PublishReview |
| `ui/src/components/review/PublishReview.tsx` | `workspaceName` prop, `workspaceActions` split button, `onSendForReview` panel switch |

---

## Out of scope for these chunks (follow-up work)

- **Conflict notification** (`ConflictNotificationPopover`) — requires `hasConflicts` polling and the diff viewer link
- **Preview dropdown** (`PreviewButtonDropdown`, `ActivePreviewToolbar`) — depends on workspace-scoped preview URL strategy
- **Full reviewer combobox** with avatars and user/group search
- **Workspace ⋮ actions** — Rename, Delete, Duplicate (stubs exist after Chunk 3)
- **Schedule date picker** — stub fires action; full UX is TBD
- **Real Workspaces module integration** — swap `workspacesApi` base path, remove dev module dependency

