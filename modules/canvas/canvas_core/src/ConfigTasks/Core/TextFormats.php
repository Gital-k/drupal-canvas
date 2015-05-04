<?php

/**
 * @file
 * Contains a TextFormats ConfigTask.
 *
 * @license GPL v2 http://www.fsf.org/licensing/licenses/gpl.html
 * @author Chris Skene chris at xtfer dot com
 * @copyright Copyright(c) 2015 Christopher Skene
 */

namespace Drupal\canvas_core\ConfigTasks\Core;

use Drupal\canvas\ConfigTasks\ConfigTaskInterface;
use Drupal\canvas\ConfigTasks\ConfigTaskBase;
use Drupal\canvas\Exception\CanvasException;
use Drupal\canvas\Exception\CanvasInstallerException;

/**
 * Class TextFormats
 * @package Drupal\canvas\ConfigTasks
 */
class TextFormats extends ConfigTaskBase implements ConfigTaskInterface {

  /**
   * Activate the configuration.
   */
  public function doConfig() {

    $formats = $this::getConfig();

    foreach ($formats as $format) {
      $format_object = (object) $format;
      $result = filter_format_save($format_object);

      if ($result != SAVED_NEW || $result != SAVED_UPDATED) {
        throw new CanvasInstallerException('Failed to save formats');
      }
    }
  }

  /**
   * Fetch the configuration parameters.
   *
   * @return mixed
   *   Parameters suitable for doConfig() to use.
   */
  public function getConfig() {
    // Add text formats.
    $text_formats['rich_text'] = array(
      'format' => 'rich_text',
      'name' => 'Rich text',
      'weight' => 0,
      'filters' => array(
        // Video filter - Must go before 'Convert URLs into links'
        // per module documentation.
        'video_filter' => array(
          'weight' => 0,
          'status' => 1,
          'settings' => array(),
        ),
        // Convert URLs into links.
        'filter_url' => array(
          'weight' => 1,
          'status' => 1,
          'settings' => array(
            'filter_url_length' => 72,
          ),
        ),
        // Convert line breaks into HTML.
        'filter_autop' => array(
          'weight' => 2,
          'status' => 1,
          'settings' => array(),
        ),
        // Wysiwyg filter.
        'wysiwyg' => array(
          'weight' => -50,
          'status' => 1,
          'settings' => array(
            'valid_elements' => 'a[href|target<_blank|title|class|name|id],div[id|class|align<center?justify?left?right],br,em,i,strong,cite,code,blockquote,ul,ol[class],li[class],dl,dt,dd,span,p[class],h2[class],h3[class],h4[class],h5[class],h6[class],img[!src|title|alt|width|height|class|hspace|vspace],table[border|cellpadding|cellspacing|align|dir|summary|style],tr[scope],thead,td[colspan|rowspan|headers|class|id],th[colspan|rowspan|class|headers|id|scope],tbody,caption,sup,sub,@[class],video',
            'allow_comments' => 1,
            'nofollow_policy' => 'disabled',
            'nofollow_domains' => array(),
            'style_color' => array(
              'color' => 'color',
              'background' => 0,
              'background-color' => 0,
              'background-image' => 0,
              'background-repeat' => 0,
              'background-attachment' => 0,
              'background-position' => 0,
            ),
            'style_font' => array(
              'font' => 0,
              'font-family' => 0,
              'font-size' => 0,
              'font-size-adjust' => 0,
              'font-stretch' => 0,
              'font-style' => 0,
              'font-variant' => 0,
              'font-weight' => 0,
            ),
            'style_text' => array(
              'text-align' => 'text-align',
              'text-decoration' => 'text-decoration',
              'text-indent' => 0,
              'text-transform' => 0,
              'letter-spacing' => 0,
              'word-spacing' => 0,
              'white-space' => 0,
              'direction' => 0,
              'unicode-bidi' => 0,
            ),
            'style_box' => array(
              'padding-right' => 'padding-right',
              'padding-left' => 'padding-left',
              'margin' => 0,
              'margin-top' => 0,
              'margin-right' => 0,
              'margin-bottom' => 0,
              'margin-left' => 0,
              'padding' => 0,
              'padding-top' => 0,
              'padding-bottom' => 0,
            ),
            'style_border-1' => array(
              'border' => 0,
              'border-top' => 0,
              'border-right' => 0,
              'border-bottom' => 0,
              'border-left' => 0,
              'border-width' => 0,
              'border-top-width' => 0,
              'border-right-width' => 0,
              'border-bottom-width' => 0,
              'border-left-width' => 0,
            ),
            'style_border-2' => array(
              'border-color' => 0,
              'border-top-color' => 0,
              'border-right-color' => 0,
              'border-bottom-color' => 0,
              'border-left-color' => 0,
              'border-style' => 0,
              'border-top-style' => 0,
              'border-right-style' => 0,
              'border-bottom-style' => 0,
              'border-left-style' => 0,
            ),
            'style_dimension' => array(
              'height' => 'height',
              'line-height' => 0,
              'max-height' => 0,
              'max-width' => 0,
              'min-height' => 0,
              'min-width' => 0,
              'width' => 'width',
            ),
            'style_positioning' => array(
              'bottom' => 0,
              'clip' => 0,
              'left' => 0,
              'overflow' => 0,
              'right' => 0,
              'top' => 0,
              'vertical-align' => 0,
              'z-index' => 0,
            ),
            'style_layout' => array(
              'float' => 'float',
              'clear' => 0,
              'display' => 0,
              'position' => 0,
              'visibility' => 0,
            ),
            'style_list' => array(
              'list-style' => 0,
              'list-style-image' => 0,
              'list-style-position' => 0,
              'list-style-type' => 0,
            ),
            'style_table' => array(
              'border-collapse' => 0,
              'border-spacing' => 0,
              'caption-side' => 0,
              'empty-cells' => 0,
              'table-layout' => 0,
            ),
            'style_user' => array(
              'cursor' => 0,
              'outline' => 0,
              'outline-width' => 0,
              'outline-style' => 0,
              'outline-color' => 0,
              'zoom' => 0,
            ),
            'rule_valid_classes' => array(
              0 => 'a*',
              2 => 'b*',
              4 => 'c*',
              6 => 'd*',
              8 => 'e*',
              10 => 'f*',
              12 => 'g*',
              14 => 'h*',
              16 => 'i*',
              18 => 'j*',
              20 => 'k*',
              22 => 'l*',
              24 => 'm*',
              26 => 'n*',
              28 => 'o*',
              30 => 'p*',
              32 => 'q*',
              34 => 'r*',
              36 => 's*',
              38 => 't*',
              40 => 'u*',
              42 => 'v*',
              44 => 'w*',
              46 => 'x*',
              48 => 'y*',
              50 => 'z*',
              52 => 'A*',
              54 => 'B*',
              56 => 'C*',
              58 => 'D*',
              60 => 'E*',
              62 => 'F*',
              64 => 'G*',
              66 => 'H*',
              68 => 'I*',
              70 => 'J*',
              72 => 'K*',
              74 => 'L*',
              76 => 'M*',
              78 => 'N*',
              80 => 'O*',
              82 => 'P*',
              84 => 'Q*',
              86 => 'R*',
              88 => 'S*',
              90 => 'T*',
              92 => 'U*',
              94 => 'V*',
              96 => 'W*',
              98 => 'X*',
              100 => 'Y*',
              102 => 'Z*',
              104 => 'lower-roman',
              106 => 'upper-roman',
              108 => 'lower-alpha',
              110 => 'upper-alpha',
            ),
            'rule_valid_ids' => array(
              0 => 'a*',
              2 => 'b*',
              4 => 'c*',
              6 => 'd*',
              8 => 'e*',
              10 => 'f*',
              12 => 'g*',
              14 => 'h*',
              16 => 'i*',
              18 => 'j*',
              20 => 'k*',
              22 => 'l*',
              24 => 'm*',
              26 => 'n*',
              28 => 'o*',
              30 => 'p*',
              32 => 'q*',
              34 => 'r*',
              36 => 's*',
              38 => 't*',
              40 => 'u*',
              42 => 'v*',
              44 => 'w*',
              46 => 'x*',
              48 => 'y*',
              50 => 'z*',
              52 => 'A*',
              54 => 'B*',
              56 => 'C*',
              58 => 'D*',
              60 => 'E*',
              62 => 'F*',
              64 => 'G*',
              66 => 'H*',
              68 => 'I*',
              70 => 'J*',
              72 => 'K*',
              74 => 'L*',
              76 => 'M*',
              78 => 'N*',
              80 => 'O*',
              82 => 'P*',
              84 => 'Q*',
              86 => 'R*',
              88 => 'S*',
              90 => 'T*',
              92 => 'U*',
              94 => 'V*',
              96 => 'W*',
              98 => 'X*',
              100 => 'Y*',
              102 => 'Z*',
            ),
            'rule_style_urls' => array(),
          ),
        ),
        // Convert Media tags to markup.
        'media_filter' => array(
          'weight' => 3,
          'status' => 1,
          'settings' => array(),
        ),
        // Table of contents filter.
        'toc_filter' => array(
          'weight' => 4,
          'status' => 1,
          'settings' => array(),
        ),
        // Clean up HTML.
        'filter_htmlcorrector' => array(
          'weight' => 5,
          'status' => 1,
          'settings' => array(),
        ),
      ),
    );

    $text_formats['plain_text'] = array(
      'format' => 'plain_text',
      'name' => 'Plain text',
      'weight' => 10,
      'filters' => array(
        // Display any HTML as plain text.
        'filter_html_escape' => array(
          'weight' => 0,
          'status' => 1,
          'settings' => array(),
        ),
        // Convert URLs into links.
        'filter_url' => array(
          'weight' => 1,
          'status' => 1,
          'settings' => array(
            'filter_url_length' => 72,
          ),
        ),
        // Convert line breaks into HTML.
        'filter_autop' => array(
          'weight' => 2,
          'status' => 1,
          'settings' => array(),
        ),
      ),
    );

    return $text_formats;
  }

}
