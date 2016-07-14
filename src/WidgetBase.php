<?php

namespace Drupal\entity_browser;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\entity_browser\Events\EntitySelectionEvent;
use Drupal\entity_browser\Events\Events;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Base class for widget plugins.
 */
abstract class WidgetBase extends PluginBase implements WidgetInterface, ContainerFactoryPluginInterface {

  use PluginConfigurationFormTrait;

  /**
   * Plugin id.
   *
   * @var string
   */
  protected $id;

  /**
   * Plugin uuid.
   *
   * @var string
   */
  protected $uuid;
  /**
   * Plugin label.
   *
   * @var string
   */
  protected $label;

  /**
   * Plugin weight.
   *
   * @var int
   */
  protected $weight;

  /**
   * Event dispatcher service.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * Entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The Widget Validation Manager service.
   *
   * @var \Drupal\entity_browser\WidgetValidationManager
   */
  protected $validationManager;

  /**
   * WidgetBase constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   Event dispatcher service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\entity_browser\WidgetValidationManager $validation_manager
   *   The Widget Validation Manager service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EventDispatcherInterface $event_dispatcher, EntityTypeManagerInterface $entity_type_manager, WidgetValidationManager $validation_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->eventDispatcher = $event_dispatcher;
    $this->entityTypeManager = $entity_type_manager;
    $this->validationManager = $validation_manager;
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('event_dispatcher'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.entity_browser.widget_validation')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    return [
      'settings' => array_diff_key(
        $this->configuration,
        ['entity_browser_id' => 0]
      ),
      'uuid' => $this->uuid(),
      'weight' => $this->getWeight(),
      'label' => $this->label(),
      'id' => $this->id(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {
    $configuration += [
      'settings' => [],
      'uuid' => '',
      'weight' => '',
      'label' => '',
      'id' => '',
    ];

    $this->configuration = $configuration['settings'] + $this->defaultConfiguration();
    $this->label = $configuration['label'];
    $this->weight = $configuration['weight'];
    $this->uuid = $configuration['uuid'];
    $this->id = $configuration['id'];
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function id() {
    return $this->id;
  }

  /**
   * {@inheritdoc}
   */
  public function uuid() {
    return $this->uuid;
  }

  /**
   * {@inheritdoc}
   */
  public function label() {
    return $this->label;
  }

  /**
   * {@inheritdoc}
   */
  public function setLabel($label) {
    $this->label = $label;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight() {
    return $this->weight;
  }

  /**
   * {@inheritdoc}
   */
  public function setWeight($weight) {
    $this->weight = $weight;
    return $this;
  }

  /**
   * Prepares the entities without saving them.
   *
   * We need this method when we want to validate or perform other operations
   * before submit.
   *
   * @param array $form
   *   Complete form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Array of entities.
   */
  abstract protected function prepareEntities(array $form, FormStateInterface $form_state);

  /**
   * {@inheritdoc}
   */
  public function validate(array &$form, FormStateInterface $form_state) {
    $entities = $this->prepareEntities($form, $form_state);
    $validators = $form_state->get(['entity_browser', 'validators']);
    if ($validators) {
      $violations = $this->runWidgetValidators($entities, $validators);
      foreach ($violations as $violation) {
        $form_state->setError($form['widget'], $violation->getMessage());
      }
    }
  }

  /**
   * Run widget validators.
   *
   * @param array $entities
   *   Array of entity ids to validate.
   * @param array $validators
   *   Array of widget validator ids.
   *
   * @return \Symfony\Component\Validator\ConstraintViolationListInterface
   *   A list of constraint violations. If the list is empty, validation
   *   succeeded.
   */
  protected function runWidgetValidators(array $entities, $validators = []) {
    $violations = new ConstraintViolationList();
    foreach ($validators as $validator_id => $options) {
      /** @var \Drupal\entity_browser\WidgetValidationInterface $widget_validator */
      $widget_validator = $this->validationManager->createInstance($validator_id, []);
      if ($widget_validator) {
        $violations->addAll($widget_validator->validate($entities, $options));
      }
    }

    return $violations;
  }

  /**
   * {@inheritdoc}
   */
  public function submit(array &$element, array &$form, FormStateInterface $form_state) {}

  /**
   * Dispatches event that informs all subscribers about new selected entities.
   *
   * @param array $entities
   *   Array of entities.
   */
  protected function selectEntities(array $entities, FormStateInterface $form_state) {
    $selected_entities = &$form_state->get(['entity_browser', 'selected_entities']);
    $selected_entities = array_merge($selected_entities, $entities);

    $this->eventDispatcher->dispatch(
      Events::SELECTED,
      new EntitySelectionEvent(
        $this->configuration['entity_browser_id'],
        $form_state->get(['entity_browser', 'instance_uuid']),
        $entities
      ));
  }

}
