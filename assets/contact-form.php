<?php get_header();

// for our default template, we're going to assume that everything that
// appears before the content is in the theme's header.php file.  similarly,
// we assume that everything after it is in the footer.php file.  this is
// probably not accurate, but it's the best we can do.  then, we print the
// page's content followed by the action which prints our form.

echo apply_filters('the_content',  get_post()->post_content);
do_action('display-contact-form');
get_footer();
