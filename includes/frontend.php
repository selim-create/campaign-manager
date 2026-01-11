<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Pro_SCM_Frontend {

    public function __construct() {
        // Dashboard Shortcode
        add_shortcode( 'client_dashboard', array( $this, 'render_dashboard' ) );
        
        // Menüye Bakiye ve Ödeme Butonu Ekleme
        add_filter( 'wp_nav_menu_items', array( $this, 'add_balance_to_menu' ), 10, 2 );
    }

    /**
     * TÜRK LİRASI VE SAYI FORMATLAMA YARDIMCISI
     */
    private function tr_num($num, $decimals = 0, $suffix = '') {
        // 1. Durum: Veri zaten standart sayı formatındaysa (Örn: 1250.50 veya "1250.50")
        // ve içinde virgül yoksa, temizlik yapma. Veritabanı verilerini korur.
        if ( is_numeric($num) && strpos((string)$num, ',') === false ) {
            // İşlem yapma, $num zaten doğru.
        } 
        // 2. Durum: Virgül içeriyorsa veya sayısal değilse (Örn: "1.250,50"), temizle.
        elseif ( is_string($num) ) {
            $clean_num = str_replace('.', '', $num); // Binlik ayırıcıları temizle
            $clean_num = str_replace(',', '.', $clean_num); // Virgülü noktaya çevir
            if ( is_numeric($clean_num) ) {
                $num = $clean_num;
            }
        }

        if (!is_numeric($num)) return $num;
        $formatted = number_format((float)$num, $decimals, ',', '.');
        return $formatted . $suffix;
    }

    /**
     * MENÜYE OTOMATİK EKLEME FONKSİYONU
     */
    public function add_balance_to_menu( $items, $args ) {
        // Sadece giriş yapmış kullanıcılara göster
        if ( ! is_user_logged_in() ) {
            return $items;
        }

        // Kullanıcı verilerini çek
        $user_id = get_current_user_id();
        $balance = get_user_meta( $user_id, '_scm_user_balance', true );
        $pay_url = get_user_meta( $user_id, '_scm_payment_url', true );

        // Bakiye varsa menünün sonuna ekle
        if ( $balance ) {
            $formatted = $this->tr_num( $balance, 2, ' TL' );
            // "javascript:void(0);" linkin tıklanmasını engeller, sadece gösterim amaçlıdır.
            $items .= '<li class="menu-item scm-menu-balance"><a href="javascript:void(0);" style="cursor:default;">Bakiye: ' . $formatted . '</a></li>';
        }

        // Ödeme linki varsa menünün sonuna ekle
        if ( $pay_url ) {
            // Dikkat çekmesi için inline style ekledim, temanızın CSS'i baskın gelebilir.
            $items .= '<li class="menu-item scm-menu-pay"><a href="' . esc_url( $pay_url ) . '" style="color:#ffffff; background-color:#2ecc71; padding: 10px 15px; border-radius:4px; font-weight:bold;">Ödeme Yap</a></li>';
        }

        return $items;
    }

    /**
     * Kampanya Tarih Sıralaması İçin Yardımcı
     */
    private function get_campaign_start_date($campaign_id) {
        global $wpdb;
        $date = $wpdb->get_var($wpdb->prepare(
            "
            SELECT m.meta_value 
            FROM {$wpdb->postmeta} m
            INNER JOIN {$wpdb->postmeta} pm ON m.post_id = pm.post_id
            WHERE pm.meta_key = '_scm_parent_campaign' 
            AND pm.meta_value = %d
            AND m.meta_key = '_scm_start_date'
            AND m.meta_value != ''
            ORDER BY m.meta_value ASC
            LIMIT 1
            ",
            $campaign_id
        ));
        
        return $date ?: '9999-12-31';
    }

    /**
     * DASHBOARD SHORTCODE
     */
    public function render_dashboard() {
        if ( ! is_user_logged_in() ) return '<div class="scm-alert scm-error">Lütfen raporları görmek için giriş yapınız.</div>';

        $user_id = get_current_user_id();
        
        // Header Bar için verileri çek (Dashboard içi)
        $balance = get_user_meta($user_id, '_scm_user_balance', true);
        $pay_url = get_user_meta($user_id, '_scm_payment_url', true);
        
        $args = array(
            'post_type'      => 'campaign',
            'posts_per_page' => -1, 
            'post_status'    => 'publish'
        );

        $all_campaigns = get_posts( $args );
        $my_campaigns = array();

        foreach ( $all_campaigns as $campaign ) {
            $assigned_users = get_post_meta( $campaign->ID, '_scm_assigned_users', true ) ?: array();
            if ( in_array( $user_id, $assigned_users ) ) {
                $my_campaigns[] = $campaign;
            }
        }

        if ( empty( $my_campaigns ) ) return '<div class="scm-alert scm-info">Size atanmış aktif kampanya bulunamadı.</div>';

        usort($my_campaigns, function($a, $b) {
            $date_a = $this->get_campaign_start_date($a->ID);
            $date_b = $this->get_campaign_start_date($b->ID);
            return strcmp($date_a, $date_b);
        });

        ob_start();
        $this->print_styles();
        ?>
        <div class="scm-wrapper">
            <!-- HEADER BAR: Müşteri Bilgisi & Bakiye (Dashboard İçi) -->
            <div class="scm-top-bar">
                <div class="scm-welcome">
                    Hoşgeldiniz, <strong><?php echo wp_get_current_user()->display_name; ?></strong>
                </div>
                
                <div class="scm-wallet-actions">
                    <?php if($balance): ?>
                        <div class="scm-balance-box">
                            <span>Güncel Bakiye:</span>
                            <span class="scm-balance-amount"><?php echo $this->tr_num($balance, 2, ' TL'); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if($pay_url): ?>
                        <a href="<?php echo esc_url($pay_url); ?>" class="scm-btn-pay" target="_blank">Ödeme Yap</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php foreach ( $my_campaigns as $post ) : 
                setup_postdata( $post );
                $camp_id = $post->ID;
                $visible = get_post_meta( $camp_id, '_scm_visible_metrics', true ) ?: array();
                
                $show_targets = in_array('target_goals', $visible) || in_array('target_reals', $visible) || in_array('targets', $visible);

                $items = get_posts(array(
                    'post_type' => 'line_item',
                    'posts_per_page' => -1,
                    'meta_query' => array(
                        array(
                            'key' => '_scm_parent_campaign',
                            'value' => $camp_id
                        )
                    ),
                    'meta_key' => '_scm_start_date',
                    'orderby' => 'meta_value',
                    'order' => 'ASC'
                ));
            ?>
                <div class="scm-card">
                    <div class="scm-header">
                        <h2><?php echo get_the_title($post); ?></h2>
                        <span class="scm-badge-blue"><?php echo count($items); ?> Kalem</span>
                    </div>

                    <div class="table-responsive">
                    <table class="scm-table">
                        <thead>
                            <tr>
                                <th>Kampanya Detayları</th>
                                <?php if(in_array('media', $visible)) echo '<th>Mecra</th>'; ?>
                                <?php if(in_array('location', $visible)) echo '<th>Lokasyon</th>'; ?>
                                <?php if($show_targets) echo '<th>Hedefler / Durum</th>'; ?>
                                <?php if(in_array('impressions', $visible)) echo '<th>Impression</th>'; ?>
                                <?php if(in_array('clicks', $visible)) echo '<th>Click</th>'; ?>
                                <?php if(in_array('ctr', $visible)) echo '<th>CTR</th>'; ?>
                                <?php if(in_array('unit_cost', $visible)) echo '<th>Birim Mal.</th>'; ?>
                                <?php if(in_array('planned_budget', $visible)) echo '<th>Planlanan Bütçe</th>'; ?>
                                <?php if(in_array('budget', $visible)) echo '<th>Harcanan Bütçe</th>'; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($items as $item): 
                                $meta = get_post_meta($item->ID);
                                $media = $meta['_scm_media'][0] ?? '-';
                                $loc = $meta['_scm_location'][0] ?? '-';
                                $desc = $meta['_scm_description'][0] ?? '';
                            ?>
                            <tr>
                                <td style="min-width: 200px;">
                                    <div class="scm-item-title"><?php echo $item->post_title; ?></div>
                                    
                                    <?php if(isset($meta['_scm_last_updated'][0])): ?>
                                        <div class="scm-meta" style="font-size: 0.75rem; color: #95a5a6; margin-bottom: 4px;">
                                            Güncelleme: <?php echo date('d.m.Y', strtotime($meta['_scm_last_updated'][0])); ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if(in_array('desc', $visible) && !empty($desc)): ?>
                                        <div class="scm-desc"><?php echo nl2br(esc_html($desc)); ?></div>
                                    <?php endif; ?>

                                    <?php if(in_array('dates', $visible)): 
                                        $s = $meta['_scm_start_date'][0] ?? '';
                                        $e = $meta['_scm_end_date'][0] ?? '';
                                        if($s || $e): ?>
                                        <div class="scm-dates">
                                            <?php if($s) echo date('d.m.Y', strtotime($s)); ?> 
                                            <?php if($e) echo ' - ' . date('d.m.Y', strtotime($e)); ?>
                                        </div>
                                    <?php endif; endif; ?>
                                </td>

                                <?php if(in_array('media', $visible)): ?>
                                    <td><span class="scm-tag"><?php echo esc_html($media); ?></span></td>
                                <?php endif; ?>

                                <?php if(in_array('location', $visible)): ?>
                                    <td><?php echo esc_html($loc); ?></td>
                                <?php endif; ?>

                                <?php if($show_targets): ?>
                                    <td style="min-width:220px;">
                                        <?php 
                                        $see_goals = in_array('target_goals', $visible) || in_array('targets', $visible);
                                        $see_reals = in_array('target_reals', $visible) || in_array('targets', $visible);

                                        if(!empty($meta['_scm_target_p_type'][0])): 
                                            $p_type = $meta['_scm_target_p_type'][0];
                                            $is_percent = in_array($p_type, ['ctr', 'vcr', 'viewability']);
                                            $decimals = $is_percent ? 2 : 0;
                                            $suffix = $is_percent ? '%' : '';
                                        ?>
                                            <div class="scm-target scm-primary">
                                                <div class="scm-target-title"><?php echo ucfirst($p_type); ?> (Birincil)</div>
                                                <?php if($see_goals): ?>
                                                <div class="scm-target-row">
                                                    <span class="scm-label-text">Hedef:</span>
                                                    <span class="scm-val"><?php echo $this->tr_num($meta['_scm_target_p_val'][0], $decimals, $suffix); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if($see_reals && !empty($meta['_scm_target_p_real'][0])): ?>
                                                <div class="scm-target-row">
                                                    <span class="scm-label-text">Gerçekleşen:</span>
                                                    <span class="scm-val scm-bold"><?php echo $this->tr_num($meta['_scm_target_p_real'][0], $decimals, $suffix); ?></span>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php 
                                        if(!empty($meta['_scm_target_s_type'][0])): 
                                            $s_type = $meta['_scm_target_s_type'][0];
                                            $is_percent = in_array($s_type, ['ctr', 'vcr', 'viewability']);
                                            $decimals = $is_percent ? 2 : 0;
                                            $suffix = $is_percent ? '%' : '';
                                        ?>
                                            <div class="scm-target scm-secondary">
                                                <div class="scm-target-title"><?php echo ucfirst($s_type); ?> (İkincil)</div>
                                                <?php if($see_goals): ?>
                                                <div class="scm-target-row">
                                                    <span class="scm-label-text">Hedef:</span>
                                                    <span class="scm-val"><?php echo $this->tr_num($meta['_scm_target_s_val'][0], $decimals, $suffix); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if($see_reals && !empty($meta['_scm_target_s_real'][0])): ?>
                                                <div class="scm-target-row">
                                                    <span class="scm-label-text">Gerçekleşen:</span>
                                                    <span class="scm-val scm-bold"><?php echo $this->tr_num($meta['_scm_target_s_real'][0], $decimals, $suffix); ?></span>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>

                                <?php if(in_array('impressions', $visible)): ?>
                                    <td><?php echo $this->tr_num($meta['_scm_impressions'][0] ?? 0, 0); ?></td>
                                <?php endif; ?>

                                <?php if(in_array('clicks', $visible)): ?>
                                    <td><?php echo $this->tr_num($meta['_scm_clicks'][0] ?? 0, 0); ?></td>
                                <?php endif; ?>

                                <?php if(in_array('ctr', $visible)): ?>
                                    <td><strong>%<?php echo $this->tr_num($meta['_scm_ctr'][0] ?? 0, 2); ?></strong></td>
                                <?php endif; ?>

                                <?php if(in_array('unit_cost', $visible)): ?>
                                    <td><?php echo $this->tr_num($meta['_scm_unit_cost'][0] ?? 0, 2, ' TL'); ?></td>
                                <?php endif; ?>

                                <?php if(in_array('planned_budget', $visible)): ?>
                                    <td><?php echo $this->tr_num($meta['_scm_planned_budget'][0] ?? 0, 2, ' TL'); ?></td>
                                <?php endif; ?>

                                <?php if(in_array('budget', $visible)): ?>
                                    <td><?php echo $this->tr_num($meta['_scm_budget'][0] ?? 0, 2, ' TL'); ?></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            <?php endforeach; wp_reset_postdata(); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function print_styles() {
        ?>
        <style>
            .scm-wrapper { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif; font-size: 13px; max-width: 1200px; margin: 0 auto; }
            
            /* TOP BAR STYLES */
            .scm-top-bar { 
                background: #fff; 
                padding: 15px 20px; 
                border-radius: 8px; 
                border: 1px solid #e2e4e7; 
                margin-bottom: 25px; 
                display: flex; 
                justify-content: space-between; 
                align-items: center; 
                box-shadow: 0 2px 4px rgba(0,0,0,0.03);
                flex-wrap: wrap;
                gap: 15px;
            }
            .scm-welcome { font-size: 1.1rem; color: #333; }
            .scm-wallet-actions { display: flex; align-items: center; gap: 20px; }
            .scm-balance-box { font-size: 1rem; color: #555; background: #f8f9fa; padding: 8px 15px; border-radius: 6px; border: 1px solid #eee; }
            .scm-balance-amount { font-weight: 700; color: #2ecc71; font-size: 1.1rem; margin-left: 5px; }
            .scm-btn-pay { 
                background: #0073aa; color: #fff; padding: 8px 16px; border-radius: 4px; text-decoration: none; font-weight: 600; transition: background 0.2s; 
                display: inline-block;
            }
            .scm-btn-pay:hover { background: #005177; color: #fff; }

            /* Menü Özel Stilleri */
            .scm-menu-pay a:hover { opacity: 0.9; }

            .scm-card { background: #fff; border: 1px solid #e2e4e7; border-radius: 8px; margin-bottom: 30px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); overflow: hidden; }
            .scm-header { background: #f8f9fa; padding: 15px 20px; border-bottom: 1px solid #e2e4e7; display: flex; justify-content: space-between; align-items: center; }
            .scm-header h2 { margin: 0; font-size: 1.1rem; color: #2c3e50; font-weight: 700; }
            .scm-table { width: 100%; border-collapse: collapse; }
            .scm-table th { background: #fff; text-align: left; padding: 12px 15px; border-bottom: 2px solid #eef2f7; color: #7f8c8d; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; white-space: nowrap; }
            .scm-table td { padding: 12px 15px; border-bottom: 1px solid #f0f0f0; color: #34495e; font-size: 0.9rem; vertical-align: top; }
            .scm-table tr:hover { background-color: #fbfbfb; }
            
            .scm-item-title { font-weight: 600; color: #2c3e50; margin-bottom: 4px; font-size: 1rem; }
            .scm-desc { font-size: 0.8rem; color: #7f8c8d; line-height: 1.4; margin-bottom: 6px; font-style: italic; }
            .scm-dates { font-size: 0.75rem; color: #95a5a6; background: #f8f9fa; display: inline-block; padding: 2px 6px; border-radius: 4px; border: 1px solid #eee; }
            .scm-tag { background: #e8f5e9; color: #2e7d32; padding: 3px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 500; }
            .scm-badge-blue { background: #e3f2fd; color: #1565c0; padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; }
            
            /* Hedef Kartları Tasarımı */
            .scm-target { margin-bottom: 8px; background: #fcfcfc; padding: 8px; border-radius: 4px; border: 1px solid #e5e5e5; }
            .scm-primary { border-left: 3px solid #0073aa; }
            .scm-secondary { border-left: 3px solid #46b450; }
            .scm-target-title { font-weight: 700; font-size: 0.8rem; margin-bottom: 5px; color: #444; border-bottom: 1px solid #eee; padding-bottom: 3px; }
            .scm-target-row { display: flex; justify-content: space-between; margin-bottom: 3px; font-size: 0.85rem; }
            .scm-label-text { color: #666; font-size: 0.8rem; }
            .scm-val { color: #333; }
            .scm-bold { font-weight: 700; color: #000; }

            .table-responsive { overflow-x: auto; }
            .scm-alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
            .scm-info { background-color: #e1f5fe; color: #0277bd; }
            .scm-error { background-color: #ffebee; color: #c62828; }
        </style>
        <?php
    }
}

new Pro_SCM_Frontend();