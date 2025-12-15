# Glossary

adapted prop source
: TODO

block
: TODO

code component
: TODO

component
: A piece of a Canvas layout. There are different types of components. Components may (but don't have to) have props and slots.

component inputs
: TODO

component metadata
: TODO

content template
: A configuration entity that contains a component tree, which is used to render entities of a specific type and bundle, in a specific view mode. Conceptually similar to core's entity view displays. Content templates are the only kind of Canvas layout that allow you to use dynamic prop sources. Usually referred to in Canvas's code as `ContentTemplates`.

component tree
: TODO

dynamic prop source
: A prop source that gets its value from an entity; specifically, from an entity (a node, for example) being rendered through Canvas. This is only really used when using Canvas to render an entity's fields by inserting the field values into specific component props. Usually referred to in Canvas's code as a `DynamicPropSources`.

host entity URL prop source
: TODO

JS component
: TODO

JSON schema
: TODO

page
: In the context of Canvas, a page is a specific kind of content entity. Unlike nodes, pages aren't really meant to have custom fields; they're meant to hold a Canvas layout, plus some additional metadata (such as a URL slug). Essentially, a generic landing page.

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

single-directory component
: TODO

slot
: An area of a component into which other components may be placed. Components may have any number of slots, or none at all.

static prop source
: A prop source that holds a single, specific, unchanging value. The value will be automatically wired into the prop which is using the prop source. Usually referred to in Canvas's code as a `StaticPropSources`.
