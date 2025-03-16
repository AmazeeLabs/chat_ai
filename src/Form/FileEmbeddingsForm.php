<?php

declare(strict_types=1);

namespace Drupal\chat_ai\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\file\Entity\File;

/**
 * Provides a Chat AI form.
 */
final class FileEmbeddingsForm extends FormBase {

  private const PAGE_LIMIT = 25;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'chat_ai_file_embeddings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['container'] = [
      '#type' => 'details',
      '#title' => $this->t('Custom knowledge'),
      '#description' => $this->t('Upload files here to enhance the custom knowledge of the chatbot.'),
      '#open' => TRUE,
    ];

    $form['container']['file_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload File'),
      '#description' => $this->t('Allowed types: txt, doc, docx, pdf'),
      '#upload_validators' => [
        'file_validate_extensions' => ['txt doc docx pdf'],
      ],
      '#upload_location' => 'public://chat_ai_files_custom/',
    ];

    $form['container']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];

    $form['files_table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('File Name'),
        $this->t('Size'),
        $this->t('Indexed'),
        $this->t('Operations'),
      ],
      '#empty' => $this->t('No files found.'),
    ];

    // Load all file entities.
    $files = $this->loadEmbeddings();
    $pager = \Drupal::service('pager.manager')->createPager(count($files), self::PAGE_LIMIT);
    $currentPage = $pager->getCurrentPage();
    $files = array_slice($files, $currentPage * self::PAGE_LIMIT, self::PAGE_LIMIT);

    foreach ($files as $file) {
      /** @var Drupal\file\Entity\File $file */

      // @todo Add DI
      $url = Url::fromUserInput(\Drupal::service('file_url_generator')->generateString($file->getFileUri()));
      $icon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-box-arrow-up-right" viewBox="0 0 16 16">
        <path fill-rule="evenodd" d="M8.636 3.5a.5.5 0 0 0-.5-.5H1.5A1.5 1.5 0 0 0 0 4.5v10A1.5 1.5 0 0 0 1.5 16h10a1.5 1.5 0 0 0 1.5-1.5V7.864a.5.5 0 0 0-1 0V14.5a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h6.636a.5.5 0 0 0 .5-.5"/>
        <path fill-rule="evenodd" d="M16 .5a.5.5 0 0 0-.5-.5h-5a.5.5 0 0 0 0 1h3.793L6.146 9.146a.5.5 0 1 0 .708.708L15 1.707V5.5a.5.5 0 0 0 1 0z"/>
      </svg>';

      $link = [
        '#type' => 'link',
        '#title' => $file->getFilename(),
        '#suffix' => Markup::create("  $icon"),
        '#url' => $url,
        '#attributes' => [
          'target' => '_blank',
        ],
      ];

      $link_modal['open_modal'] = [
        '#type' => 'link',
        '#title' => $this->t('Remove'),
        '#url' => Url::fromRoute('chat_ai.clear_file_embedding_confirm', [
          'file' => $file->id(),
        ]),
        '#attributes' => [
          'class' => ['use-ajax', 'button', 'button--small', 'button--danger'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode([
            'width' => 800,
          ]),
        ],
      ];
      // \Drupal::service('renderer')->renderRoot($form['actions']['open_modal']);

      $form['files_table'][$file->id()] = [
        'name' => $link,
        'size' => [
          '#markup' => ByteSizeMarkup::create($file->getSize()),
        ],
        'indexed' => [
          '#markup' => Markup::create('✅'),
        ],
        'operations' => [$link_modal],
        // 'operations' => [
        //   '#type' => 'submit',
        //   '#value' => $this->t('Remove'),
        //   '#name' => 'remove_' . $file->id(),
        //   '#submit' => ['::removeFileSubmit'],
        //   '#attributes' => [
        //     'class' => ['button', 'button--danger', 'button--small'],
        //   ],
        //   '#file_id' => $file->id(),
        // ],
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['pager'] = [
      '#type' => 'pager',
    ];

    return $form;
  }

  /**
   * Custom submit handler for remove buttons.
   */
  public function removeFileSubmit(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $file_id = $triggering_element['#file_id'];

    if ($file = File::load($file_id)) {
      \Drupal::service('chat_ai.supabase')->clearEntityIndexedData($file);
      $file->delete();
      $this->messenger()->addMessage($this->t('File %name has been removed.', ['%name' => $file->getFilename()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $fids = $form_state->getValue('file_upload');

    if (!empty($fids)) {
      $batch = [
        'title' => $this->t('Processing uploaded files'),
        'init_message' => $this->t('Starting to process data.'),
        'operations' => [],
        'finished' => 'Drupal\chat_ai\Form\FileEmbeddingsForm::batchFinished',
        'error_message' => $this->t('Error processing document.'),
      ];

      // @todo Add DI
      $embeddings = \Drupal::service('chat_ai.embeddings');

      // @todo Process chunks
      foreach ($fids as $fid) {
        if ($file = File::load($fid)) {
          $file->setPermanent();
          $file->set('ai_indexed', TRUE);
          $file->save();
        }

        $chunks = $embeddings->documentSplitterFile($file);
        foreach ($chunks as $chunk) {
          $batch['operations'][] = [['Drupal\chat_ai\Form\FileEmbeddingsForm', 'batchProcess'], [$fid, $chunk]];
        }
      }

      batch_set($batch);
    }
  }

  /**
   * Batch process callback.
   */
  public static function batchProcess($fid, $chunk, &$context) {

    if (empty($context['results']['processed'])) {
      $context['results']['processed'] = 0;
    }

    if ($file = File::load($fid)) {
      $embeddings = \Drupal::service('chat_ai.embeddings');
      $embeddings->createSingleChunkEmbedding($file, $chunk);
      $context['results']['processed']++;
      $context['message'] = t('Processed file: @filename', ['@filename' => $file->getFilename()]);
    }
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished($success, $results, $operations) {
    $messenger = \Drupal::messenger();

    if ($success) {
      $messenger->addMessage(t('Successfully processed @count chunks.', ['@count' => $results['processed']]));
    } else {
      $messenger->addError(t('An error occurred while processing files.'));
    }

    // Redirect back to the form.
    return [
      '#type' => 'redirect',
      '#url' => Url::fromRoute('chat_ai.file_embeddings'),
    ];
  }

  /**
   *
   */
  private function loadEmbeddings() {
    // @todo Update to load only files related to embeddings
    $extensions = ['txt', 'pdf', 'doc', 'docx'];
    $files = [];
    $query = \Drupal::entityQuery('file')
      ->condition('filemime', '^application/pdf|text/plain|application/msword|application/vnd.openxmlformats-officedocument.wordprocessingml.document$', 'REGEXP')
      ->condition('ai_indexed', TRUE)
      ->accessCheck(FALSE);

    $fids = $query->execute();
    if (!empty($fids)) {
      $files = File::loadMultiple($fids);
    }
    return $files;
  }
}
