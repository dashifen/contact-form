<?php

namespace Dashifen\ConscientiousContactForm\Services;

use Dashifen\Validator\AbstractValidator;
use Dashifen\WPHandler\Traits\CaseChangingTrait;

class SettingsValidator extends AbstractValidator
{
  use CaseChangingTrait;
  
  public const OPTIONAL_FIELDS = ['name', 'email', 'organization'];
  public const SUBMISSION_HANDLERS = ['email', 'database', 'both'];
  
  protected function getValidationMethod(string $field): string
  {
    // the fields we receive are the option names from the SettingsAgent.
    // these names are in kebab-case because they're also used as HTML
    // attribute values.  therefore, we switch them to StudlyCase and add
    // "validate" in front of them to make our method names.
    
    return 'validate' . $this->kebabToStudlyCase($field);
  }
  
  /**
   * validateOptionalFields
   *
   * Each of the marked optional fields chosen by the visitor are sent here
   * individually.  we confirm that they're in the OPTIONAL_FIELDS constant
   * and adjust our error message accordingly if not.
   *
   * @param string $field
   *
   * @return bool
   */
  protected function validateOptionalFields(string $field): bool
  {
    if (!($isValidField = in_array($field, self::OPTIONAL_FIELDS))) {
      
      // if we couldn't find this field in our list of valid fields, then we
      // need to update our error message.  but, because we could have multiple
      // unknown fields, we'll see if our message already begins with the word
      // "Unknown" to see if that's the case.  if so, we alter the existing
      // message to include this field.  otherwise, we start the message and
      // make sure that it contains "Unknown" as its first word.
      
      if (strpos($this->messages['optional-fields'], 'Unknown') === 0) {
        $this->messages['optional-fields'] = str_replace('field', 'fields', $this->messages['optional-fields']);
        $this->messages['optional-fields'] .= ', ' . $field;
      } else {
        $this->messages['optional-fields'] = 'Unknown field: ' . $field;
      }
    }
    
    return $isValidField;
  }
  
  /**
   * validateSubmissionHandler
   *
   * Given the handler chosen by the visitor, returns true if it's found
   * within the SUBMISSION_HANDLERS constant.
   *
   * @param string $handler
   *
   * @return bool
   */
  protected function validateSubmissionHandler(string $handler): bool
  {
    if (!($isValidHandler = in_array($handler, self::SUBMISSION_HANDLERS))) {
      $this->messages['submission-handler'] = 'Invalid handler: ' . $handler;
    }
    
    return $isValidHandler;
  }
  
  /**
   * validateRecipient
   *
   * Returns true if the $recipient parameter is an email address.
   *
   * @param string $recipient
   *
   * @return bool
   */
  protected function validateRecipient(string $recipient): bool
  {
    if (!in_array('recipient', $this->requirements)) {

      // the recipient is conditionally required.  luckily, the scope using
      // this object tells us if it's needed, and if we're in here, then it's
      // not.  therefore, we just return true because we don't actually care
      // what value it has.
  
      return true;
    }
    
    if (!($isValidEmail = $this->isEmail($recipient))) {
      $this->messages['recipient'] = 'Invalid email: ' . $recipient;
    }
    
    return $isValidEmail;
  }
}
