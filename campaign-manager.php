<?php
/*
Plugin Name: AdOps Data Core
Description: Ad Server verilerini işler ve dashboard'a yansıtır.
Version: 3.6
Author: AdOps Team
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CM_PATH', plugin_dir_path( __FILE__ ) );
define( 'CM_URL', plugin_dir_url( __FILE__ ) );

require_once CM_PATH . 'includes/post-types.php';
require_once CM_PATH . 'includes/meta-boxes.php';
require_once CM_PATH . 'includes/admin-columns.php';
require_once CM_PATH . 'includes/frontend.php';
require_once CM_PATH . 'includes/sync.php';

// ---------------------------------------------------------
// 1. ZAMANLAYICI (CRON) AYARLARI
// ---------------------------------------------------------

// A. WordPress'e 15 Dakikalık Aralığı Öğretelim (BU KISIM EKSİKTİ)
add_filter( 'cron_schedules', 'cm_add_custom_interval' );
function cm_add_custom_interval( $schedules ) {
    $schedules['every_15_min'] = array(
        'interval' => 900, // 15 dakika x 60 saniye = 900
        'display'  => __( 'Her 15 Dakikada Bir' )
    );
    return $schedules;
}

// B. Eklenti Aktif Olduğunda Zamanlayıcıyı Kur
register_activation_hook(__FILE__, 'cm_setup_schedule');
function cm_setup_schedule() {
    // Eğer daha önce kurulu değilse kur
    if (!wp_next_scheduled('cm_sync_event_hook')) {
        wp_schedule_event(time(), 'every_15_min', 'cm_sync_event_hook');
    }
}

// C. Eklenti Pasif Olduğunda Zamanlayıcıyı Sil
register_deactivation_hook(__FILE__, 'cm_clear_schedule');
function cm_clear_schedule() {
    wp_clear_scheduled_hook('cm_sync_event_hook');
}

// D. Zamanı Gelince Çalışacak Fonksiyon
add_action('cm_sync_event_hook', 'cm_run_automatic_sync');
function cm_run_automatic_sync() {
    $dash_url = get_option('cm_dashboard_csv_url');
    $pay_url  = get_option('cm_payment_csv_url');
    
    // Linkler varsa ve sync fonksiyonu mevcutsa çalıştır
    if ($dash_url && $pay_url && function_exists('cm_sync_google_sheet_data')) {
        cm_sync_google_sheet_data($dash_url, $pay_url);
    }
}

// ---------------------------------------------------------
// 2. ADMIN MENÜSÜ & API AYARLARI
// ---------------------------------------------------------

add_action('admin_menu', 'cm_add_sync_page');
function cm_add_sync_page() {
    add_submenu_page(
        'edit.php?post_type=campaign',
        'Veri Akışı Ayarları', 
        'Data Stream (API)',   
        'manage_options',
        'cm-sync',
        'cm_handle_sync_page'
    );
}

function cm_handle_sync_page() {
    echo '<div class="wrap"><h1>Data Stream & API Konfigürasyonu</h1>';
    
    if (isset($_POST['cm_sync_now'])) {
        $dashboard_url = isset($_POST['dashboard_csv']) ? sanitize_text_field($_POST['dashboard_csv']) : '';
        $payment_url = isset($_POST['payment_csv']) ? sanitize_text_field($_POST['payment_csv']) : '';
        
        update_option('cm_dashboard_csv_url', $dashboard_url);
        update_option('cm_payment_csv_url', $payment_url);

        $result = cm_sync_google_sheet_data($dashboard_url, $payment_url);
        
        if ($result['success']) echo '<div class="notice notice-success"><p>' . $result['message'] . '</p></div>';
        else echo '<div class="notice notice-error"><p>Hata: ' . $result['message'] . '</p></div>';
    }

    if (isset($_POST['cm_create_defaults'])) {
        $create_result = cm_auto_create_campaigns();
        if ($create_result['success']) echo '<div class="notice notice-info"><p>' . $create_result['message'] . '</p></div>';
    }

    $saved_dash = get_option('cm_dashboard_csv_url', '');
    $saved_pay = get_option('cm_payment_csv_url', '');

    ?>
    <div style="display:flex; gap:20px; margin-top:20px;">
        
        <div style="flex:1; background:#fff; padding:30px; border:1px solid #dcdcde; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.05);">
            <h2 style="margin-top:0;">API Endpoint Konfigürasyonu</h2>
            <p style="color:#666;">Veri sağlayıcıdan (Ad Server) alınan kaynak bağlantılarını aşağıya giriniz.</p>
            <hr style="margin:20px 0; border:0; border-top:1px solid #eee;">
            
            <form method="post">
                <table class="form-table">
                    <tr>
                        <th style="padding-left:0;"><label>Data Source Endpoint (Main):</label></th>
                        <td><input type="text" name="dashboard_csv" value="<?php echo esc_attr($saved_dash); ?>" class="regular-text" style="width:100%;" placeholder="https://api.ad-server.com/v2/report..."></td>
                    </tr>
                    <tr>
                        <th style="padding-left:0;"><label>Financial Data Endpoint:</label></th>
                        <td><input type="text" name="payment_csv" value="<?php echo esc_attr($saved_pay); ?>" class="regular-text" style="width:100%;" placeholder="https://api.finance-server.com/v1/billing..."></td>
                    </tr>
                </table>
                <input type="hidden" name="cm_sync_now" value="1">
                <p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="Bağlantıyı Kur ve Verileri Çek"></p>
            </form>
        </div>

        <div style="flex:1; background:#fff; padding:30px; border:1px solid #dcdcde; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.05);">
            <h2 style="margin-top:0;">Kampanya Kurulum Sihirbazı</h2>
            <p style="color:#666;">Sisteme tanımlı kampanya kimliklerini (Campaign IDs) otomatik olarak veritabanına işler.</p>
            <hr style="margin:20px 0; border:0; border-top:1px solid #eee;">
            
            <form method="post">
                <input type="hidden" name="cm_create_defaults" value="1">
                <input type="submit" class="button button-secondary" value="Otomatik Tanımlamayı Başlat">
            </form>
        </div>

    </div>
    <?php
    echo '</div>';
}