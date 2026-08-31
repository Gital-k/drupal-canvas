# 18. Server-sequenced operation log for real-time collaboration

Date: 2026-07-04

Issue: <https://www.drupal.org/project/canvas/issues/3492065>

## Status

Proposed

## Context

Canvas assumes a single editor per entity. Every structural change writes the
full component tree and model to the auto-save store, the client serializes
its own writes, and staleness is detected by comparing data hashes and
rejecting mismatches. Staging autosaves as pending Workspace revisions (see
ADR 0014 and ADR 0017) additionally means an entity with a pending draft is
implicitly locked to that draft's owner. Two people editing the same page
either overwrite each other's full-document writes or are blocked outright.

Teams expect simultaneous editing in the style of collaborative design and
document tools: concurrent edits that merge, visible presence, and no lost
work. Several properties of Canvas constrain how that can be built:

- Drupal hosting cannot assume long-running processes. A baseline must work
  on plain PHP-FPM with no Node.js sidecar, message broker, or push hub.
- The server must remain authoritative. Drupal validates inputs, enforces
  access, renders the preview, and persists drafts; a state merged purely
  client-side that the server never validated is not acceptable.
- The front end should stay simple (ADR 0005) and avoid new bundled
  dependencies (ADR 0007).
- The component tree is UUID-addressed with the tree structure and the
  per-component input values held separately, so edits to different
  components are naturally independent; the genuinely conflicting cases are
  few and enumerable.

Client-side CRDT libraries (Yjs, Automerge, Loro) and OT servers (ShareDB)
were evaluated and rejected: all invert authority by converging state on the
client or in a Node.js relay, store opaque state the PHP server cannot
validate or render previews from, and require infrastructure Drupal sites
cannot assume. CRDTs earn their complexity for long offline divergence and
peer-to-peer merging; Canvas editors are online co-editors against one
authoritative server.

## Decision

Real-time collaboration is built on a server-authoritative, per-draft
operation log, with pluggable delivery and presence:

1. **Atomic operations replace full-document writes.** The editor expresses
   every change as a discrete operation (insert, delete, duplicate, move,
   shift, sort, per-component input patch, entity field patch). A store
   listener middleware mirrors editor actions to the server, which applies
   each operation atomically to the shared draft and returns the updated
   preview. Full-document layout writes are removed from the editor.
2. **The server assigns a total order.** Each accepted operation receives a
   per-draft, monotonically increasing sequence number, assigned under a
   per-draft lock. Operations carry a client-generated operation id for
   idempotent retries and a client instance id for echo suppression. Clients
   apply their own operations optimistically, apply remote operations in
   sequence order, and rebase unacknowledged local operations on top.
3. **Conflicts merge deterministically; nothing is rejected.** Operations on
   different components commute. Concurrent input patches to the same
   component resolve last-writer-wins per individual input. An operation
   targeting a concurrently deleted component resolves to a no-op and the
   affected user is notified. Ordering operations resolve against current
   server state, falling back to the nearest valid position. Hash-based
   conflict rejection remains only at the draft-versus-published boundary.
4. **One shared draft per entity, many editors.** All permitted editors
   attach to the same pending draft instead of being locked out by its
   owner. The operation log is pruned when the draft is published or
   discarded and may be compacted into the draft snapshot while retaining a
   tail for connected clients; clients beyond the tail reload the snapshot
   and resume. Every operation result carries a post-apply state hash, and a
   client detecting a mismatch reloads the snapshot automatically.
5. **Transport is pluggable, polling is the baseline.** Delivery of
   operations and presence goes through a transport plugin interface. The
   default transport polls over HTTP and piggybacks missed operations on
   operation responses, so collaboration works with no extra infrastructure.
   Push transports (Mercure/SSE first; WebSocket possible) are configuration,
   not editor code changes, and fall back to polling. Subscriptions are
   access-checked per draft regardless of transport.
6. **Presence uses expiring sessions and soft locks.** Editors register a
   heartbeat-refreshed presence session with a short TTL; the roster,
   per-user selections, and "editing this component" markers broadcast over
   the same transport. A component form held by another editor opens
   read-only by default with an explicit override. Locks are advisory and
   expire with the session; nothing can be permanently wedged.
7. **Undo is per user and operation-based.** Undo submits inverses of the
   user's own operations through the same pipeline instead of restoring
   whole-state snapshots, so undo never reverts another editor's work. An
   inverse whose target no longer exists is skipped with a notice.

## Consequences

Easier:

- Multiple editors can work on the same entity draft simultaneously with
  convergent results and no lost work; the implicit one-editor lock from
  workspace staging is lifted.
- Single-editor writes become smaller and cheaper than full-document writes.
- Presence, attribution, and per-component awareness become possible because
  every change is a discrete, attributed operation.
- Sites choose their latency: zero-infrastructure polling by default, push
  delivery as configuration.

More difficult, and risks to mitigate:

- Server-side operation appliers must mirror client tree semantics exactly;
  divergence is mitigated by post-apply state hashes with automatic snapshot
  reload and error reporting.
- Per-draft sequencing serializes writes on the server; the lock window is
  per operation and small, which is sufficient for human editing rates but a
  ceiling for automation writing at high frequency.
- Last-writer-wins per input can lose one editor's value when two editors
  edit the same input simultaneously; soft locks and notices make this rare
  and visible. Character-level merging within a single text input is out of
  scope and would layer on top later.
- The undo model changes from snapshots to operation inverses, a
  user-visible behavior change that needs thorough coverage.
- External consumers of the full-document write API must migrate to
  operations; a deprecated shim translating full writes into a derived
  operation set eases the transition.
