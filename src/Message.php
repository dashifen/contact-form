<?php

namespace Dashifen\WordPress\Plugins\ContactForm;

use Dashifen\DTO\DTO;

class Message extends DTO
{
  protected array $requirements = ['subject', 'message'];
  
  protected(set) string $name {
    set {
      $this->name = sanitize_text_field($value);
    }
  }
  
  protected(set) string $email {
    set {
      $this->email = sanitize_email($value);
    }
  }
  
  protected(set) string $subject {
    set {
      $this->subject = sanitize_text_field($value);
    }
  }
  
  protected(set) string $message {
    set {
      $this->message = sanitize_textarea_field($value);
    }
  }
}
