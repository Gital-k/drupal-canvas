<?php

namespace Drupal\experience_builder;

use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedData;

class ComputedOembedResourceUrl extends TypedData {

  public function getValue() {
    assert($this->getParent() instanceof TraversableTypedDataInterface);
    return \Drupal::service('media.oembed.url_resolver')->getResourceUrl($this->getParent()->getValue()['value']);
  }

}
