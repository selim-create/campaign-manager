<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Pro_SCM_Meta_Boxes {

    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'add_campaign_boxes' ) );
        add_action( 'save_post', array( $this, 'save_campaign_data' ) );
        add_action( 'show_user_profile', array( $this, 'add_user_fields' ) );
        add_action( 'edit_user_profile', array( $this, 'add_user_fields' ) );
        add_action( 'personal_options_update', array( $this, 'save_user_fields' ) );
        add_action( 'edit_user_profile_update', array( $this, 'save_user_fields' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
    }

    public function enqueue_admin_scripts() {
        global $typenow;
        if ( 'campaign' === $typenow ) {
            wp_enqueue_style( 'scm-select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css' );
            wp_enqueue_script( 'scm-select2-js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), null, true );
            wp_add_inline_script( 'scm-select2-js', "jQuery(document).ready(function($){ $('.scm-select2-users').select2({ width: '100%' }); });" );
        }
    }

    public function add_user_fields( $user ) {
        $payment_url = get_user_meta( $user->ID, '_scm_payment_url', true );
        ?>
        <h3>Cüzdan Entegrasyonu</h3>
        <table class="form-table">
            <tr>
                <th><label>Ödeme Gateway URL</label></th>
                <td>
                    <input type="text" name="scm_payment_url" value="<?php echo esc_attr( $payment_url ); ?>" class="regular-text" />
                    <p class="description">Secure Payment Gateway bağlantı linki.</p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_user_fields( $user_id ) {
        if ( !current_user_can( 'edit_user', $user_id ) ) return false;
        if(isset($_POST['scm_payment_url'])) update_user_meta( $user_id, '_scm_payment_url', sanitize_text_field( $_POST['scm_payment_url'] ) );
    }

    public function add_campaign_boxes() {
        // İSİMLENDİRME DEĞİŞİKLİĞİ: "Google Sheets" yerine "Data Source"
        add_meta_box( 'cm_settings', 'Data Source Configuration', array( $this, 'render_settings' ), 'campaign', 'side', 'high' );
        add_meta_box( 'cm_auth', 'Erişim Yetkilendirme', array( $this, 'render_auth' ), 'campaign', 'normal', 'high' );
    }

    public function render_settings( $post ) {
        $id = get_post_meta( $post->ID, 'campaign_id', true );
        $last_sync = get_post_meta( $post->ID, 'last_sync_time', true );
        ?>
        <p><strong>External Campaign ID:</strong></p>
        <input type="text" name="campaign_id" value="<?php echo esc_attr($id); ?>" style="width:100%; padding:8px; border-radius:4px; border:1px solid #ddd;">
        <p style="font-size:11px; color:#888; margin-top:5px;">Ad Server üzerindeki benzersiz kampanya kimliği (UUID).</p>
        
        <?php if($last_sync): ?>
            <div style="margin-top:15px; padding:8px; background:#f6f7f7; border:1px solid #dcdcde; border-radius:4px; font-size:11px; color:#50575e;">
                <strong>Last API Sync:</strong><br>
                <?php echo date('d.m.Y H:i', strtotime($last_sync)); ?>
            </div>
        <?php endif;
    }

    public function render_auth( $post ) {
        $assigned = get_post_meta( $post->ID, '_scm_assigned_users', true ) ?: array();
        $users = get_users();
        ?>
        <p>Bu kampanya verilerine erişebilecek hesapları yetkilendirin:</p>
        <select name="scm_assigned_users[]" class="scm-select2-users" multiple>
            <?php foreach($users as $u): ?>
                <option value="<?php echo $u->ID; ?>" <?php echo in_array($u->ID, $assigned)?'selected':''; ?>><?php echo $u->display_name; ?> (<?php echo $u->user_email; ?>)</option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function save_campaign_data( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( isset($_POST['campaign_id']) ) update_post_meta( $post_id, 'campaign_id', sanitize_text_field( $_POST['campaign_id'] ) );
        if ( isset($_POST['scm_assigned_users']) ) {
            update_post_meta( $post_id, '_scm_assigned_users', array_map('intval', $_POST['scm_assigned_users']) );
        } else {
            delete_post_meta( $post_id, '_scm_assigned_users' );
        }
    }
}
new Pro_SCM_Meta_Boxes();