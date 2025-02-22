<?php

namespace Dashifen\WordPress\Plugins\ConscientiousContactForm\Traits;

use WP_Post;

trait GetPageBySlugTrait
{
  protected function getPageBySlug(string $slug): ?WP_Post
  {
    $pages = get_posts(
      [
        'name'           => $slug,
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
      ]
    );
    
    return is_array($pages) && sizeof($pages) === 1 ? $pages[0] : null;
  }
}
