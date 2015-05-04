# Canvas

## Download

Canvas is available as a full drupal site in tgz and zip format at: http://drupal.org/project/canvas

## Building from Source

If you would prefer to build Canvas directly, it is recommended to use phing to 
do so...

### Requirements

Install phing and drush in the standard way. You can use composer to install both
tools using the following:

```
composer global require --prefer-dist --no-interaction drush/drush:6.*
composer global require --prefer-dist --no-interaction phing/phing:2.7.*
```

### Building
To install a local working copy of canvas follow these steps.

First create a copy of build.properties and update it for your local settings.

```
cp build.example.properties build.properties
```

Run phing to build a site in a directory _at the same level_ as the current directory called `build`.

```
phing
```

You should point your apache vhost configuration to `build`.

## Credits

Development Lead: Christopher Skene.

Development partly sponsored by Komosion (http://komosion.com)

Canvas is freely adapted from multiple sources, including PreviousNext's aGov
Distribution (http://drupal.org/project/agov).
