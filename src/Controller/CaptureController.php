<?php

declare(strict_types=1);

namespace Drupal\threed_assets\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\file\FileRepositoryInterface;
use Drupal\media\MediaInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Hosts the in-browser turntable capture and receives the six rendered stills.
 *
 * The page() method serves a minimal admin page; the bundled capture.js loads
 * the source model into a hidden three.js scene with neutral studio lighting,
 * renders six cardinal views and POSTs them back to ingest(), which attaches
 * them to the media's field_3d_render.
 */
final class CaptureController extends ControllerBase {

  /**
   * The six cardinal view ids the capture page emits.
   */
  private const VIEWS = ['front', 'back', 'right', 'left', 'top', 'bottom'];

  public function __construct(
    private readonly FileRepositoryInterface $fileRepository,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('file.repository'),
      $container->get('file_system'),
      $container->get('file_url_generator'),
    );
  }

  /**
   * Render the capture page for a 3d_model media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The source 3D model media.
   *
   * @return array
   *   Render array attaching the capture library + drupalSettings.
   */
  public function page(MediaInterface $media): array {
    if ($media->bundle() !== '3d_model') {
      throw new NotFoundHttpException('Captures only land on 3d_model media.');
    }
    $file = $media->get('field_3d_file')->entity;
    if (!$file) {
      throw new NotFoundHttpException('Source model has no file.');
    }
    $model_url = $this->fileUrlGenerator->generateString($file->getFileUri());
    $ingest_url = Url::fromRoute(
      'threed_assets.capture_ingest',
      ['media' => $media->id()]
    )->toString();

    return [
      '#theme' => 'threed_assets_capture',
      '#media_id' => (int) $media->id(),
      '#capture_size' => 512,
      '#attached' => [
        'library' => ['threed_assets/capture'],
        'drupalSettings' => [
          'threedAssetsCapture' => [
            'modelUrl' => $model_url,
            'ingestUrl' => $ingest_url,
            'mediaId' => (int) $media->id(),
            'size' => 512,
            'views' => self::VIEWS,
          ],
        ],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Receive the six cardinal PNG renders and attach them to the media.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request (multipart with files keyed by view id).
   * @param \Drupal\media\MediaInterface $media
   *   The source 3D model media to attach the renders to.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with the saved count and a redirect URL to the media canonical page.
   */
  public function ingest(Request $request, MediaInterface $media): JsonResponse {
    if ($media->bundle() !== '3d_model') {
      throw new BadRequestHttpException('Captures only land on 3d_model media.');
    }
    $files = $request->files->get('renders');
    if (!is_array($files) || count($files) === 0) {
      throw new BadRequestHttpException('No renders posted.');
    }

    $dir = "public://3d/renders/media-{$media->id()}";
    $this->fileSystem->prepareDirectory(
      $dir,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
    );

    $saved = [];
    foreach (self::VIEWS as $view) {
      $upload = $files[$view] ?? NULL;
      if (!$upload) {
        continue;
      }
      $data = file_get_contents($upload->getPathname());
      if ($data === FALSE) {
        continue;
      }
      $file = $this->fileRepository->writeData($data, "$dir/$view.png", FileExists::Replace);
      $file->setPermanent();
      $file->save();
      $saved[] = ['view' => $view, 'fid' => $file->id()];
    }

    if ($saved) {
      $media->set(
        'field_3d_render',
        array_map(static fn(array $s): array => ['target_id' => $s['fid']], $saved)
      );
      $media->save();
    }

    return new JsonResponse([
      'saved' => count($saved),
      'redirect' => Url::fromRoute('entity.media.canonical', ['media' => $media->id()])->toString(),
    ]);
  }

}
