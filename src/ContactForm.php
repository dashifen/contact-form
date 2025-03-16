<?php

namespace Dashifen\WordPress\Plugins\ContactForm;

use Dashifen\WPHandler\Handlers\HandlerException;
use Dashifen\WordPress\Plugins\ContactForm\Agents\OptionsAgent;
use Dashifen\WPHandler\Handlers\Plugins\AbstractPluginHandler;

class ContactForm extends AbstractPluginHandler
{
  public const string SLUG = 'contact-form';
  
  /**
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
   * Returns a reference to our settings agent.
   *
   * @return OptionsAgent
   */
  public function getOptionsAgent(): OptionsAgent
  {
    return $this->agentCollection[OptionsAgent::class];
  }
  
  /**
   * Adds our /assets/twigs folder to the list of places where Timber will
   * look for template files.
   *
   * @param array $locations
   *
   * @return array
   */
  protected function addTwigLocation(array $locations): array
  {
    $locations[] = [$this->getPluginDir() . '/assets/twigs/'];
    return $locations;
  }
  
  /**
   * Returns the default value for one of this plugin's options.  Placed here
   * because both Agents need to know these values from time to time.
   *
   * @param string $option
   *
   * @return string
   */
  public function getDefaultValue(string $option): string
  {
    return match ($option) {
      'recipient'          => get_option('admin_email'),
      'subject'            => 'A message from ' . get_bloginfo('name') . ' website',
      'success', 'failure' => '',
    };
  }
}
