<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Pro_SCM_Meta_Boxes {

    public function __construct() {
        // Meta Box'lar (Kampanya & Line Item)
        add_action( 'add_meta_boxes', array( $this, 'add_boxes' ) );
        add_action( 'save_post', array( $this, 'save_data' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

        // KULLANICI PROFİLİ ALANLARI (Bakiye & Ödeme Linki)
        // Profil sayfasında göster
        add_action( 'show_user_profile', array( $this, 'add_user_fields' ) );
        add_action( 'edit_user_profile', array( $this, 'add_user_fields' ) );
        
        // Profil güncellendiğinde kaydet
        add_action( 'personal_options_update', array( $this, 'save_user_fields' ) );
        add_action( 'edit_user_profile_update', array( $this, 'save_user_fields' ) );
    }

    public function enqueue_admin_scripts() {
        global $typenow;
        if ( 'campaign' === $typenow ) {
            wp_enqueue_style( 'scm-select2-css', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css' );
            wp_enqueue_script( 'scm-select2-js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), null, true );
            
            wp_add_inline_script( 'scm-select2-js', "
                jQuery(document).ready(function($) {
                    $('.scm-select2-users').select2({
                        placeholder: 'Müşteri arayın ve seçin...',
                        allowClear: true,
                        width: '100%'
                    });
                });
            " );
        }
    }

    /**
     * KULLANICI PROFİLİNE BAKİYE ALANI EKLEME
     */
    public function add_user_fields( $user ) {
        $balance = get_user_meta( $user->ID, '_scm_user_balance', true );
        $payment_url = get_user_meta( $user->ID, '_scm_payment_url', true );
        ?>
        <h3>Müşteri Paneli Ayarları (Bakiye & Ödeme)</h3>
        <table class="form-table">
            <tr>
                <th><label for="scm_user_balance">Güncel Bakiye</label></th>
                <td>
                    <input type="text" name="scm_user_balance" id="scm_user_balance" value="<?php echo esc_attr( $balance ); ?>" class="regular-text" />
                    <p class="description">Örn: 5000. Müşterinin panelde göreceği bakiye tutarı.</p>
                </td>
            </tr>
            <tr>
                <th><label for="scm_payment_url">Ödeme Sayfası Linki</label></th>
                <td>
                    <input type="text" name="scm_payment_url" id="scm_payment_url" value="<?php echo esc_attr( $payment_url ); ?>" class="regular-text" />
                    <p class="description">"Ödeme Yap" butonuna tıklandığında gidilecek URL (Örn: https://site.com/odeme).</p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_user_fields( $user_id ) {
        if ( !current_user_can( 'edit_user', $user_id ) ) return false;
        
        if(isset($_POST['scm_user_balance']))
            update_user_meta( $user_id, '_scm_user_balance', sanitize_text_field( $_POST['scm_user_balance'] ) );
            
        if(isset($_POST['scm_payment_url']))
            update_user_meta( $user_id, '_scm_payment_url', sanitize_text_field( $_POST['scm_payment_url'] ) );
    }

    public function add_boxes() {
        add_meta_box( 'scm_campaign_auth', 'Yetkilendirme & Görünürlük', array( $this, 'render_campaign_auth' ), 'campaign', 'normal', 'high' );
        add_meta_box( 'scm_line_item_details', 'Line Item Verileri', array( $this, 'render_line_item_details' ), 'line_item', 'normal', 'high' );
    }

    public function render_campaign_auth( $post ) {
        $assigned_users = get_post_meta( $post->ID, '_scm_assigned_users', true ) ?: array();
        $visible_metrics = get_post_meta( $post->ID, '_scm_visible_metrics', true ) ?: array();
        $all_users = get_users( array( 'fields' => array( 'ID', 'display_name', 'user_email' ) ) );
        ?>
        <div style="display:flex; gap:20px; flex-wrap:wrap;">
            <div style="flex:1; min-width: 300px;">
                <h4>Erişim Yetkisi Olan Müşteriler</h4>
                <select name="scm_assigned_users[]" class="scm-select2-users" multiple="multiple">
                    <?php foreach ( $all_users as $user ) : ?>
                        <option value="<?php echo $user->ID; ?>" <?php echo in_array( $user->ID, $assigned_users ) ? 'selected' : ''; ?>>
                            <?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="flex:1; min-width: 300px; border-left:1px solid #ddd; padding-left:20px;">
                <h4>Müşterinin Görebileceği Alanlar</h4>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                    <div>
                        <strong>Genel Bilgiler</strong><br>
                        <label><input type="checkbox" name="scm_visible_metrics[]" value="media" <?php checked( in_array( 'media', $visible_metrics ) ); ?>> Mecra</label><br>
                        <label><input type="checkbox" name="scm_visible_metrics[]" value="location" <?php checked( in_array( 'location', $visible_metrics ) ); ?>> Lokasyon</label><br>
                        <label><input type="checkbox" name="scm_visible_metrics[]" value="desc" <?php checked( in_array( 'desc', $visible_metrics ) ); ?>> Açıklama</label><br>
                        <label><input type="checkbox" name="scm_visible_metrics[]" value="dates" <?php checked( in_array( 'dates', $visible_metrics ) ); ?>> Tarihler</label><br>
                    </div>
                    <div>
                        <strong>Metrikler</strong><br>
                        <label><input type="checkbox" name="scm_visible_metrics[]" value="impressions" <?php checked( in_array( 'impressions', $visible_metrics ) ); ?>> Impressions</label><br>
                        <label><input type="checkbox" name="scm_visible_metrics[]" value="clicks" <?php checked( in_array( 'clicks', $visible_metrics ) ); ?>> Clicks</label><br>
                        <label><input type="checkbox" name="scm_visible_metrics[]" value="ctr" <?php checked( in_array( 'ctr', $visible_metrics ) ); ?>> CTR</label><br>
                        <label><input type="checkbox" name="scm_visible_metrics[]" value="unit_cost" <?php checked( in_array( 'unit_cost', $visible_metrics ) ); ?>> Birim Maliyet</label><br>
                        <label><input type="checkbox" name="scm_visible_metrics[]" value="planned_budget" <?php checked( in_array( 'planned_budget', $visible_metrics ) ); ?>> Kampanya Bütçesi</label><br>
                        <label><input type="checkbox" name="scm_visible_metrics[]" value="budget" <?php checked( in_array( 'budget', $visible_metrics ) ); ?>> Harcanan Bütçe</label><br>
                        
                        <hr style="margin:5px 0;">
                        <strong>Hedefler</strong><br>
                        <label><input type="checkbox" name="scm_visible_metrics[]" value="target_goals" <?php checked( in_array( 'target_goals', $visible_metrics ) ); ?>> Hedef Rakamlar</label><br>
                        <label><input type="checkbox" name="scm_visible_metrics[]" value="target_reals" <?php checked( in_array( 'target_reals', $visible_metrics ) ); ?>> Gerçekleşenler</label><br>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_line_item_details( $post ) {
        $meta = get_post_meta( $post->ID );
        
        // Verileri Çek
        $parent_campaign = $meta['_scm_parent_campaign'][0] ?? '';
        $media = $meta['_scm_media'][0] ?? '';
        $location = $meta['_scm_location'][0] ?? '';
        $desc = $meta['_scm_description'][0] ?? '';
        $start_date = $meta['_scm_start_date'][0] ?? '';
        $end_date = $meta['_scm_end_date'][0] ?? '';
        
        $imp = $meta['_scm_impressions'][0] ?? '';
        $clk = $meta['_scm_clicks'][0] ?? '';
        $planned_budget = $meta['_scm_planned_budget'][0] ?? '';
        $budget = $meta['_scm_budget'][0] ?? '';
        $unit_cost = $meta['_scm_unit_cost'][0] ?? '';
        
        $target_p_type = $meta['_scm_target_p_type'][0] ?? ''; 
        $target_p_val  = $meta['_scm_target_p_val'][0] ?? '';   
        $target_p_real = $meta['_scm_target_p_real'][0] ?? ''; 
        
        $target_s_type = $meta['_scm_target_s_type'][0] ?? ''; 
        $target_s_val  = $meta['_scm_target_s_val'][0] ?? '';   
        $target_s_real = $meta['_scm_target_s_real'][0] ?? ''; 

        $campaigns = get_posts(array('post_type' => 'campaign', 'numberposts' => -1, 'post_status' => 'publish'));
        
        $target_types = array(
            '' => '-- Seçiniz --',
            'impressions' => 'Impressions',
            'clicks' => 'Clicks',
            'ctr' => 'CTR (%)',
            'leads' => 'Leads / Form',
            'sales' => 'Sales',
            'viewability' => 'Viewability',
            'video_views' => 'Video Views',
            'vcr' => 'VCR (%)'
        );
        ?>
        <style>
            .scm-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px; }
            .scm-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 15px; }
            .scm-section { border-top: 1px solid #eee; padding-top: 15px; margin-top: 15px; }
            .scm-label { font-weight: 600; display: block; margin-bottom: 5px; color: #23282d; }
            .widefat-input { width: 100%; padding: 6px; border: 1px solid #ddd; border-radius: 4px; }
            .scm-textarea { width: 100%; height: 60px; }
            .scm-sub-label { font-size: 0.85em; color: #666; display: block; margin-top: 5px; }
            .scm-highlight-box { background:#f0f6fc; padding:15px; border:1px solid #cce5ff; border-radius:5px; }
        </style>

        <!-- GENEL BİLGİLER -->
        <div class="scm-grid">
            <div>
                <label class="scm-label">Bağlı Kampanya</label>
                <select name="scm_parent_campaign" class="widefat-input">
                    <option value="">-- Kampanya Seçin --</option>
                    <?php foreach($campaigns as $c): ?>
                        <option value="<?php echo $c->ID; ?>" <?php selected($parent_campaign, $c->ID); ?>><?php echo esc_html($c->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="scm-label">Mecra</label>
                <input type="text" name="scm_media" value="<?php echo esc_attr($media); ?>" class="widefat-input">
            </div>
        </div>

        <div class="scm-grid">
             <div>
                <label class="scm-label">Lokasyon</label>
                <input type="text" name="scm_location" value="<?php echo esc_attr($location); ?>" class="widefat-input">
            </div>
            <div>
                <label class="scm-label">Birim Maliyet (CPU/CPC)</label>
                <input type="number" step="0.001" name="scm_unit_cost" value="<?php echo esc_attr($unit_cost); ?>" class="widefat-input">
            </div>
        </div>

        <div>
            <label class="scm-label">Açıklama</label>
            <textarea name="scm_description" class="scm-textarea"><?php echo esc_textarea($desc); ?></textarea>
        </div>

        <div class="scm-grid" style="margin-top:15px;">
            <div>
                <label class="scm-label">Başlangıç Tarihi</label>
                <input type="date" name="scm_start_date" value="<?php echo esc_attr($start_date); ?>" class="widefat-input">
            </div>
            <div>
                <label class="scm-label">Bitiş Tarihi</label>
                <input type="date" name="scm_end_date" value="<?php echo esc_attr($end_date); ?>" class="widefat-input">
            </div>
        </div>

        <!-- KPI / HEDEFLER BÖLÜMÜ -->
        <div class="scm-section">
            <h4>Kampanya Hedefleri (KPIs) & Gerçekleşenler</h4>
            <p class="description" style="margin-bottom:10px;">Eğer hedef türü <strong>Impressions</strong> veya <strong>Clicks</strong> seçilirse, buraya girdiğiniz "Gerçekleşen" değeri otomatik olarak CTR hesaplamasında kullanılır.</p>
            
            <div class="scm-grid">
                <!-- Birincil -->
                <div class="scm-highlight-box" style="border-left: 4px solid #0073aa;">
                    <label class="scm-label" style="color:#0073aa;">Birincil Hedef</label>
                    <select name="scm_target_p_type" style="width:100%; margin-bottom:10px;">
                        <?php foreach($target_types as $k => $v) { echo "<option value='$k' ".selected($target_p_type, $k, false).">$v</option>"; } ?>
                    </select>
                    
                    <div style="display:flex; gap:10px;">
                        <div style="flex:1;">
                            <span class="scm-sub-label">Hedef (Target)</span>
                            <input type="text" name="scm_target_p_val" value="<?php echo esc_attr($target_p_val); ?>" class="widefat-input">
                        </div>
                        <div style="flex:1;">
                            <span class="scm-sub-label">Gerçekleşen (Actual)</span>
                            <input type="text" name="scm_target_p_real" value="<?php echo esc_attr($target_p_real); ?>" class="widefat-input" placeholder="Gerçekleşen Değer">
                        </div>
                    </div>
                </div>

                <!-- İkincil -->
                <div class="scm-highlight-box" style="border-left: 4px solid #46b450; background:#f6fff8; border-color:#bceac3;">
                    <label class="scm-label" style="color:#2d7d34;">İkincil Hedef</label>
                    <select name="scm_target_s_type" style="width:100%; margin-bottom:10px;">
                        <?php foreach($target_types as $k => $v) { echo "<option value='$k' ".selected($target_s_type, $k, false).">$v</option>"; } ?>
                    </select>
                    
                    <div style="display:flex; gap:10px;">
                        <div style="flex:1;">
                            <span class="scm-sub-label">Hedef (Target)</span>
                            <input type="text" name="scm_target_s_val" value="<?php echo esc_attr($target_s_val); ?>" class="widefat-input">
                        </div>
                        <div style="flex:1;">
                            <span class="scm-sub-label">Gerçekleşen (Actual)</span>
                            <input type="text" name="scm_target_s_real" value="<?php echo esc_attr($target_s_real); ?>" class="widefat-input">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TEMEL PERFORMANS VERİLERİ -->
        <div class="scm-section">
            <h4>Temel Performans Verileri (Base Metrics)</h4>
            <p class="description">Yukarıda Hedef olarak Impression/Click seçmediyseniz, CTR hesaplanması için aşağıdaki verileri doldurunuz.</p>
            
            <div class="scm-grid-3">
                <div>
                    <label class="scm-label">Impressions</label>
                    <input type="number" name="scm_impressions" value="<?php echo esc_attr($imp); ?>" class="widefat-input">
                </div>
                <div>
                    <label class="scm-label">Clicks</label>
                    <input type="number" name="scm_clicks" value="<?php echo esc_attr($clk); ?>" class="widefat-input">
                </div>
                <div>
                    <!-- CTR otomatik hesaplanır -->
                </div>
            </div>

            <div class="scm-grid">
                <div>
                    <label class="scm-label">Kampanya Bütçesi (Plan)</label>
                    <input type="number" step="0.01" name="scm_planned_budget" value="<?php echo esc_attr($planned_budget); ?>" class="widefat-input">
                </div>
                <div>
                    <label class="scm-label">Harcanan Bütçe (Cost)</label>
                    <input type="number" step="0.01" name="scm_budget" value="<?php echo esc_attr($budget); ?>" class="widefat-input">
                </div>
            </div>
        </div>
        <?php
    }

    public function save_data( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

        // KAMPANYA KAYDI
        if ( get_post_type($post_id) === 'campaign' ) {
            $users = isset( $_POST['scm_assigned_users'] ) ? array_map( 'intval', $_POST['scm_assigned_users'] ) : array();
            update_post_meta( $post_id, '_scm_assigned_users', $users );

            $metrics = isset( $_POST['scm_visible_metrics'] ) ? $_POST['scm_visible_metrics'] : null;
            if($metrics) update_post_meta( $post_id, '_scm_visible_metrics', $metrics );
            else delete_post_meta( $post_id, '_scm_visible_metrics' );
        }

        // LINE ITEM KAYDI
        if ( get_post_type($post_id) === 'line_item' && isset($_POST['scm_parent_campaign']) ) {
            // Standart Alanlar
            $fields = array(
                '_scm_parent_campaign', '_scm_media', '_scm_location', '_scm_description',
                '_scm_unit_cost', '_scm_start_date', '_scm_end_date',
                '_scm_planned_budget', '_scm_budget',
                '_scm_target_p_type', '_scm_target_p_val', '_scm_target_p_real',
                '_scm_target_s_type', '_scm_target_s_val', '_scm_target_s_real'
            );

            foreach ( $fields as $field ) {
                $key = str_replace('_scm_', 'scm_', $field);
                if ( isset( $_POST[$key] ) ) {
                    $val = ($field == '_scm_description') ? sanitize_textarea_field( $_POST[$key] ) : sanitize_text_field( $_POST[$key] );
                    update_post_meta( $post_id, $field, $val );
                }
            }

            // SMART SYNC
            $imp = (int) $_POST['scm_impressions'];
            $clk = (int) $_POST['scm_clicks'];
            
            // Birincil Hedef Kontrolü
            if(isset($_POST['scm_target_p_type'])) {
                if($_POST['scm_target_p_type'] == 'impressions' && !empty($_POST['scm_target_p_real'])) {
                    $imp = (int) str_replace(['.', ','], '', $_POST['scm_target_p_real']);
                }
                if($_POST['scm_target_p_type'] == 'clicks' && !empty($_POST['scm_target_p_real'])) {
                    $clk = (int) str_replace(['.', ','], '', $_POST['scm_target_p_real']);
                }
            }

            // İkincil Hedef Kontrolü
            if(isset($_POST['scm_target_s_type'])) {
                if($_POST['scm_target_s_type'] == 'impressions' && !empty($_POST['scm_target_s_real'])) {
                    $imp = (int) str_replace(['.', ','], '', $_POST['scm_target_s_real']);
                }
                if($_POST['scm_target_s_type'] == 'clicks' && !empty($_POST['scm_target_s_real'])) {
                    $clk = (int) str_replace(['.', ','], '', $_POST['scm_target_s_real']);
                }
            }

            // Ana Metrikleri ve CTR'ı Güncelle
            update_post_meta( $post_id, '_scm_impressions', $imp );
            update_post_meta( $post_id, '_scm_clicks', $clk );

            $ctr = ($imp > 0) ? ($clk / $imp) * 100 : 0;
            update_post_meta( $post_id, '_scm_ctr', $ctr );
            
            update_post_meta( $post_id, '_scm_last_updated', current_time( 'mysql' ) );
        }
    }
}

new Pro_SCM_Meta_Boxes();