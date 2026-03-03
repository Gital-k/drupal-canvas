
# Drupal Canvas's Shape Matching

In the rest of this document, `Drupal Canvas` will be written as `Canvas`.

This builds on top of the [`Canvas Components` doc](components.md). Please read that first.

**Also see the [diagram](diagrams/data-model.md).**

## Finding issues 🐛, code 🤖 & people 👯‍♀️
Related Canvas issue queue components:
- [Shape matching](https://www.drupal.org/project/issues/canvas?component=Shape+matching) (see section
   3.1.2 below, and specifically 3.1.2.a)
- [Redux-integrated field widgets](https://www.drupal.org/project/issues/canvas?component=Redux-integrated+field+widgets)
- [Data model](https://www.drupal.org/project/issues/canvas?component=Data+model)

Those issue queue components also have corresponding entries in [`CODEOWNERS`](../CODEOWNERS).

If anything is unclear or missing in this document, create an issue in one of those issue queue components and assign it
to one of us! 😊 🙏

## 1. Terminology

### 1.1 Existing Drupal Terminology that is crucial for Canvas

- `computed field prop`: not every _field prop_ has their value _stored_, some may have their value _computed_ (for example: the `file_uri` field type's `url` prop)
- _base field_: an _entity field_ that exists for _all_ bundles of an entity type, typically defined in code
- _bundle field_: a _field instance_ that exists for _some_ bundles of an entity type, typically defined in config
- _content entity_: an entity that can be created by a Content Creator, containing various `fields` of a particular entity type (e.g. "node")
- _content type_: a definition for content entities of a certain entity type and bundle, and hence every _content entity_ of this bundle is guaranteed to contain the same _bundle fields_
- _data type_: Drupal's smallest unit of representing data, defines semantics and typically comes with validation logic and convenience methods for interacting with the data it represents ⚠️ Not all data types in Drupal core do what they say, see `\Drupal\canvas\Plugin\DataTypeOverride\UriOverride` for example. ⚠️
- _entity field_: a definition on the entity type or bundle level for creating a _field instance_ on such an entity — see _base field_ and _bundle field_
- `field`: synonym of `field item list`
- _field prop_: a property defined by a _field type_, with a value for that property on such a _field item_, represented by a _data type_. Often a single prop exists (typically: `value`), but not always (for example: the `image` field type: `target_id`, `entity`, `alt`, `title`, `width`, `height` — with `entity` a `computed field prop`)
- _field instance_: a definition for instantiating a _field type_ into a `field item list` containing >=1 _field item_
- _field item_: the instantiation of a _field type_
- `field item list`: to support multiple-cardinality values, Drupal core has opted to wrap every _field item_ in a list — even if a particular _field instance_ is single-cardinality
- _field type_: metadata plus a class defining the _field props_ that exist on this field type, requires a _field instance_ to be used
- _field widget_: see [`Redux-integrated field widgets` doc](redux-integrated-field-widgets.md)
- `SDC`: see [`Canvas Components` doc](components.md)

### 1.2 Canvas terminology

- _component_: see [`Canvas Components` doc](components.md)
- _component input_: see [`Canvas Components` doc](components.md)
- _Component Source Plugin_: see [`Canvas Components` doc](components.md)
- _component type_: see [`Canvas Components` doc](components.md)
- _conjured field_: a _field instance_ that is not backed by code nor config, but generated dynamically to edit/store a value for a _component input_ as _unstructured data_
- _prop expression_: a (compact) string representing what context (entity type+bundle or field type) is required for retrieving one or more properties stored inside of that context; also has a typed PHP object representation to facilitate logic
- _prop shape_: a normalized representation of the schema for a _component input_, without metadata that does not affect the _shape_: a title or description does not affect what values _fit into this shape_; only necessary for `Component Source Plugins` that DO NOT provide their own input UX.
- _prop source_: a source for retrieving a prop value
  - `static prop source`: a _prop source_ powered by a _conjured field_ (i.e. _unstructured data_)
  - `entity field prop source`: a _prop source_ powered by a _base field_ or _bundle field_ (i.e. _structured data_)
  - `host entity URL prop source`: a _prop source_ that generates various URLs for the _content entity_ that contains it (e.g., the entity's canonical URL)
  - TBD: `remote prop source`: a _prop source_ powered by a remote source ("external data"), i.e. data stored outside Drupal
- _structured data_: the data model defined by a Site Builder in a _content type_, and whose smallest units are _field props_ — queryable by Views
- _unstructured data_: the ad-hoc data used to populate _component inputs_ that are not populated using _unstructured data_ — NOT queryable by Views, this should be minimized/discouraged
- `well-known prop shape`: a _prop shape_ that is _named_: one that is defined in a module's or theme's `/schema.json` file and is then referenced (`$ref`) from the JSON schema for that _component input_.

## 2. Product requirements

This uses the terms defined above.

This adds to the product requirements listed in [`Canvas Components` doc](components.md).

(There are [more](https://docs.google.com/spreadsheets/d/1OpETAzprh6DWjpTsZG55LWgldWV_D8jNe9AM73jNaZo/edit?gid=1721130122#gid=1721130122), but these in particular affect Canvas's data model.)

- MUST allow continuing to use existing Drupal functionality (notably: _field types_ and _field widgets_ for `Component Source Plugins` that do not have their own input UX)
- SHOULD encourage Content Creators to use _structured data_ whenever possible, _unstructured data_ should be minimized except where necessary
- MUST be able to facilitate changes in _component inputs_ (i.e. schema changes, that may result in a changed _prop shape_)

## 3. Implementation

This uses the terms defined above.

### 3.1 Data Model: from Front-End Developer to a Canvas data model that empowers the Content Creator

⚠️ This only applies to _components_ originating from a _Component Source Plugin_ that DO NOT have an input UX (such as
`SDC`), for others the UX and storage are both simply the existing one, and NOTHING in this document applies! ⚠️

#### 3.1.1 Interpreting _components_ without input UX: _prop shapes_

See `\Drupal\canvas\PropShape\PropShape`.

Each _component input_ must have a schema that defines the primitive type (string, number,  integer, object, array or
boolean), with typically additional restrictions (e.g. a  string containing a URI vs a date,  or an integer in a certain
range). That primitive type plus additional restrictions identifies a unique _prop shape_.

Some _prop sources_ only support particular _prop shapes_, others may be able to support virtually any _prop shape_.

#### 3.1.2 Finding fitting _field type_: _conjured fields_ and _entity fieldss_

Per the product requirements, existing _field types_ and _field widgets_ MUST be used, and ideally _structured data_
SHOULD be used.  But _field types_ can be configured, and depending on the configured settings, they may support rather
different _prop shapes_. For example: Drupal's "datetime" _field type_ can, depending on settings, store either:

- date only
- date and time

So, the settings for a _field type_ are critical: a _field type_ alone is insufficient. How can `Canvas` determine the
appropriate field settings for a _prop shape_? And what about existing _structured data_ versus _unstructured data_?

⚠️ _Why even have _unstructured data_? Why not create _structured data_ to populate all _component inputs_?_, you might
ask. Because:

- _structured data_ requires _base fields_ or _bundle fields_, and once in use, they cannot be removed
- therefore capturing all values for _component inputs_ as _structured data_ would cause many new _bundle fields_ to be
  created that may shortly thereafter no longer be used
- plus, not all _component inputs_ contain meaningful information to query — many contain purely _presentational_
  information such as the width of a column, the icon to use, et cetera
- in other words: _component inputs_ should be populated by _structured data_ if they contain _semantical_ information,
  otherwise it is _presentational_ information and hence _unstructured data_ is more appropriate

##### 3.1.2.a _structured data_ → matching _entity fields_ ⇒ `entity field prop source`

See:
- `\Drupal\canvas\ShapeMatcher\JsonSchemaFieldInstanceMatcher`
- `\Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType::toDataTypeShapeRequirements()`
- `\Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface`
- `\Drupal\canvas\PropSource\DynamicPropSource`

All _structured data_ in every _content entity_ in Drupal is found in an _entity field_ (a _base fields_ or
_bundle field_). These already have field settings defined. Hence `Canvas` must **match** a _field instance_ for a given
_prop shape_.

How can this reliably be matched? Drupal's validation constraints for _field types_ and _data types_ determine the
precise shapes of values that are allowed … exactly like a _prop shape_ (i.e. the JSON schema for a _component input_)!

Hence the matching works like this:
1. transform the JSON schema of a _prop shape_ to the equivalent primitive _data type_ + validation constraints (see
   `JsonSchemaType::toDataTypeShapeRequirements()`)
2. iterate over all _entity fields_ in the site, and compare the previous step's _data type_ + validation constraints
   to find a match

Finally, while the _prop shape_ may be the same for many _component inputs_, that same _prop shape_ may be required for
one _component_'s _component input_, but optional for another. So an additional filtering step is required for optional
versus required occurrences of a _prop shape_:
3. if a _component input_ is required, the matching _entity fields_ must also be marked as required

The found _entity fields_ can then be used in a `entity field prop source`, that can be _evaluated_ to retrieve the
stored value that fits in the _prop shape_.
ℹ️ An `entity field prop source` may specify a single "adapter" plugin (which must take a single input), which allows
Canvas to suggest field properties that _conceptually_ fit perfectly, but don't _technically_ fit, a particular _prop shape_.
For example: "timestamp" fields (which contain UNIX timestamps) can be made available to props that have the
`type: string, format: date` shape, by using the `unix_to_date` adapter plugin.

See `\Drupal\canvas\PropSource\EntityFieldPropSource`.

⚠️ **Multiple** bits of _structured data_ may be able to fit into a given _prop shape_. All viable choices are
suggested by `\Drupal\canvas\ShapeMatcher\PropSourceSuggester`, with irrelevant choices omitted (such as the
"Default translation" boolean, or the "Revision log message" prose string). The Content Creator or Site Builder will
choose one.

ℹ️ The completeness of this is tested by `\Drupal\Tests\canvas\Kernel\EcosystemSupport\FieldTypeSupportTest`.

##### 3.1.2.b _unstructured data_ → generating _conjured fields_ ⇒ `static prop source`

See:
- `\Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType::computeStorablePropShape()`
- `\Drupal\canvas\PropShape\StorablePropShape`
- `\Drupal\canvas\PropShape\CandidateStorablePropShape`
- `\Drupal\canvas\PropShape\PropShape::standardize()`
- `hook_canvas_storable_prop_shape_alter()`
- `\Drupal\canvas\PropExpressions\StructuredData\FieldTypeBasedPropExpressionInterface`
- `\Drupal\canvas\PropSource\StaticPropSource`

For any _unstructured data_, no field settings exist yet, so the appropriate settings for a _prop shape_ must be
generated. `JsonSchemaType::computeStorablePropShape()` contains logic to that relies only on _field types_
available in Drupal core. Unlike for _structured data_, no additional complexity is necessary for required versus
optional _component inputs_.

Contributed modules can implement `hook_canvas_storable_prop_shape_alter()` to make different choices. Such hooks
receive a `\Drupal\canvas\PropShape\CandidateStorablePropShape` value object, which contains:
- the precise storage decision: field type, storage settings, instance settings and cardinality
- the corresponding UX decision: field widget
- the cacheability that led to this decision — e.g. the presence of certain config

Any _component input_ whose _prop shape_ corresponds to a `well-known prop shape` may cause _two_ invocations of that
alter hook:
1. once for the `well-known prop shape` (so: `"type": "string|object|…", "$ref": "json-schema-definitions://…"`), if any
2. once for the resolved equivalent (with `$ref` resolved) — unless the above already found a result

The computed `\Drupal\canvas\PropShape\StorablePropShape` can be used to create a `static prop source`
(which contains all information for the _conjured field_ that powers it), that can be _evaluated_ to retrieve the stored
value that fits in the _prop shape_.

See `\Drupal\canvas\PropSource\StaticPropSource`.

💡 Whenever the cacheability that led to a decision is invalidated (e.g. some config was saved), these hooks are executed
again, to ensure an up-to-date decision for how to store props of the given _prop shape_.
Note: if the result is  different, a [new version is added to the `Component config entity`](config-management.md#3.1).

⚠️ When choosing to use _unstructured data_ to populate a _component input_, Canvas decides
using the aforementioned logic what _field type_, _field widget_ et cetera to use. Only when using _structured data_,
there is a need for an additional choice (see the `PropSourceSuggester` mentioned in 3.1.2.a).

#### 3.1.3 _prop expressions_: evaluating a `entity field prop source` or `static prop source`

_prop expressions_ are one of the lowest level building block of how Canvas works: it's similar to Drupal core's "token"
system, but more precise (it can return non-string values) and powerful (they can be chained).

Only developers of field types ever have to understand them in detail. Site Builders and Content Creators only interact
with them  indirectly: Site Builders choose _entity field prop sources_ or _static prop sources_ to populate
_component inputs_, and those _prop sources_ contain _prop expressions_ that define how to retrieve the actual value(s).

So, _if_ you're going to implement `hook_canvas_storable_prop_shape_alter()`, you will need to have at least a basic
understanding of _prop expressions_, because you may need to read or modify them. The classes and tests mentioned below
should help with that.

See
- `\Drupal\canvas\PropExpressions\StructuredData\Labeler`
- `\Drupal\canvas\PropExpressions\StructuredData\Evaluator`
- `\Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface`
- `\Drupal\canvas\PropExpressions\StructuredData\FieldTypeBasedPropExpressionInterface`
- `\Drupal\canvas\PropExpressions\StructuredData\ScalarPropExpressionInterface`
- `\Drupal\canvas\PropExpressions\StructuredData\ObjectPropExpressionInterface`
- `\Drupal\canvas\PropExpressions\StructuredData\ReferencePropExpressionInterface`
- `\Drupal\Tests\canvas\Unit\PropExpressionTest::testFromString()`
- `\Drupal\Tests\canvas\Unit\PropExpressionTest::testToString()`
- `\Drupal\Tests\canvas\Kernel\PropExpressionKernelTest::testLabel()`
- `\Drupal\Tests\canvas\Kernel\PropExpressionKernelTest::testCalculateDependencies()`

Many _field types_ contain a single _field prop_ (typically named "value"), but not all. Most _field types_ have one
required "main prop", many have additional optional props or even computed props.

To reliable retrieve the value from a `static prop source` or `entity field prop source`, the _field item_ alone is
insufficient: `Canvas` needs to know exactly which _field prop_(s) to retrieve from a _field item_. Plus, it may need to
arrange those retrieved values in a particular layout (for _prop shapes_ that use the "object" primitive type the right
key-value pairs must be assembled).

To express that, _prop expressions_ exist, which define:

- what context they need as a starting point (**Starting point** column), either:
  - _field item_ or `field item list` of a certain _field type_: field type-based prop expressions
  - or a _content entity_ of a certain _content type_ containing a particular _entity field_: entity field-based prop
    expressions
2. optionally for _entity fields_: specify a delta to determine which _field item_ from a `field item list` to use. The
   absence of a delta  is interpreted as "everything please". For a `field item list` configured for single cardinality
   that would be a single value, versus an array of values for multiple cardinality.
3. what _field props_ they must retrieve in that context, possibly following entity references (**Reference** column)
4. what the **evaluation result shape** is: either
   - a single scalar value (most common), or a list ("array") of scalar values
   - or a list of key-value pairs: an "object", or a list ("array") of such key-value pairs

| prop expression class                | starting point | reference | evaluation result shape |
|--------------------------------------|:--------------:|:---------:|:-----------------------:|
| `FieldPropExpression`                | entity field   |    ❌    |         scalar          |
| `FieldObjectPropExpression`          | entity field   |    ❌    |         object          |
| `ReferenceFieldPropExpression`       | entity field   |    ✅    |            ❌           |
| `FieldTypePropExpression`            | field type     |    ❌    |         scalar          |
| `FieldTypeObjectPropExpression`      | field type     |    ❌    |         object          |
| `ReferenceFieldTypePropExpression`   | field type     |    ✅    |            ❌           |

_prop expressions_ have 2 representations:

- a string representation, to simplify both debugging and storing them (both of those benefit from terseness) — to
  convert to the other representation: `StructuredDataPropExpression::fromString()`)
- a typed PHP object representation, to simplify logic interacting with them — to convert to the other representation:
  cast to string using `(string)`)

Examples:
- `ℹ︎␜entity:node:article␝title␞99␟value` declares it evaluates an "article" _content entity_, and returns the "value"
  prop of the 100th _field item_ in the "title" `field`. When the Site Builder constructs a content template, they are
  presented with the corresponding label: "Title␞100th item". This is a hierarchical label; the semantical hierarchy
  markers such as `␞` are never shown to the end user.
- `ℹ︎image␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt}` declares it evaluates an "image" _field item_, has no
  corresponding label (because it is for a `static prop source` and hence never needs to be explained/presented to a
  Canvas user), and returns two key-value pairs:
  - the first one being "src" for which the first "url" _field prop_ of the "uri" `field` on the "file"
    _content entity_ that is referenced by the "image" _field type_
  - the second one being "alt", which can be retrieved directly from the "image" _field item_.
- `ℹ︎entity_reference␟entity␜[␜entity:media:image␝field_media_image␞␟src_with_alternate_widths][␜entity:media:remote_image␝field_media_oembed_image␞␟remote_image_web_uri]`
  declares it evaluates an entity reference _field item_, has no corresponding label (same reason as above), branches
  based on the bundle of the referenced `Media` _content entity_:
  - if it is of the "image" media type, it fetches the "src_with_alternate_widths" field property
  - if it is of the "remote_image" media type, it fetches the "remote_image_web_uri" field property
  - … but in either case, it returns a single value: an HTTP(S) URI pointing to an image

For more examples, see `\Drupal\Tests\canvas\Unit\PropExpressionTest`. To gain a deeper understanding of how these work,
put a breakpoint in its `::testFromString()` and `::testToString()` methods.
Note that their functionality that requires a working kernel has its test logic in
`\Drupal\Tests\canvas\Kernel\PropExpressionKernelTest`, but reuses the same test cases as the unit test. It is split to
keep maximally benefit from unit test speed when working on this. This improves maintainability/DX.


#### 3.1.4 Non-structured data _prop source_: `host entity URL prop source`

There may be additional _prop sources_ that may be offered as suggestions to Site Builders and/or Content Authors to
populate _component inputs_.

For the various URI _prop shapes_ (see also [3.2.2](#3.2.2)!), there is the `host entity URL prop source`, which is able
to generate various URIs that point to the host entity (i.e. the containing _content entity_).

See:
- `\Drupal\canvas\PropSource\HostEntityUrlPropSource`
- `\Drupal\canvas\ShapeMatcher\PropSourceSuggester::matchHostEntityUrlPropSources()`


### 3.2 Additional functionality overlaid on top of the SDC JSON Schema

Drupal Canvas extends SDC JSON Schema to support additional prop shapes to complete the content editing experience.

#### 3.2.1 HTML Content with CKEditor 5 Integration

Drupal Canvas supports rich text editing for _prop shapes_ through CKEditor 5 integration. This allows SDC
developers to define props that can contain formatted HTML content.

##### JSON Schema Extensions

Two additional metadata properties are used to indicate HTML content — one is part of the JSON Schema standard, the
other is a [custom annotation](https://json-schema.org/understanding-json-schema/reference/non_json_data#contentmediatype)
(which can be recognized by the `x-` prefix).

Example:
```yaml
heading:
  type: string
  contentMediaType: text/html
  x-formatting-context: inline
```

Explanation:
- `contentMediaType: text/html` - Indicates this is a prop expecting to receive HTML content
- `x-formatting-context: inline|block` - Optionally specifies the formatting context (`block` is the default):
  - `inline`: Only inline elements allowed (`<strong>`, `<em>`, `<u>`, `<a>`)
  - `block`: Both inline and block elements allowed (adds `<p>`, `<br>`, `<ul>`, `<ol>`, `<li>`)

##### Text Formats

To allow populating such props, Drupal Canvas provides two predefined text formats:

1. **Canvas HTML Inline Format**
   - Allows only inline elements: `<strong>`, `<em>`, `<u>`, `<a href>`
   - Appropriate for headings, labels, and other inline content

2. **Canvas HTML Block Format**
   - Allows both inline elements and block elements: `<p>`, `<br>`, `<ul>`, `<ol>`, `<li>`
   - Appropriate for longer content blocks, descriptions, etc.

##### Example Component with HTML Props

```yaml
props:
  heading:
    type: string
    title: "Heading"
    contentMediaType: text/html
    x-formatting-context: inline

  description:
    type: string
    title: "Description"
    contentMediaType: text/html
    # This is the default, so it can be omitted.
    x-formatting-context: block
```

#### 3.2.2 URIs pointing to particular types of targets and allowed URI schemes

Two additional metadata properties are used to indicate:
1. types of targets using `contentMediaType` (repurposing an existing part of the JSON Schema spec per [a JSON Schema
   spec issue](https://github.com/json-schema-org/json-schema-spec/issues/1557)
2. allowed URI schemes using `x-allowed-schemes` (which is another [custom annotation](https://json-schema.org/understanding-json-schema/reference/non_json_data#contentmediatype))

Example:
```yaml
heading:
  type: string
  format: uri
  contentMediaType: image/*
  x-allowed-schemes: [http, https]
```

Explanation:
- `contentMediaType: image/*` - Optional; indicates this is a prop expecting to receive a URI pointing to an image
  resource (using a wildcard MIME type). See
  [JSON Schema spec issue](https://github.com/json-schema-org/json-schema-spec/issues/1557) and Canvas'
  `UriTargetMediaTypeConstraint`.
- `x-allowed-schemes` - Optional; indicates which URI schemes are allowed for URIs passed into this shape. Specifying
  `[http, https]` conveys the URI must be resolvable by web browsers. (As opposed to something like Drupal's `public` or
  `private`, or other proprietary URI schemes.)
