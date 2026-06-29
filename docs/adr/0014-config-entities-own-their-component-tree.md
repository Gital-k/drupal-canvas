# 14. Config entities own a single component tree instance

Date: 2026-06-29

## Status

Accepted

## Context

The typed-data representation of a component tree is a specialized field-item list. Every entity that stores a component tree implements a common interface, which exposes the tree via a single method returning this list type.

Two storage paths exist. Content entities store the tree as a real typed-data field. The method on a content entity returns the live field item list — the same object every time, so mutations made through it are automatically visible to all callers and persist on save.

Config entities store the tree as a plain PHP array that satisfies the config schema. To service the interface, the array must be wrapped in the list type. Without a deliberate design decision for how that wrapping is managed, the natural implementation wraps on every call — returning a fresh object each time.

A fresh-object-per-call implementation has a critical footgun: any mutation made through one call's return value is invisible to a subsequent call's return value, because they are different objects. This forces every mutating caller to write its result back to the entity after modifying the tree. Forgetting this write-back silently discards edits, with no type system or runtime warning to catch the mistake. The pattern spreads an implicit write-back requirement across every callsite that touches a config entity's component tree, creating implicit coupling and a divergence from the content-entity developer experience.

The asymmetry between the two paths is a persistent source of bugs and developer confusion. Config entity callers must follow a different protocol (mutate → write back) than content entity callers (mutate directly), despite both paths presenting the same list type. The distinction is not visible in the return type or any documentation that travels with the value.

A secondary issue is that the logic for constructing the list — obtaining a typed data manager and wiring up the definition — lives in a trait used by multiple unrelated classes. Placing instantiation logic in a trait is a code smell: the class that knows best how to create itself is the list class, not a mix-in imported by its callers.

## Decision

### 1. Config entities own one lazily-instantiated component tree list

Each config entity that stores a component tree holds a single cached list instance, initialized to absent. The base class for all such config entities implements the `getComponentTree()` method once:

- On first call, a list is created, parented to the config entity for context (cacheability, entity resolution), and populated from the stored array. The instance is cached.
- On subsequent calls, the cached instance is returned directly.

All config-entity subclasses delete their own separate implementations of `getComponentTree()` and inherit the base. The exception is any subclass-specific method that accepts a content entity as context for render-preview purposes: that method creates a separate, purpose-built list for the preview context and is documented as distinct from the owned instance.

The owned instance is parented to the config entity. This is equivalent to what content entities provide naturally through the field API, and means entity reference resolution, root traversal, and cacheability all work correctly on the owned instance.

### 2. The stored array and the owned list are kept in two-way sync

The sync is **asymmetric in eagerness**.

**Array → list (eager).** When the entity's raw array representation is updated via the standard property-set path, the raw write happens first. If the owned list has already been instantiated, the list is immediately reconciled to match the new array. This direction is eager because the set path is the only write path and is fully interceptable.

**List → array (deferred).** PHP provides no mechanism to intercept mutations to a typed-data item list's internal state once an item object has been handed out. Callers can mutate item objects in place without triggering the set path. The list's internal state therefore cannot be kept in sync with the stored array in real time. Instead, synchronization is deferred to boundary chokepoints: config export and pre-save processing each derive the stored array from the owned list before they execute.

The deferred sync means that the stored array may lag behind the owned list between a list mutation and the next chokepoint. Code within the config entity base class that needs the current array representation must read through the chokepoints or through the owned list directly.

### 3. Array → list sync preserves component instance object identity

When the owned list is reconciled from a new array value, object identity for surviving component instances is preserved: the same item object is reused for any instance whose UUID appears in both the old and new arrays. Its stored values are updated in place. New UUIDs get freshly created items; removed UUIDs are dropped. Delta positions are updated to match the new order.

Preserving object identity matters when callers hold a reference to a specific component instance obtained before the array was updated: their reference remains valid and reflects the updated values. Without identity preservation, such references would silently become stale.

### 4. Instantiation logic lives on the list class itself

The static factory methods for creating a list instance replace the spread-across-a-trait approach. The list class is the authoritative place to know how to instantiate itself. The existing trait and its test counterpart are deleted. All callers that used the trait's methods are updated to call the static factory directly.

For cases where a list must be created without storage ownership — validation, AI response checking, and similar non-entity-bound operations — the factory accepts a typed data manager directly and takes an optional context entity that sets the typed-data parent for cacheability and entity resolution. This context entity is orthogonal to storage ownership: a list without a storage owner may still have a typed-data parent.

### 5. Lifecycle boundaries reset the owned instance

A shallow clone of a config entity shares the same list reference as the original. Mutations through the clone would silently affect the original and vice versa. Both `createDuplicate()` and `__clone()` reset the cached list on the clone, forcing a fresh instance to be built on the next call to `getComponentTree()`. This is a new-identity boundary, not a staleness invalidation: the clone's stored array already contains the correct values and will seed the new instance correctly.

Serialization excludes the cached list. The back-reference from the list to its parent entity adapter would otherwise create a circular reference that breaks PHP serialization. Config export reads from the stored array representation, so excluding the cached list from serialization is safe.

### Cross-references

- [ADR #10](0010-dynamic-config-schema-for-component-tree-translatability.md) — translatability classification; the deferred sync and export chokepoint must preserve the UUID-keyed sequence format that config translations target.
- [ADR #12](0012-symmetric-content-translation-store-all-inputs-validate-at-write-time.md) — symmetric translation invariant; the owned list must remain correct across write-time synchronization.
- [ADR #13](0013-symmetric-translation-propagation-on-component-instance-update.md) — translation propagation; per-langcode merged trees are constructed as separate lists for the merge and do not interact with the owned instance.

## Consequences

In order of importance, with the following markers:
- positives (`+`) vs negatives (`-`) vs status quo (`≃`)
- impact types: technical (`T`) vs operational (`O`) vs business (`B`)

1. `+TB` **The write-back footgun is eliminated.** Mutating callers no longer need to write the tree back to the entity after modifying it. The owned instance accumulates mutations directly. Code that forgot the write-back was silently discarding edits; after this decision, that class of bug cannot occur.

2. `+T` **Config and content entity callers share one protocol.** The tree-access method on any entity returns a live, mutation-visible list. Callers need not know or care whether they are working with a content entity or a config entity.

3. `+T` **Zero cost when unused.** Entities that are loaded and exported without any component tree operation pay no instantiation cost and produce a byte-identical export: the array is never wrapped in a list, and the list is never derived back to an array.

4. `+T` **Component instance object identity is preserved across array writes.** Code that holds a reference to a component instance obtained before an array update retains a valid, up-to-date reference after the update.

5. `+T` **Instantiation logic consolidated.** The factory lives on the list class. The trait is deleted. Callers that needed the trait for instantiation are simpler.

6. `-T` **List → array sync is deferred, not real-time.** Code that reads the stored array directly after mutating the owned list will see stale values until the next chokepoint. All internal code in the config entity base class and its subclasses must access the array representation only through the sync chokepoints or through the owned list.

7. `-T` **Clone and duplicate operations must reset the cached instance.** Forgetting this reset on a future subclass that overrides either operation would re-introduce the cross-entity aliasing hazard. The invariant is non-obvious.

8. `≃T` **Per-langcode translated trees are unaffected.** They construct a separate list for each translation's merged tree and do not interact with the owned instance. The sparse-override handling required by config translation storage remains in place.
