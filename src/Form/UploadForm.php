<?php

declare(strict_types=1);

namespace Drupal\threed_assets\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\threed_assets\AssetIngestor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin form to ingest a 3D asset file into the right Media bundle.
 *
 * Reuses AssetIngestor (sha256-keyed, idempotent), so the same dedup and
 * manifest-extraction rules as `drush ta:import` apply to a single upload.
 */
final class UploadForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AssetIngestor $ingestor,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get(AssetIngestor::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'threed_assets_upload';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $bundles = _threed_assets_leaf_bundles();
    $file_settings = _threed_assets_leaf_file_settings();

    // Union of every leaf's allowed extensions — narrowed in validateForm.
    $all_exts = [];
    foreach ($file_settings as $settings) {
      foreach (explode(' ', $settings['file_extensions'] ?? '') as $ext) {
        if ($ext !== '') {
          $all_exts[$ext] = TRUE;
        }
      }
    }

    $form['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Asset kind'),
      '#options' => $bundles,
      '#required' => TRUE,
      '#description' => $this->t('Pick the leaf type that matches the file.'),
    ];
    $form['file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('File'),
      '#upload_location' => 'public://3d/ingest/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => implode(' ', array_keys($all_exts))],
      ],
      '#required' => TRUE,
    ];
    $form['role'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Role'),
      '#description' => $this->t('Free-text role hint (e.g. skybox, PBR texture, ambience).'),
    ];
    $form['scene'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Scene'),
      '#description' => $this->t('Optional scene tag (e.g. grassland, snow, sea).'),
    ];
    $form['resolution'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Resolution / LOD'),
      '#description' => $this->t('Optional ladder label (e.g. 4k web-low, 4k web-high, 8k).'),
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save asset'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $bundle = (string) $form_state->getValue('bundle');
    $fids = (array) $form_state->getValue('file');
    $fid = (int) reset($fids);
    if (!$fid || !$bundle) {
      return;
    }
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if (!$file) {
      $form_state->setErrorByName('file', $this->t('The uploaded file could not be loaded.'));
      return;
    }
    $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
    $allowed = _threed_assets_leaf_file_settings()[$bundle]['file_extensions'] ?? '';
    if ($ext !== '' && !in_array($ext, explode(' ', $allowed), TRUE)) {
      $form_state->setErrorByName('file', $this->t(
        'A @bundle asset must use one of: @exts (got .@ext).',
        ['@bundle' => $bundle, '@exts' => $allowed, '@ext' => $ext]
      ));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $fids = (array) $form_state->getValue('file');
    $fid = (int) reset($fids);
    /** @var \Drupal\file\FileInterface|null $file */
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if (!$file) {
      $this->messenger()->addError($this->t('Upload failed: file could not be loaded.'));
      return;
    }
    $bundle = (string) $form_state->getValue('bundle');
    $media = $this->ingestor->ingestFile($file, $bundle, [
      'name' => $file->getFilename(),
      'role' => (string) $form_state->getValue('role'),
      'scene' => (string) $form_state->getValue('scene'),
      'resolution' => (string) $form_state->getValue('resolution'),
    ]);

    $this->messenger()->addStatus($this->t(
      'Asset saved: @label (sha @h…)',
      ['@label' => $media->label(), '@h' => substr((string) $media->get('field_3d_hash')->value, 0, 12)]
    ));
    // For models, hand off to the in-browser turntable capture.
    if ($media->bundle() === '3d_model') {
      $form_state->setRedirect('threed_assets.capture', ['media' => $media->id()]);
      return;
    }
    $form_state->setRedirect('entity.media.canonical', ['media' => $media->id()]);
  }

}
