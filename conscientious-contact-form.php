<?php
/**
 * Plugin Name: Conscientious Contact Form
 * Plugin URI: https://github.com/dashifen/conscientious-contact-form
 * Description: A WordPress plugin that produces a contact form that can conscientiously either email or simply store messages in the database (or both).
 * Author: David Dashifen Kees
 * Author URI: http://dashifen.com
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Version: 1.0.0
 *
 * @noinspection PhpIncludeInspection
 */

use Dashifen\Exception\Exception;
use Dashifen\ConscientiousContactForm\Agents\SettingsAgent;
use Dashifen\ConscientiousContactForm\ConscientiousContactForm;
use Dashifen\WPHandler\Agents\Collection\Factory\AgentCollectionFactory;

$autoloader = file_exists(dirname(ABSPATH) . '/deps/vendor/autoload.php')
  ? dirname(ABSPATH) . '/deps/vendor/autoload.php'    // production location
  : 'vendor/autoload.php';                            // development location

require_once($autoloader);

try {
  (function () {
    
    // our form object has some public methods related to accessing options
    // to which we don't want other areas of the site having access.  so, we
    // instantiate it here in this anonymous function so that it's not added
    // to the global PHP scope.
    
    $acf = new AgentCollectionFactory();
    $conscientiousContactForm = new ConscientiousContactForm();
    
    if (is_admin()) {
      $acf->registerAgent(SettingsAgent::class);
    }
    
    $conscientiousContactForm->setAgentCollection($acf);
    $conscientiousContactForm->initialize();
  })();
} catch (Exception $e) {
  ConscientiousContactForm::catcher($e);
}
