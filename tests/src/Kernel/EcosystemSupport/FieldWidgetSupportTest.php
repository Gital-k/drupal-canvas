<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\EcosystemSupport;

use Drupal\canvas\Form\ComponentInstanceForm;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\RendererInterface;
use Drupal\filter\Entity\FilterFormat;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Checks core field widget integration and text format guidelines.
 *
 * @see docs/redux-integrated-field-widgets.md#3.4
 * @legacy-covers \Drupal\canvas\Hook\ReduxIntegratedFieldWidgetsHooks::fieldWidgetInfoAlter
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class FieldWidgetSupportTest extends EcosystemSupportTestBase {

  public const COMPLETION = 17 / 29;
  public const SUPPORTED = [
    'boolean_checkbox',
    'canvas_color_picker',
    'daterange_default',
    'datetime_default',
    'email_default',
    'entity_reference_autocomplete',
    'file_generic',
    'image_image',
    'link_default',
    'media_library_widget',
    'number',
    'options_select',
    'string_textarea',
    'string_textfield',
    'text_textarea',
    'text_textarea_with_summary',
    'text_textfield',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'comment',
    'content_moderation',
    'datetime',
    'datetime_range',
    'file',
    'image',
    'link',
    'media',
    'media_library',
    'path',
    'telephone',
    'text',
    // Content Moderation dependency.
    'workflows',
    // Modules that field widget-providing modules depend on.
    'views',
  ];

  public function test(): void {
    $this->assertSame(['layout_builder'], self::getUninstalledStableModulesWithPlugin('Plugin/Field/FieldWidget'));

    $field_widget_definitions = $this->container->get('plugin.manager.field.widget')->getDefinitions();
    ksort($field_widget_definitions);
    $supported = array_filter($field_widget_definitions, fn (array $def): bool => \array_key_exists('canvas', $def) && \array_key_exists('transforms', $def['canvas']));
    $missing = array_diff_key($field_widget_definitions, $supported);

    $this->assertSame(self::SUPPORTED, \array_keys($supported));
    $this->assertSame(
      self::COMPLETION,
      count($supported) / count($field_widget_definitions),
      \sprintf('Not yet supported: %s', implode(', ', \array_keys($missing)))
    );
  }

  /**
   * Tests that guidelines match the formats available outside Canvas forms.
   */
  #[DataProvider('providerTextFormatGuidelines')]
  public function testTextFormatGuidelines(string $form_id, bool $canvas_formats_allowed): void {
    $this->installEntitySchema('path_alias');
    $this->installConfig(['filter', 'user']);
    $format = FilterFormat::create([
      'format' => 'public_html',
      'name' => 'Public HTML',
      'weight' => -10,
      'filters' => [
        'filter_html' => ['status' => TRUE, 'settings' => ['allowed_html' => '<p> <strong>']],
      ],
    ]);
    $format->save();
    $this->assertEntityIsValid($format);
    $role = Role::load('anonymous');
    \assert($role instanceof Role);
    $role->grantPermission('use text format public_html')->save();
    $this->assertTrue(\Drupal::currentUser()->isAnonymous());
    $form = [
      '#type' => 'form',
      '#tree' => FALSE,
      '#action' => '/',
      '#parents' => [],
      '#array_parents' => [],
      'form_id' => ['#type' => 'hidden', '#value' => $form_id],
      'body' => ['#type' => 'text_format', '#title' => 'Body', '#format' => 'public_html'],
    ];
    $form_state = new FormState();
    $form = \Drupal::service(FormBuilderInterface::class)->doBuildForm($form_id, $form, $form_state);
    $expected_formats = $canvas_formats_allowed
      ? ['public_html', 'canvas_html_block', 'canvas_html_inline']
      : ['public_html'];
    $this->assertSame($expected_formats, \array_keys($form['body']['format']['format']['#options']));
    $this->assertSame($expected_formats, Element::children($form['body']['format']['guidelines']));
    if (!$canvas_formats_allowed) {
      // Guidelines must be correct without relying on a visible format selector
      // and its JavaScript, including when only one format can be selected.
      $form['body']['format']['format']['#access'] = FALSE;
      $html = (string) \Drupal::service(RendererInterface::class)->renderRoot($form);
      $this->assertStringContainsString('data-drupal-format-id="public_html"', $html);
      $this->assertStringNotContainsString('data-drupal-format-id="canvas_html_block"', $html);
      $this->assertStringNotContainsString('data-drupal-format-id="canvas_html_inline"', $html);
    }
  }

  /**
   * @return array<string, array{string, bool}>
   */
  public static function providerTextFormatGuidelines(): array {
    return [
      'comment form' => ['comment_form', FALSE],
      'node form' => ['node_article_form', FALSE],
      'component instance' => [ComponentInstanceForm::FORM_ID, TRUE],
      'add config translation' => ['config_translation_add_form', TRUE],
      'edit config translation' => ['config_translation_edit_form', TRUE],
      'translation job item' => ['tmgmt_job_item_edit_form', TRUE],
    ];
  }

}
