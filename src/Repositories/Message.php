<?php

namespace Dashifen\WordPress\Plugins\ContactForm\Repositories;

use Dashifen\Repository\Repository;
use Dashifen\Repository\RepositoryException;

/**
 * Class Message
 *
 * @property-read string $name
 * @property-read string $email
 * @property-read string $organization
 * @property-read string $subject
 * @property-read string $message
 *
 * @package Dashifen\WordPress\Plugins\ContactForm\Repositories
 */
class Message extends Repository
{
  protected string $name;
  protected string $email;
  protected string $organization;
  protected string $subject;
  protected string $message;
  
  /**
   * AbstractRepository constructor.
   *
   * If given an associative data array, loops over its values settings
   * properties that match indices therein.
   *
   * @param array $data
   *
   * @throws RepositoryException
   */
  public function __construct(array $data = [])
  {
    // we may receive more data here than we actually care about.  thus, we
    // filter our data so that it is limited to only those field/value pairs
    // that match what this repository stores.
    
    parent::__construct(array_filter(
      $data,
      fn($datum) => property_exists($this, $datum),
      ARRAY_FILTER_USE_KEY
    ));
  }
  
  /**
   * getRequiredProperties
   *
   * Returns an array of property names that must be non-empty after
   * construction.
   *
   * @return array
   */
  protected function getRequiredProperties(): array
  {
    return ['subject', 'message'];
  }
  
  /**
   * setName
   *
   * Sets the name property.
   *
   * @param string $name
   *
   * @return void
   */
  protected function setName(string $name): void
  {
    $this->name = sanitize_text_field($name);
  }
  
  /**
   * setEmail
   *
   * Sets the email property.
   *
   * @param string $email
   *
   * @return void
   */
  protected function setEmail(string $email): void
  {
    // because emails are optional, we don't worry about validating them here.
    // instead, we just sanitize them and then call it a day.  we do test for
    // validity elsewhere, though, right before we send a message.
    
    $this->email = sanitize_email($email);
  }
  
  /**
   * setOrganization
   *
   * Sets the organization property.
   *
   * @param string $organization
   *
   * @return void
   */
  protected function setOrganization(string $organization): void
  {
    $this->organization = sanitize_text_field($organization);
  }
  
  /**
   * setSubject
   *
   * Sets the subject property.
   *
   * @param string $subject
   *
   * @return void
   */
  protected function setSubject(string $subject): void
  {
    $this->subject = sanitize_text_field($subject);
  }
  
  /**
   * setMessage
   *
   * Sets the message property.
   *
   * @param string $message
   *
   * @return void
   */
  protected function setMessage(string $message): void
  {
    $this->message = sanitize_textarea_field($message);
  }
}
