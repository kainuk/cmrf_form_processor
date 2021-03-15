<?php

namespace Drupal\cmrf_form_processor_mollie\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\mollie\Events\MollieNotificationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * mollie_ke event subscriber.
 */
class MollieFormProcessorSubscriber implements EventSubscriberInterface {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private $entityTypeManager;

  /**
   * Constructs event subscriber.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  public function callFormProcessor(MollieNotificationEvent $event): void {

    $httpCode = 200;

    // Fetch the transaction.
    try {
      $submission = $this->entityTypeManager->getStorage('webform_submission')
        ->load($event->getContextId());
      $webform = $submission->getWebform();
      $cmrfHandler = FALSE;
      $handlers = $webform->getHandlers();
      foreach ($handlers as $handler) {
        if ($handler->getHandlerId() == 'cmfr_form_processor') {
          $cmrfHandler = $handler;
        }
      }
      $transaction = $this->entityTypeManager->getStorage('mollie_payment')
        ->load($event->getTransactionId());

      if ($cmrfHandler && !$handler->isDisabled() && $handler->checkConditions($submission)) {
        $cmrfHandler->postSave($submission, TRUE, [
          'mollie_payment_id' => $event->getTransactionId(),
          'mollie_payment_status' => $transaction->getStatus()
        ]);
      }
    } catch (\Exception $e) {
      watchdog_exception('cmrf_form_processor_mollie', $e);
      $httpCode = 500;
    }

    // Set the HTTP code to return to Mollie.
    $event->setHttpCode($httpCode);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      MollieNotificationEvent::EVENT_NAME => 'callFormProcessor',
    ];
  }

}
