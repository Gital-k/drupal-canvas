<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Hook;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSaveEntity;
use Drupal\canvas\Hook\AutoSaveHooks;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Tests Drupal\canvas\Hook\AutoSaveHooks.
 */
#[CoversClass(AutoSaveHooks::class)]
#[Group('canvas')]
final class AutoSaveHooksTest extends UnitTestCase {

  /**
   * The original Drupal service container.
   */
  private ContainerInterface|false $originalContainer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->originalContainer = \Drupal::hasContainer() ? \Drupal::getContainer() : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::setContainer($this->originalContainer !== FALSE ? $this->originalContainer : new ContainerBuilder());
    parent::tearDown();
  }

  /**
   * Tests formAlter() resolves revisionable entities from route parameters.
   *
   * @legacy-covers ::formAlter
   */
  public function testFormAlterUsesRevisionableRouteParameter(): void {
    $revision = $this->createMock(RevisionableInterface::class);
    $other_revisionable_parameter = $this->createMock(RevisionableInterface::class);
    $auto_save_manager = $this->createMock(AutoSaveManager::class);
    $auto_save_manager->expects($this->once())
      ->method('getAutoSaveEntity')
      ->with($revision)
      ->willReturn(new AutoSaveEntity($revision, 'draft-hash', 'client-1', 1700000000));

    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getParameters')
      ->willReturn(new ParameterBag([
        'ai_context_item' => $other_revisionable_parameter,
        'ai_context_item_revision' => $revision,
      ]));

    $sut = new AutoSaveHooks($auto_save_manager, $route_match);
    $form_state = $this->createFormState(new \stdClass());
    $form = [
      'actions' => [
        'submit' => [
          '#submit' => ['existing_submit_handler'],
        ],
      ],
    ];

    $sut->formAlter($form, $form_state, 'ai_context_item_revision_revert_confirm');

    self::assertSame(
      ['existing_submit_handler', [AutoSaveHooks::class, 'revisionRevertSubmit']],
      $form['actions']['submit']['#submit']
    );
    self::assertArrayHasKey('canvas_auto_save_warning', $form);
    self::assertSame('status_messages', $form['canvas_auto_save_warning']['#theme']);
    self::assertSame(
      'This page has unpublished changed in Drupal Canvas. Reverting to this revision will delete the auto-saved changes.',
      self::assertWarningMarkup($form['canvas_auto_save_warning']['#message_list']['warning'][0])
    );
  }

  /**
   * Tests formAlter() is a safe no-op without a revisionable entity.
   *
   * @legacy-covers ::formAlter
   */
  public function testFormAlterWithoutRevisionableEntityDoesNothing(): void {
    $auto_save_manager = $this->createMock(AutoSaveManager::class);
    $auto_save_manager->expects($this->never())
      ->method('getAutoSaveEntity');

    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getParameters')
      ->willReturn(new ParameterBag([
        'unrelated' => new \stdClass(),
      ]));

    $sut = new AutoSaveHooks($auto_save_manager, $route_match);
    $form_state = $this->createFormState(new \stdClass());
    $form = [
      'actions' => [
        'submit' => [
          '#submit' => ['existing_submit_handler'],
        ],
      ],
    ];

    $sut->formAlter($form, $form_state, 'ai_context_item_revision_revert_confirm');

    self::assertSame(['existing_submit_handler'], $form['actions']['submit']['#submit']);
    self::assertArrayNotHasKey('canvas_auto_save_warning', $form);
  }

  /**
   * Tests revisionRevertSubmit() deletes auto-save data for route revisions.
   *
   * @legacy-covers ::revisionRevertSubmit
   */
  public function testRevisionRevertSubmitDeletesAutoSaveForRouteRevision(): void {
    $revision = $this->createMock(RevisionableInterface::class);
    $other_revisionable_parameter = $this->createMock(RevisionableInterface::class);
    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getParameters')
      ->willReturn(new ParameterBag([
        'ai_context_item' => $other_revisionable_parameter,
        'ai_context_item_revision' => $revision,
      ]));

    $auto_save_manager = $this->createMock(AutoSaveManager::class);
    $auto_save_manager->expects($this->once())
      ->method('delete')
      ->with($revision);

    $container = new ContainerBuilder();
    $container->set('current_route_match', $route_match);
    $container->set(AutoSaveManager::class, $auto_save_manager);
    \Drupal::setContainer($container);

    $form_state = $this->createFormState(new \stdClass());
    $form = [];

    AutoSaveHooks::revisionRevertSubmit($form, $form_state);
  }

  /**
   * Creates a form state mock with the given form object.
   */
  private function createFormState(object $form_object): FormStateInterface {
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getFormObject')
      ->willReturn($form_object);
    return $form_state;
  }

  /**
   * Asserts the warning is a translatable markup object and returns its source.
   */
  private static function assertWarningMarkup(mixed $warning): string {
    self::assertInstanceOf(TranslatableMarkup::class, $warning);
    return $warning->getUntranslatedString();
  }

}
