<?php

// =============================================================================
// FUNCTIONS.PHP
// -----------------------------------------------------------------------------
// Overwrite or add your own custom functions to Pro in this file.
//
// 2026-10-27 cutover: every Classified Listing (rtcl) customisation was removed —
// the member directory, classifieds, accounts, memberships and emails are now
// provided by the SouthPoll Membership plugin. The previous version of this file
// and the removed templates are kept under temp/migration/retired/pro-child/.
// =============================================================================

// =============================================================================
// TABLE OF CONTENTS
// -----------------------------------------------------------------------------
//   01. Enqueue Parent Stylesheet
//   02. Body class for subscribers (used by the child stylesheet)
//   03. Comments disabled site-wide
//   04. [classifieds_search] header search box → /classifieds/?q=
// =============================================================================

// Enqueue Parent Stylesheet
// =============================================================================

function my_child_theme_enqueue_styles() {
	// Load parent theme styles
	wp_enqueue_style( 'parent-style', get_template_directory_uri() . '/style.css' );

	// Load child theme styles
	wp_enqueue_style( 'child-style', get_stylesheet_directory_uri() . '/style.css', array( 'parent-style' ) );
}

add_action( 'wp_enqueue_scripts', 'my_child_theme_enqueue_styles' );
add_filter( 'x_enqueue_parent_stylesheet', '__return_true' );

// Body class for subscribers
// =============================================================================

function add_subscriber_body_class( $classes ) {
	if ( is_user_logged_in() && current_user_can( 'subscriber' ) ) {
		$classes[] = 'role-subscriber';
	}

	return $classes;
}

add_filter( 'body_class', 'add_subscriber_body_class' );

// Comments disabled site-wide
// =============================================================================

// Disable support for comments and trackbacks on all post types
add_action('admin_init', function () {
    foreach (get_post_types() as $post_type) {
        if (post_type_supports($post_type, 'comments')) {
            remove_post_type_support($post_type, 'comments');
            remove_post_type_support($post_type, 'trackbacks');
        }
    }
});

// Close comments on the front-end
add_filter('comments_open', '__return_false', 20, 2);
add_filter('pings_open', '__return_false', 20, 2);

// Hide existing comments
add_filter('comments_array', '__return_empty_array', 10, 2);

// Remove comment-related menu items from admin
add_action('admin_menu', function () {
    remove_menu_page('edit-comments.php');
});

// Redirect users who try to access the comments page
add_action('admin_init', function () {
    if (is_admin() && isset($_GET['page']) && $_GET['page'] === 'edit-comments.php') {
        wp_redirect(admin_url());
        exit;
    }
});

// Remove the recent comments widget from the dashboard
add_action('admin_init', function () {
    remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
});

// Remove comment links from the admin bar
add_action('init', function () {
    if (is_admin_bar_showing()) {
        remove_action('admin_bar_menu', 'wp_admin_bar_comments_menu', 60);
    }
});

// [classifieds_search] — compact search box that submits to the classifieds archive
// =============================================================================

add_shortcode('classifieds_search', function ($atts) {
  $action = home_url('/classifieds/');
  $q      = isset($_GET['q']) ? esc_attr(wp_unslash($_GET['q'])) : '';
  ob_start(); ?>
  <form role="search" method="get" class="search-form classifieds-search" action="<?php echo esc_url($action); ?>">
    <label class="classifieds-search__label">
      <span class="screen-reader-text">Public animal search</span>

      <!-- Icon -->
      <span class="classifieds-search__icon" aria-hidden="true">
        <!-- inline SVG (magnifying glass) -->
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false">
          <path d="M21 21l-4.2-4.2m1.2-5.8a7 7 0 11-14 0 7 7 0 0114 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </span>

      <!-- Input -->
      <input
        type="search"
        class="search-field classifieds-search__input"
        placeholder="Public animal search"
        value="<?php echo $q; ?>"
        name="q"
        autocomplete="off"
      />
    </label>

    <!-- Hidden submit so Enter works everywhere, but no visible button -->
    <input type="submit" class="classifieds-search__submit" value="Search" aria-hidden="true" tabindex="-1" />
  </form>

  <style>
    .classifieds-search {
    	display: flex;
	    align-items: center;
	    margin: 0;
	    border: 0;
	    padding: 0;
	    cursor: text;
	    background: white;
	    height: 40.5px;
	}
    .classifieds-search__label {
    	display: block;
    	position: relative;
    	padding-left: 35px;
    }
    .classifieds-search__icon {
      position: absolute; left: 9px; top: 55%; transform: translateY(-50%);
      line-height: 0; pointer-events: none; opacity: .7;
    }
    .classifieds-search__input {
        flex-grow: 1;
	    order: 2;
	    margin-bottom: 0 !important;
	    width: 100%;
	    min-width: 1px;
	    height: auto !important;
	    border: 0 !important;
	    padding: 0 !important;
	    background-color: transparent !important;
	    box-shadow: none !important;
	    -webkit-appearance: none;
	    -moz-appearance: none;
	    appearance: none;
	       font-size: 14px !important;
    	line-height: 14px !important;
    }
    .classifieds-search__input:focus { outline: 2px solid #009da5; outline-offset: 2px; }
    .classifieds-search__submit { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
  </style>
  <?php
  return ob_get_clean();
});
