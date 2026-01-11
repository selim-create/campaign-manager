<?php
/**
 * Plugin Name: Pro Campaign Manager
 * Description: Kampanyalar, Line Item'lar, Çoklu Müşteri Yetkilendirme, Gelişmiş Hedefleme ve Raporlama.
 * Version: 2.0
 * Author: Gemini
 * Text Domain: pro-scm
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Sabitler
define( 'PRO_SCM_PATH', plugin_dir_path( __FILE__ ) );
define( 'PRO_SCM_URL', plugin_dir_url( __FILE__ ) );

class Pro_Campaign_Manager {

    public function __construct() {
        $this->load_dependencies();
    }

    private function load_dependencies() {
        // Modülleri dahil et
        require_once PRO_SCM_PATH . 'includes/post-types.php';
        require_once PRO_SCM_PATH . 'includes/meta-boxes.php';
        require_once PRO_SCM_PATH . 'includes/admin-columns.php';
        require_once PRO_SCM_PATH . 'includes/frontend.php';
    }
}

// Eklentiyi Başlat
new Pro_Campaign_Manager();