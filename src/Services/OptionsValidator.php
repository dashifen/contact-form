<?php

namespace Dashifen\WordPress\Plugins\ContactForm\Services;

use Dashifen\Validator\AbstractValidator;

class OptionsValidator extends AbstractValidator
{
  /**
   * Returns the name of a method within this object used to validate $field.
   *
   * @param string $field
   *
   * @return string
   */
  protected function getValidationMethod(string $field): string
  {
    return match ($field) {
      'recipient'          => 'validateRecipient',
      'subject'            => 'validateSubject',
      'success', 'failure' => 'validatePage',
    };
  }
  
  /**
   * Returns true if $recipient is an email address.
   *
   * @param string $recipient
   *
   * @return bool
   */
  protected function validateRecipient(string $recipient): bool
  {
    if ($this->isEmail($recipient)) {
      return true;
    }
    
    $this->messages['recipient'] = empty($recipient)
      ? 'Please enter an email address'
      : 'Invalid email: ' . $recipient;
    
    return false;
  }
  
  /**
   * Returns true if $subject is not empty.
   *
   * @param string $subject
   *
   * @return bool
   */
  protected function validateSubject(string $subject): bool
  {
    if (!empty($subject)) {
      return true;
    }
    
    $this->messages['subject'] = 'Please enter a subject for your message.';
    return false;
  }
  
  /**
   * Returns true if $id is a published page.
   *
   * @param int    $id
   * @param string $field
   *
   * @return bool
   */
  protected function validatePage(int $id, string $field): bool
  {
    if (
      ($post = get_post($id)) &&          // does the post exist?
      $post->post_type === 'page' &&      // is it a page?
      $post->post_status === 'publish'    // is it published?
    ) {
      return true;                        // if so, return true.
    }
    
    $this->messages[$field] = 'Please select a page.';
    return false;
  }
}
