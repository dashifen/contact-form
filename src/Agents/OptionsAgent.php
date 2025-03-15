<?php

namespace Dashifen\WordPress\Plugins\ContactForm\Agents;

use Timber\Timber;
use Dashifen\Validator\ValidatorException;
use Dashifen\Repository\RepositoryException;
use Dashifen\Transformer\TransformerException;
use Dashifen\WPHandler\Traits\CaseChangingTrait;
use Dashifen\WPHandler\Handlers\HandlerException;
use Dashifen\WPHandler\Repositories\PostValidity;
use Dashifen\WPHandler\Agents\AbstractPluginAgent;
use Dashifen\WPHandler\Traits\ActionAndNonceTrait;
use Dashifen\WPHandler\Traits\OptionsManagementTrait;
use Dashifen\WordPress\Plugins\ContactForm\ContactForm;
use Dashifen\WPHandler\Repositories\MenuItems\SubmenuItem;
use Dashifen\WPHandler\Repositories\MenuItems\MenuItemException;
use Dashifen\WordPress\Plugins\ContactForm\Services\OptionsValidator;

/**
 * Class OptionsAgent
 *
 * @property ContactForm $handler
 *
 * @package Dashfen\ContactForm\Agents
 */
class OptionsAgent extends AbstractPluginAgent
{
  use CaseChangingTrait;
  use OptionsManagementTrait;
  use ActionAndNonceTrait;
  
  private array $defaultOptionValues = [];
  
  /**
   * Returns the array of default values this plugin's options.  Public
   * because this method is used by the FormAgent as well.
   *
   * @return array
   */
  public function getDefaultValues(): array
  {
    // TODO: Move this to the handler.
    if (sizeof($this->defaultOptionValues) === 0) {
      foreach ($this->getOptionNames() as $option) {
        $this->defaultOptionValues[$option] = match ($option) {
          'recipient'          => get_option('admin_email'),
          'subject'            => 'A message from ' . get_bloginfo('name') . ' website',
          'success', 'failure' => '',
        };
      }
    }
    
    return $this->defaultOptionValues;
  }
  
  /**
   * Returns the default value for a specific option or null if that option
   * doesn't exist.
   *
   * @param string $option
   *
   * @return mixed|null
   */
  public function getDefaultValue(string $option): mixed
  {
    // TODO: Move this to the handler.
    return $this->getDefaultValues()[$option] ?? null;
  }
  
  /**
   * Uses addAction and/or addFilter to hook protected methods of this object
   * into the WordPress ecosystem of action and filter hooks.
   *
   * @throws HandlerException
   */
  public
  function initialize(): void
  {
    if (!$this->isInitialized()) {
      $this->addAction('admin_menu', 'addFormOptionsMenuItem');
      $this->addAction('admin_post_' . $this->getAction(), 'saveFormOptions');
    }
  }
  
  /**
   * Adds a submenu item to the Dashboard's Options menu which allows us to
   * control the display of our form and what it does with submissions.
   *
   * @return void
   * @throws HandlerException
   * @throws MenuItemException
   * @throws RepositoryException
   */
  protected function addFormOptionsMenuItem(): void
  {
    $hook = $this->addOptionsPage(new SubmenuItem($this,  [
      'menuTitle'  => 'Contact Form',
      'pageTitle'  => 'Contact Form',
      'capability' => $this->getCapabilityForAction('access'),
      'method'     => 'showFormOptionsPage',
    ]));
    
    $this->addAction('load-' . $hook, 'loadFormOptionsPage');
  }
  
  /**
   * Fires when the form options page is loaded but before content is shown
   * so we can prepare for its display.
   *
   * @return void
   * @throws HandlerException
   */
  protected function loadFormOptionsPage(): void
  {
    $this->addAction('admin_enqueue_scripts', function () {
      $this->enqueue('assets/styles/admin-options.css');
    });
    
    // the other thing we need to do here is set up an admin notices action if
    // we have a record of a prior post.  for that, we'll check the transient
    // that's set at the end of the save method below.
    
    $transient = $this->getTransient();
    $priorPostValidity = get_transient($transient);
    if ($priorPostValidity instanceof PostValidity) {
      
      // now that we know that we have information about a prior post, we'll
      // want to use it to share a success or failure message with the visitor.
      // then we remove the transient so we only do so once per post action.
      // even without the transient, the validity information will be available
      // during the admin_notices action due to its use via closure in the
      // following anonymous function.
      
      $notifier = function () use ($priorPostValidity): void {
        $twig = $priorPostValidity->valid
          ? 'options/success.twig'
          : 'options/failure.twig';
        
        Timber::render($twig, ['problems' => $priorPostValidity->problems]);
      };
      
      $this->addAction('admin_notices', $notifier);
      delete_transient($transient);
    }
  }
  
  /**
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
   * Displays the form options page.
   *
   * @return void
   * @throws HandlerException
   * @throws TransformerException
   */
  protected function showFormOptionsPage(): void
  {
    $context = [
      'action'    => $this->getAction(),
      'nonceName' => $this->getNonceName(),
      'pages'     => $this->getPages(),
    ];
    
    foreach ($this->getOptionNames() as $option) {
      $context[$option] = $this->getOption($option, $this->getDefaultValue($option));
    }
    
    Timber::render('options/options.twig', $context);
  }
  
  /**
   * Returns a map of page IDs to their titles.
   *
   * @return array
   */
  private function getPages(): array
  {
    foreach (get_posts([
      'post_type'   => 'page',
      'post_status' => 'publish',
      'numberposts' => -1,
    ]) as $page) {
      $pages[$page->ID] = $page->post_title;
    }
    
    return $pages ?? [];
  }
  
  /**
   * Validates and saves the options for our plugin.
   *
   * @throws HandlerException
   * @throws RepositoryException
   * @throws TransformerException
   * @throws ValidatorException
   */
  protected function saveFormOptions(): void
  {
    if ($this->isValidActionAndNonce()) {
      
      // if our action and nonce are valid, then we want to validate the
      // posted data.  however, more information is posted here than we care
      // to validate.  we'll use array_filter to reduce $_POST down to only
      // those values we care about, i.e. our options.  then, if our data is
      // valid, we save it in the database.
      
      $filter = fn($key) => in_array($key, $this->getOptionNames());
      $postedData = array_filter($_POST, $filter, ARRAY_FILTER_USE_KEY);
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
  private
  function validatePostedData(array $postedData): PostValidity
  {
    $validator = new OptionsValidator();
    $validator->setRequirements($this->getOptionNames());
    foreach ($postedData as $field => $value) {
      
      // the first use of $field here determines the validation method that
      // we use to confirm that $value is good to go.  then, we send $field in
      // again so it can be passed to the validation methods that need to know
      // what they're working with not just the value their working on.
      
      if (!$validator->isValid($field, $value, $field)) {
        $problems[] = $validator->getValidationMessage($field);
      }
    }
    
    // there's one problem that the validator isn't prepared to test:  if a
    // person chooses the same page for both the success and failure messages.
    // we'll test for that one now as long as we don't currently have a problem
    // with the current selections.
    
    if (
      !isset($problems['success']) &&
      !isset($problems['failure']) &&
      $postedData['success'] === $postedData['failure']
    ) {
      $problems['failure'] = "Don't select the same page twice.";
    }
    
    return new PostValidity($problems ?? []);
  }
  
  /**
   * Inherited from the OptionManagementTrait, this method returns the unique
   * prefix for the options managed by this Agent.  This prefix is added to
   * option names in the trait's methods automatically so that we don't have
   * to type and re-type it herein.
   *
   * @return string
   */
  protected function getOptionNamePrefix(): string
  {
    return ContactForm::SLUG . '-';
  }
  
  /**
   * Inherited from the OptionsManagementTrait, this method returns a list of
   * the names of all options that this plugin manages, and using that list,
   * prevents it from messing with any other options.
   *
   * @return array
   */
  protected function getOptionNames(): array
  {
    return ['recipient', 'subject', 'success', 'failure'];
  }
}
