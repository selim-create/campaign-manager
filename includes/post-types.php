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
                'menu_name'          => 'Kampanyalar', // Menü adı garanti olsun
                'add_new'            => 'Yeni Ekle',
                'add_new_item'       => 'Yeni Kampanya Ekle',
                'edit_item'          => 'Kampanyayı Düzenle',
                'all_items'          => 'Tüm Kampanyalar',
                'search_items'       => 'Kampanya Ara',
                'not_found'          => 'Kampanya Bulunamadı',
            ),
            'public'              => false, // Frontend tekil sayfası (single.php) olmasına gerek yok
            'show_ui'             => true,  // Admin panelinde görünsün
            'show_in_menu'        => true,  // Ana menüde görünsün
            'menu_position'       => 5,     // Yazılar'ın hemen altında
            'menu_icon'           => 'dashicons-chart-pie',
            'supports'            => array( 'title' ), // Sadece başlık
            'has_archive'         => false,
            'show_in_rest'        => false, 
        ));

        // Line Item Post Type'ını KALDIRDIK.
        // Çünkü artık Google Sheets'ten gelen her satır sanal bir line item'dır.
        // Veritabanını şişirmemek için bunları ayrı post olarak kaydetmiyoruz.
    }
}

new Pro_SCM_Post_Types();