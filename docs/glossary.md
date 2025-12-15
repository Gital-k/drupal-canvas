# Glossary

adapted prop source
: TODO

asset library
: TODO

block
: A regular Drupal block. Canvas has a _component source_ that allows blocks to be used as component in layouts, although they don't support _slots_.

code component
: A kind of component (i.e., a _component source_) that is written in JSX and stored as a Drupal configuration entity, but rendered entirely on the client side using React and Tailwind CSS. Synonymous with _JS component_ and _JavaScript component_.

component
: A piece of a Canvas layout. There are different types of components (see _component source_). Components may have _props_ and _slots_, but don't have to.

component (entity)
: TODO

component inputs
: TODO

component instance
: A single item in a _component tree_. A component instance contains all of the information needed to render a specific component in a specific place in a layout, including which _component source_ it uses, what _prop sources_ it has, and what components (if any) are placed into its _slots_.

component metadata
: TODO

component source
: A type of Drupal plugin which handles a particular kind of _component_. Component sources are responsible for rendering components and validating _component inputs_. Often referred to in Canvas's code as `ComponentSources`.

content template
: A configuration entity that contains a component tree, which is used to render entities of a specific type and bundle, in a specific view mode. Similar to core's entity view displays, except for Canvas. Content templates are the only kind of Canvas layout that allow you to use dynamic prop sources. Often referred to in Canvas's code as `ContentTemplates`.

component tree
: A data structure that is a list of _component instances_. Usually stored in a Drupal field of the `component_tree` type, or in configuration with the `canvas.component_tree` config schema type. Component trees may contain any number of components, and contain all of the information needed to render the layout with everything its right place.

dynamic prop source
: A _prop source_ that gets its value from an entity (a node, for example) that is being rendered by Canvas. This is only really used when using Canvas is rendering an entity using a _content template_ and needs to map field values into specific component _props_. Often referred to in Canvas's code as `DynamicPropSources`.

folder
: A simple configuration entity which allows components to be organized into arbitrary groups in the Canvas UI.

host entity URL prop source
: TODO

hyperscriptify
: A small JavaScript library, written for Canvas, which allows React components to be output by Drupal as JSX, which are then converted to fully functional, "live" React components on the front end. Used by Canvas's user interface to display Drupal field widgets, which do not natively support React. See https://github.com/effulgentsia/hyperscriptify for the code.

JavaScript component
: See _code component_.

JS component
: See _code component_.

JSON schema
: TODO

page
: In the context of Canvas, a page is a specific kind of content entity. Unlike nodes, pages aren't really meant to have custom fields; they're meant to hold a Canvas layout, plus some additional metadata (such as a URL slug). Essentially, a generic landing page.

page region
: A configuration entity that contains a component tree, used to render a specific region of a specific theme. This allows Canvas to be used to lay out parts of the page which are normally managed by the theme.

pattern
: A configuration entity that contains a component tree, which can be cloned into any Canvas layout as many times as you want. Useful for small pieces of a design — a testimonial, for example — that can be reused in various places, but is not meant to be a component of its own.

prop
: A property of a _component_. For example, a button component might have a prop for a link, or a prop for the text of the button. Components may have any number of props, or none at all.

prop expression
: TODO

prop shape
: The full definition of a _prop_, including what type of data it expects (string, integer, etc.), and what that data needs to look like in order to be usable by the prop. The Canvas UI uses prop shapes to match a component's props to relevant Drupal fields.

prop source
: Information that tells Canvas how to put a value into a _prop_. There are different kinds of prop sources. Despite the name, totally unrelated to _component sources_.

schema
: TODO

shape matching
: The process of identifying which Drupal fields are compatible with a specific _prop shape_.

single-directory component (SDC)
: A kind of component (i.e., a _component source_) that integrates with Drupal's single-directory component system so that SDCs may be used in Canvas. See https://www.drupal.org/docs/develop/theming-drupal/using-single-directory-components for more information about SDCs in general.

slot
: An area of a _component_ into which other components may be placed. Components may have any number of slots, or none at all.

static prop source
: A _prop source_ that holds a single, specific, unchanging value. The value will be automatically wired into the prop which is using the prop source. Often referred to in Canvas's code as `StaticPropSources`.

versioned configuration entity
: TODO
