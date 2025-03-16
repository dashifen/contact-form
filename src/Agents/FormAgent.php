<?php

namespace Dashifen\WordPress\Plugins\ContactForm\Agents;

use WP_Post;
use Timber\Timber;
use Dashifen\Transformer\TransformerException;
use Dashifen\WPHandler\Traits\CaseChangingTrait;
use Dashifen\WPHandler\Handlers\HandlerException;
use Dashifen\WPHandler\Agents\AbstractPluginAgent;
use Dashifen\WPHandler\Traits\ActionAndNonceTrait;
use Dashifen\WordPress\Plugins\ContactForm\Message;
use Dashifen\WordPress\Plugins\ContactForm\ContactForm;

/**
 * Class ContactForm
 *
 * @property ContactForm $handler
 *
 * @package Dashifen\WordPress\Plugins\ContactForm
 */
class FormAgent extends AbstractPluginAgent
{
  use CaseChangingTrait;
  use ActionAndNonceTrait;
  
  private const string TEMPLATE_NAME = 'Contact Form Template';
  private const string TEMPLATE_FILE = 'contact-form.php';
  
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
      $this->addFilter('theme_page_templates', 'addFormTemplate');
      $this->addFilter('template_include', 'maybeIncludeFormTemplate');
      
      // these attachments are for our form processing.  notice that we use
      // both admin post hooks here:  the one for anonymous visitors and the
      // one for logged-in users.  that's intentional; an authentic user using
      // the form might be odd, but it shouldn't be prevented.
      
      $action = $this->getAction('submit');
      $this->addAction('admin_post_nopriv_' . $action, 'processForm');
      $this->addAction('admin_post_' . $action, 'processForm');
      
      // this attachment actually prints our form.  since it's automatically
      // attached here when the plugin is initialized, if a theme or other
      // plugin wants to attach a different action to it, then it'll have to
      // use the remove_all_actions function to clear the hook of any attached
      // behaviors, and then, it can add its own.
      
      $this->addAction('display-contact-form', 'displayForm');
    }
  }
  
  /**
   * Adds our template to the list of page templates for this site.
   *
   * @param array $templates
   *
   * @return array
   */
  protected function addFormTemplate(array $templates): array
  {
    $templates[self::TEMPLATE_FILE] = self::TEMPLATE_NAME;
    return $templates;
  }
  
  /**
   * Ensures that we use the form template in our plugin's filesystem if it's
   * not been overridden by a file in the theme already.
   *
   * @param string $template
   *
   * @return string
   * @throws HandlerException
   */
  protected function maybeIncludeFormTemplate(string $template): string
  {
    $isFormTemplate = $this->getCurrentPostTemplate() === self::TEMPLATE_FILE;
    
    if ($isFormTemplate) {
      $template = $this->getTemplateFile();
      if ($this->isPluginFormTemplate($template)) {
        
        // if this page is using our form template, and we're relying on this
        // plugin's version of the template, then we include some CSS to make
        // it look gorgeous.
        
        $this->addAction('wp_enqueue_scripts', 'addFormAssets');
      }
    }
    
    return $template;
  }
  
  /**
   * Returns the current post's template file or null.
   *
   * @return string|null
   */
  private function getCurrentPostTemplate(): ?string
  {
    return ($post = get_post()) instanceof WP_Post
      ? get_post_meta($post->ID, '_wp_page_template', true)
      : null;
  }
  
  /**
   * Returns the template file name, either from this plugin or the current
   * theme, if it overrides the plugin's file.
   *
   * @return string
   */
  private function getTemplateFile(): string
  {
    // the locate_template function looks in the current theme's folder for
    // the specified filename.  if it can't find it, it returns the empty
    // string; otherwise, it returns the path to that file.  so, this ternary
    // statement checks the theme folder for our file, and if it doesn't find
    // it, returns the default file.  but if it does find it, returns the path
    // to the theme's file.
    
    return ($template = locate_template(self::TEMPLATE_FILE)) === ''
      ? $this->getPluginFormTemplate()
      : $template;
  }
  
  /**
   * Returns the path to the page template this plugin maintains.
   *
   * @return string
   */
  private function getPluginFormTemplate(): string
  {
    return $this->getPluginDir() . '/assets/' . self::TEMPLATE_FILE;
  }
  
  /**
   * Returns true if the parameter is our plugin's form template (as opposed to
   * a theme's override).
   *
   * @param string $template
   *
   * @return bool
   */
  private function isPluginFormTemplate(string $template): bool
  {
    return $template === $this->getPluginFormTemplate();
  }
  
  /**
   * This method is called only when using our plugin's template to add some
   * bare-minimum styles to the default display of its form.
   *
   * @return void
   */
  protected function addFormAssets(): void
  {
    $this->enqueue('assets/styles/form.css');
  }
  
  /**
   * Gathers the context necessary to utilize our form's twig file to render
   * the contact form for this plugin.
   *
   * @return void
   * @throws HandlerException
   * @throws TransformerException
   */
  protected function displayForm(): void
  {
    $context['action'] = $this->getAction('submit');
    $context['nonceName'] = $this->getNonce('submit');
    
    // this object doesn't "know" anything about our form options, but the
    // OptionsAgent does and our twig template will need to.  luckily, our
    // handler can deliver to us a reference to that agent, and then we can use
    // its public methods to extract the information we need here to build our
    // form as follows.
    
    $optionsAgent = $this->handler->getOptionsAgent();
    foreach ($optionsAgent->getDefaultValues() as $option => $value) {
      $context[$option] = $optionsAgent->getOption($option, $value);
    }
    
    // we add these filters here in case additional classes or attributes need
    // to be added to the submit button, e.g. reCAPTCHA data.
    
    $context['submit_attrs'] = apply_filters('ccf-submit-attributes', '');
    $context['submit_classes'] = apply_filters('ccf-submit-classes', 'ccf-form-submit');
    Timber::render('contact-form.twig', $context);
  }
  
  /**
   * Receives the posted data from our visitor and processes it.
   *
   * @return void
   * @throws HandlerException
   * @throws TransformerException
   */
  protected function processForm(): void
  {
    $optionsAgent = $this->handler->getOptionsAgent();
    
    // our Message DTO will take the posted data and set its properties with
    // the relevant values within it.  as long as our honeypot "organization"
    // field is empty, we pass that object over to the sendEmail method below
    // which returns a Boolean.  that value determines whether we redirect to
    // the success or the failure page.
    
    $message = new Message($_POST);
    $redirectTo = empty($message->organization) && $this->sendEmail($message)
      ? $optionsAgent->getOption('success', $this->handler->getDefaultValue('success'))
      : $optionsAgent->getOption('failure', $this->handler->getDefaultValue('failure'));
    
    if (empty(($permalink = get_permalink($redirectTo)))) {
      
      // if the permalink doesn't exist, perhaps because someone deleted one
      // of the redirect-to pages by mistake, then we'll simply go back to the
      // page with the form.  it's not ideal, but it may be better than a 404
      // error.
      
      $permalink = $_POST['_wp_http_referer'];
    }
    
    wp_safe_redirect($permalink);
  }
  
  /**
   * When our form submission handle specifies that an email should be sent
   * including the message, this is the method that does so.
   *
   * @param Message $message
   *
   * @return bool
   * @throws HandlerException
   * @throws TransformerException
   */
  private function sendEmail(Message $message): bool
  {
    $optionsAgent = $this->handler->getOptionsAgent();
    $subject = $optionsAgent->getOption('subject', $this->handler->getDefaultValue('subject'));
    $recipient = $optionsAgent->getOption('recipient', $this->handler->getDefaultValue('recipient'));
    
    // if we have an email, and it's valid, we'll want to set the From: header
    // so that a reply can be easily sent.  if we also have a person's name, we
    // can set the From: header to include that.
    
    if ($this->isEmail($message->email)) {
      $headers[] = 'From: ' . !empty($message->name)
        ? sprintf('%s <%s>', $message->name, $message->email)
        : $message->email;
    }
    
    // this X-header may help people do some filtering in their inboxes.
    // likely, the easier way to filter will be the subject, but this may be a
    // tool for some.  we add the site's name to the header in case anyone
    // receives multiple form messages.  so, if the site's name is "the Best
    // Ever Site" this produces X-The-Best-Ever-Site-Contact-Form: true as a
    // header.
    
    $siteName = ucwords(get_bloginfo('name'));
    $siteName = preg_replace('~\s+~', '-', $siteName);
    $headers[] = "X-$siteName-Contact-Form: true";
    return wp_mail($recipient, $subject, $message->message, $headers);
  }
  
  /**
   * Returns true if the parameter appears to be an email address.
   *
   * @param string $email
   *
   * @return bool
   */
  private function isEmail(string $email): bool
  {
    return !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL);
  }
}
