<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

// cspell:ignore Bwidth Fitok DNSF ITOK

use Drupal\canvas\Controller\ApiMediaControllers;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\MockFileUploadTrait;
use Drupal\Tests\canvas\Kernel\Traits\PredictableImageStyleItokTestTrait;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Kernel\Traits\VfsPublicStreamUrlTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[CoversClass(ApiMediaControllers::class)]
#[CoversMethod(ApiMediaControllers::class, 'upload')]
class ApiMediaControllersPostTest extends CanvasKernelTestBase {

  use UserCreationTrait;
  use MediaTypeCreationTrait;
  use MockFileUploadTrait;
  use RequestTrait;
  use VfsPublicStreamUrlTrait;
  use PredictableImageStyleItokTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_page',
    'field',
  ];

  private const string URL = '/canvas/api/v0/media/%s/upload';

  private string $testImagePath;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('media');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'field', 'filter', 'path_alias']);

    $this->setupPredictableItok();

    $this->createMediaType('image', [
      'id' => 'image',
      'label' => 'Image',
    ]);
    $this->createMediaType('video_file', [
      'id' => 'video',
      'label' => 'Video',
    ]);
    $this->setUpCurrentUser([], ['access content', 'view media', 'create media']);

    $this->mockFileSystemForUploads();

    // Copy a test image into the vfsStream-backed temporary directory so
    // FileUploadHandler can move it to the public:// destination.
    $source = \dirname(__DIR__, 3) . '/fixtures/images/gracie-big.jpg';
    $temp_dir = $this->container->get(FileSystemInterface::class)->getTempDirectory();
    $this->testImagePath = $temp_dir . '/canvas-test-upload-' . \uniqid() . '.jpg';
    \copy($source, $this->testImagePath);
  }

  /**
   * Tests uploading a media file via the HTTP API.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiMediaControllers::upload
   */
  #[DataProvider('providerValidPost')]
  public function testPost(string $media_type, array $post_data, array $expected_response_contents): void {
    $response = $this->request(
      Request::create(
        \sprintf(self::URL, $media_type),
        'POST',
        parameters: $post_data,
        files: [
          'file' => new UploadedFile($this->testImagePath, $post_data['file'], 'image/jpeg', NULL, test: TRUE),
        ],
        server: ['CONTENT_TYPE' => 'multipart/form-data'],
      )
    );
    // The response of a POST request shouldn't be cacheable.
    \assert($response instanceof JsonResponse && !$response instanceof CacheableJsonResponse);
    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());

    $data = $this->decodeResponse($response);

    $vfs_site_base_url = base_path() . $this->siteDirectory;
    \array_walk_recursive($data, function (mixed &$value) use ($vfs_site_base_url) {
      if (\is_string($value)) {
        $value = \str_replace($vfs_site_base_url, '::SITE_DIR_BASE_URL::', $value);
      }
    });

    // Versioned public APIs need to be strict: this means asserting
    // that we get all the expected info, but also NO extra additions.
    // So we use `assertSame` in the full response contents.
    $this->assertSame(
      [
        'id' => $expected_response_contents['id'],
        // But we cannot know in advance the UUID, so just take that from
        // the response itself.
        'uuid' => $data['uuid'],
      ] + $expected_response_contents,
      $data
    );
  }

  #[DataProvider('providerInvalidPost')]
  public function testInvalidPost(string $media_type, array $post_data, int $expected_http_code, string $expected_message): void {
    $response = $this->request(
      Request::create(
        \sprintf(self::URL, $media_type),
        'POST',
        parameters: $post_data,
        files: [
          'file' => new UploadedFile($this->testImagePath, $post_data['file'], 'image/jpeg', NULL, test: TRUE),
        ],
        server: ['CONTENT_TYPE' => 'multipart/form-data'],
      )
    );

    // The response of a POST request shouldn't be cacheable.
    \assert($response instanceof JsonResponse && !$response instanceof CacheableJsonResponse);
    $this->assertSame($expected_http_code, $response->getStatusCode());

    $data = $this->decodeResponse($response);
    $this->assertSame($expected_message, $data['errors'][0]['detail']);
  }

  /**
   * Tests that a non-image file with an allowed extension is rejected.
   *
   * A file whose name has an allowed image extension but whose contents are
   * not a valid image must not be stored: the FileIsImage validator rejects it
   * with a 422.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiMediaControllers::upload
   */
  public function testPostNonImageWithAllowedExtension(): void {
    // Write a non-image payload to a file with an allowed image extension.
    $temp_dir = $this->container->get(FileSystemInterface::class)->getTempDirectory();
    $payload_path = $temp_dir . '/canvas-test-payload-' . \uniqid() . '.jpg';
    \file_put_contents($payload_path, "<?php phpinfo(); ?>\n<script>alert(document.cookie)</script>");

    $response = $this->request(
      Request::create(
        \sprintf(self::URL, 'image'),
        'POST',
        parameters: ['file' => 'payload.jpg', 'alt' => 'x'],
        files: [
          'file' => new UploadedFile($payload_path, 'payload.jpg', 'image/jpeg', NULL, test: TRUE),
        ],
        server: ['CONTENT_TYPE' => 'multipart/form-data'],
      )
    );

    // The response of a POST request shouldn't be cacheable.
    \assert($response instanceof JsonResponse && !$response instanceof CacheableJsonResponse);
    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $data = $this->decodeResponse($response);
    $this->assertStringStartsWith('The image file is invalid or the image type is not allowed.', $data['errors'][0]['detail']);

    // No managed file or media entity should have been created.
    $entity_type_manager = $this->container->get(EntityTypeManagerInterface::class);
    $this->assertEmpty($entity_type_manager->getStorage('file')->loadMultiple());
    $this->assertEmpty($entity_type_manager->getStorage('media')->loadMultiple());
  }

  /**
   * A file whose name contains a space uploads successfully.
   *
   * A stream wrapper URI is not an RFC 3986 URI: what follows `://` is a file
   * path rather than an authority plus a path, and Drupal never percent-encodes
   * file names. Validating the stored `public://…` URI as an RFC 3986 URI
   * therefore rejected every upload whose file name contains a space — for
   * every file field on the site, not just Canvas' own.
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\UriConstraintValidator
   * @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\FileUriItemOverride
   * @legacy-covers \Drupal\canvas\Controller\ApiMediaControllers::upload
   */
  public function testPostFileNameWithSpaces(): void {
    $response = $this->request(
      Request::create(
        \sprintf(self::URL, 'image'),
        'POST',
        parameters: ['file' => 'gracie big.jpg', 'alt' => 'Gracie Dog'],
        files: [
          'file' => new UploadedFile($this->testImagePath, 'gracie big.jpg', 'image/jpeg', NULL, test: TRUE),
        ],
        server: ['CONTENT_TYPE' => 'multipart/form-data'],
      )
    );
    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());

    $files = $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage('file')
      ->loadMultiple();
    $this->assertCount(1, $files);
    $file = \reset($files);
    \assert($file instanceof FileInterface);
    // The space survives: it is stored, and the File entity is valid.
    $this->assertStringEndsWith('/gracie big.jpg', $file->getFileUri());
    $this->assertCount(0, $file->validate());
  }

  public function testPostWithoutFile(): void {
    $request = Request::create(
      \sprintf(self::URL, 'image'),
      'POST',
      server: ['CONTENT_TYPE' => 'multipart/form-data'],
    );
    // Bypass the OpenAPI request validator (dev-only middleware) so we can
    // test the controller's own null-file handling, which is the production
    // safety net.
    $request->headers->set('X-NO-OPENAPI-VALIDATION', '1');
    $response = $this->request($request);
    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $data = $this->decodeResponse($response);
    $this->assertSame('No file was uploaded. The "file" field is required.', $data['errors'][0]['detail']);
  }

  public static function providerValidPost(): \Generator {
    yield "Create a new image" => [
      'image',
      [
        'file' => 'gracie-big.jpg',
        'title' => 'Gracie Dog',
        'alt' => 'Gracie Dog in its most happy state',
      ],
      [
        'id' => 1,
        'inputs_resolved' => [
          'src' => '::SITE_DIR_BASE_URL::/files/2026-04/gracie-big.jpg?alternateWidths=::SITE_DIR_BASE_URL::/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/2026-04/gracie-big.jpg.avif%3Fitok%3Dh5xv7Qhl',
          'alt' => 'Gracie Dog in its most happy state',
          'width' => 3000,
          'height' => 2595,
        ],
      ],
    ];
    yield "Create a new image without alt nor title" => [
      'image',
      [
        'file' => 'gracie-big.jpg',
      ],
      [
        'id' => 1,
        'inputs_resolved' => [
          'src' => '::SITE_DIR_BASE_URL::/files/2026-04/gracie-big.jpg?alternateWidths=::SITE_DIR_BASE_URL::/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/2026-04/gracie-big.jpg.avif%3Fitok%3Dh5xv7Qhl',
          'alt' => '',
          'width' => 3000,
          'height' => 2595,
        ],
      ],
    ];
  }

  public static function providerInvalidPost(): \Generator {
    yield "Create a new media with non-image media type" => [
      'video',
      [
        'file' => 'gracie-big.jpg',
      ],
      Response::HTTP_BAD_REQUEST,
      "The media type 'video' is not an image media type.",
    ];
    yield "Create a new media with invalid file extension" => [
      'image',
      [
        'file' => 'gracie-big.exe',
      ],
      Response::HTTP_UNPROCESSABLE_ENTITY,
      'Only files with the following extensions are allowed: <em class="placeholder">png gif jpg jpeg webp avif</em>.',
    ];
  }

}
