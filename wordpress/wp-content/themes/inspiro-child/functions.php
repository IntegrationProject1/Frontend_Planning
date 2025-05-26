<?php
add_action( 'wp_enqueue_scripts', function(){
  wp_enqueue_style( 'inspiro-child-style',
    get_stylesheet_directory_uri().'/style.css',
    ['inspiro-style'], // dépend du handle du style parent
    filemtime( get_stylesheet_directory().'/style.css' )
  );
} );
