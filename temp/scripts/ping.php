<?php
echo "WP ", get_bloginfo( 'version' ), " | home=", home_url(), " | theme=", wp_get_theme()->get( 'Name' ), " (", get_stylesheet(), ")\n";
echo "DB prefix: ", $GLOBALS['wpdb']->prefix, " | tables: ", count( $GLOBALS['wpdb']->get_col( 'SHOW TABLES' ) ), "\n";
echo "rtcl loaded: ", function_exists( 'rtcl' ) ? 'yes' : 'no', " | rtclStore: ", function_exists( 'rtclStore' ) ? 'yes' : 'no', "\n";
