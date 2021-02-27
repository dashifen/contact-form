<?php

namespace Dashifen\ConscientiousContactForm\Agents;

use WP_Post;
use WP_Query;
use Dashifen\Transformer\TransformerException;
use Dashifen\WPHandler\Handlers\HandlerException;
use Dashifen\WPHandler\Agents\AbstractPluginAgent;
use Dashifen\WPHandler\Traits\ActionAndNonceTrait;
use Dashifen\WPHandler\Traits\FormattedDateTimeTrait;
use Dashifen\WPHandler\Traits\PostMetaManagementTrait;
use Dashifen\WPHandler\Traits\PostTypeRegistrationTrait;
use Dashifen\ConscientiousContactForm\Repositories\Message;
use Dashifen\ConscientiousContactForm\ConscientiousContactForm;
use Dashifen\ConscientiousContactForm\Services\SettingsValidator;

/**
 * Class PostTypeAgent
 *
 * @property ConscientiousContactForm $handler
 *
 * @package Dashifen\ConscientiousContactForm\Agents
 */
class PostTypeAgent extends AbstractPluginAgent
{
  use PostMetaManagementTrait;
  use PostTypeRegistrationTrait;
  use FormattedDateTimeTrait;
  use ActionAndNonceTrait;
  
  // WP core puts an arbitrary maximum length of 20 on the names of post types.
  // therefore, we can't use our SLUG in the post type name.  instead, we'll
  // abbreviate it "ccf" and that'll have to do.
  
  public const POST_TYPE = 'ccf-response';
  public const POST_STATUSES = ['read', 'unread'];
  public const CAPABILITY = 'ccf-responder';
  
  private int $unreadCount;
  
  /**
   * initialize
   *
   * Uses addAction and/or addFilter to attach protected methods of this
   * object to the WordPress ecosystem of action and filter hooks.
   *
   * @return void
   * @throws TransformerException
   * @throws HandlerException
   */
  public function initialize(): void
  {
    if (is_admin()) {
      $settingsAgent = $this->handler->getSettingsAgent();
      $defaultHandler = $settingsAgent->getDefaultValue('submission-handler');
      $submissionHandler = $settingsAgent->getOption('submission-handler', $defaultHandler);
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
        $this->addFilter('manage_edit-' . self::POST_TYPE . '_sortable_columns', 'addSortableColumns');
        $this->addAction('manage_' . self::POST_TYPE . '_posts_custom_column', 'addResponseColumnData', 10, 2);
        $this->addFilter('pre_get_posts', 'addCustomStatusToAllView');
        $this->addFilter('post_row_actions', 'addResponseActions', 10, 2);
        $this->addAction('admin_action_toggle', 'toggleResponseStatus');
        $this->addFilter('wp_untrash_post_status', 'restoreStatusAfterTrash', 10, 3);
        
        // the work of this attachment is so simple it's pointless to add it
        // to a method below.  we just return an empty array to remove the
        // bulk actions from our CPT's listing.
        
        $this->addFilter('bulk_actions-edit-' . self::POST_TYPE, fn() => []);
      }
    }
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
    
    foreach (self::POST_STATUSES as $status) {
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
    if ($this->isResponseListing()) {
      $this->enqueue('//kit.fontawesome.com/d393024f83.js');
    }
    
    $this->enqueue('assets/styles/admin-general.css');
  }
  
  /**
   * isResponseListing
   *
   * Returns true when we're on the edit.php?post_type=ccf-responder page.
   *
   * @return bool
   */
  private function isResponseListing(): bool
  {
    // we know we're on our response listing page if we can (a) detect the
    // current screen and (b) if it's ID is as constructed below.
    
    return ($screen = get_current_screen()) !== null
      && $screen->id === 'edit-' . self::POST_TYPE;
  }
  
  /**
   * addResponseColumns
   *
   * Adds the necessary custom columns to our response listing.
   *
   * @return array
   */
  protected function addResponseColumns(): array
  {
    // we want to print our own date information and the title appears as a
    // part of the message column we add here.  therefore, we remove both of
    // these default columns from the screen.  then we add our own date, the
    // column for our optional fields, and then, finally, our message.
    
    foreach (['date', 'from', 'status', 'message'] as $column) {
      $columns[$this->getColumnName($column)] = $column === 'status'
        ? '<span class="screen-reader-text">' . ucfirst($column) . '</span>'
        : ucfirst($column);
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
    $settingsAgent = $this->handler->getSettingsAgent();
    $defaultFields = $settingsAgent->getDefaultValue('optional-fields');
    return $settingsAgent->getOption('optional-fields', $defaultFields);
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
    return ConscientiousContactForm::SLUG . '-';
  }
  
  /**
   * addSortableColumns
   *
   * Adds our custom date column to the list of columns by which you can sort
   * form responses.
   *
   * @return array
   */
  protected function addSortableColumns(): array
  {
    return [$this->getColumnName('date') => 'post_date'];
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
    switch ($column) {
      case $this->getColumnName('message'):
        // our message is the post's content.  typically, this wouldn't be on
        // the listing page, but this isn't a typical CPT.  using a <details>
        // element in this way allows us to show the message's title and then
        // it's contents in one cell.  notice that we don't both to run the
        // content through filters; what the visitor enters is what we get
        // here.
        
        $content = get_post_field('post_content', $postId);
        $format = get_post_field('post_status', $postId) === 'unread'
          ? '<details><summary><strong>%s</strong></summary>%s</details>'
          : '<details><summary>%s</summary>%s</details>';
        
        echo sprintf($format, get_the_title($postId), $content);
        break;
      
      case $this->getColumnName('date'):
        
        // the default date column shows all sorts of stuff about the last
        // modified time, etc.  we don't want that, so we'll just show the
        // post's date as a formatted date time string.
        
        $postDate = get_post_field('post_date', $postId);
        echo $this->getFormattedDateTime(strtotime($postDate));
        break;
      
      case $this->getColumnName('status'):
        echo get_post_field('post_status', $postId) === 'read'
          ? '<i title="Read" class="fas fa-envelope-open-text"></i>'
          : '<i title="Unread" class="fas fa-envelope"></i>';
        break;
      
      case $this->getColumnName('from'):
        
        // the from column should show the information in our optional fields.
        // most of it can be printed directly to the screen, but the email we
        // want to wrap in a mailto: link.
        
        foreach ($this->getOptionalFields() as $field) {
          $meta = $this->getPostMeta($postId, $field);
          echo $field === 'email'
            ? sprintf('<a href="mailto:%s">%s</a><br>', $meta, $meta)
            : $meta . '<br>';
        }
    }
  }
  
  /**
   * addCustomStatusToAllView
   *
   * Adds our custom post statuses to the All view when visiting our CPT's post
   * listing.
   *
   * @param WP_Query $query
   *
   * @return WP_Query
   */
  protected function addCustomStatusToAllView(WP_Query $query): WP_Query
  {
    if ($this->isResponseListing() && !isset($_GET['post_status'])) {
      
      // if we're in here, then this is the post listing for our CPT and the
      // visitor hasn't selected a specific post status.  therefore, they want
      // to show all post statuses.  by default, this wouldn't include our
      // custom ones, so we'll add them here.
      
      $query->set('post_status', self::POST_STATUSES);
    }
    
    return $query;
  }
  
  /**
   * addResponseActions
   *
   * Adds either the Mark as Read or Mark as Unread actions for a given
   * message.
   *
   * @param array   $actions
   * @param WP_Post $post
   *
   * @return array
   */
  protected function addResponseActions(array $actions, WP_Post $post): array
  {
    if ($post->post_type === self::POST_TYPE) {
      $nonce = $this->getNonce($action = 'toggle');
      $nonceName = $this->getNonceName($action);
      
      // the two adjacent percents within our tag are intentional.  it allows
      // us to cram the link, ID, and nonce into the format during the first
      // sprintf call which converts %%s to %s.  then, the second call within
      // the ternary statement will replace that final %s with the correct
      // description of our action.
      
      /** @noinspection HtmlUnknownTarget */
      
      $format = '<a href="%s?post=%d&action=toggle&%s=%s">%%s</a>';
      $partial = sprintf($format, admin_url('admin.php'), $post->ID, $nonceName, $nonce);
      $myActions['toggle'] = $post->post_status === 'read'
        ? sprintf($partial, 'Mark as Unread')
        : sprintf($partial, 'Mark as Read');
      
      // putting our action first makes puts it before the trash action in
      // the array.
      
      $actions = array_merge($myActions, $actions);
    }
    
    return $actions;
  }
  
  /**
   * toggleResponseStatus
   *
   * This method switches a response from the unread to the read status or
   * vice versa depending on its current status.  Then, it redirects back to
   * the referrer.
   *
   * @return void
   */
  protected function toggleResponseStatus(): void
  {
    // remember, the isValidActionAndNonce function will either return true
    // or die.  therefore, we can put the entirety of our method's work within
    // the if-block and it'll execute as long as our request is valid.
    
    if ($this->isValidActionAndNonce('toggle')) {
      if (($postId = $_GET['post'] ?? null) !== null) {
        $status = get_post_field('post_status', $postId) === 'read' ? 'unread' : 'read';
        wp_update_post(['ID' => $postId, 'post_status' => $status]);
        wp_safe_redirect($_SERVER['HTTP_REFERER']);
        
        // now that we've redirected, we need to halt the execution of this
        // request.  otherwise, it's possible that, as the server finishes it,
        // it could try to send more data to the client and we don't want that.
        
        exit;
      }
      
      // if we're still executing this request then we couldn't find our post
      // ID.  without that, we can't really do anything other then die.
      
      wp_die('Something went wrong: unable to identify post.');
    }
  }
  
  /**
   * restoreStatusAfterTrash
   *
   * After WP 5.6, untrashed posts receive the draft status.  This method makes
   * sure that, for our post type, the original read or unread status is
   * restored to such posts.
   *
   * @param string $status
   * @param int    $postId
   * @param string $oldStatus
   *
   * @return string
   */
  protected function restoreStatusAfterTrash(string $status, int $postId, string $oldStatus): string
  {
    if (get_post_field('post_type', $postId) === self::POST_TYPE) {
      $status = $oldStatus;
    }
    
    return $status;
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
   * @throws TransformerException
   */
  public function savePost(Message $message): void
  {
    $postId = wp_insert_post(
      [
        'post_title'   => $message->subject,
        'post_content' => $message->message,
        'post_type'    => self::POST_TYPE,
        'post_status'  => 'unread',
      ]
    );
    
    // now that our post exists in the database, we'll want to save the
    // metadata about it.  we can get the set of chosen optional fields that
    // can be provided with a message from our SettingsAgent.  then, we loop
    // over them and save the non-empty ones in the database.
    
    foreach ($this->getOptionalFields() as $field) {
      if (!empty($message->{$field})) {
        $this->updatePostMeta($postId, $field, $message->{$field});
      }
    }
    
    // last thing we save is the visitor's IP address.  this may be used at
    // some point to block spam coming from the same IP, we'll see.  it seems
    // like it might be useful, so we'll make sure it's available from the get
    // go.
    
    $this->updatePostMeta($postId, 'ip', $_SERVER['REMOTE_ADDR']);
  }
  
  /**
   * getPostMetaNames
   *
   * Inherited from the PostMetaManagementTrait, this method returns an array
   * of valid post meta keys to ensure that this object only changes the data
   * associated with them.
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
    
    $optionalFields = SettingsValidator::OPTIONAL_FIELDS;
    return array_merge($optionalFields, ['ip', 'pre-trash-status']);
  }
  
  /**
   * getCapabilityForAction
   *
   * Inherited from the ActionAndNonceTrait, this method, given the name of an
   * action this visitor is attempting to perform, returns the WP capability
   * necessary to do so.  In this case, we return the name of our custom
   * capability regardless of the action, hence the @noinspection override.
   *
   *
   * @param string $action
   *
   * @return string
   * @noinspection PhpUnusedParameterInspection
   */
  protected function getCapabilityForAction(string $action): string
  {
    return self::CAPABILITY;
  }
  
  
}
