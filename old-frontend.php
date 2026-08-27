<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Pro_SCM_Frontend {

    public function __construct() {
        add_shortcode( 'client_dashboard', array( $this, 'render_dashboard' ) );
        
        add_filter( 'wp_nav_menu_items', array( $this, 'add_items_to_header_menu' ), 10, 2 );
        
        add_action('wp_head', array($this, 'print_global_styles'));
    }

    public function add_items_to_header_menu( $items, $args ) {
        if ( ! is_user_logged_in() ) return $items;


        $user_id = get_current_user_id();
        
        // Hesaplamaları Yap
        $global_payment = (float)get_option('cm_global_total_payment', 0);
        
        // Kullanıcının yetkili olduğu kampanyaların toplam harcamasını bul
        $total_spent_user = 0;
        $all_posts = get_posts(['post_type' => 'campaign', 'posts_per_page' => -1, 'post_status' => 'publish']);
        
        foreach ($all_posts as $post) {
            $assigned = get_post_meta($post->ID, '_scm_assigned_users', true) ?: [];
            if (in_array($user_id, $assigned) || current_user_can('manage_options')) {
                $total_spent_user += (float)get_post_meta($post->ID, 'total_spent', true);
            }
        }

        $balance = $global_payment - $total_spent_user;
        $pay_link = get_user_meta($user_id, '_scm_payment_url', true);
        
        $balance_html = '<span class="cm-menu-label">Kalan:</span> <span class="cm-menu-val">'.number_format($balance, 2, ',', '.').' ₺</span>';
        
        // 1. Bakiye Kutusu
        $items .= '<li class="menu-item cm-menu-item cm-menu-balance"><a>' . $balance_html . '</a></li>';

        // 2. Ödeme Butonu
        if($pay_link) {
            $items .= '<li class="menu-item cm-menu-item cm-menu-btn"><a href="'.esc_url($pay_link).'" target="_blank">Ödeme Yap</a></li>';
        }

        return $items;
    }

    /**
     * YARDIMCI: Sayı Formatlama
     */
    private function tr_num($num, $decimals = 0, $suffix = '') {
        $formatted = number_format((float)$num, $decimals, ',', '.');
        return '<span class="cm-num">' . $formatted . '</span>' . ($suffix ? ' <span class="cm-suffix">'.$suffix.'</span>' : '');
    }

    /**
     * 2. DASHBOARD RENDER (TABLO TASARIMI)
     */
    public function render_dashboard() {
        if ( ! is_user_logged_in() ) return '<div class="cm-alert cm-error">Raporları görüntülemek için lütfen giriş yapınız.</div>';

        $user_id = get_current_user_id();
        $global_payment = (float)get_option('cm_global_total_payment', 0);
        
        // Kampanyaları ve Verileri Hazırla
        $args = [ 'post_type' => 'campaign', 'posts_per_page' => -1, 'post_status' => 'publish' ];
        $all_posts = get_posts($args);
        $my_campaigns = [];
        $total_spent_user = 0;
        $all_countries = [];

        foreach ($all_posts as $post) {
            $assigned = get_post_meta($post->ID, '_scm_assigned_users', true) ?: [];
            if (in_array($user_id, $assigned) || current_user_can('manage_options')) {
                $my_campaigns[] = $post;
                $spent = (float)get_post_meta($post->ID, 'total_spent', true);
                $total_spent_user += $spent;

                // Filtre için ülkeleri topla
                $rows = get_post_meta($post->ID, 'campaign_rows', true);
                if($rows) {
                    foreach($rows as $r) {
                        if(!empty($r['ulke'])) $all_countries[$r['ulke']] = $r['ulke'];
                    }
                }
            }
        }

        // Genel Bakiye Hesabı
        $balance = $global_payment - $total_spent_user;
        $balance_percent = ($global_payment > 0) ? ($total_spent_user / $global_payment) * 100 : 0;
        $balance_color = ($balance_percent > 90) ? '#e74c3c' : '#2ecc71';

        // Son Güncelleme Zamanını Çek
        $last_sync = get_option('cm_last_successful_sync_time');
        $sync_text = $last_sync ? date_i18n('d F Y H:i', strtotime($last_sync)) : 'Henüz güncellenmedi';

        ob_start();
        ?>
        
        <div class="cm-header-card">
                <div class="cm-header-top">
                    <div class="cm-welcome">
                        <span class="cm-welcome-label">Hoşgeldiniz,</span>
                        <h2 class="cm-user-name"><?php echo wp_get_current_user()->display_name; ?></h2>
                        
                        <div class="cm-sync-badge">
                            <span class="dashicons dashicons-clock"></span> 
                            Son Güncelleme: <strong><?php echo $sync_text; ?></strong>
                        </div>
                    </div>
                </div>

                <div class="cm-stats-row">
                    <div class="cm-stat-item">
                        <span class="cm-stat-icon" style="background:rgba(52, 152, 219, 0.1); color:#3498db;"><span class="dashicons dashicons-wallet"></span></span>
                        <div class="cm-stat-info">
                            <span class="cm-stat-label">Toplam Bakiye</span>
                            <div class="cm-stat-value"><?php echo $this->tr_num($global_payment, 2, '₺'); ?></div>
                        </div>
                    </div>
                    <div class="cm-stat-item">
                        <span class="cm-stat-icon" style="background:rgba(231, 76, 60, 0.1); color:#e74c3c;"><span class="dashicons dashicons-chart-line"></span></span>
                        <div class="cm-stat-info">
                            <span class="cm-stat-label">Harcanan</span>
                            <div class="cm-stat-value"><?php echo $this->tr_num($total_spent_user, 2, '₺'); ?></div>
                        </div>
                    </div>
                    <div class="cm-stat-item cm-highlight">
                        <span class="cm-stat-icon" style="background:#e8f5e9; color:#27ae60;"><span class="dashicons dashicons-yes"></span></span>
                        <div class="cm-stat-info">
                            <span class="cm-stat-label">Kalan</span>
                            <div class="cm-stat-value text-green"><?php echo $this->tr_num($balance, 2, '₺'); ?></div>
                        </div>
                    </div>
                </div>

                <div class="cm-balance-progress">
                    <div class="cm-progress-labels">
                        <span>Bütçe Kullanımı</span>
                        <span>%<?php echo number_format($balance_percent, 1); ?></span>
                    </div>
                    <div class="cm-progress-track">
                        <div class="cm-progress-fill" style="width: <?php echo min(100, $balance_percent); ?>%; background: <?php echo $balance_color; ?>;"></div>
                    </div>
                </div>
            </div>

            <div class="cm-filters-area">
                <div class="cm-filter-group">
                    <span class="dashicons dashicons-filter"></span>
                    <select id="cm-filter-campaign">
                        <option value="">Tüm Kampanyalar</option>
                        <?php foreach($my_campaigns as $c): ?>
                            <option value="<?php echo $c->ID; ?>"><?php echo get_the_title($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="cm-filter-group">
                    <span class="dashicons dashicons-admin-site"></span>
                    <select id="cm-filter-country">
                        <option value="">Tüm Ülkeler</option>
                        <?php foreach($all_countries as $country): ?>
                            <option value="<?php echo esc_attr($country); ?>"><?php echo esc_html($country); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="cm-campaigns-wrapper">
                <?php foreach($my_campaigns as $camp): 
                    $rows = get_post_meta($camp->ID, 'campaign_rows', true);
                    if(!$rows) continue;

                    // Kampanya Toplam Verilerini Çek
                    $t_spent = (float)get_post_meta($camp->ID, 'total_spent', true);
                    $t_plan  = (float)get_post_meta($camp->ID, 'total_planned', true);
                    $t_imp   = (float)get_post_meta($camp->ID, 'total_imp', true);
                    $t_clk   = (float)get_post_meta($camp->ID, 'total_clk', true);
                    
                    $real_total_ctr = ($t_imp > 0) ? ($t_clk / $t_imp) * 100 : 0;
                    $real_total_cpm = ($t_imp > 0) ? ($t_spent / ($t_imp / 1000)) : 0;
                    $real_total_cpc = ($t_clk > 0) ? ($t_spent / $t_clk) : 0;

                    $c_percent = ($t_plan > 0) ? ($t_spent / $t_plan) * 100 : 0;
                ?>
                
                <div class="cm-campaign-card" data-id="<?php echo $camp->ID; ?>">
                    <div class="cm-card-header">
                        <div class="cm-card-title">
                            <h3><?php echo get_the_title($camp); ?></h3>
                            <span class="cm-badge">Aktif</span>
                        </div>
                        <div class="cm-card-meta">
                            <div class="cm-meta-item">
                                <span class="label">Planlanan</span>
                                <span class="val"><?php echo $this->tr_num($t_plan, 0, '₺'); ?></span>
                            </div>
                            <div class="cm-meta-item">
                                <span class="label">Harcanan</span>
                                <span class="val text-blue"><?php echo $this->tr_num($t_spent, 0, '₺'); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="cm-card-progress">
                        <div class="cm-bar" style="width: <?php echo min(100, $c_percent); ?>%; background:#3498db;"></div>
                    </div>

                    <div class="cm-table-responsive">
                        <table class="cm-table">
                            <thead>
                                <tr>
                                    <th>Ülke / Mecra</th>
                                    <th>Tarih</th>
                                    <th class="text-right">Impression</th>
                                    <th class="text-right">Click</th>
                                    <th class="text-center">CTR</th>
                                    <th class="text-right">Maliyetler</th>
                                    <th class="text-right">Harcama</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($rows as $r): 
                                    $row_percent = ($r['planned'] > 0) ? ($r['spent'] / $r['planned']) * 100 : 0;
                                    
                                    $raw_ctr = str_replace('%', '', $r['ctr']);
                                ?>
                                <tr class="cm-data-row" data-country="<?php echo esc_attr($r['ulke']); ?>">
                                    <td class="cm-cell-main">
                                        <div class="cm-country-badge"><?php echo esc_html($r['ulke']); ?></div>
                                        <div class="cm-media-name"><?php echo esc_html($r['mecra']); ?></div>
                                    </td>
                                    <td class="cm-cell-date">
                                        <?php echo $r['baslangic']; ?><br>
                                        <span class="text-muted"><?php echo $r['bitis']; ?></span>
                                    </td>
                                    <td class="text-right" data-label="Impression">
                                        <span class="cm-val-bold"><?php echo $this->tr_num($r['imp']); ?></span>
                                    </td>
                                    <td class="text-right" data-label="Click">
                                        <span class="cm-val-bold"><?php echo $this->tr_num($r['click']); ?></span>
                                    </td>
                                    <td class="text-center" data-label="CTR">
                                        <div class="cm-ctr-badge">%<?php echo $raw_ctr; ?></div>
                                    </td>
                                    <td class="text-right cm-cell-costs" data-label="Birim Maliyet">
                                        <div>CPM: <strong><?php echo $r['cpm']; ?>₺</strong></div>
                                        <div>CPC: <strong><?php echo $r['cpc']; ?>₺</strong></div>
                                    </td>
                                    <td class="text-right" data-label="Harcama">
                                        <div class="cm-spent-val"><?php echo $this->tr_num($r['spent'], 2, '₺'); ?></div>
                                        <div class="cm-mini-progress">
                                            <div class="fill" style="width:<?php echo min(100, $row_percent); ?>%"></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            
                            <tfoot>
                                <tr class="cm-total-row">
                                    <td colspan="2">GENEL TOPLAM</td>
                                    <td class="text-right"><?php echo $this->tr_num($t_imp); ?></td>
                                    <td class="text-right"><?php echo $this->tr_num($t_clk); ?></td>
                                    <td class="text-center">%<?php echo number_format($real_total_ctr, 2); ?></td>
                                    <td class="text-right" style="font-size:11px;">
                                        CPM: <?php echo number_format($real_total_cpm, 2); ?>₺<br>
                                        CPC: <?php echo number_format($real_total_cpc, 2); ?>₺
                                    </td>
                                    <td class="text-right text-blue"><?php echo $this->tr_num($t_spent, 2, '₺'); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div id="cm-no-results" style="display:none; text-align:center; padding:40px; color:#999;">
                <span class="dashicons dashicons-search" style="font-size:40px;"></span>
                <p>Kriterlere uygun sonuç bulunamadı.</p>
            </div>

        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function(){
            const campFilter = document.getElementById('cm-filter-campaign');
            const countryFilter = document.getElementById('cm-filter-country');
            const campaigns = document.querySelectorAll('.cm-campaign-card');
            const noRes = document.getElementById('cm-no-results');

            function filterApp() {
                const sCamp = campFilter.value;
                const sCountry = countryFilter.value;
                let hasVisible = false;

                campaigns.forEach(card => {
                    const cardId = card.getAttribute('data-id');
                    const rows = card.querySelectorAll('.cm-data-row');
                    
                    let showCard = true;
                    if(sCamp && cardId !== sCamp) showCard = false;

                    let visibleRows = 0;
                    rows.forEach(row => {
                        const rCountry = row.getAttribute('data-country');
                        if(sCountry && rCountry !== sCountry) {
                            row.style.display = 'none';
                        } else {
                            row.style.display = '';
                            visibleRows++;
                        }
                    });

                    if(sCountry && visibleRows === 0) showCard = false;
                    
                    const foot = card.querySelector('tfoot');
                    if(foot) foot.style.display = (sCountry) ? 'none' : '';

                    if(showCard) {
                        card.style.display = 'block';
                        hasVisible = true;
                    } else {
                        card.style.display = 'none';
                    }
                });

                noRes.style.display = hasVisible ? 'none' : 'block';
            }

            campFilter.addEventListener('change', filterApp);
            countryFilter.addEventListener('change', filterApp);
        });
        </script>
        <?php
        return ob_get_clean();
    }

    public function print_global_styles() {
        ?>
        <style>
            .cm-menu-item { margin-left: 10px; display: inline-flex; align-items: center; }
            .cm-menu-balance a { background: #f0fdf4; border: 1px solid #dcfce7; border-radius: 6px; padding: 6px 12px !important; color: #166534 !important; font-weight: 600; line-height: 1.2; }
            .cm-menu-val { font-weight: 800; color: #15803d; }
            .cm-menu-label { font-size: 0.85em; opacity: 0.8; margin-right: 4px; }
            
            .cm-menu-btn a { background: #2c3e50 !important; color: #fff !important; border-radius: 6px; padding: 6px 15px !important; transition: all 0.2s; font-weight: 600; }
            .cm-menu-btn a:hover { background: #34495e !important; transform: translateY(-1px); }

            .cm-dashboard-container { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 1200px; margin: 20px auto; color: #333; line-height: 1.5; }
            .cm-dashboard-container * { box-sizing: border-box; }
            .cm-num { font-weight: 600; } .cm-suffix { font-size: 0.85em; opacity: 0.7; }
            .text-right { text-align: right; } .text-center { text-align: center; }
            .text-green { color: #27ae60; } .text-blue { color: #2980b9; } .text-muted { color: #999; font-size: 0.9em; }

            .cm-header-card { background: #fff; border-radius: 12px; padding: 25px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); margin-bottom: 30px; }
            .cm-header-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
            .cm-welcome-label { font-size: 13px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; }
            .cm-user-name { margin: 0; font-size: 24px; color: #2c3e50; font-weight: 700; }
            
            .cm-stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 25px; }
            .cm-stat-item { background: #f8f9fa; padding: 15px; border-radius: 10px; display: flex; align-items: center; gap: 15px; border: 1px solid #eee; }
            .cm-stat-item.cm-highlight { background: #f0fdf4; border-color: #dcfce7; }
            .cm-stat-icon { width: 45px; height: 45px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
            .cm-stat-info { display: flex; flex-direction: column; }
            .cm-stat-label { font-size: 12px; color: #777; font-weight: 600; text-transform: uppercase; }
            .cm-stat-value { font-size: 18px; font-weight: 700; color: #2c3e50; }

            .cm-balance-progress { background: #f1f2f6; border-radius: 20px; padding: 4px; position: relative; height: 28px; }
            .cm-progress-labels { position: absolute; width: 100%; top: 0; left: 0; height: 100%; display: flex; justify-content: space-between; align-items: center; padding: 0 15px; font-size: 11px; font-weight: 700; color: #555; z-index: 2; pointer-events: none; }
            .cm-progress-track { width: 100%; height: 100%; background: transparent; border-radius: 16px; overflow: hidden; position: relative; }
            .cm-progress-fill { height: 100%; border-radius: 16px; transition: width 1s ease-in-out; opacity: 0.25; }

            .cm-filters-area { display: flex; gap: 15px; margin-bottom: 25px; flex-wrap: wrap; }
            .cm-filter-group { position: relative; flex: 1; min-width: 200px; }
            .cm-filter-group .dashicons { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #999; pointer-events: none; }
            .cm-filter-group select { width: 100%; padding: 12px 15px 12px 40px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; background: #fff; cursor: pointer; }

            .cm-campaign-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); margin-bottom: 30px; border: 1px solid #eee; overflow: hidden; }
            .cm-card-header { padding: 20px 25px; border-bottom: 1px solid #f5f5f5; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; background: #fafafa; }
            .cm-card-title h3 { margin: 0; font-size: 18px; color: #2c3e50; font-weight: 700; display: inline-block; vertical-align: middle; }
            .cm-badge { background: #e3f2fd; color: #1976d2; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; margin-left: 10px; vertical-align: middle; }
            .cm-card-meta { display: flex; gap: 20px; font-size: 13px; }
            .cm-meta-item { display: flex; flex-direction: column; align-items: flex-end; }
            .cm-meta-item .label { font-size: 10px; color: #999; text-transform: uppercase; font-weight: 600; }
            .cm-meta-item .val { font-weight: 700; color: #444; font-size: 15px; }

            .cm-card-progress { height: 4px; background: #eee; width: 100%; }
            .cm-card-progress .cm-bar { height: 100%; transition: width 0.5s ease; }

            .cm-table-responsive { width: 100%; overflow-x: auto; }
            .cm-table { width: 100%; border-collapse: collapse; min-width: 800px; }
            .cm-table th { background: #fff; padding: 15px 20px; text-align: left; font-size: 12px; color: #999; font-weight: 600; text-transform: uppercase; border-bottom: 2px solid #f0f0f0; white-space: nowrap; }
            .cm-table td { padding: 15px 20px; border-bottom: 1px solid #f9f9f9; font-size: 14px; color: #444; vertical-align: middle; }
            
            .cm-country-badge { font-weight: 700; color: #2c3e50; font-size: 15px; margin-bottom: 2px; }
            .cm-media-name { font-size: 12px; color: #888; }
            .cm-val-bold { font-weight: 600; color: #222; }
            .cm-ctr-badge { background: #f0f0f0; padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: 700; display: inline-block; color: #555; }
            .cm-spent-val { font-weight: 700; color: #2980b9; font-size: 15px; }
            .cm-mini-progress { height: 3px; background: #eee; border-radius: 2px; margin-top: 5px; width: 100px; margin-left: auto; overflow: hidden; }
            .cm-mini-progress .fill { height: 100%; background: #2980b9; }
            
            .cm-total-row { background: #fcfcfc; font-weight: 700; border-top: 2px solid #eee; }
            .cm-total-row td { color: #2c3e50; padding: 20px; font-size: 15px; }
            .cm-sync-badge {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                background: #f0f0f1;
                color: #646970;
                padding: 4px 10px;
                border-radius: 4px;
                font-size: 11px;
                margin-top: 8px;
                border: 1px solid #dcdcde;
            }
            .cm-sync-badge .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
            }
            /* MOBILE RESPONSIVE */
            @media (max-width: 768px) {
                .cm-header-top { flex-direction: column; align-items: flex-start; gap: 15px; }
                .cm-stats-row { grid-template-columns: 1fr; }
                .cm-filters-area { flex-direction: column; }
                .cm-filter-group { width: 100%; }

                .cm-table, .cm-table tbody, .cm-table tr, .cm-table td, .cm-table th { display: block; width: 100%; }
                .cm-table thead { display: none; }
                .cm-table tr { margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 15px; position: relative; }
                .cm-table td { padding: 8px 20px; text-align: right; border: none; display: flex; justify-content: space-between; align-items: center; }
                .cm-table td:before { content: attr(data-label); float: left; font-weight: 600; color: #999; font-size: 12px; text-transform: uppercase; margin-right: auto; }
                
                .cm-table td.cm-cell-main { text-align: left; background: #f9f9f9; padding: 15px 20px; border-radius: 8px; margin: 0 15px 10px 15px; width: auto; display: block; }
                .cm-table td.cm-cell-main:before { display: none; }
                .cm-table td.cm-cell-date { display: none; }
                .cm-mini-progress { margin-left: 0; width: 100%; margin-top: 5px; }
                .cm-table td[data-label="Harcama"] { flex-direction: column; align-items: flex-end; }
                .cm-total-row td { text-align: center; display: block; padding: 10px; }
            }
        </style>
        <?php
    }
}

new Pro_SCM_Frontend();