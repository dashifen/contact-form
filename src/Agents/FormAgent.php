<?php

namespace Dashifen\ConscientiousContactForm\Agents;

use WP_Post;
use Timber\Timber;
use Dashifen\Repository\RepositoryException;
use Dashifen\Transformer\TransformerException;
use Dashifen\WPHandler\Traits\CaseChangingTrait;
use Dashifen\WPHandler\Handlers\HandlerException;
use Dashifen\WPHandler\Agents\AbstractPluginAgent;
use Dashifen\WPHandler\Traits\ActionAndNonceTrait;
use Dashifen\ConscientiousContactForm\Repositories\Message;
use Dashifen\ConscientiousContactForm\ConscientiousContactForm;
use Dashifen\ConscientiousContactForm\Traits\GetPageBySlugTrait;

/**
 * Class ConscientiousContactForm
 *
 * @property ConscientiousContactForm $handler
 *
 * @package Dashifen\ConscientiousContactForm
 */
class FormAgent extends AbstractPluginAgent
{
  use CaseChangingTrait;
  use GetPageBySlugTrait;
  use ActionAndNonceTrait;
  
  public const TEMPLATE_NAME = 'Contact Form Template';
  public const TEMPLATE_FILE = 'conscientious-contact-form.php';
  
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
      $this->addFilter('theme_page_templates', 'addFormTemplate');
      $this->addFilter('wp_insert_post_data', 'registerFormTemplate');
      $this->addFilter('template_include', 'useFormTemplate');
      
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
      // behaviors and then it can add its own.
      
      $this->addAction('display-conscientious-contact-form', 'displayForm');
    }
  }
  
  /**
   * addFormTemplate
   *
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
   * registerFormTemplate
   *
   * Tricks WP into thinking that the page template this plugin maintains is
   * located in the theme folder by altering the cache of page templates in the
   * database.
   *
   * @param array $attributes
   *
   * @return array
   * @link https://www.wpexplorer.com/wordpress-page-templates-plugin/
   */
  protected function registerFormTemplate(array $attributes): array
  {
    // first, we get our templates and then add ours to it.  if it was already
    // listed, then our addition simply re-adds the same value at the same
    // index of the array, so whether it's the first time or the n-th time,
    // this works perfectly.
    
    $templates = $this->getTemplates();
    $templates[self::TEMPLATE_FILE] = self::TEMPLATE_NAME;
    
    // next: construct the WP cache key for page templates so we can remove the
    // old one and add the new one.  this block of code was found at the site
    // linked in the phpDocBlock and altered to fit the style of this plugin.
    
    $cacheKey = $this->getCacheKey();
    wp_cache_delete($cacheKey, 'themes');
    wp_cache_add($cacheKey, $templates, 'themes', 1800);
    return $attributes;
  }
  
  /**
   * getTemplates
   *
   * Gets the list of page templates for the current theme and returns it.
   * If there are no templates, we return an empty array.
   *
   * @return array
   */
  private function getTemplates(): array
  {
    $templates = wp_get_theme()->get_page_templates();
    return !empty($templates) ? $templates : [];
  }
  
  /**
   * getCacheKey
   *
   * Returns the cache key for page-templates constructed just like WP core
   * does it.
   *
   * @return string
   */
  private function getCacheKey(): string
  {
    return 'page-templates-' . md5(get_theme_root() . '/' . get_stylesheet());
  }
  
  /**
   * useFormTemplate
   *
   * Ensures that we use the form template in our plugin's filesystem if it's
   * not been overridden by a file in the theme already.
   *
   * @param string $template
   *
   * @return string
   * @throws HandlerException
   */
  protected function useFormTemplate(string $template): string
  {
    // we get the current post, and if it's an instance of WP_POST, then we
    // also get the page template it's using.  if that page template matches
    // this plugin's form template, we'll return the path to the file that
    // defines its output.  otherwise, if either $post is not a WP_POST or if
    // it is but it's not using our page template, we just return the template
    // that WP core identified.
    
    $post = get_post();
    return $post instanceof WP_Post && get_post_meta($post->ID, '_wp_page_template', true) === self::TEMPLATE_FILE
      ? $this->getTemplateFile()
      : $template;
  }
  
  /**
   * getTemplateFile
   *
   * When it's determined that we need to load our plugin's page template,
   * this method is called to find the right PHP file to use for it.  We also
   * take the opportunity to enqueue some CSS so that it's only added to this
   * page and doesn't slow down any other requests.
   *
   * @return string
   * @throws HandlerException
   */
  private function getTemplateFile(): string
  {
    // now that we know we're going to be displaying our form, we'll load the
    // bare minimum assets that control the display of our default form.  these
    // are made with as little specificity as possible to make it easier for
    // themes to override them if they need to.
    
    $this->addAction('wp_enqueue_scripts', 'addFormAssets');
    
    // the locate_template function looks in the current theme's folder for
    // the specified filename.  if it can't find it, it returns the empty
    // string; otherwise, it returns the path to that file.  so, this ternary
    // statement checks the theme folder for our file, and if it doesn't find
    // it, returns the default file.  but if it does find it, returns the path
    // to the theme's file.
    
    return ($template = locate_template(self::TEMPLATE_FILE)) === ''
      ? $this->getPluginDir() . '/assets/' . self::TEMPLATE_FILE
      : $template;
  }
  
  /**
   * addFormAssets
   *
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
   * displayForm
   *
   * Gathers the context necessary to utilize our form's twig file to render
   * the contact form for this plugin.
   *
   * @return void
   * @throws HandlerException
   * @throws TransformerException
   */
  protected function displayForm(): void
  {
    // this object doesn't "know" anything about our form settings, but the
    // SettingsAgent does.  luckily, our handler can deliver to us a reference
    // to that agent, and then we can use it's public methods to extract the
    // information we need here to build our form as follows.
    
    $settingsAgent = $this->handler->getSettingsAgent();
    foreach ($settingsAgent->getDefaultValues() as $settings => $value) {
      
      // because they're also HTML attribute values, our setting names are in
      // kebab-case.  but, that won't work for Twig variable names.  so, we use
      // the CaseChangingTrait to switch them from kebab-case to camelCase and
      // use those instead.
      
      $camelField = $this->kebabToCamelCase($settings);
      $context[$camelField] = $settingsAgent->getOption($settings, $value);
    }
    
    $context['action'] = $this->getAction('submit');
    $context['nonceName'] = $this->getNonce('submit');
    
    // we add this filter here in case additional classes need to be added to
    // the submit button, e.g. the reCAPTCHA v3 classes.
    
    $context['submit_classes'] = apply_filters('ccf-submit-classes', 'ccf-form-submit');
    Timber::render('conscientious-contact-form.twig', $context ?? []);
  }
  
  /**
   * processForm
   *
   * Receives the posted data from our visitor and processes it.
   *
   * @return void
   * @throws HandlerException
   * @throws TransformerException
   * @throws RepositoryException
   */
  protected function processForm(): void
  {
    $message = new Message($_POST);
    
    // for simplicity's sake, we're relying on the browser-based form
    // validation process to make sure that the visitor enters an email address
    // and a message.  the email is even optional unless it's the only means
    // of handling our submission.  as such, all we do here handle the
    // visitor's submission and redirect to the thank you page.
    
    $settingsAgent = $this->handler->getSettingsAgent();
    $defaultHandler = $settingsAgent->getDefaultValue('submission-handler');
    $submissionHandler = $settingsAgent->getOption('submission-handler', $defaultHandler);
    
    if ($submissionHandler === 'email' || $submissionHandler === 'both') {
      $this->sendEmail($message);
    }
    
    if ($submissionHandler === 'database' || $submissionHandler === 'both') {
      
      // this object sends our email, but because the PostTypeAgent handles
      // the rest of the activity related to our form responses, we'll let it
      // handle their creation, too.
      
      $postTypeAgent = $this->handler->getPostTypeAgent();
      $postTypeAgent->savePost($message);
    }
    
    // last thing to do before we're done here is to redirect to the thank-you
    // page.  we get the slug of that page out of our settings, and then we can
    // use the method we inherit from the GetPageBySlugTrait to, well, get the
    // page with its slug.  if we can't find that page, we'll default to
    // going back where we came from (which will probably be confusing, but
    // hopefully it gets fixed soonish).
    
    $defaultThankYou = $settingsAgent->getDefaultValue('thank-you');
    $thankYou = $settingsAgent->getOption('thank-you', $defaultThankYou);
    $page = $this->getPageBySlug($thankYou);
    $permalink = !($page instanceof WP_Post)
      ? $_POST['_wp_http_referer']
      : get_permalink($page->ID);
    
    wp_safe_redirect($permalink);
  }
  
  /**
   * sendEmail
   *
   * When our form submission handle specifies that an email should be sent
   * including the message, this is the method that does so.
   *
   * @param Message $message
   *
   * @return void
   * @throws HandlerException
   * @throws TransformerException
   */
  private function sendEmail(Message $message): void
  {
    $settingsAgent = $this->handler->getSettingsAgent();
    $defaultSubject = $settingsAgent->getDefaultValue('subject');
    $subject = $settingsAgent->getOption('subject', $defaultSubject);
    $defaultRecipient = $settingsAgent->getDefaultValue('recipient');
    $recipient = $settingsAgent->getOption('recipient', $defaultRecipient);
    
    // if we have an email and it's valid, we'll want to set the From header
    // so that a reply can be easily sent.  if we also have a person's name, we
    // can set the From header to include that.
    
    if (!empty($message->email) && filter_var($message->email, FILTER_VALIDATE_EMAIL)) {
      $from = !empty($message->name)
        ? sprintf('%s <%s>', $message->name, $message->email)
        : $message->email;
      
      $headers[] = 'From: ' . $from;
    }
    
    // this X-header may help people do some filtering in their inboxes.
    // likely, the easier way to filter will be the subject, but this may be a
    // tool for some.
    
    $headers[] = 'X-Conscientious-Contact-Form: true';
    wp_mail($recipient, $subject, $message->message, $headers);
  }
}
