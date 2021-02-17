<?php

namespace Dashifen\ConscientiousContactForm;

use Dashifen\WPHandler\Handlers\HandlerException;
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
      $this->addAction('init', 'initializeAgents', 1);
      $this->addFilter('timber/locations', 'addTwigLocation');
    }
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
