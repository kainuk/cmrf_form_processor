<?php


namespace Drupal\cmrf_form_processor\Plugin\WebformHandler;


use Drupal\Core\Serialization\Yaml;
use Drupal\webform\WebformInterface;

class FormProcessorWebformBuilder {

  var $webform;

  /**
   * FormProcessorWebformBuilder constructor.
   *
   * @param $webform
   */
  public function __construct(WebformInterface $webform) {
    $this->webform = $webform;
  }

  public function addFields($fields,$fpValues){
    $flattenedElements = $this->webform->getElementsDecodedAndFlattened();
    $elements = $this->webform->getElementsDecoded();
    foreach($fields as $key=>$value){
      if($value==1 && !key_exists($key,$flattenedElements)){
         if(empty($fpValues[$key]['options'])) {
           $elements[$key] = [
             '#type' => 'textfield',
             '#title' => $key
           ];
         } else {
           $elements[$key] = [
             '#type' => 'select',
             '#title' => $key,
             '#options' => $fpValues[$key]['options'],
           ];
         }
      }
    }
    $this->webform->set('elements',Yaml::encode($elements));
    $this->webform->save();
  }

  public function deleteFields($fields){
    $elements = $this->webform->getElementsDecodedAndFlattened();
    foreach($fields as $key=>$value){
      if($value==0 && key_exists($key,$elements)){
         $this->webform->deleteElement($key);
      }
    }
  }

  public function save(){

  }

}
