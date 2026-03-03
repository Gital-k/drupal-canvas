# The Drupal Canvas Data Model

In the rest of this document, `Drupal Canvas` will be written as `Canvas`.

This builds on top of the [`Canvas Components` doc](components.md). Please read that first.

Some of the examples here refer to details that _component types_ that use
[`Canvas Shape Matching into Field Types` doc](shape-matching-into-field-types.md). It should be possible to first read this
without having read that, to understand the big picture. It is recommended to first read this, then that one, followed
by a second pass of this document.

It also builds on top of the [`Canvas Config Management` doc](config-management.md), which itself refers back to this one
for a few things. The data model is built on top of the configuration architecture.

**Also see the [diagram](diagrams/data-model.md).**

## Finding issues 🐛, code 🤖 & people 👯‍♀️
Related Canvas issue queue components:
1. [Data model](https://www.drupal.org/project/issues/canvas?component=Data+model)

Those issue queue components also have corresponding entries in [`CODEOWNERS`](../CODEOWNERS).

If anything is unclear or missing in this document, create an issue in one of those issue queue components and assign it
to one of us! 😊 🙏

## 1. Terminology

### 1.1 Existing Drupal Terminology that is crucial for Canvas

- _content entity_: an entity that can be created by a Content Creator, containing various `fields`, potentially including the `Canvas field type`, of a particular entity type (e.g. "node")
- _data type_: Drupal's smallest unit of representing data, defines semantics and typically comes with validation logic and convenience methods for interacting with the data it represents ⚠️ Not all data types in Drupal core do what they say, see `\Drupal\canvas\Plugin\DataTypeOverride\UriOverride` for example. ⚠️
- `field`: synonym of `field item list`
- _field prop_: a property defined by a _field type_, with a value for that property on such a _field item_, represented by a _data type_. Often a single prop exists (typically: `value`), but not always (for example: the `image` field type: `target_id`, `entity`, `alt`, `title`, `width`, `height` — with `entity` a `computed field prop`)
- _field instance_: a definition for instantiating a _field type_ into a `field item list` containing >=1 _field item_
- _field item_: the instantiation of a _field type_
- `field item list`: to support multiple-cardinality values, Drupal core has opted to wrap every _field item_ in a list — even if a particular _field instance_ is single-cardinality
- _field type_: metadata plus a class defining the _field props_ that exist on this field type, requires a _field instance_ to be used
- `SDC`: see [`Canvas Components` doc](components.md)
- _theme region_: see [`Canvas Config Management` doc](config-management.md)
- _view mode_: view modes lets a _content entity_ be displayed in multiple ways

### 1.2 Canvas terminology

- _component_: see [`Canvas Components` doc](components.md)
- `Component config entity`: see [`Canvas Config Management` doc](config-management.md)
- _component instance_: a UUID uniquely identifying this instance + _component version_ + values for each required _component input_ (if any) + optionally values for its _component slots_ (if any)
- _component node_: one of the node types in the UI data model, representing a _component instance_ in the _component tree_
- _component input_: see [`Canvas Components` doc](components.md)
- _component slot_: see [`Canvas Components` doc](components.md)
- `Component Source Plugin`: see [`Canvas Components` doc](components.md)
- _component tree_: a tree of _component instances_, by placing >=1 _component instances_ in a particular order in another _component instance_'s slot
- `component tree field type`: Canvas's field type that allows storing a _component tree_ ⚠️ This is currently limited to the "default" _view mode_, and hence one component tree per _content entity_. ⚠️
- `component tree root`: the root of the _component tree_ is the special case: it does not exist in another _component_, but it behaves the same as any other _component slot_
- _component type_: see [`Canvas Components` doc](components.md)
- _component version_: a version (a deterministic hash) identifying the _version_ of a `Component config entity` either because the underlying _component_ itself changed, or because the default `static prop sources` changed due to modified shape matching
- `content type template`: see [`Canvas Config Management` doc](config-management.md).
- `layout`: synonym of _component tree_
- _prop expression_: see [`Canvas Shape Matching into Field Types` doc](shape-matching-into-field-types.md)
- _prop source_: see [`Canvas Shape Matching into Field Types` doc](shape-matching-into-field-types.md)
- `static prop source`: see [`Canvas Shape Matching into Field Types` doc](shape-matching-into-field-types.md)
- `entity field prop source`: see [`Canvas Shape Matching into Field Types` doc](shape-matching-into-field-types.md)
- _region node_: one of the node types in the UI data model, representing a _theme region_'s _component tree_
- _slot node_: one of the node types in the UI data model, representing a _component instance_'s _component slot_
- `Canvas field`: an instance of the `component tree field type`
- `Canvas field type`: see `component tree field type`

## 2. Product requirements

This uses the terms defined above.

This adds to the product requirements listed in [`Canvas Components` doc](components.md) and [`Canvas Config Management` doc](config-management.md).

(There are [more](https://docs.google.com/spreadsheets/d/1OpETAzprh6DWjpTsZG55LWgldWV_D8jNe9AM73jNaZo/edit?gid=1721130122#gid=1721130122), but these in particular affect Canvas's data model.)

- MUST have validation logic that generates consistent validation error messages for either content (a _component tree_ created by the Content Creator and stored in a _content entity_) or config (a _component tree_ created by the Site Builder and stored in a `content type template`)
- MUST support both symmetric and asymmetric translations (same vs different `layout` per translation, respectively)
- SHOULD facilitate real-time collaborative editing

## 3. Implementation

This uses the terms defined above.

Given a component developed by a [Front-End Developer](diagrams/structurizr-SystemContext-001.md): how does Canvas allow a
Content Creator to place a _component instance_ in the _component tree_, specify values for the _component inputs_ and
_component slots_?

### 3.1 Data Model: from Front-End Developer to a Canvas data model that empowers the Content Creator

Moved to the [`Canvas Shape Matching into Field Types` doc](shape-matching-into-field-types.md).


### 3.2 Data Model: storing a component tree

The _component tree_ is represented by a `\Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList`, which
contains one field value for each _component instance_ in the tree.
Each _component instance_ is represented by a `\Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem`, which
each allow accessing the `Component config entity` and `Component Source Plugin` that represents the _component_.

See `\Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem` + its validation constraint.

Canvas defines a new `Canvas field type` with the following _field props_:
- _uuid_ — A unique ID for this _component instance_
- _component_id_ — This is the ID of the `Component config entity` this _component instance_ references
- _component_version_ — This is the version of the `Component config entity` this _component instance_ uses.
- _parent_uuid_ — If this _component instance_ is placed inside another _component instance_ in the tree, the UUID of the parent _component instance_
- _slot_ — If this _component instance_ is placed inside another _component instance_ in the tree, the machine name of the _component slot_ in which it is placed. This slot must exist in the parent _component instance_.
- _inputs_ — see 3.2.2

When _parent_uuid_ and _slot_ are empty, the _component instance_ is at the root of the _component tree_.

Additionally there are two computed _field props_:
- _component_ - this is an entity reference to the `Component config entity` the _component instance_ uses, meaning also the appropriate version will be loaded. Any methods on the `Component config entity` can be chained. E.g. `$item->get('component')?->getComponentSource()`.
- _parent_item_ - this is a data reference to the sibling `\Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem` in the tree that represents the _component instance_'s parent _component instance_ in the _component tree_. If the _component instance_ has no parent, this will be NULL. Any methods on the parent _component instance_ can be chained, e.g. `$item->get('parent_item')->getComponent()?->getComponentSource()?->getSlotDefinitions()`

Additionally, convenience methods for accessing/setting values on the `ComponentTreeItem` exist including:
- `getParentUuid(): ?string` - gets the value of _parent_uuid_ if it exists
- `getParentComponentTreeItem(): ?ComponentTreeItem` - gets the parent _component instance_ if it exists
- `getSlot(): ?string` - gets the _component slot_ machine name if it exists
- `getComponent(): ComponentInterface` - gets the `Component config entity` at the specified version
- `getComponentId(): string` - gets the ID of the `Component config entity`
- `getComponentVersion(): string` - gets the version of the `Component config entity` used for this instance
- `getUuid(): string` - gets the UUID of the _component instance_
- `getInputs(): ?array` - gets the explicit inputs of the _component instance_ as an array (JSON decoded)
- `getInput(): ?string` - gets the explicit inputs of the _component instance_ as a string (JSON encoded)
- `setInput(array|string $input): static` - sets the inputs, can be passed as either a string (JSON encoded) or an array
- `getLabel(): ?string` - gets the (optional) label for the _component instance_ to provide context for content authors
- `setLabel(?string $label): self` - sets the (optional) label for the _component instance_ to provide context for content authors

Storing these as separate _field props_ simplifies supporting both symmetric and asymmetric translations:
- the _inputs_ column group (just the `inputs` column) group SHOULD always be translatable
- the _tree_ column group (comprising `uuid`, `component_id`, `component_version`, `parent_uuid` and `slot`) can be either:
  1. marked translatable for _asymmetric translations_ (a different _component tree_ per _content entity_ translation)
  2. marked untranslatable for _symmetric translations_ (same _component tree_ for all _content entity_ translations)

(Drupal's Content Translation module natively supports configuring this.)

#### 3.2.1 The columns (_field props_) storing the tree structure

The `uuid`, `component_id`, `component_version`, `parent_uuid` and `slot` columns model the tree structure.

See `\Drupal\canvas\Plugin\DataType\ComponentTreeStructure` + its validation constraint.

These columns always meet the following requirements
1. every _component instance_ is represented by a "uuid, component_id, component_version" triple, with:
  - the value for "component_id" being the ID of a `Component config entity` (NOT that of the underlying _component_)
  - the value for "component_version" being a version on the (versioned!) `Component config entity` (see `\Drupal\canvas\Entity\VersionedConfigEntityInterface::getVersions()`)
  - the "uuid" being a randomly generated UUID
2. Any top-level items have NULL for both the `parent_uuid` and `slot`.
3. Nested components must have a value for both the `parent_uuid` and `slot`.
    1. The `parent_uuid` must exist in a sibling field item in the `ComponentTreeItemList`.
    2. The `slot` must be present in the parent _component_'s slot definitions
    3. The `parent_uuid` must not be the same as the `uuid` - you cannot reference yourself as a parent
4. Each `uuid` must be unique in the list of items
5. The `delta` of each field item represents the order that components in the same level of the tree appear in.

#### 3.2.2 The column (_field prop_) storing the _component input_ values

See
- `\Drupal\canvas\Plugin\DataType\ComponentInputs`
- `\Drupal\canvas\ComponentSource\ComponentSourceInterface::getExplicitInput()`
- `\Drupal\canvas\ComponentSource\ComponentSourceInterface::validateComponentInput()`

_This uses 3.1._

The _component tree_'s _inputs_ _field prop_ has a trivial representation that could easily change. It is stored as a
JSON blob, and meets the following requirements:
1. it contains opaque arrays that are validated by that source's `::validateComponentInput()` and are decodable using that source's
   `::getExplicitInput()`
2. the `inputs` for a given component live in the same field-item as its corresponding `uuid`, `component_id`, `parent_uuid` and `slot`

Note: this simplifies different (symmetric) translation strategies: it's trivial to either reuse another translation's
_inputs_ _field prop_ (to show what to translate from) or not reuse anything at all — that needs only array intersection.

Note: a welcome bonus is that when real-time collaborative editing is eventually added, one user can move a
_component instance_ while another edits the _inputs_ of that same _component instance_, without causing a conflict.
This is because editing will be specific to a _component instance_, which is modeled as a single delta.

No validation is necessary for this _field prop_, because it is more easily validated at the _field item_ level of the
`Canvas field type`, not at the _field prop_ level — there, the aforementioned `::validateComponentInput()` method is called
for every _component instance_ encountered in the stored _component tree_. If the `Component Source Plugin` complains, a
validation error occurs.

Example: A simple tree showing a root item (`41595148-e5c1-4873-b373-be3ae6e21340`) with a child (`3b305d86-86a7-4684-8664-7ef1fc2be070`) in the `body` slot, plus another root item (`41595148-e5c1-4873-b373-be3ae6e21340`).
```php
[
  'uuid' => '41595148-e5c1-4873-b373-be3ae6e21340',
  'component_id' => 'sdc.canvas_test_sdc.props-slots',
  'component_version' => '85a5c0c7dd53e0bb',
  'inputs' => [
    'heading' => [
      'sourceType' => 'static:field_item:string',
      'value' => "Hello, world!",
      'expression' => 'ℹ︎string␟value',
    ],
  ],
],
[
  'uuid' => '3b305d86-86a7-4684-8664-7ef1fc2be070',
  'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
  'component_version' => 'b1e991f726a2a266',
  'parent_uuid' => '41595148-e5c1-4873-b373-be3ae6e21340',
  'slot' => 'the_body',
  'inputs' => [
    'heading' => [
      'sourceType' => 'static:field_item:string',
      'value' => "It's me!",
      'expression' => 'ℹ︎string␟value',
    ],
  ],
  [
    'uuid' => '41595148-e5c1-4873-b373-be3ae6e21340',
    'component_id' => 'block.system_branding_block',
    'component_version' => '247a23298360adb2',
    // Example, that populates a Block component instance.
    // Note how much simpler the stored information is, because it uses the Block system's native input UX:
    'inputs' => [
      'label' => '',
      'label_display' => '0',
      'use_site_logo' => TRUE,
      'use_site_name' => TRUE,
      'use_site_slogan' => TRUE,
    ],
  ],
],
```

The above is the _runtime_ representation.

The _stored_ representation is optimized for compactness, and each `ComponentSource` is the authority for how to
optimize the stored data. For example:
- `ComponentSources` whose explicit inputs are populated by `StaticPropSources` can decide that all instances of some
  _component version_ must use the exact same `StaticPropSource`. That enables them to store only the value inside the
  `StaticPropSource`, and load the metadata when needed. Result: see below.
- The `Block` `ComponentSource` _could_ (but does not yet at the time of writing) choose to omit `label` and
  `label_display` settings (which exist for _every_ block plugin), because it's forcing it to not have a label anyway.

```php
[
  'uuid' => '41595148-e5c1-4873-b373-be3ae6e21340',
  'component_id' => 'sdc.canvas_test_sdc.props-slots',
  'component_version' => '85a5c0c7dd53e0bb',
  'inputs' => [
    // Note how much simpler this is compared to the runtime representation above.
    'heading' => "Hello, world!",
  ],
],
[
  'uuid' => '3b305d86-86a7-4684-8664-7ef1fc2be070',
  'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
  'component_version' => 'b1e991f726a2a266',
  'parent_uuid' => '41595148-e5c1-4873-b373-be3ae6e21340',
  'slot' => 'the_body',
  'inputs' => [
    // Note how much simpler this is compared to the runtime representation above.
    'heading' => "It's me!",
  ],
  [
    'uuid' => '41595148-e5c1-4873-b373-be3ae6e21340',
    'component_id' => 'block.system_branding_block',
    'component_version' => '247a23298360adb2',
    'inputs' => [
      'label' => '',
      'label_display' => '0',
      'use_site_logo' => TRUE,
      'use_site_name' => TRUE,
      'use_site_slogan' => TRUE,
    ],
  ],
],
```

#### 3.2.3 Validation

Assuming the _tree_ column groups (`uuid`, `component_id`, `parent_uuid` and `slot`) has already been validated, a _component tree_ described in an `Canvas field` then is valid
when: for each _component instance_ in the _tree_ _field prop_:
1. getting the explicit input using `ComponentSourceInterface::getExplicitInput()` (which for `Block` requires no extra
   work but for `SDC` involves resolving the stored _prop sources_, resulting in values to be passed to the
   corresponding _component inputs_)
2. calling `ComponentSourceInterface::validateComponentInput()` (which for `Block` uses config schema validation and for
  `SDC` checking if `\Drupal\Core\Theme\Component\ComponentValidator::validateProps()` does not throw an exception)

#### 3.2.4 Facilitating _component inputs_ changes

When a _component_ evolves, _some_ _component inputs_ cannot happen without also updating the stored _component tree_. In
other words: an upgrade path is necessary if a Front-End Developer makes certain drastic changes:
- renaming a _component input_
- changing the schema of a _component input_
- adding a new _required_ _component input_

Here too, storing the _inputs_ as separate _field props_ is helpful. An upgrade path for a _component_ would
require logic somewhat like this:

1. SQL query to search the `component_id` column for uses of this _component_, capture the UUIDs. If 0 matches: break.
2. If >0 matches, PHP logic computes the necessary changes.
3. Insert the updated _inputs_ JSON blob into that specific delta.

The above sequence assumes doing this per-entity. But this can actually be done _per entity-type_, or more precisely:
per `Canvas field`. So if the `Canvas field type` is only used for one entity type but is used in many bundles (i.e. many
different `content entity types`), then a single query can find all _component instances_ of the evolving _component_.
After that point, the typical Drupal update path best practices apply. The key observation here: it is possible to
efficiently find all uses of a _component_.

### 3.3 Data Model: rendering a stored _component tree_

See `\Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList`.

_This uses 3.2.1, 3.2.2 and 3.2.3._

Thanks to the validation in 3.2.3, it is guaranteed that each individual _component instance_ _can_ be rendered. But the
goal is of course to render a _component tree_ (not _component instances_), by starting at the root and rendering each
_component instance_ in the specified _component slot_.

To hydrate the stored _component tree_:
1. get (flat) list of _component instances_ from the _tree_ _field prop_ (3.2.1): a list of `uuids`
2. load the corresponding `Component config entity` for each _component instance_ given its UUID, which in turn enables
   loading the corresponding `Component Source Plugin`
3. get the explicit input from the _inputs_ _field prop_ (3.2.2) for each _component instance_, by using the `Component
   Source Plugin`'s `::getExplicitInput()` method
   - for `Component Source Plugins` with their own input UX (such as `BlockComponent`), that's just forwarding the
     stored values
   - for those without their own input UX (such as `SingleDirectoryComponent`), that may require additional resolving or
     evaluating (such as resolving the stored _prop source_ — see 3.1)
4. pass those explicit inputs to each _component instance_, resulting in a list of hydrated _component instances_
5. transform that list to a tree by respecting the _tree_ _field prop_ (3.2.1), by placing nested _component instances_
   in the specified _component slot_ of the specified parent _component instance_ (special case: the root)

To render the stored _component tree_, it must first be hydrated it (see above), after which it can be
converted to a render array.

### 3.4 UI Data Model: communicating a _component tree_ to the front end

All prior sections refer to the data model that is _stored_ (on the back end). But what makes sense on the back end does
not necessarily make sense on the front end:
- the back end must integrate with many (server-side) Drupal subsystems, and it should as much as possible avoid
  burdening the front end with those implementation details
- the front end has different data structure needs, specifically the need for highly frequent changes, including
  concurrent ones during collaborative real-time editing

The front end needs the _component tree_ to generate a preview that the end
user can modify and interact with. For this we split the tree into `layout`
and `model` parts. The `layout` represents the tree's overall structure
and the `model` represents data for each _component_ within that tree.
The `model` is stored as a flat structure so it can more easily be queried
by the front end.

The front end `layout` is a set of nodes, where each node can be one of the
following types, represented by the `nodeType` key:

- `'component'` which represents a _component instance_
- `'slot'` which represents a _component slot_ in a _component instance_.
- `'region'` which represents a separate _theme region_ in the user interface.

Each node in the _component tree_ is described with a `nodeType` key which is one of the above 3 strings.

The top level of the `layout` structure is an array of zero or more `region` nodes.

#### 3.4.1 _component nodes_

A _component node_ represents a single _component instance_ in the _component tree_ and
will contain zero or more _component slots_.

_component nodes_ have the following keys
- `uuid`: a unique identifier for the _component instance_.
- `type`: an opaque string containing a `Component config entity` ID + version that this instantiates
- `name`: a name assigned by a Content Creator, to for example distinguish this particular _component instance_ of some
  _component_ among the 20 such in the current component tree
- `slots`: an object of _slot nodes_ representing each _component slot_ of this _component instance_ (including empty
  slots)

An example simple _component instance_ of a _component_ with no _component slots_, and with a name for the _component
instance_ specified by the Content Creator:
```json
{
  "nodeType": "component",
  "id": "380aaa26-5678-4c86-9b32-12161ea34196",
  "name": "Most Important Heading",
  "type": "sdc.canvas_test_sdc.heading@1b4f8df7c94d7e3c",
  "slots": []
}
```

An example simple _component instance_ of a _component_ with a single _component slot_ that is empty:
```json
{
  "nodeType": "component",
  "id": "177122af-1679-4ee4-b700-dcf5ab376c4a",
  "type": "sdc.canvas_test_sdc.one_column@f6a3a392e98e8342",
  "slots": [
    {
      "id": "177122af-1679-4ee4-b700-dcf5ab376c4a/content",
      "name": "content",
      "nodeType": "slot",
      "components": []
    }
  ]
}
```

#### 3.4.2 _slot nodes_

A _slot node_ must be the child of a _component node_.

_slot nodes_ have the following keys
- `name`: a human-readable name that may be displayed to the user.
- `components`: an array of _component nodes_ that represent the top-level _component instances_ for this _component slot_
- `id`: a unique ID made up of the `uuid` of the parent component followed by the _component slot_ name, separated by a slash.

```json
{
  "nodeType": "slot",
  "id": "380aaa26-5678-4c86-9b32-12161ea34196/column_one",
  "name": "Column one",
  "components": []
}
```

#### 3.4.3 _region nodes_

A _region node_ can only exist at the top level in the `layout` tree and can be
thought of as a special case of a `slot` that applies to the page rather than
a _component_. Just like a _slot node_, it can contain zero or more _component nodes_.

_region nodes_ have the following keys
- `id` is the identifier of the _theme region_.
- `name`: a human-readable name that may be displayed to the user.
- `components`: an array of _component nodes_ that represent the top-level _component instances_ for this _theme region_

The _theme region_ with the ID of `content` is treated specially by the server, and assumed to contain
the _content entity_. The front end should not need to do anything special
here except perhaps default to editing the `content` region (but perhaps the
server should express this default via a flag somewhere?).

```json
{
  "nodeType": "region",
  "id": "content",
  "name": "Content",
  "components": []
}
```

#### 3.4.4 The complete API response

The API response contains two top level keys:

- `layout`: the _component tree_ described above, using the 3 layout tree node types
- `model`: an array of model data for each _component node_ in the tree, keyed by the
UUID of the _component instance_.

(What if the model and layout get out of sync? We could theoretically have
UUIDs that don't have model values, or model values that are orphaned and
don't have corresponding components in the layout. The server side's validation logic forbids saving in this case.)

A complete example, with three _region nodes_:
* A `'header'` region with a single component instance.
* A `'content'` region with multiple, nested component instances a tree.
* An empty `'footer'` region.

```json
{
  "layout": [
    {
      "nodeType": "region",
      "id": "header",
      "name": "Header",
      "components": [
        {
          "nodeType": "component",
          "id": "a164fa84-0460-40b0-a428-bf332b4a792a",
          "type": "block.system_branding_block@247a23298360adb2",
          "slots": []
        }
      ]
    },
    {
      "nodeType": "region",
      "id": "content",
      "name": "Content",
      "components": [
        {
          "nodeType": "component",
          "id": "97fb7bb9-4c8e-4fdc-87a8-c39ac9e8e618",
          "type": "sdc.canvas_test_sdc.two_column@e5ef92acda2ee2d1",
          "slots": [
            {
              "nodeType": "slot",
              "id": "97fb7bb9-4c8e-4fdc-87a8-c39ac9e8e618/column_one",
              "components": [
                {
                  "nodeType": "component",
                  "id": "e8ecc571-0221-40d8-9ab2-262389fabd58",
                  "type": "sdc.canvas_test_sdc.heading@1b4f8df7c94d7e3c",
                  "slots": []
                },
                {
                  "nodeType": "component",
                  "id": "baf231e8-b214-4e3e-93d3-5d3f03a1eae9",
                  "type": "sdc.canvas_test_sdc.druplicon@some-version-string",
                  "slots": []
                }
              ]
            },
            {
              "nodeType": "slot",
              "id": "97fb7bb9-4c8e-4fdc-87a8-c39ac9e8e618/column_two",
              "components": [
                {
                  "nodeType": "component",
                  "id": "39648574-b937-4a5a-b1b2-9db0f30ae315",
                  "type": "sdc.canvas_test_sdc.one_column@f6a3a392e98e8342",
                  "slots": [
                    {
                      "nodeType": "slot",
                      "id": "39648574-b937-4a5a-b1b2-9db0f30ae315/content",
                      "components": [
                        {
                          "nodeType": "component",
                          "id": "a1cfa9f1-0088-45d9-b837-39571485b75e",
                          "type": "sdc.canvas_test_sdc.my-hero",
                          "slots": []
                        }
                      ]
                    }
                  ]
                }
              ]
            }
          ]
        }
      ]
    },
    {
      "nodeType": "region",
      "id": "footer",
      "name": "Footer",
      "components": []
    }
  ],
  "model": {
    "a164fa84-0460-40b0-a428-bf332b4a792a": {},
    "97fb7bb9-4c8e-4fdc-87a8-c39ac9e8e618": {},
    "e8ecc571-0221-40d8-9ab2-262389fabd58": {
      "text": "Heading",
      "style": "primary",
      "element": "h1"
    },
    "baf231e8-b214-4e3e-93d3-5d3f03a1eae9": {},
    "39648574-b937-4a5a-b1b2-9db0f30ae315": {},
    "a1cfa9f1-0088-45d9-b837-39571485b75e": {
      "heading": "Hero",
      "subheading": "My subheading"
    }
  }
}
```

### 3.5 Data Model: dealing with `components` evolution

The stored _component trees_ contain _component instances_ tied with specific versions of the associated `Component config entity`. But every _component_ can evolve, which will result in new versions.

Each _component source_ is able to specify an updater class (see `\Drupal\canvas\ComponentSource\ComponentInstanceUpdaterInterface`).
This updater will be responsible for:

* Checking if a _component instance_ is using the active version.
* Deciding if a _component instance_ not using the active version can be updated automatically without any data loss.
* Performing that update.

This update of the _component tree_'s_component instances_ will happen automatically
as soon as we attempt to edit a component tree (see `\Drupal\canvas\Controller\ApiLayoutController::buildRegion()` and
`\Drupal\canvas\Controller\ApiConfigControllers::normalize()`).

For those _component sources_ not providing an input UX (see [`Canvas Components` doc](components.md)), the scenarios
where such an update is possible without any risk are:

- Adding optional props
- Adding slots
- Changing props from required to optional
- Changing a prop matched prop shape field widget (but only the widget!)
- Changing default values in prop_field_definitions
- Changing slot examples
- Removing props (required or optional)
- Removing slots
- Adding a new required prop.
- Changing props from optional to required

Unsafe changes (that prevent auto-update) include:

- Changing prop shapes

See
- `\Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentInstanceUpdater::canUpdate`
