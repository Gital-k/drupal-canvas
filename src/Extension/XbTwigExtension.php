<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Extension;

use Drupal\media\IFrameMarkup;
use Drupal\media\OEmbed\ResourceFetcherInterface;
use Drupal\media\OEmbed\UrlResolverInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Defines a twig extension to add metadata to output as HTML comments.
 */
final class XbTwigExtension extends AbstractExtension {

  public function __construct(
    protected readonly UrlResolverInterface $urlResolver,
    protected readonly ResourceFetcherInterface $resourceFetcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFunctions() {
    return [
      new TwigFunction('create_oembed_iframe_from_url', [
        $this,
        'createOembedIframeFromUrl',
      ]),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getNodeVisitors() {
    return [
      new XbPropVisitor(),
    ];
  }

  public function createOembedIframeFromUrl(string $media_url, int $max_width, int $max_height): string {
    $resource_url = $this->urlResolver->getResourceUrl($media_url, $max_width, $max_height);
    $resource = $this->resourceFetcher->fetchResource($resource_url);
    return (string) IFrameMarkup::create($resource->getHtml());
  }

}
