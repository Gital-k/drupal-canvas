# Glossary

adapted prop source
: TODO

asset library
: TODO

block
: TODO

code component
: TODO

component
: A piece of a Canvas layout. There are different types of components (see _component source_). Components may have props and slots, but don't have to.

component (entity)
: TODO

component inputs
: TODO

component metadata
: TODO

component source
: TODO

content template
: A configuration entity that contains a component tree, which is used to render entities of a specific type and bundle, in a specific view mode. Conceptually similar to core's entity view displays. Content templates are the only kind of Canvas layout that allow you to use dynamic prop sources. Usually referred to in Canvas's code as `ContentTemplates`.

component tree
: TODO

dynamic prop source
: A prop source that gets its value from an entity; specifically, from an entity (a node, for example) being rendered through Canvas. This is only really used when using Canvas is rendering an entity's fields, and needs to map field values into specific component props. Usually referred to in Canvas's code as `DynamicPropSources`.

folder
: TODO

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
: TODO

pattern
: TODO

prop
: A property of a component. For example, a button component might have a prop for a link, or a prop for the text of the button. Components may have any number of props, or none at all.

prop expression
: TODO

prop shape
: TODO

prop source
: Information that tells Canvas how to put a value into a prop. There are different kinds of prop sources.

schema
: TODO

single-directory component (SDC)
: A kind of component (i.e., a component source) that integrates with Drupal's single-directory component system so that SDCs may be used in Canvas. See https://www.drupal.org/docs/develop/theming-drupal/using-single-directory-components for more information about SDCs in general.

slot
: An area of a component into which other components may be placed. Components may have any number of slots, or none at all.

static prop source
: A prop source that holds a single, specific, unchanging value. The value will be automatically wired into the prop which is using the prop source. Usually referred to in Canvas's code as `StaticPropSources`.

versioned configuration entity
: TODO
