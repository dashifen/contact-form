<?php
/**
 * Plugin Name: Contact Form
 * Plugin URI: https://github.com/dashifen/contact-form
 * Description: A WordPress plugin that adds a contact form via a page template..
 * Author: David Dashifen Kees
 * Author URI: http://dashifen.com
 * Version: 2.3.0
 */

namespace Dashifen\WordPress\Plugins;

use Dashifen\Exception\Exception;
use Dashifen\WordPress\Plugins\ContactForm\Agents\FormAgent;
use Dashifen\WordPress\Plugins\ContactForm\Agents\SettingsAgent;
use Dashifen\WordPress\Plugins\ContactForm\Agents\PostTypeAgent;
use Dashifen\WordPress\Plugins\ContactForm\ContactForm;
use Dashifen\WPHandler\Agents\Collection\Factory\AgentCollectionFactory;

if (!class_exists(ContactForm::class)) {
  require_once 'vendor/autoload.php';
}

try {
  (function () {
    
    // our form object has some public methods related to accessing options
    // to which we don't want other areas of the site having access.  so, we
    // instantiate it here in this anonymous function so that it's not added
    // to the global PHP scope.
    
    $acf = new AgentCollectionFactory();
    $acf->registerAgent(FormAgent::class);
    $acf->registerAgent(SettingsAgent::class);
    $acf->registerAgent(PostTypeAgent::class);
    $ContactForm = new ContactForm();
    $ContactForm->setAgentCollection($acf);
    $ContactForm->initialize();
  })();
} catch (Exception $e) {
  ContactForm::catcher($e);
}
