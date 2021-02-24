<?php

namespace Dashifen\ConscientiousContactForm;

use Dashifen\Transformer\TransformerException;
use Dashifen\WPHandler\Handlers\HandlerException;
use Dashifen\WPHandler\Traits\PostMetaManagementTrait;
use Dashifen\WPHandler\Traits\PostTypeRegistrationTrait;
use Dashifen\ConscientiousContactForm\Agents\SettingsAgent;
use Dashifen\ConscientiousContactForm\Repositories\Message;
use Dashifen\WPHandler\Handlers\Plugins\AbstractPluginHandler;
use Dashifen\ConscientiousContactForm\Services\SettingsValidator;

class ConscientiousContactForm extends AbstractPluginHandler
{
  use PostTypeRegistrationTrait;
  use PostMetaManagementTrait;
  
  public const SLUG = 'conscientious-contact-form';
  public const CAPABILITY = 'ccf-responder';
  
  // WP core puts an arbitrary maximum length of 20 on the names of post types.
  // therefore, we can't use our SLUG in the post type name.  instead, we'll
  // abbreviate it "ccf" and that'll have to do.
  
  public const POST_TYPE = 'ccf-response';

  private int $unreadCount;
  
  /**
   * initialize
   *
   * Uses addAction and/or addFilter to attach protected methods of this object
   * to the WordPress ecosystem of action and filter hooks.
   *
   * @return void
   * @throws HandlerException
   * @throws TransformerException
   */
  public function initialize(): void
  {
    if (!$this->isInitialized()) {
      $this->registerActivationHook('activation');
      $this->registerDeactivationHook('deactivation');
      $this->addAction('init', 'initializeAgents', 1);
      $this->addFilter('timber/locations', 'addTwigLocation');
      
      $settingsAgent = $this->getSettingsAgent();
      $submissionHandler = $settingsAgent->getOption('submission-handler', 'email');
      if ($submissionHandler !== 'email') {
        
        // if the current settings of this plugin indicate that the database is
        // involved in the storing and reviewing of our form responses, then we
        // need to prep the post type that handles them.
        
        $this->addAction('init', 'registerPostType');
        $this->addAction('init', 'registerPostStatuses');
        $this->addAction('admin_menu', 'showUnreadCount');
        $this->addFilter('add_menu_classes', 'alterMenuClasses');
        $this->addAction('admin_enqueue_scripts', 'addAdminAssets');
        $this->addFilter('manage_' . self::POST_TYPE . '_posts_columns', 'addResponseColumns');
        $this->addFilter('manage_' . self::POST_TYPE . '_posts_custom_column', 'addResponseColumnData', 10, 2);
      }
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
    get_role('administrator')->add_cap(self::CAPABILITY);
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
      get_role($role)->remove_cap(self::CAPABILITY);
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
   * registerPostType
   *
   * Registers the ccf-response post type.
   *
   * @return void
   */
  protected function registerPostType(): void
  {
    $args = [
      'label'               => 'Response',
      'description'         => 'Responses from our conscientious contact form',
      'labels'              => $this->getPostTypeLabels('Response', 'Responses'),
      'menu_icon'           => 'dashicons-email',
      'has_archive'         => false,
      'publicly_queryable'  => false,
      'rewrite'             => false,
      'show_in_rest'        => false,
      'show_in_admin_bar'   => false,
      'show_in_nav_menus'   => false,
      'hierarchical'        => false,
      'public'              => true,
      'show_ui'             => true,
      'show_in_menu'        => true,
      'can_export'          => true,
      'exclude_from_search' => true,
      'menu_position'       => 25,
      'supports'            => [],
      'capabilities'        => [
        
        // this is a strange post type in that we want to give people the
        // ability to see, review, and delete them, but we don't actually
        // make new ones or edit them here.  therefore, we've carefully nulled
        // capabilities that would allow for the actions we don't want.
        
        'create_posts'       => false,
        'edit_post'          => null,
        'read_post'          => null,
        'delete_post'        => self::CAPABILITY,
        'edit_posts'         => self::CAPABILITY,
        'edit_others_posts'  => null,
        'publish_posts'      => null,
        'read_private_posts' => null,
      ],
    ];
    
    register_post_type(self::POST_TYPE, $args);
  }
  
  /**
   * registerPostStatuses
   *
   * Adds post statuses related to form submissions.
   *
   * @return void
   */
  protected function registerPostStatuses(): void
  {
    $countFormat = '%s <span class="count">(%%s)</span>';
    
    foreach (['read', 'unread'] as $status) {
      $capitalized = ucfirst($status);
      $formatted = sprintf($countFormat, $capitalized);
      
      $statusSettings = [
        'label'       => $capitalized,
        'label_count' => _n_noop($formatted, $formatted),
        'public'      => false,
      ];
      
      register_post_status($status, $statusSettings);
    }
  }
  
  /**
   * showUnreadCount
   *
   * Adds the count of unread messages to the Dashboard menu item for CCF
   * responses.
   *
   * @return void
   */
  protected function showUnreadCount(): void
  {
    global $menu;
    foreach ($menu as &$item) {
      if ($item[1] === self::CAPABILITY) {
        if (($unreadCount = $this->getUnreadCount()) !== 0) {
          $item[0] .= sprintf(
            '<div class="circle"><p>%d</p></div>',
            $unreadCount
          );
        }
        
        return;
      }
    }
    
    self::debug($menu, true);
  }
  
  /**
   * getUnreadCount
   *
   * Returns
   *
   * @return int
   */
  private function getUnreadCount(): int
  {
    if (!isset($this->unreadCount)) {
      $unreadResponses = get_posts(
        [
          'fields'         => 'ids',
          'post_type'      => self::POST_TYPE,
          'post_status'    => 'unread',
          'posts_per_page' => -1,
        ]
      );
  
      $this->unreadCount = sizeof($unreadResponses);
    }
    
    return $this->unreadCount;
  }
  
  /**
   * alterMenuClasses
   *
   * Changes the classes on the ccf-response menu item when there are unread
   * messages.
   *
   * @param array $menu
   *
   * @return array
   */
  protected function alterMenuClasses(array $menu): array
  {
    
    
    if ($this->getUnreadCount() !== 0) {
      foreach ($menu as &$item) {
        if ($item[1] === self::CAPABILITY) {
          $item[4] .= ' with-unread-messages';
          break;
        }
      }
    }
    
    return $menu;
  }
  
  /**
   * addAdminAssets
   *
   * Adds the general assets that are loaded throughout the Dashboard.  The
   * SettingsAgent adds another CSS file but only to the settings page.
   *
   * @return void
   */
  protected function addAdminAssets(): void
  {
    $this->enqueue('assets/styles/admin-general.css');
  }
  
  /**
   * addResponseColumns
   *
   * Adds the necessary custom columns to our response listing.
   *
   * @param array $columns
   *
   * @return array
   * @throws HandlerException
   * @throws TransformerException
   */
  protected function addResponseColumns(array $columns): array
  {
    // at the moment our responses don't get titles, so we'll remove that
    // column.  then, we get the optional fields that have been added to our
    // form and use them to create additional columns on-screen.,
    
    unset($columns['title']);
    foreach ($this->getOptionalFields() as $field) {
      $columnName = $this->getColumnName($field);
      $columns[$columnName] = $field === 'name' ? 'From' : ucfirst($field);
    }
    
    return $columns;
  }
  
  /**
   * getOptionalFields
   *
   * Gets the list of optional fields from our SettingsAgent that have been
   * added to our form.
   *
   * @return array
   * @throws HandlerException
   * @throws TransformerException
   */
  private function getOptionalFields(): array
  {
    return $this->getSettingsAgent()
      ->getOption('optional-fields', SettingsValidator::OPTIONAL_FIELDS);
  }
  
  /**
   * getColumnName
   *
   * Returns the name we'll use to identify our custom columns based on the
   * field name that we receive from the calling scope.
   *
   * @param string $field
   *
   * @return string
   */
  private function getColumnName(string $field): string
  {
    return $this->getPostMetaNamePrefix() . $field;
  }
  
  /**
   * getPostMetaNamePrefix
   *
   * Returns the prefix that that is used to differentiate the post meta for
   * this handler's sphere of influence from others.  By default, we return
   * an empty string, but we assume that this will likely get overridden.
   * Public in case an agent needs to ask their handler what prefix to use.
   *
   * @return string
   */
  public function getPostMetaNamePrefix(): string
  {
    return self::SLUG . '-';
  }
  
  /**
   * addResponseColumnData
   *
   * Given the name of a column, determines the information that should be
   * crammed into it.
   *
   * @param string $column
   * @param int    $postId
   *
   * @return void
   * @throws HandlerException
   * @throws TransformerException
   */
  protected function addResponseColumnData(string $column, int $postId): void
  {
    foreach ($this->getOptionalFields() as $field) {
      if ($column === $this->getColumnName($field)) {
        
        // if our column matches the one we created in the method above, then
        // we want to print the post meta for this post that matches our field.
        // then we can break out of the loop because we know we won't match
        // anything else.
        
        echo $this->getPostMeta($postId, $field);
        break;
      }
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
  
  /**
   * savePost
   *
   * When our form agent detects that a ccf-response post should be saved in
   * the database, it passes control back to us so that we can do so.  this is
   * so that it doesn't have to know about the post meta fields and use the
   * PostMetaManagementTrait both of which give this object a sense of purpose.
   *
   * @param Message $message
   *
   * @return void
   * @throws HandlerException
   */
  public function savePost(Message $message): void
  {
    $postId = wp_insert_post(
      [
        'post_content' => $message->message,
        'post_type'    => ConscientiousContactForm::POST_TYPE,
        'post_status'  => 'unread',
      ]
    );
    
    $settingsAgent = $this->getSettingsAgent();
    $defaultFields = $settingsAgent->getDefaultValue('optional-fields');
    $chosenFields = $settingsAgent->getOption('optional-fields', $defaultFields);
    foreach ($chosenFields as $field) {
      if (!empty($message->{$field})) {
        $this->updatePostMeta($postId, $field, $message->{$field});
      }
    }
  }
  
  /**
   * getPostMetaNames
   *
   * Returns an array of valid post meta names for use within the
   * isPostMetaValid method.
   *
   * @return array
   */
  protected function getPostMetaNames(): array
  {
    // the names of our post meta are the same as the names for the optional
    // fields of the form.  handily, our settings validator knows what those
    // fields are named so that it can confirm that a visitor hasn't messed
    // them up.  so, we can use that constant as follows to define the post
    // meta that this object manages.
    
    return SettingsValidator::OPTIONAL_FIELDS;
  }
}
