<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Pro_SCM_Bulk_Assignment {

    private $page_hook = '';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function add_admin_page() {
        $this->page_hook = add_submenu_page(
            'edit.php?post_type=campaign',
            'Toplu Kullanıcı Atama',
            'Toplu Kullanıcı Atama',
            'manage_options',
            'cm-bulk-assignment',
            array( $this, 'render_page' )
        );
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== $this->page_hook ) {
            return;
        }

        wp_enqueue_style( 'scm-bulk-select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', array(), '4.1.0-rc.0' );
        wp_enqueue_script( 'scm-bulk-select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array( 'jquery' ), '4.1.0-rc.0', true );

        wp_add_inline_style( 'scm-bulk-select2', '
            .cm-bulk-wrap{max-width:980px;margin-top:24px}.cm-bulk-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:28px;box-shadow:0 2px 10px rgba(0,0,0,.035)}
            .cm-bulk-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px}.cm-bulk-field{margin-bottom:24px}.cm-bulk-field label.cm-label{display:block;font-weight:600;margin-bottom:8px}
            .cm-bulk-field .description{margin-top:7px}.cm-bulk-actions{display:flex;gap:18px;flex-wrap:wrap;margin:8px 0 24px}.cm-bulk-actions label{display:flex;align-items:center;gap:6px}
            .cm-bulk-summary{display:flex;gap:12px;flex-wrap:wrap;margin:0 0 22px}.cm-bulk-pill{background:#f0f6fc;border:1px solid #c7dff5;color:#0c3e6d;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:600}
            .select2-container{width:100%!important}.select2-container--default .select2-selection--multiple{min-height:42px;border-color:#8c8f94;border-radius:4px;padding:3px 5px}
            .cm-bulk-disabled{opacity:.5;pointer-events:none}@media(max-width:782px){.cm-bulk-grid{grid-template-columns:1fr}}
        ' );

        wp_add_inline_script( 'scm-bulk-select2', "
            jQuery(function($){
                var userSelect = $('#cm_bulk_users').select2({placeholder:'Kullanıcı seçin',closeOnSelect:false,width:'100%'});
                var campaignSelect = $('#cm_bulk_campaigns').select2({placeholder:'Kampanya seçin',closeOnSelect:false,width:'100%'});
                function syncCampaignState(){
                    var all = $('#cm_all_active_campaigns').is(':checked');
                    $('#cm_campaign_selector_wrap').toggleClass('cm-bulk-disabled', all);
                    campaignSelect.prop('disabled', all).trigger('change.select2');
                }
                $('#cm_all_active_campaigns').on('change', syncCampaignState);
                syncCampaignState();
            });
        " );
    }

    private function get_campaign_ids_from_request() {
        if ( ! empty( $_POST['cm_all_active_campaigns'] ) ) {
            return get_posts( array(
                'post_type'      => 'campaign',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'orderby'        => 'title',
                'order'          => 'ASC',
            ) );
        }

        $ids = isset( $_POST['cm_campaign_ids'] ) ? (array) wp_unslash( $_POST['cm_campaign_ids'] ) : array();
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

        return array_values( array_filter( $ids, static function( $post_id ) {
            return 'campaign' === get_post_type( $post_id );
        } ) );
    }

    private function get_user_ids_from_request() {
        $ids = isset( $_POST['cm_user_ids'] ) ? (array) wp_unslash( $_POST['cm_user_ids'] ) : array();
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

        return array_values( array_filter( $ids, static function( $user_id ) {
            return (bool) get_userdata( $user_id );
        } ) );
    }

    private function process_form() {
        if ( empty( $_POST['cm_bulk_assign_submit'] ) ) {
            return;
        }

        check_admin_referer( 'cm_bulk_assignment_action', 'cm_bulk_assignment_nonce' );

        $mode = isset( $_POST['cm_assignment_mode'] ) ? sanitize_key( wp_unslash( $_POST['cm_assignment_mode'] ) ) : 'add';
        if ( ! in_array( $mode, array( 'add', 'replace', 'remove' ), true ) ) {
            $mode = 'add';
        }

        $campaign_ids = $this->get_campaign_ids_from_request();
        $user_ids     = $this->get_user_ids_from_request();

        if ( empty( $campaign_ids ) ) {
            $this->redirect_with_notice( 'no_campaigns', 0 );
        }

        if ( empty( $user_ids ) && 'replace' !== $mode ) {
            $this->redirect_with_notice( 'no_users', 0 );
        }

        $updated = 0;

        foreach ( $campaign_ids as $campaign_id ) {
            $current = get_post_meta( $campaign_id, '_scm_assigned_users', true );
            $current = is_array( $current ) ? array_values( array_unique( array_map( 'absint', $current ) ) ) : array();

            if ( 'replace' === $mode ) {
                $next = $user_ids;
            } elseif ( 'remove' === $mode ) {
                $next = array_values( array_diff( $current, $user_ids ) );
            } else {
                $next = array_values( array_unique( array_merge( $current, $user_ids ) ) );
            }

            if ( $next ) {
                update_post_meta( $campaign_id, '_scm_assigned_users', $next );
            } else {
                delete_post_meta( $campaign_id, '_scm_assigned_users' );
            }

            $updated++;
        }

        $this->redirect_with_notice( 'updated', $updated );
    }

    private function redirect_with_notice( $status, $count ) {
        $url = add_query_arg(
            array(
                'post_type' => 'campaign',
                'page'      => 'cm-bulk-assignment',
                'cm_status' => $status,
                'cm_count'  => absint( $count ),
            ),
            admin_url( 'edit.php' )
        );

        wp_safe_redirect( $url );
        exit;
    }

    private function render_notice() {
        $status = isset( $_GET['cm_status'] ) ? sanitize_key( wp_unslash( $_GET['cm_status'] ) ) : '';
        $count  = isset( $_GET['cm_count'] ) ? absint( $_GET['cm_count'] ) : 0;

        if ( 'updated' === $status ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html( $count ) . ' kampanyanın kullanıcı yetkilendirmesi güncellendi.</strong></p></div>';
        } elseif ( 'no_campaigns' === $status ) {
            echo '<div class="notice notice-error"><p>İşlem için en az bir kampanya seçmelisiniz.</p></div>';
        } elseif ( 'no_users' === $status ) {
            echo '<div class="notice notice-error"><p>Ekle/Kaldır işlemi için en az bir kullanıcı seçmelisiniz.</p></div>';
        }
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.' ) );
        }

        $this->process_form();

        $campaigns = get_posts( array(
            'post_type'      => 'campaign',
            'post_status'    => array( 'publish', 'draft' ),
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
        $active_count = count( array_filter( $campaigns, static function( $campaign ) {
            return 'publish' === $campaign->post_status;
        } ) );
        $users = get_users( array( 'orderby' => 'display_name', 'order' => 'ASC' ) );
        ?>
        <div class="wrap cm-bulk-wrap">
            <h1>Toplu Kullanıcı Atama</h1>
            <p>Bir veya birden fazla müşteriyi seçili kampanyalara tek işlemde yetkilendirin.</p>

            <?php $this->render_notice(); ?>

            <div class="cm-bulk-card">
                <div class="cm-bulk-summary">
                    <span class="cm-bulk-pill"><?php echo esc_html( $active_count ); ?> aktif kampanya</span>
                    <span class="cm-bulk-pill"><?php echo esc_html( count( $users ) ); ?> kullanıcı</span>
                </div>

                <form method="post">
                    <?php wp_nonce_field( 'cm_bulk_assignment_action', 'cm_bulk_assignment_nonce' ); ?>

                    <div class="cm-bulk-field">
                        <label>
                            <input type="checkbox" id="cm_all_active_campaigns" name="cm_all_active_campaigns" value="1" checked>
                            <strong>Tüm aktif kampanyalara uygula</strong>
                        </label>
                        <p class="description">Demo müşterisi gibi tüm yayınlanmış kampanyaları tek kullanıcıya vermek için bunu açık bırakın.</p>
                    </div>

                    <div class="cm-bulk-grid">
                        <div class="cm-bulk-field" id="cm_campaign_selector_wrap">
                            <label class="cm-label" for="cm_bulk_campaigns">Kampanyalar</label>
                            <select id="cm_bulk_campaigns" name="cm_campaign_ids[]" multiple>
                                <?php foreach ( $campaigns as $campaign ) : ?>
                                    <option value="<?php echo esc_attr( $campaign->ID ); ?>">
                                        <?php echo esc_html( $campaign->post_title . ( 'draft' === $campaign->post_status ? ' — Taslak' : '' ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">“Tüm aktif kampanyalar” kapalıysa buradan istediğiniz kampanyaları seçin.</p>
                        </div>

                        <div class="cm-bulk-field">
                            <label class="cm-label" for="cm_bulk_users">Kullanıcılar</label>
                            <select id="cm_bulk_users" name="cm_user_ids[]" multiple>
                                <?php foreach ( $users as $user ) : ?>
                                    <option value="<?php echo esc_attr( $user->ID ); ?>">
                                        <?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Bir veya birden fazla kullanıcı seçebilirsiniz.</p>
                        </div>
                    </div>

                    <div class="cm-bulk-field">
                        <span class="cm-label">İşlem</span>
                        <div class="cm-bulk-actions">
                            <label><input type="radio" name="cm_assignment_mode" value="add" checked> <strong>Ekle</strong> — mevcut yetkilileri korur</label>
                            <label><input type="radio" name="cm_assignment_mode" value="replace"> <strong>Değiştir</strong> — mevcut yetkilileri seçtiklerinizle değiştirir</label>
                            <label><input type="radio" name="cm_assignment_mode" value="remove"> <strong>Kaldır</strong> — seçilen kullanıcıları kaldırır</label>
                        </div>
                    </div>

                    <p class="submit">
                        <button type="submit" name="cm_bulk_assign_submit" value="1" class="button button-primary button-hero">Yetkilendirmeyi Uygula</button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
}

new Pro_SCM_Bulk_Assignment();
