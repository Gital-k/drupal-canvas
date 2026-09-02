<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Folder;
use Drupal\canvas\Entity\FolderAccessControlHandler;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageAccessControlHandler;
use Drupal\canvas\FolderPermissions;
use Drupal\canvas\Plugin\Validation\Constraint\FolderMembershipAccessConstraintValidator;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\PermissionHandlerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Tests the permissions scoped to a folder of pages.
 */
#[CoversClass(FolderPermissions::class)]
#[CoversClass(FolderAccessControlHandler::class)]
#[CoversClass(PageAccessControlHandler::class)]
#[CoversClass(FolderMembershipAccessConstraintValidator::class)]
#[Group('canvas')]
final class FolderPermissionsTest extends CanvasKernelTestBase {

  use RequestTrait;
  use UserCreationTrait;

  private const string URL = '/canvas/api/v0/content/canvas_page/%s';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_page',
    'field',
  ];

  private Folder $campaigns;

  private Folder $legal;

  private Page $campaignPage;

  private Page $legalPage;

  private Page $loosePage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('media');
    $this->installConfig(['system', 'field', 'filter', 'path_alias']);
    $component_source_manager = $this->container->get(ComponentSourceManager::class);
    \assert($component_source_manager instanceof ComponentSourceManager);
    $component_source_manager->generateComponents();
    // The first user is the superuser, who bypasses access checks: create it
    // so the users under test are ordinary ones.
    $this->createUser();

    // Two folders of pages, an unpublished page in each, and one in no folder.
    $this->campaigns = self::createPageFolder('Campaigns');
    $this->legal = self::createPageFolder('Legal');
    $this->campaignPage = self::createPage('Summer sale', $this->campaigns);
    $this->legalPage = self::createPage('Terms of service', $this->legal);
    $this->loosePage = self::createPage('Scratch');
  }

  /**
   * Two permissions per folder of content entities; none for config entities.
   */
  public function testPermissionsAreGeneratedPerFolderOfContentEntities(): void {
    $component_folder = Folder::create([
      'name' => 'Widgets',
      'configEntityTypeId' => Component::ENTITY_TYPE_ID,
    ]);
    $component_folder->save();
    $campaigns_id = $this->campaigns->id();
    self::assertNotNull($campaigns_id);
    $permissions = $this->container->get(PermissionHandlerInterface::class)->getPermissions();

    $edit = FolderPermissions::permission('edit', Page::ENTITY_TYPE_ID, $campaigns_id);
    self::assertSame("edit canvas_page in folder $campaigns_id", $edit);
    self::assertArrayHasKey($edit, $permissions);
    self::assertSame('Folder <em class="placeholder">Campaigns</em>: Edit pages', (string) $permissions[$edit]['title']);
    self::assertSame('Edit every page in this folder, without the site-wide permission to edit any page.', (string) $permissions[$edit]['description']);
    self::assertSame('canvas', $permissions[$edit]['provider']);
    // Roles holding the permission depend on the folder.
    self::assertSame(['config' => ["canvas.folder.$campaigns_id"]], $permissions[$edit]['dependencies']);

    $delete = FolderPermissions::permission('delete', Page::ENTITY_TYPE_ID, $campaigns_id);
    self::assertArrayHasKey($delete, $permissions);
    self::assertSame('Folder <em class="placeholder">Campaigns</em>: Delete pages', (string) $permissions[$delete]['title']);
    self::assertSame(['config' => ["canvas.folder.$campaigns_id"]], $permissions[$delete]['dependencies']);

    // Folders of config entities remain a purely organizational tool.
    $component_folder_id = $component_folder->id();
    self::assertNotNull($component_folder_id);
    foreach (FolderPermissions::OPERATIONS as $operation) {
      self::assertArrayNotHasKey(FolderPermissions::permission($operation, Component::ENTITY_TYPE_ID, $component_folder_id), $permissions);
    }

    // The permissions narrow down the ones Canvas declares statically.
    self::assertSame(Page::EDIT_PERMISSION, FolderPermissions::siteWidePermission('edit', Page::ENTITY_TYPE_ID));
    self::assertSame(Page::DELETE_PERMISSION, FolderPermissions::siteWidePermission('delete', Page::ENTITY_TYPE_ID));
  }

  /**
   * A folder permission covers exactly the pages filed into that folder.
   */
  public function testFolderPermissionsGrantAccessToThePagesInThatFolder(): void {
    $campaigns_id = $this->campaigns->id();
    self::assertNotNull($campaigns_id);
    $editor = $this->createUser([
      'access content',
      FolderPermissions::permission('edit', Page::ENTITY_TYPE_ID, $campaigns_id),
    ]);
    $remover = $this->createUser([
      'access content',
      FolderPermissions::permission('delete', Page::ENTITY_TYPE_ID, $campaigns_id),
    ]);
    $bystander = $this->createUser(['access content']);
    self::assertNotFalse($editor);
    self::assertNotFalse($remover);
    self::assertNotFalse($bystander);

    // Deleting the pages of a folder is not editing them: a remover sees the
    // page (the Pages panel lists it, locked) but cannot open its editor.
    self::assertTrue($this->campaignPage->access('view', $remover));
    self::assertFalse($this->campaignPage->access('update', $remover));

    // In their folder, the editor may do what a site-wide editor may, and the
    // remover may delete — both see the unpublished page.
    self::assertPageAccess($this->campaignPage, $editor, view: TRUE, update: TRUE, delete: FALSE, view_all_revisions: TRUE);
    self::assertPageAccess($this->campaignPage, $remover, view: TRUE, update: FALSE, delete: TRUE, view_all_revisions: FALSE);
    // Elsewhere, they are no better off than anyone with `access content`.
    foreach ([$this->legalPage, $this->loosePage] as $page) {
      foreach ([$editor, $remover, $bystander] as $account) {
        self::assertPageAccess($page, $account, view: FALSE, update: FALSE, delete: FALSE, view_all_revisions: FALSE);
      }
    }
    self::assertPageAccess($this->campaignPage, $bystander, view: FALSE, update: FALSE, delete: FALSE, view_all_revisions: FALSE);

    // A granted verdict depends on the page as well as on the permissions:
    // filing it elsewhere takes the grant away.
    $result = $this->campaignPage->access('update', $editor, TRUE);
    self::assertInstanceOf(AccessResult::class, $result);
    self::assertTrue($result->isAllowed());
    self::assertContains('user.permissions', $result->getCacheContexts());
    self::assertContains('canvas_page:' . $this->campaignPage->id(), $result->getCacheTags());
    // A denial deliberately does not: it would otherwise put a page's cache
    // tag into every response that checks an operation the user does not have,
    // and the Pages panel checks the delete operation of every row it lists.
    // Whatever renders or lists a page already depends on it.
    $result = $this->loosePage->access('update', $editor, TRUE);
    self::assertInstanceOf(AccessResult::class, $result);
    self::assertFalse($result->isAllowed());
    self::assertContains('user.permissions', $result->getCacheContexts());
    self::assertNotContains('canvas_page:' . $this->loosePage->id(), $result->getCacheTags());

    // Both see their folder — and only theirs — but cannot administer it.
    foreach ([$editor, $remover] as $account) {
      self::assertTrue($this->campaigns->access('view', $account));
      self::assertFalse($this->campaigns->access('update', $account));
      self::assertFalse($this->campaigns->access('delete', $account));
      self::assertFalse($this->legal->access('view', $account));
    }
    self::assertFalse($this->campaigns->access('view', $bystander));

    // An editor of pages anywhere sees every folder: they organize all of
    // them, and a tree missing folders would misrepresent where pages are.
    // Administering the folders themselves still takes `administer folders`.
    $page_editor = $this->createUser([Page::EDIT_PERMISSION]);
    $page_remover = $this->createUser([Page::DELETE_PERMISSION]);
    $page_creator = $this->createUser([Page::CREATE_PERMISSION]);
    self::assertNotFalse($page_editor);
    self::assertNotFalse($page_remover);
    self::assertNotFalse($page_creator);
    $site_wide = [
      'editor' => $page_editor,
      'remover' => $page_remover,
      'creator' => $page_creator,
    ];
    foreach ($site_wide as $role => $account) {
      foreach ([$this->campaigns, $this->legal] as $folder) {
        self::assertTrue($folder->access('view', $account), \sprintf('%s sees %s', $role, (string) $folder->label()));
        self::assertFalse($folder->access('update', $account), \sprintf('%s cannot rename %s', $role, (string) $folder->label()));
        self::assertFalse($folder->access('delete', $account), \sprintf('%s cannot delete %s', $role, (string) $folder->label()));
      }
    }
    // Folders of components are unaffected: they are administrative.
    $component_folder = Folder::create([
      'name' => 'Widgets',
      'configEntityTypeId' => Component::ENTITY_TYPE_ID,
    ]);
    $component_folder->save();
    self::assertFalse($component_folder->access('view', $page_editor));
    self::assertFalse($component_folder->access('view', $editor));
    $folder_administrator = $this->createUser([Folder::ADMIN_PERMISSION]);
    self::assertNotFalse($folder_administrator);
    self::assertTrue($component_folder->access('view', $folder_administrator));
    // The permission name carries the folder's ID, so the verdict follows the
    // account's permissions: deleting the folder revokes them, which changes
    // the permissions hash.
    $result = $this->campaigns->access('view', $editor, TRUE);
    self::assertInstanceOf(AccessResult::class, $result);
    self::assertContains('user.permissions', $result->getCacheContexts());

    // Moving the page to another folder takes the access with it.
    $this->campaignPage->set('folder', $this->legal->id())->save();
    $this->resetAccessCache();
    self::assertPageAccess($this->campaignPage, $editor, view: FALSE, update: FALSE, delete: FALSE, view_all_revisions: FALSE);
    $this->campaignPage->set('folder', $campaigns_id)->save();
    $this->resetAccessCache();
    self::assertTrue($this->campaignPage->access('update', $editor));

    // Deleting the folder revokes its permissions from every role: the page
    // still points at the deleted folder, yet grants nothing anymore.
    $roles = Role::loadMultiple($editor->getRoles(TRUE));
    self::assertCount(1, $roles);
    $role = \reset($roles);
    self::assertTrue($role->hasPermission(FolderPermissions::permission('edit', Page::ENTITY_TYPE_ID, $campaigns_id)));
    $this->campaigns->delete();
    $role = Role::load($role->id());
    self::assertNotNull($role);
    self::assertFalse($role->hasPermission(FolderPermissions::permission('edit', Page::ENTITY_TYPE_ID, $campaigns_id)));
    self::assertSame(['access content'], $role->getPermissions());
    $this->resetAccessCache();
    self::assertFalse($this->campaignPage->access('update', $editor));
  }

  /**
   * Folder editors hold no site-wide permission, yet need the Canvas UI.
   */
  public function testFolderEditorsMayUseTheCanvasUi(): void {
    $campaigns_id = $this->campaigns->id();
    self::assertNotNull($campaigns_id);
    $editor = $this->createUser([FolderPermissions::permission('edit', Page::ENTITY_TYPE_ID, $campaigns_id)]);
    $remover = $this->createUser([FolderPermissions::permission('delete', Page::ENTITY_TYPE_ID, $campaigns_id)]);
    $bystander = $this->createUser(['access content']);
    self::assertNotFalse($editor);
    self::assertNotFalse($remover);
    self::assertNotFalse($bystander);
    $access_manager = $this->container->get(AccessManagerInterface::class);
    \assert($access_manager instanceof AccessManagerInterface);
    $parameters = ['canvas_config_entity_type_id' => Folder::ENTITY_TYPE_ID];

    self::assertTrue($access_manager->checkNamedRoute('canvas.api.config.list', $parameters, $editor));
    // Deleting pages is not done in the Canvas UI.
    self::assertFalse($access_manager->checkNamedRoute('canvas.api.config.list', $parameters, $remover));
    self::assertFalse($access_manager->checkNamedRoute('canvas.api.config.list', $parameters, $bystander));

    // The Pages panel lists what they may view, so its route is open to them.
    $list_parameters = ['entity_type' => Page::ENTITY_TYPE_ID];
    self::assertTrue($access_manager->checkNamedRoute('canvas.api.content.list', $list_parameters, $editor));
    self::assertFalse($access_manager->checkNamedRoute('canvas.api.content.list', $list_parameters, $remover));
    $result = $access_manager->checkNamedRoute('canvas.api.content.list', $list_parameters, $bystander, TRUE);
    self::assertInstanceOf(AccessResultReasonInterface::class, $result);
    self::assertFalse($result->isAllowed());
    // @see \Drupal\Tests\canvas\Functional\CanvasContentEntityHttpApiTest
    self::assertSame('Requires >=1 content entity type with a Canvas field that can be created or edited.', $result->getReason());

    // The permission name carries the folder's ID, so the verdict varies by
    // permissions alone: no response ends up depending on the folder list.
    $result = $access_manager->checkNamedRoute('canvas.api.config.list', $parameters, $editor, TRUE);
    self::assertInstanceOf(AccessResult::class, $result);
    self::assertTrue($result->isAllowed());
    self::assertContains('user.permissions', $result->getCacheContexts());
    self::assertNotContains('config:folder_list', $result->getCacheTags());
    $result = $access_manager->checkNamedRoute('canvas.api.config.list', $parameters, $bystander, TRUE);
    self::assertInstanceOf(AccessResult::class, $result);
    self::assertFalse($result->isAllowed());
    self::assertNotContains('config:folder_list', $result->getCacheTags());
  }

  /**
   * A page may only be moved by someone who could still edit it afterwards.
   */
  public function testMovingAPageRequiresEditingItInItsNewFolder(): void {
    $campaigns_id = $this->campaigns->id();
    $marketing = self::createPageFolder('Marketing');
    $marketing_id = $marketing->id();
    self::assertNotNull($campaigns_id);
    self::assertNotNull($marketing_id);
    $editor = $this->createUser([
      FolderPermissions::permission('edit', Page::ENTITY_TYPE_ID, $campaigns_id),
      FolderPermissions::permission('edit', Page::ENTITY_TYPE_ID, $marketing_id),
    ]);
    self::assertNotFalse($editor);
    $this->setCurrentUser($editor);

    // Not into a folder they cannot edit in.
    $page = $this->campaignPage;
    $page->set('folder', $this->legal->id());
    self::assertSame([
      'folder' => 'You cannot move this page into the folder <em class="placeholder">Legal</em>: you would not be allowed to edit it there.',
    ], self::violationsByPath($page->validate()));
    // Not out of folders altogether.
    $page->set('folder', NULL);
    self::assertSame([
      'folder' => 'You cannot move this page out of its folder: that requires the permission to edit any page.',
    ], self::violationsByPath($page->validate()));
    // Into another folder they edit in, or leaving it where it is: fine.
    $page->set('folder', $marketing_id);
    self::assertSame([], self::violationsByPath($page->validate()));
    $page->set('folder', $campaigns_id);
    self::assertSame([], self::violationsByPath($page->validate()));
    // Unrelated changes never trigger the check.
    $page->set('title', 'Winter sale');
    self::assertSame([], self::violationsByPath($page->validate()));

    // Creating a page directly in a folder is not a move: it is governed by
    // that folder's permissions from the start, wherever it is filed.
    $new_page = Page::create(['title' => 'Draft', 'components' => [], 'folder' => $this->legal->id()]);
    self::assertSame([], self::violationsByPath($new_page->validate()));
    $new_page->set('folder', $campaigns_id);
    self::assertSame([], self::violationsByPath($new_page->validate()));

    // A site-wide editor may file pages anywhere, and take them out.
    $site_wide_editor = $this->createUser([Page::EDIT_PERMISSION]);
    self::assertNotFalse($site_wide_editor);
    $this->setCurrentUser($site_wide_editor);
    $page->set('folder', $this->legal->id());
    self::assertSame([], self::violationsByPath($page->validate()));
    $page->set('folder', NULL);
    self::assertSame([], self::violationsByPath($page->validate()));
  }

  /**
   * Membership in a folder the user cannot see is not disclosed.
   */
  public function testTheHttpApiOnlyDisclosesMembershipOfViewableFolders(): void {
    $campaigns_id = $this->campaigns->id();
    self::assertNotNull($campaigns_id);
    $editor = $this->createUser([
      'access content',
      FolderPermissions::permission('edit', Page::ENTITY_TYPE_ID, $campaigns_id),
    ]);
    self::assertNotFalse($editor);
    $this->setCurrentUser($editor);
    // A published page in a folder this editor cannot view: the page itself is
    // viewable, its folder is not.
    $announcement = self::createPage('Announcement', $this->legal);
    $announcement->setPublished()->save();
    self::assertTrue($announcement->access('view', $editor));
    self::assertFalse($this->legal->access('view', $editor));

    $response = $this->request(Request::create(\sprintf(self::URL, $announcement->id())));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertNull($this->decodeResponse($response)['folder']);

    // Nor does the Pages panel list what they cannot view: the drafts outside
    // their folders are absent, the published page is there without its folder.
    $response = $this->request(Request::create('/canvas/api/v0/content/canvas_page'));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $body = $this->decodeResponse($response);
    $listed = [];
    foreach ($body['data'] as $row) {
      $listed[$row['title']] = $row['folder'];
    }
    // Newest first, as the panel lists them.
    self::assertSame([
      'Summer sale' => $campaigns_id,
      'Announcement' => NULL,
    ], $listed);
    // The count matches what is listed, so paging cannot ask for an offset
    // that means something else and serve the same row twice.
    self::assertSame(2, $body['meta']['count']);
    $second_page = $this->request(Request::create('/canvas/api/v0/content/canvas_page', 'GET', ['page' => ['offset' => 2, 'limit' => 10]]));
    self::assertSame([], $this->decodeResponse($second_page)['data']);
    // Their own folder is disclosed on the pages filed into it.
    $response = $this->request(Request::create(\sprintf(self::URL, $this->campaignPage->id())));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame($campaigns_id, $this->decodeResponse($response)['folder']);
  }

  /**
   * The HTTP API enforces the same rule, with a 422.
   */
  public function testTheHttpApiRefusesMovingAPageWhereItsEditorCouldNotEditIt(): void {
    $campaigns_id = $this->campaigns->id();
    $marketing = self::createPageFolder('Marketing');
    $marketing_id = $marketing->id();
    self::assertNotNull($campaigns_id);
    self::assertNotNull($marketing_id);
    $editor = $this->createUser([
      'access content',
      FolderPermissions::permission('edit', Page::ENTITY_TYPE_ID, $campaigns_id),
      FolderPermissions::permission('edit', Page::ENTITY_TYPE_ID, $marketing_id),
    ]);
    self::assertNotFalse($editor);
    $this->setCurrentUser($editor);
    $page_id = $this->campaignPage->id();
    self::assertNotNull($page_id);

    $response = $this->request(self::createPatchRequest($page_id, $this->legal->id()));
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    self::assertSame(
      ['You cannot move this page into the folder <em class="placeholder">Legal</em>: you would not be allowed to edit it there.'],
      \array_column($this->decodeResponse($response)['errors'], 'detail'),
    );
    $stored = $this->loadPage($page_id);
    self::assertSame($campaigns_id, $stored->get('folder')->target_id);

    $response = $this->request(self::createPatchRequest($page_id, $marketing_id));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $stored = $this->loadPage($page_id);
    self::assertSame($marketing_id, $stored->get('folder')->target_id);

    // A page they cannot edit cannot be moved at all, wherever it would go.
    $legal_page_id = $this->legalPage->id();
    self::assertNotNull($legal_page_id);
    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage("The 'edit canvas_page' permission is required.");
    $this->request(self::createPatchRequest($legal_page_id, $campaigns_id));
  }

  private static function createPageFolder(string $name): Folder {
    $folder = Folder::create([
      'name' => $name,
      'configEntityTypeId' => Page::ENTITY_TYPE_ID,
    ]);
    $folder->save();
    return $folder;
  }

  private static function createPage(string $title, ?Folder $folder = NULL): Page {
    $page = Page::create([
      'title' => $title,
      'status' => FALSE,
      'components' => [],
      'folder' => $folder?->id(),
    ]);
    $page->save();
    return $page;
  }

  private function loadPage(int|string $id): Page {
    $page = $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage(Page::ENTITY_TYPE_ID)
      ->loadUnchanged($id);
    \assert($page instanceof Page);
    return $page;
  }

  private static function createPatchRequest(int|string $page_id, ?string $folder_id): Request {
    return Request::create(
      \sprintf(self::URL, $page_id),
      'PATCH',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: \json_encode([
        'title' => 'Summer sale',
        'status' => FALSE,
        'path' => '/summer-sale',
        'components' => [],
        'folder' => $folder_id,
      ], JSON_THROW_ON_ERROR),
    );
  }

  private static function assertPageAccess(Page $page, AccountInterface $account, bool $view, bool $update, bool $delete, bool $view_all_revisions): void {
    $message = \sprintf('%s on "%s" for %s', '%s', $page->label(), \implode(', ', $account->getRoles(TRUE)));
    self::assertSame($view, $page->access('view', $account), \sprintf($message, 'view'));
    self::assertSame($update, $page->access('update', $account), \sprintf($message, 'update'));
    self::assertSame($delete, $page->access('delete', $account), \sprintf($message, 'delete'));
    self::assertSame($view_all_revisions, $page->access('view all revisions', $account), \sprintf($message, 'view all revisions'));
  }

  /**
   * Entity access results are statically cached; folder moves must bypass it.
   */
  private function resetAccessCache(): void {
    $this->container->get(EntityTypeManagerInterface::class)
      ->getAccessControlHandler(Page::ENTITY_TYPE_ID)
      ->resetCache();
  }

  /**
   * @return array<string, string>
   *   Rendered violation messages keyed by property path.
   */
  private static function violationsByPath(ConstraintViolationListInterface $violations): array {
    $by_path = [];
    foreach ($violations as $violation) {
      $by_path[$violation->getPropertyPath()] = (string) $violation->getMessage();
    }
    return $by_path;
  }

}
