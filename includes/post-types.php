<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Pro_SCM_Post_Types {

    public function __construct() {
        add_action( 'init', array( $this, 'register_cpts' ) );
    }

    public function register_cpts() {
        // 1. Kampanya (Campaign)
        register_post_type( 'campaign', array(
            'labels' => array(
                'name'               => 'Kampanyalar',
                'singular_name'      => 'Kampanya',
                'add_new_item'       => 'Yeni Kampanya Ekle',
                'edit_item'          => 'Kampanyayı Düzenle',
                'all_items'          => 'Tüm Kampanyalar',
                'search_items'       => 'Kampanya Ara',
                'not_found'          => 'Kampanya Bulunamadı',
            ),
            'public'        => true,
            'menu_icon'     => 'dashicons-chart-pie',
            'supports'      => array( 'title' ), // Sadece başlık yeterli
            'rewrite'       => array( 'slug' => 'campaigns' ),
            'show_in_rest'  => false, 
        ));

        // 2. Line Item
        register_post_type( 'line_item', array(
            'labels' => array(
                'name'               => 'Line Items',
                'singular_name'      => 'Line Item',
                'add_new_item'       => 'Yeni Line Item Ekle',
                'edit_item'          => 'Line Item Düzenle',
            ),
            'public'        => true,
            'show_in_menu'  => 'edit.php?post_type=campaign', // Kampanya menüsü altında görünsün
            'supports'      => array( 'title' ),
            'show_in_rest'  => false,
        ));
    }
}

new Pro_SCM_Post_Types();