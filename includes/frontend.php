<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Pro_SCM_Frontend {

    public function __construct() {
        // Dashboard Shortcode
        add_shortcode( 'client_dashboard', array( $this, 'render_dashboard' ) );

        // Header Menü
        add_filter( 'wp_nav_menu_items', array( $this, 'add_items_to_header_menu' ), 10, 2 );

        // CSS ve JS Yüklemeleri
        add_action('wp_enqueue_scripts', array( $this, 'enqueue_datatables_assets' ));
        add_action('wp_head', array( $this, 'print_global_styles' ));
    }

    /**
     * DATATABLES ASSETS
     */
    public function enqueue_datatables_assets() {
        // DataTables Core (ScrollX için ekstra eklenti gerekmez)
        wp_enqueue_style('cm-datatables-css', 'https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css');
        wp_enqueue_script('cm-datatables-js', 'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js', array('jquery'), null, true);

        // NOT: Responsive eklentisini bilerek kaldırdık. Çünkü mobilde kolonları gizliyor/collapse ediyor.
        // Eğer başka sayfalarda kullanıyorsan ekleyebilirsin ama bu dashboard için önerim kapalı kalması.
        // wp_enqueue_style('cm-responsive-css', 'https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css');
        // wp_enqueue_script('cm-responsive-js', 'https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js', array('cm-datatables-js'), null, true);
    }

    /**
     * HEADER MENÜSÜNE BAKİYE VE ÖDEME EKLEME
     */
    public function add_items_to_header_menu( $items, $args ) {
        if ( ! is_user_logged_in() ) return $items;

        $user_id = get_current_user_id();
        $global_payment = (float)get_option('cm_global_total_payment', 0);

        $total_spent_user = 0;
        $all_posts = get_posts([
            'post_type'      => 'campaign',
            'posts_per_page' => -1,
            'post_status'    => 'publish'
        ]);

        foreach ($all_posts as $post) {
            $assigned = get_post_meta($post->ID, '_scm_assigned_users', true) ?: [];
            if (in_array($user_id, $assigned) || current_user_can('manage_options')) {
                $total_spent_user += (float)get_post_meta($post->ID, 'total_spent', true);
            }
        }

        $balance  = $global_payment - $total_spent_user;
        $pay_link = get_user_meta($user_id, '_scm_payment_url', true);

        $balance_html = '<span class="cm-menu-label">Kalan:</span> <span class="cm-menu-val">'.number_format($balance, 2, ',', '.').' ₺</span>';
        $items .= '<li class="menu-item cm-menu-item cm-menu-balance"><a>' . $balance_html . '</a></li>';

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
     * DASHBOARD RENDER
     */
    public function render_dashboard() {
        if ( ! is_user_logged_in() ) {
            return '<div class="cm-alert cm-error">Raporları görüntülemek için lütfen giriş yapınız.</div>';
        }

        $user_id        = get_current_user_id();
        $global_payment = (float)get_option('cm_global_total_payment', 0);

        $args = [ 'post_type' => 'campaign', 'posts_per_page' => -1, 'post_status' => 'publish' ];
        $all_posts = get_posts($args);

        $my_campaigns     = [];
        $total_spent_user = 0;

        // Global filtre seçenekleri için
        $country_set      = [];
        $campaign_options = []; // [id => title]

        foreach ($all_posts as $post) {
            $assigned = get_post_meta($post->ID, '_scm_assigned_users', true) ?: [];
            if (in_array($user_id, $assigned) || current_user_can('manage_options')) {
                $my_campaigns[] = $post;

                $spent = (float)get_post_meta($post->ID, 'total_spent', true);
                $total_spent_user += $spent;

                $campaign_options[$post->ID] = get_the_title($post);

                // Ülke setini topla
                $rows = get_post_meta($post->ID, 'campaign_rows', true);
                if (is_array($rows)) {
                    foreach ($rows as $r) {
                        if (!empty($r['ulke'])) {
                            $country_set[trim($r['ulke'])] = true;
                        }
                    }
                }
            }
        }

        $countries = array_keys($country_set);
        sort($countries, SORT_NATURAL | SORT_FLAG_CASE);

        // Kampanya options title'a göre sırala
        asort($campaign_options, SORT_NATURAL | SORT_FLAG_CASE);

        $balance         = $global_payment - $total_spent_user;
        $balance_percent = ($global_payment > 0) ? ($total_spent_user / $global_payment) * 100 : 0;
        $balance_color   = ($balance_percent > 90) ? '#e74c3c' : '#2ecc71';

        $last_sync = get_option('cm_last_successful_sync_time');
        $sync_text = $last_sync ? date_i18n('d F Y H:i', strtotime($last_sync)) : 'Bekleniyor...';

        ob_start();
        ?>

        <div class="cm-dashboard-container">

            <div class="cm-header-card">
                <div class="cm-header-top">
                    <div class="cm-welcome">
                        <span class="cm-welcome-label">Hoşgeldiniz,</span>
                        <h2 class="cm-user-name"><?php echo wp_get_current_user()->display_name; ?></h2>
                        <div class="cm-sync-badge">
                            <span class="dashicons dashicons-clock"></span>
                            Güncelleme: <strong><?php echo $sync_text; ?></strong>
                        </div>
                    </div>
                </div>

                <div class="cm-stats-row">
                    <div class="cm-stat-item">
                        <span class="cm-stat-label">Toplam Bakiye</span>
                        <div class="cm-stat-value"><?php echo $this->tr_num($global_payment, 2, '₺'); ?></div>
                    </div>
                    <div class="cm-stat-item">
                        <span class="cm-stat-label">Harcanan</span>
                        <div class="cm-stat-value"><?php echo $this->tr_num($total_spent_user, 2, '₺'); ?></div>
                    </div>
                    <div class="cm-stat-item cm-highlight">
                        <span class="cm-stat-label">Kalan</span>
                        <div class="cm-stat-value text-green"><?php echo $this->tr_num($balance, 2, '₺'); ?></div>
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

            <!-- ✅ GLOBAL FİLTRE BAR (cm-header-card'ın hemen altı) -->
            <div class="cm-global-filters">
                <div class="cm-filter-title">
                    <span class="dashicons dashicons-filter"></span>
                    Genel Filtreler
                </div>

                <div class="cm-filter-grid">
                    <div class="cm-filter-item">
                        <label for="cmFilterCampaign">Kampanya</label>
                        <select id="cmFilterCampaign" class="cm-filter-select">
                            <option value="">Tümü</option>
                            <?php foreach($campaign_options as $cid => $ctitle): ?>
                                <option value="<?php echo esc_attr($cid); ?>"><?php echo esc_html($ctitle); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="cm-filter-item">
                        <label for="cmFilterCountry">Ülke</label>
                        <select id="cmFilterCountry" class="cm-filter-select">
                            <option value="">Tümü</option>
                            <?php foreach($countries as $c): ?>
                                <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($c); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="cm-filter-item cm-filter-actions">
                        <button type="button" id="cmFilterReset" class="cm-filter-btn">Sıfırla</button>
                    </div>
                </div>

                <div class="cm-filter-hint">
                    Kampanya filtresi kartları daraltır; ülke filtresi tüm tablolarda satırları filtreler ve o ülkede yayını olmayan kampanyaları gizler.
                </div>
            </div>

            <div class="cm-campaigns-wrapper">
                <?php foreach($my_campaigns as $camp):
                    $rows = get_post_meta($camp->ID, 'campaign_rows', true);
                    if(!$rows) continue;

                    // Kampanya Toplam Verileri Meta'dan Al
                    $t_spent = (float)get_post_meta($camp->ID, 'total_spent', true);
                    $t_plan  = (float)get_post_meta($camp->ID, 'total_planned', true);
                    $t_imp   = (float)get_post_meta($camp->ID, 'total_imp', true);
                    $t_clk   = (float)get_post_meta($camp->ID, 'total_clk', true);

                    // --- GENEL TOPLAM SATIRI İÇİN MATEMATİKSEL HESAPLAMA ---
                    $real_total_ctr = ($t_imp > 0) ? ($t_clk / $t_imp) * 100 : 0;
                    $real_total_cpm = ($t_imp > 0) ? ($t_spent / ($t_imp / 1000)) : 0;
                    $real_total_cpc = ($t_clk > 0) ? ($t_spent / $t_clk) : 0;

                    $c_percent = ($t_plan > 0) ? ($t_spent / $t_plan) * 100 : 0;
                ?>

                <div class="cm-campaign-card" data-campaign-id="<?php echo esc_attr($camp->ID); ?>">
                    <div class="cm-card-header">
                        <div class="cm-card-title">
                            <h3><?php echo get_the_title($camp); ?></h3>
                            <span class="cm-badge">Aktif</span>
                        </div>
                        <div class="cm-card-meta">
                            <span class="meta-box">Planlanan: <strong><?php echo $this->tr_num($t_plan, 0, '₺'); ?></strong></span>
                            <span class="meta-box">Harcanan: <strong class="text-blue"><?php echo $this->tr_num($t_spent, 0, '₺'); ?></strong></span>
                        </div>
                    </div>

                    <div class="cm-card-progress">
                        <div class="cm-bar" style="width: <?php echo min(100, $c_percent); ?>%; background:#3498db;"></div>
                    </div>

                    <div class="cm-table-container">
                        <table class="cm-datatable display nowrap" style="width:100%">
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
                                    $raw_ctr = str_replace('%', '', $r['ctr']);
                                ?>
                                <tr>
                                    <td>
                                        <div class="cm-country-badge"><?php echo esc_html($r['ulke']); ?></div>
                                        <div class="cm-media-name"><?php echo esc_html($r['mecra']); ?></div>
                                    </td>
                                    <td>
                                        <?php echo esc_html($r['baslangic']); ?><br>
                                        <span class="text-muted"><?php echo esc_html($r['bitis']); ?></span>
                                    </td>
                                    <td class="text-right"><span class="cm-val-bold"><?php echo $this->tr_num($r['imp']); ?></span></td>
                                    <td class="text-right"><span class="cm-val-bold"><?php echo $this->tr_num($r['click']); ?></span></td>
                                    <td class="text-center">
                                        <div class="cm-ctr-badge">%<?php echo esc_html($raw_ctr); ?></div>
                                    </td>
                                    <td class="text-right" style="font-size:11px; line-height:1.4;">
                                        CPM: <?php echo esc_html($r['cpm']); ?>₺<br>
                                        CPC: <?php echo esc_html($r['cpc']); ?>₺
                                    </td>
                                    <td class="text-right text-blue">
                                        <span class="cm-val-bold"><?php echo $this->tr_num($r['spent'], 2, '₺'); ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>

                            <!-- ✅ Genel toplam hücresi ilk 2 kolonu span'liyor -->
                            <tfoot>
                                <tr class="cm-total-row">
                                    <td colspan="2" style="font-weight:800; color:#2c3e50;">GENEL TOPLAM:</td>

                                    <td class="text-right"><span class="cm-val-bold"><?php echo $this->tr_num($t_imp); ?></span></td>
                                    <td class="text-right"><span class="cm-val-bold"><?php echo $this->tr_num($t_clk); ?></span></td>

                                    <td class="text-center">
                                        <div class="cm-ctr-badge">%<?php echo number_format($real_total_ctr, 2); ?></div>
                                    </td>

                                    <td class="text-right" style="font-size:11px; line-height:1.4;">
                                        CPM: <?php echo number_format($real_total_cpm, 2); ?>₺<br>
                                        CPC: <?php echo number_format($real_total_cpc, 2); ?>₺
                                    </td>

                                    <td class="text-right text-blue">
                                        <span class="cm-val-bold" style="font-size:15px;"><?php echo $this->tr_num($t_spent, 2, '₺'); ?></span>
                                    </td>
                                </tr>
                            </tfoot>

                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($){

            var cmTables = [];
            var cmSelectedCountry = "";

            // ✅ Ülke filtresi: tüm DataTables'lar için global filtre
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                if (!cmSelectedCountry) return true;

                // 0. kolon: "Ülke / Mecra"
                var col0 = (data[0] || "").toString().toLowerCase();
                var target = cmSelectedCountry.toLowerCase();

                return col0.indexOf(target) !== -1;
            });

            // ✅ DataTables init (MOBİLDE TÜM KOLONLAR GÖRÜNSÜN: scrollX)
            $('.cm-datatable').each(function(){
                var dt = $(this).DataTable({
                    responsive: false,       // ✅ kolon gizleme yok
                    scrollX: true,           // ✅ yatay scroll
                    scrollCollapse: true,
                    autoWidth: false,
                    pageLength: 20,
                    lengthMenu: [ [10, 20, 50, -1], [10, 20, 50, "Tümü"] ],
                    language: {
                        url: "//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json",
                        search: "_INPUT_",
                        searchPlaceholder: "Tabloda ara..."
                    },
                    dom: '<"cm-table-top"lf>rt<"cm-table-bottom"ip>',
                    columnDefs: [
                        { targets: 0, width: 220 },
                        { targets: 1, width: 140 }
                    ]
                });

                cmTables.push(dt);
            });

            function applyCampaignDropdownAvailability(){
                var $sel = $('#cmFilterCampaign');

                // ülke seçili değilse tüm seçenekleri aç
                if(!cmSelectedCountry){
                    $sel.find('option').prop('disabled', false).show();
                    return;
                }

                // önce hepsini kapat, sonra uygun olanları aç
                $sel.find('option').each(function(){
                    var val = $(this).val();
                    if(val === "") { $(this).prop('disabled', false).show(); return; } // "Tümü"
                    $(this).prop('disabled', true).hide();
                });

                // ülkeye göre görünür kalan kartların campaign-id'lerini aç
                $('.cm-campaign-card').each(function(){
                    var $card = $(this);
                    if($card.hasClass('is-hidden-by-country')) return;

                    var cid = $card.data('campaign-id');
                    $sel.find('option[value="'+cid+'"]').prop('disabled', false).show();
                });

                // seçili kampanya artık geçersizse resetle
                var current = $sel.val();
                if(current && $sel.find('option[value="'+current+'"]:disabled').length){
                    $sel.val('');
                    $('.cm-campaign-card').removeClass('is-hidden'); // kampanya filtresi reset
                }
            }

            function redrawAllTables(){
                cmTables.forEach(function(dt){
                    dt.draw();
                });

                // ✅ ülke filtresinden sonra: satırı olmayan kampanya kartını gizle
                $('.cm-campaign-card').each(function(){
                    var $card = $(this);
                    var table = $card.find('table.cm-datatable');
                    if(!table.length) return;

                    var dt = table.DataTable();
                    var visibleCount = dt.rows({ filter: 'applied' }).count();

                    if(!cmSelectedCountry){
                        $card.removeClass('is-hidden-by-country');
                    } else {
                        $card.toggleClass('is-hidden-by-country', visibleCount === 0);
                    }
                });

                applyCampaignDropdownAvailability();

                // ScrollX hizası için bazen gerekebiliyor
                cmTables.forEach(function(dt){
                    dt.columns.adjust();
                });
            }

            // Kampanya filtresi: kartları show/hide (country hide'ı bozmadan)
            $('#cmFilterCampaign').on('change', function(){
                var id = $(this).val();

                if (!id) {
                    $('.cm-campaign-card').removeClass('is-hidden');
                } else {
                    $('.cm-campaign-card').each(function(){
                        var match = $(this).data('campaign-id').toString() === id.toString();
                        $(this).toggleClass('is-hidden', !match);
                    });
                }

                // Kampanya filtreledikten sonra da tablo genişlik ayarı
                cmTables.forEach(function(dt){
                    dt.columns.adjust();
                });
            });

            // Ülke filtresi: tüm tablolarda satır filtrele + uygun kampanyaları göster
            $('#cmFilterCountry').on('change', function(){
                cmSelectedCountry = $(this).val() || "";
                redrawAllTables();
            });

            // Reset
            $('#cmFilterReset').on('click', function(){
                $('#cmFilterCampaign').val('');
                $('#cmFilterCountry').val('');
                cmSelectedCountry = "";
                $('.cm-campaign-card').removeClass('is-hidden is-hidden-by-country');
                redrawAllTables();
            });

            // İlk load: dropdown availability + tablo adjust
            redrawAllTables();

            // Mobil rotate / resize'da hizayı koru
            $(window).on('resize', function(){
                cmTables.forEach(function(dt){
                    dt.columns.adjust();
                });
            });

        });
        </script>

        <?php
        return ob_get_clean();
    }

    public function print_global_styles() {
        ?>
        <style>
            /* --- RESET & LAYOUT --- */
            .cm-dashboard-container {
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                max-width: 1200px;
                width: 100%;
                margin: 30px auto;
                color: #1d2327;
                background: transparent;
                padding: 0 12px; /* ✅ mobilde taşmayı engeller */
            }
            .cm-dashboard-container * { box-sizing: border-box; }

            /* --- HEADER CARD --- */
            .cm-header-card {
                background: #fff;
                border-radius: 12px;
                padding: 30px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.04);
                margin-bottom: 18px;
                border: 1px solid #eaeaea;
            }
            .cm-header-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px; }
            .cm-user-name { margin: 0; font-size: 26px; font-weight: 800; color: #1d2327; letter-spacing: -0.5px; }
            .cm-welcome-label { font-size: 13px; color: #8c9094; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }
            .cm-sync-badge {
                display: inline-flex; align-items: center; gap: 6px;
                background: #f6f7f7; color: #50575e;
                padding: 6px 12px; border-radius: 20px; font-size: 12px;
                margin-top: 8px; border: 1px solid #dcdcde; font-weight: 500;
            }

            /* Stats Row */
            .cm-stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 25px; margin-bottom: 30px; }
            .cm-stat-item { background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #eaeaea; box-shadow: 0 2px 10px rgba(0,0,0,0.02); transition: transform 0.2s; }
            .cm-stat-item:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
            .cm-stat-item.cm-highlight { background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%); border-color: #bbf7d0; }
            .cm-stat-label { font-size: 11px; color: #646970; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 8px; display: block; }
            .cm-stat-value { font-size: 24px; font-weight: 800; color: #1d2327; letter-spacing: -0.5px; }

            /* Progress Bar */
            .cm-balance-progress { background: #f0f0f1; height: 32px; border-radius: 8px; position: relative; overflow: hidden; border: 1px solid #e5e5e5; }
            .cm-progress-labels { position: absolute; width: 100%; height: 100%; display: flex; justify-content: space-between; align-items: center; padding: 0 15px; font-size: 11px; font-weight: 700; color: #50575e; z-index: 2; }
            .cm-progress-track { height: 100%; }
            .cm-progress-fill { height: 100%; opacity: 0.2; transition: width 0.8s ease-out; }

            /* ✅ GLOBAL FILTER BAR */
            .cm-global-filters{
                background:#fff;
                border:1px solid #eaeaea;
                border-radius:12px;
                padding:18px 20px;
                box-shadow: 0 8px 24px rgba(0,0,0,0.03);
                margin-bottom: 26px;
            }
            .cm-filter-title{
                display:flex;
                align-items:center;
                gap:10px;
                font-weight:800;
                color:#1d2327;
                margin-bottom: 12px;
                letter-spacing:-0.2px;
            }
            .cm-filter-title .dashicons{ color:#2271b1; }
            .cm-filter-grid{
                display:grid;
                grid-template-columns: 1fr 1fr auto;
                gap: 14px;
                align-items:end;
            }
            .cm-filter-item label{
                display:block;
                font-size:11px;
                font-weight:800;
                color:#646970;
                text-transform:uppercase;
                letter-spacing:0.6px;
                margin-bottom:6px;
            }
            .cm-filter-select{
                width:100%;
                border:1px solid #dcdcde;
                border-radius:10px;
                padding:10px 12px;
                font-size:13px;
                outline:none;
                background:#fff;
                transition: box-shadow .2s, border-color .2s;
            }
            .cm-filter-select:focus{
                border-color:#2271b1;
                box-shadow:0 0 0 3px rgba(34,113,177,.18);
            }
            .cm-filter-actions{
                display:flex;
                justify-content:flex-end;
            }
            .cm-filter-btn{
                border:1px solid #dcdcde;
                background:#1d2327;
                color:#fff;
                border-radius:10px;
                padding:10px 14px;
                font-size:13px;
                font-weight:700;
                cursor:pointer;
                transition: opacity .2s, transform .2s;
            }
            .cm-filter-btn:hover{ opacity:.92; transform: translateY(-1px); }
            .cm-filter-hint{
                margin-top:10px;
                font-size:12px;
                color:#8c9094;
            }

            /* --- CAMPAIGN CARD --- */
            .cm-campaign-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.04); margin-bottom: 40px; border: 1px solid #eaeaea; overflow: hidden; }
            .cm-card-header { padding: 25px 30px; background: #fff; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
            .cm-card-title h3 { margin: 0; font-size: 18px; color: #1d2327; font-weight: 700; letter-spacing: -0.3px; display: flex; align-items: center; gap: 10px; }
            .cm-badge { background: #eef2ff; color: #4f46e5; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
            .cm-card-meta { display: flex; gap: 20px; font-size: 13px; color: #646970; background: #f8f9fa; padding: 8px 15px; border-radius: 6px; border: 1px solid #eee; }
            .cm-card-progress { height: 4px; background: #f0f0f1; width: 100%; }
            .cm-bar { height: 100%; transition: width 0.5s; }

            /* Hide helpers */
            .cm-campaign-card.is-hidden{ display:none !important; }
            .cm-campaign-card.is-hidden-by-country{ display:none !important; } /* ✅ ülkeye göre gizlenen */

            /* --- DATATABLES OVERRIDES --- */
            .cm-table-container { padding: 20px 30px 30px 30px; }

            /* ✅ YATAY SCROLL HOST */
            .cm-table-container{
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .cm-table-container .dataTables_wrapper{
                width: 100%;
            }
            .cm-table-container table.dataTable{
                width: 100% !important;
                min-width: 820px; /* ✅ mobilde tüm sütunlar görünsün */
            }
            .dataTables_scrollHeadInner,
            .dataTables_scrollHeadInner table{
                width: 100% !important;
            }

            .cm-table-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; gap: 12px; }
            .dataTables_filter { display:flex; align-items:center; gap:10px; }
            .dataTables_filter input {
                border: 1px solid #dcdcde; padding: 8px 12px; border-radius: 6px;
                font-size: 13px; outline: none; transition: border 0.2s;
                box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            }
            .dataTables_filter input:focus { border-color: #2271b1; box-shadow: 0 0 0 2px rgba(34, 113, 177, 0.2); }
            .dataTables_length select { border: 1px solid #dcdcde; padding: 5px 25px 5px 10px; border-radius: 6px; font-size: 13px; cursor: pointer; }

            table.dataTable.no-footer { border-bottom: 1px solid #eee; }
            table.dataTable thead th {
                background-color: #f8f9fa; color: #646970; font-weight: 700;
                text-transform: uppercase; font-size: 11px; padding: 15px 10px;
                border-bottom: 2px solid #e5e5e5; letter-spacing: 0.5px;
                white-space: nowrap;
            }
            table.dataTable tbody td {
                padding: 15px 10px; border-bottom: 1px solid #f0f0f0;
                color: #3c434a; font-size: 13px; vertical-align: middle;
                white-space: nowrap;
            }
            table.dataTable tbody tr:hover { background-color: #fcfcfc; }

            /* Genel Toplam Satırı */
            table.dataTable tfoot td {
                background-color: #fcfcfc; border-top: 2px solid #e5e5e5;
                padding: 15px 10px !important; font-weight: 700; color: #2c3e50;
                white-space: nowrap;
            }

            .cm-country-badge { font-weight: 700; color: #1d2327; font-size: 14px; display: block; margin-bottom: 3px; }
            .cm-media-name { font-size: 12px; color: #8c9094; }
            .cm-ctr-badge { background: #f0f0f1; padding: 5px 8px; border-radius: 6px; font-size: 12px; font-weight: 700; color: #50575e; border: 1px solid #e5e5e5; display:inline-block; }
            .cm-val-bold { font-weight: 600; color: #1d2327; font-feature-settings: "tnum"; font-variant-numeric: tabular-nums; }
            .text-muted { color: #a0a0a0; font-size: 11px; }
            .text-blue { color: #2271b1; }
            .text-green { color: #16a34a; }
            .text-right { text-align: right; }
            .text-center { text-align: center; }

            /* Pagination */
            .cm-table-bottom { display: flex; justify-content: space-between; align-items: center; margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee; font-size: 12px; color: #888; gap: 12px; flex-wrap: wrap; }
            .dataTables_paginate .paginate_button { padding: 5px 12px !important; border-radius: 4px !important; border: 1px solid transparent !important; font-size: 12px; margin-left: 5px; }
            .dataTables_paginate .paginate_button.current { background: #b2ddff !important; color: #fff !important; border: none !important; }
            .dataTables_paginate .paginate_button:hover:not(.current) { background: #f0f0f1 !important; border-color: #ddd !important; color: #333 !important; }

            /* HEADER MENU BUTTONS */
            .cm-menu-item { margin-left: 10px; display: inline-flex; align-items: center; vertical-align: middle; }
            .cm-menu-balance a { background: #f0fdf4 !important; border: 1px solid #bbf7d0; color: #166534 !important; font-weight: 600; padding: 6px 12px !important; border-radius: 6px; font-size: 13px; line-height: 1.2; display: inline-block; }
            .cm-menu-btn a { background: #1d2327 !important; color: #fff !important; font-weight: 600; padding: 6px 16px !important; border-radius: 6px; font-size: 13px; transition: opacity 0.2s; display: inline-block; }
            .cm-menu-btn a:hover { opacity: 0.9; }

            /* MOBILE RESPONSIVE TWEAKS */
            @media (max-width: 768px) {
                .cm-header-card{ padding: 18px; }
                .cm-header-top { flex-direction: column; align-items: flex-start; gap: 10px; }
                .cm-card-header { flex-direction: column; align-items: flex-start; padding: 18px; }
                .cm-card-meta { width: 100%; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
                .cm-table-container { padding: 12px; }
                .cm-table-top { flex-direction: column; align-items: stretch; gap: 10px; }
                .dataTables_filter input { width: 100%; margin-left: 0; }

                .cm-filter-grid{ grid-template-columns: 1fr; }
                .cm-filter-actions{ justify-content: stretch; }
                .cm-filter-btn{ width:100%; }
            }
        </style>
        <?php
    }
}

new Pro_SCM_Frontend();
