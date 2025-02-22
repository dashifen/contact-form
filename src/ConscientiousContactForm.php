<?php

namespace Dashifen\WordPress\Plugins\ConscientiousContactForm;

use Dashifen\WPHandler\Handlers\HandlerException;
use Dashifen\WordPress\Plugins\ConscientiousContactForm\Agents\SettingsAgent;
use Dashifen\WordPress\Plugins\ConscientiousContactForm\Agents\PostTypeAgent;
use Dashifen\WPHandler\Handlers\Plugins\AbstractPluginHandler;

class ConscientiousContactForm extends AbstractPluginHandler
{
  public const SLUG = 'conscientious-contact-form';
  
  /**
   * initialize
   *
   * Uses addAction and/or addFilter to attach protected methods of this object
   * to the WordPress ecosystem of action and filter hooks.
   *
   * @return void
   * @throws HandlerException
   */
  public function initialize(): void
  {
    if (!$this->isInitialized()) {
      $this->registerActivationHook('activation');
      $this->registerDeactivationHook('deactivation');
      $this->addAction('init', 'initializeAgents', 1);
      $this->addFilter('timber/locations', 'addTwigLocation');
    }
  }
  
  /**
   * activation
   *
   * When this plugin is activated, we add our ccf responder capability to
   * the administrators.  This capability can be added to additional roles
   * by administrators as desired.
   *
   * @return void
   */
  protected function activation(): void
  {
    get_role('administrator')->add_cap(PostTypeAgent::CAPABILITY);
  }
  
  /**
   * deactivation
   *
   * When this plugin is deactivated, we want to remove our ccf responder
   * capability from all roles; this is a little faster/easier than looking
   * for the roles to which it has been added.
   *
   * @return void
   */
  protected function deactivation(): void
  {
    foreach (array_keys(wp_roles()->get_names()) as $role) {
      get_role($role)->remove_cap(PostTypeAgent::CAPABILITY);
    }
  }
  
  /**
   * getSettingsAgent
   *
   * Returns a reference to our settings agent.
   *
   * @return SettingsAgent
   */
  public function getSettingsAgent(): SettingsAgent
  {
    return $this->agentCollection[SettingsAgent::class];
  }
  
  /**
   * getPostTypeAgent
   *
   * Returns a reference to our PostTypeAgent
   *
   * @return PostTypeAgent
   */
  public function getPostTypeAgent(): PostTypeAgent
  {
    return $this->agentCollection[PostTypeAgent::class];
  }
  
  /**
   * addTwigLocation
   *
   * Adds our /assets/twigs folder to the list of places where Timber will
   * look for template files.
   *
   * @param array $locations
   *
   * @return array
   */
  protected function addTwigLocation(array $locations): array
  {
    $locations[] = $this->getPluginDir() . '/assets/twigs/';
    return $locations;
  }
}
