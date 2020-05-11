<?php

namespace Drupal\cmrf_form_processor\Plugin\WebformHandler;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\Utility\WebformElementHelper;
use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\WebformTokenManagerInterface;
use Drupal\cmrf_core\Core;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Serialization\Yaml;

/**
 * Webform submission debug handler.
 *
 * @WebformHandler(
 *   id = "cmrf_form_processor",
 *   label = @Translation("CMFR Form Processor"),
 *   category = @Translation("CiviCRM"),
 *   description = @Translation("Post values to CiviCRM form processor"),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * )
 */
class FormProcessorWebformHandler extends WebformHandlerBase {

  /**
   * The webform token manager.
   *
   * @var \Drupal\webform\WebformTokenManagerInterface
   */
  protected $tokenManager;

  protected $core;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, WebformSubmissionConditionsValidatorInterface $conditions_validator, WebformTokenManagerInterface $token_manager, Core $core) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger_factory, $config_factory, $entity_type_manager, $conditions_validator);
    $this->tokenManager = $token_manager;
    $this->core = $core;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('webform_submission.conditions_validator'),
      $container->get('webform.token_manager'),
      $container->get('cmrf_core.core')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    $t_args = ['%plugin_id' => $this->getPluginId(),
               '%connection' => $this->configuration['connection'],
               '%form_processor' =>  $this->configuration['form_processor'],
               '%form_processor_params' =>  '['.implode(',',$this->configuration['form_processor_params']).']',
    ];
    return [
      'message' => [
        '#markup' => $this->t('This %plugin_id handler using connection %connection and form_processor %form_processor with parameters %form_processor_params ', $t_args),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'connection' => null,
      'form_processor' => null,
      'form_processor_fields' => [],
      'form_processor_params' => [],
      'form_processor_current_contact' => 0,
      'form_processor_background' =>0,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
   // $results_disabled = $this->getWebform()->getSetting('results_disabled');

    //parent::submitConfigurationForm($form, $form_state);
    $this->applyFormStateToConfiguration($form_state);

    $selected_connection = empty($this->configuration['connection'])?null:$this->configuration['connection'];
    $selected_formprocessor = empty($this->configuration['form_processor'])?null:$this->configuration['form_processor'];

    $form['fp'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('CMRF Form Processor'),
      '#attributes' => ['id' => 'form-processor-stuff'],
    ];
    $form['fp']['connection'] = [
      '#type' => 'select',
      '#title' => $this->t('Connector'),
      '#empty_option' => $this->t('- None -'),
      '#options' => $this->core->getConnectors(),
      '#default_value' => $this->configuration['connection'],
      '#ajax' => [
        'callback' => [$this, 'connectionCallback'],
        'wrapper' => 'form-processor-stuff',
        'event'   => 'change',
        ]
      ];
    if($selected_connection) {
      $form['fp']['form_processor'] = [
        '#type' => 'select',
        '#title' => $this->t('Form Processor'),
        '#empty_option' => $this->t('- None -'),
        '#options' => $this->formProcessorList($selected_connection),
        '#default_value' => $this->configuration['form_processor'],
      ];
      $form['additional'] =
        [ '#type' => 'fieldset',
          '#title' => $this->t('Form Processor'),
        ];
      if ($selected_formprocessor) {
        $form['additional']['fields'] =
          [
            '#type' => 'fieldset',
            '#title' => $this->t('Fields'),
          ];
        $fpValues = $this->formProcessorFields($selected_connection, $selected_formprocessor);
        foreach ($this->mapTitle($fpValues)  as $key => $field) {
          $form['additional']['fields'][$key] = [
            '#type' => 'checkbox',
            '#title' => $field,
            '#default_value' => $this->configuration['form_processor_fields'][$key],
          ];
        }
        $form['additional']['params'] =
          [
            '#type' => 'fieldset',
            '#title' => $this->t('Parameters'),
          ];
        foreach ($this->formProcessorDefaultsParams($selected_connection, $selected_formprocessor) as $key => $field) {
          $form['additional']['params'][$key] = [
            '#type' => 'select',
            '#title' => $field,
            '#default_value' => $this->configuration['form_processor_params'][$key],
            '#options' => [
               'none' => 'None',
               'url'=> 'URL',
               'current_user' => 'Current User'
            ]
          ];
        }

        $form['additional']['form_processor_current_contact'] = [
          '#type' => 'select',
          '#title' => 'Fill Current Contact',
          '#default_value' => $this->configuration['form_processor_current_contact'],
          '#options' => [0 => "-None-"]+$this->mapTitle($fpValues),
        ];
      }
    }

    $this->elementTokenValidate($form);

    return $this->setSettingsParents($form);
  }

   public function connectionCallback(array $form, FormStateInterface $form_state) {
    return $form['settings']['fp'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {

    parent::submitConfigurationForm($form, $form_state);
    $this->applyFormStateToConfiguration($form_state);
    $values = $form_state->getValues();
    $this->configuration['connection']            = $values['connection'];
    $this->configuration['form_processor']        = $values['form_processor'];
    $this->configuration['form_processor_fields'] = $values['additional']['fields'];
    $this->configuration['form_processor_params'] = $values['additional']['params'];
    $this->configuration['form_processor_current_contact'] = $values['form_processor_current_contact'];

    $builder = new FormProcessorWebformBuilder($this->getWebform());
    $fpValues = $this->formProcessorFields($this->configuration['connection']  , $this->configuration['form_processor']);
    $builder->addFields($this->configuration['form_processor_fields'],$fpValues);
    $builder->deleteFields($this->configuration['form_processor_fields']);
    $builder->save();
  }

  private function getContactId() {
    $id =  \Drupal::currentUser()->id();
    $currentUser = \Drupal::entityTypeManager()->getStorage('user')->load($id);
    return $currentUser->field_user_contact_id->value;
  }

  public function alterForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    $form_processor_params = $this->configuration['form_processor_params'];
    if(empty($form_processor_params)){
      return; // no form_processor params means no form defaults so retun
    }
    $params = [];
    foreach($form_processor_params as $key => $fp_param){
      if($fp_param=='url' && \Drupal::request()->get($key)){
        $params[$key] = \Drupal::request()->get($key);
      }
      if($fp_param=='current_user' && $this->getContactId()){
        $params[$key] = $this->getContactId();
      }
    }
    if(empty($params)){
      return;
    }

    $values = $this->formProcessorDefaultsDefault($params);
    if($values['is_error']==0){
      unset($values['is_error']);
      foreach($values as $key=>$value){
        $element =&WebformElementHelper::getElement($form,$key);
        if($element){
          $element['#default_value']=$value;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $data = $webform_submission->getData();
    $fields = $this->configuration['form_processor_fields'];
    $params = [];
    foreach($fields as $key=>$field){
      if(key_exists($key,$data)){
        $params[$key]=$data[$key];
      }
    }
    if( $this->configuration['form_processor_current_contact']){
      $params[$this->configuration['form_processor_current_contact']]=$this->getContactId();
    }
    $call = $this->core->createCall($this->configuration['connection'],'FormProcessor',$this->configuration['form_processor'],$params,[]);
    $this->core->executeCall($call);
  }

  private function formProcessorList($connection){
     $call = $this->core->createCall($connection,'FormProcessorInstance','list',[],['limit'=>0]);
     $values = $this->core->executeCall($call)['values'];
     return array_map(function($value) { return $value['title']; } ,$values);
  }

  private function formProcessorFields($connection,$formprocessor){
    $call = $this->core->createCall($connection,'FormProcessor','getfields',['api_action'=>$formprocessor],['limit'=>0]);
    return $this->core->executeCall($call)['values'];
  }

  private function mapTitle($values){
    return array_map(function($value) { return $value['title']; } ,$values);
  }

  private function formProcessorDefaultsParams($connection, $formprocessor){
    $call = $this->core->createCall($connection,'FormProcessorDefaults','getfields',['api_action'=>$formprocessor],['limit'=>0]);
    $result = $this->core->executeCall($call);
    if($result['count']==0){
      return [];
    } else {
      return array_map(function($value) { return $value['name']; } ,$result['values']);
    }
  }

  private function formProcessorDefaultsDefault($params){
    $call = $this->core->createCall($this->configuration['connection'],'FormProcessorDefaults',$this->configuration['form_processor'],$params);
    $result = $this->core->executeCall($call);
    return $result;
  }
}
