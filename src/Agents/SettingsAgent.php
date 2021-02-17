<?php

namespace Dashifen\ConscientiousContactForm\Agents;

use Timber\Timber;
use Dashifen\Validator\ValidatorInterface;
use Dashifen\Validator\ValidatorException;
use Dashifen\Repository\RepositoryException;
use Dashifen\Transformer\TransformerException;
use Dashifen\WPHandler\Traits\CaseChangingTrait;
use Dashifen\WPHandler\Handlers\HandlerException;
use Dashifen\WPHandler\Repositories\PostValidity;
use Dashifen\WPHandler\Agents\AbstractPluginAgent;
use Dashifen\WPHandler\Traits\ActionAndNonceTrait;
use Dashifen\WPHandler\Traits\OptionsManagementTrait;
use Dashifen\WPHandler\Repositories\MenuItems\SubmenuItem;
use Dashifen\ConscientiousContactForm\ConscientiousContactForm;
use Dashifen\WPHandler\Handlers\Plugins\PluginHandlerInterface;
use Dashifen\WPHandler\Repositories\MenuItems\MenuItemException;
use Dashifen\ConscientiousContactForm\Services\SettingsValidator;

/**
 * Class SettingsAgent
 *
 * @property ConscientiousContactForm $handler
 *
 * @package Dashfen\ConscientiousContactForm\Agents
 */
class SettingsAgent extends AbstractPluginAgent
{
  use CaseChangingTrait;
  use OptionsManagementTrait;
  use ActionAndNonceTrait {
    
    // we want to call the trait's getAction method from within the overridden
    // version we use herein.  to do so, we need to give the trait's method an
    // alias as follows.  then, we can refer to that alias in our override.
    
    getAction as getTraitAction;
  }
  
  private ValidatorInterface $validator;
  
  /**
   * SettingsAgent constructor.
   *
   * @param PluginHandlerInterface  $handler
   * @param ValidatorInterface|null $validator
   *
   * @throws HandlerException
   */
  public function __construct(
    PluginHandlerInterface $handler,
    ?ValidatorInterface $validator = null
  ) {
    parent::__construct($handler);
    $this->validator = $validator ?? new SettingsValidator();
  }
  
  /**
   * getOptionsNames
   *
   * Inherited from the OptionsManagementTrait, this method returns a list of
   * the names of all options that this plugin manages, and using that list,
   * prevents it from messing with any other options.
   *
   * @return array
   */
  protected function getOptionNames(): array
  {
    return ['optional-fields', 'submission-handler', 'recipient'];
  }
  
  /**
   * initialize
   *
   * Uses addAction and/or addFilter to hook protected methods of this object
   * into the WordPress ecosystem of action and filter hooks.
   *
   * @throws HandlerException
   */
  public function initialize(): void
  {
    if (!$this->isInitialized()) {
      $this->addAction('admin_menu', 'addFormSettings');
      $this->addAction('admin_post_' . $this->getAction(), 'saveFormSettings');
    }
  }
  
  /**
   * getAction
   *
   * Returns a string naming the action an on screen form is used to perform.
   * Typically, this is then used to link a form's submission to a method of
   * the object using this trait to process a visitor's work.
   *
   * @param string|null $action
   *
   * @return string
   */
  protected function getAction(?string $action = null): string
  {
    // by default, our trait's method would return 'settings-agent-$action'
    // as our string.  but, this override adds our option prefix to that to be
    // sure that settings agent actions in other plugins don't interfere.
    
    return $this->getOptionNamePrefix() . $this->getTraitAction($action);
  }
  
  /**
   * addFormSettings
   *
   * Adds a submenu item to the Dashboard's Settings menu which allows us to
   * control the display of our form and what it does with submissions.
   *
   * @return void
   * @throws HandlerException
   * @throws MenuItemException
   * @throws RepositoryException
   */
  protected function addFormSettings(): void
  {
    $settings = [
      'menuTitle'  => 'Contact Form',
      'pageTitle'  => 'Conscientious Contact Form',
      'capability' => $this->getCapabilityForAction('access'),
      'method'     => 'showFormSettings',
    ];
    
    $hook = $this->addSettingsPage(new SubmenuItem($this, $settings));
    $this->addAction('load-' . $hook, 'loadFormSettings');
  }
  
  /**
   * loadFormSettings
   *
   * Fires when the form settings page is loaded but before content is shown
   * so we can prepare for its display.
   *
   * @return void
   * @throws HandlerException
   */
  protected function loadFormSettings(): void
  {
    $this->addAction('admin_enqueue_scripts', 'addAssets');
    
    // the other thing we need to do here is setup an admin notices action if
    // we have a record of a prior post.  for that, we'll check the transient
    // that's set at the end of the save method below.
    
    $transient = $this->getTransient();
    $priorPostValidity = get_transient($transient);
    if ($priorPostValidity instanceof PostValidity) {
      
      // now that we know that we have information about a prior post, we'll
      // want to use it to share a success or failure message with the visitor.
      // then we remove the transient so we only do so once per post action.
      // even without the transient, the validity information will be available
      // during the admin_notices action due to it's use via closure in the
      // following anonymous function.
      
      $notifier = function () use ($priorPostValidity): void {
        $twig = $priorPostValidity->valid
          ? 'settings/success.twig'
          : 'settings/failure.twig';
        
        Timber::render($twig, ['problems' => $priorPostValidity->problems]);
      };
      
      $this->addAction('admin_notices', $notifier);
      delete_transient($transient);
    }
  }
  
  /**
   * addAssets
   *
   * Adds admin assets for this plugin.
   *
   * @return void
   */
  protected function addAssets(): void
  {
    $this->enqueue('assets/admin.css');
  }
  
  /**
   * getTransient
   *
   * Returns the name of the transient we use herein so avoid misspellings
   * when we use it multiple times.
   *
   * @return string
   */
  private function getTransient(): string
  {
    return $this->getOptionNamePrefix() . 'post-validity-transient';
  }
  
  /**
   * getOptionNamePrefix
   *
   * Inherited from the OptionManagementTrait, this method returns the unique
   * prefix for the options managed by this plugin.  This prefix is added
   * automatically to option names in the trait's methods so that we don't have
   * to type and re-type it in our code.
   *
   * @return string
   */
  public function getOptionNamePrefix(): string
  {
    return ConscientiousContactForm::SLUG . '-';
  }
  
  /**
   * showFormSettings
   *
   * Displays the form settings page.
   *
   * @return void
   * @throws HandlerException
   * @throws TransformerException
   */
  protected function showFormSettings(): void
  {
    $context = [
      'nonce'     => $this->getNonce(),
      'action'    => $this->getAction(),
      'fields'    => $this->getOptionalFields(),
      'handlers'  => $this->getSubmissionHandlers(),
      'recipient' => $this->getOption('recipient', get_option('admin_email')),
    ];
    
    Timber::render('settings/form.twig', $context);
  }
  
  /**
   * getOptionalFields
   *
   * Gets the information about the optional fields settings which is added
   * to our twig's context prior to rendering.
   *
   * @return array
   * @throws HandlerException
   * @throws TransformerException
   */
  private function getOptionalFields(): array
  {
    $defaults = SettingsValidator::OPTIONAL_FIELDS;
    $chosenFields = $this->getOption('optional-fields', $defaults);
    foreach ($defaults as $field) {
      
      // for each of the optional fields listed here, if it's found in the
      // $chosenFields option, then we'll store a 1.  otherwise, a zero.  this
      // lets the form know which checkboxes need a mark and which don't.
      
      $fields[$field] = (int) in_array($field, $chosenFields);
    }
    
    return $fields ?? [];
  }
  
  /**
   * getSubmissionHandlers
   *
   * Like the prior method, returns information about the options for
   * form submission handlers as well as the chosen one.
   *
   * @return array
   * @throws HandlerException
   * @throws TransformerException
   */
  protected function getSubmissionHandlers(): array
  {
    $chosenHandler = $this->getOption('submission-handler', 'email');
    foreach (SettingsValidator::SUBMISSION_HANDLERS as $handler) {
      $handlers[$handler] = $handler === $chosenHandler ? 1 : 0;
    }
    
    return $handlers ?? [];
  }
  
  /**
   * saveFormSettings
   *
   * Validates and saves the settings for our plugin.
   *
   * @throws HandlerException
   * @throws RepositoryException
   * @throws TransformerException
   * @throws ValidatorException
   */
  protected function saveFormSettings(): void
  {
    $postedData = $_POST;
    if ($this->isValidActionAndNonce()) {
      $postValidity = $this->validatePostedData($postedData);
      if ($postValidity->valid) {
        foreach ($this->getOptionNames() as $option) {
          $this->updateOption($option, $postedData[$option]);
        }
      }
      
      // before we're completely done, we want to save this record of the
      // post's validity in the database as a transient.  we give ourselves
      // more time for bug hunting when we're in a debugging environment;
      // otherwise, in production, this transient lasts five minutes.  if we
      // can't redirect and refresh the page in less than that amount of time,
      // there's something else going on.
      
      $timeLimit = self::isDebug() ? 3600 : 300;
      set_transient($this->getTransient(), $postValidity, $timeLimit);
      wp_safe_redirect($_POST['_wp_http_referer']);
    }
  }
  
  /**
   * validatePostedData
   *
   * Analyzes the posted data to determine if it is valid or not and returns
   * a PostValidity object that encapsulates that determination and any the
   * problems we encountered, if any.
   *
   * @param array $postedData
   *
   * @return PostValidity
   * @throws RepositoryException
   * @throws ValidatorException
   */
  private function validatePostedData(array &$postedData): PostValidity
  {
    $problems = [];
    
    // before we use our validator, we need to set our required options.  the
    // recipient option is necessary based on the chosen submission handler.
    // if that handler isn't the database, then we need a recipient.
    // otherwise, all we need is the handler.  note:  we allow for fully
    // anonymous forms without any identifying information, so the optional
    // fields are never required.
    
    if (($postedData['submission-handler'] ?? '') !== 'database') {
      $this->validator->setRequirements(['submission-handler', 'recipient']);
    } else {
      
      // in here, we don't need our recipient.  so, we actually empty it so
      // that we don't store data in the database that we don't need.  because
      // the $postedData array is sent here as a reference, we know tha this
      // change will "stick" and be present in the calling scope, too.
      
      $this->validator->setRequirements(['submission-handler']);
      $postedData['recipient'] = '';
    }
    
    // now, we'll loop over our posted data and validate each field/value
    // pair as we encounter them.  the object handles all the logic needed to
    // determine validity and designs our error messages for the screen if we
    // need them.  we collect error messages and then return them to the
    // calling scope as a part of a PostValidity object.
    
    foreach ($postedData as $field => $value) {
      if (
        $this->validator->canValidate($field)
        && !$this->validator->isValid($field, $value)
      ) {
        $problems[] = $this->validator->getValidationMessage($field);
      }
    }
    
    return new PostValidity($problems);
  }
}
