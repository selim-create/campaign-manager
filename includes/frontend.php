<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Pro_SCM_Frontend {

    public function __construct() {
        add_shortcode( 'client_dashboard', array( $this, 'render_dashboard' ) );
        add_filter( 'wp_nav_menu_items', array( $this, 'add_items_to_header_menu' ), 10, 2 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_datatables_assets' ) );
        add_action( 'wp_head', array( $this, 'print_global_styles' ) );
    }

    public function enqueue_datatables_assets() {
        wp_enqueue_style(
            'cm-datatables-css',
            'https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css',
            array(),
            '1.13.6'
        );

        wp_enqueue_script(
            'cm-datatables-js',
            'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js',
            array( 'jquery' ),
            '1.13.6',
            true
        );
    }

    private function get_visible_campaigns_for_user( $user_id ) {
        $posts = get_posts( array(
            'post_type'      => 'campaign',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ) );

        $visible = array();

        foreach ( $posts as $post ) {
            $assigned = get_post_meta( $post->ID, '_scm_assigned_users', true );
            $assigned = is_array( $assigned ) ? $assigned : array();

            if ( current_user_can( 'manage_options' ) || in_array( $user_id, $assigned, true ) ) {
                $visible[] = $post;
            }
        }

        return $visible;
    }

    private function tr_num( $num, $decimals = 0, $suffix = '' ) {
        $formatted = number_format( (float) $num, $decimals, ',', '.' );
        return '<span class="cm-num">' . esc_html( $formatted ) . '</span>' .
            ( $suffix ? ' <span class="cm-suffix">' . esc_html( $suffix ) . '</span>' : '' );
    }

    private function tr_plain_num( $num, $decimals = 2 ) {
        return number_format( (float) $num, $decimals, ',', '.' );
    }

    private function get_campaign_totals( $campaigns ) {
        $totals = array(
            'spent'       => 0,
            'conversions' => 0,
        );

        foreach ( $campaigns as $campaign ) {
            $totals['spent'] += (float) get_post_meta( $campaign->ID, 'total_spent', true );
            $totals['conversions'] += (float) get_post_meta( $campaign->ID, 'total_conversions', true );
        }

        return $totals;
    }

    public function add_items_to_header_menu( $items, $args ) {
        if ( ! is_user_logged_in() ) {
            return $items;
        }

        $user_id        = get_current_user_id();
        $global_payment = (float) get_option( 'cm_global_total_payment', 0 );
        $campaigns      = $this->get_visible_campaigns_for_user( $user_id );
        $totals         = $this->get_campaign_totals( $campaigns );
        $balance        = $global_payment - $totals['spent'];
        $pay_link       = get_user_meta( $user_id, '_scm_payment_url', true );
        $balance_class  = $balance < 0 ? 'cm-menu-negative' : 'cm-menu-positive';

        $balance_html = '<span class="cm-menu-label">Kalan:</span> ' .
            '<span class="cm-menu-val ' . esc_attr( $balance_class ) . '">' .
            esc_html( number_format( $balance, 2, ',', '.' ) ) . ' ₺</span>';

        $items .= '<li class="menu-item cm-menu-item cm-menu-balance"><a>' . $balance_html . '</a></li>';

        if ( $pay_link ) {
            $items .= '<li class="menu-item cm-menu-item cm-menu-btn"><a href="' .
                esc_url( $pay_link ) . '" target="_blank" rel="noopener">Ödeme Yap</a></li>';
        }

        return $items;
    }

    public function render_dashboard() {
        if ( ! is_user_logged_in() ) {
            return '<div class="cm-alert cm-error">Raporları görüntülemek için lütfen giriş yapınız.</div>';
        }

        $user_id                = get_current_user_id();
        $global_payment         = (float) get_option( 'cm_global_total_payment', 0 );
        $data_schema            = (string) get_option( 'cm_data_schema', 'v1' );
        $is_v2                  = ( 'v2' === $data_schema );
        $country_filter_enabled = apply_filters( 'cm_country_filter_enabled', ! $is_v2, $data_schema );

        $my_campaigns = $this->get_visible_campaigns_for_user( $user_id );
        $totals       = $this->get_campaign_totals( $my_campaigns );

        $campaign_options = array();
        $country_set      = array();

        foreach ( $my_campaigns as $post ) {
            $campaign_options[ $post->ID ] = get_the_title( $post );

            if ( $country_filter_enabled ) {
                $rows = get_post_meta( $post->ID, 'campaign_rows', true );
                if ( is_array( $rows ) ) {
                    foreach ( $rows as $row ) {
                        if ( ! empty( $row['ulke'] ) ) {
                            $country_set[ trim( $row['ulke'] ) ] = true;
                        }
                    }
                }
            }
        }

        asort( $campaign_options, SORT_NATURAL | SORT_FLAG_CASE );

        $countries = array_keys( $country_set );
        sort( $countries, SORT_NATURAL | SORT_FLAG_CASE );

        $balance         = $global_payment - $totals['spent'];
        $usage_percent   = $global_payment > 0 ? ( $totals['spent'] / $global_payment ) * 100 : 0;
        $progress_width  = min( 100, max( 0, $usage_percent ) );
        $progress_status = $usage_percent > 100 ? 'over' : ( $usage_percent > 90 ? 'warning' : 'normal' );
        $balance_class   = $balance < 0 ? 'text-red' : 'text-green';

        $last_sync = get_option( 'cm_last_successful_sync_time' );
        $sync_text = $last_sync ? date_i18n( 'd F Y H:i', strtotime( $last_sync ) ) : 'Bekleniyor...';

        ob_start();
        ?>
        <div class="cm-dashboard-container">

            <div class="cm-header-card">
                <div class="cm-header-top">
                    <div class="cm-welcome">
                        <span class="cm-welcome-label">Hoşgeldiniz,</span>
                        <h2 class="cm-user-name"><?php echo esc_html( wp_get_current_user()->display_name ); ?></h2>
                        <div class="cm-sync-badge">
                            <span class="dashicons dashicons-clock"></span>
                            Güncelleme: <strong><?php echo esc_html( $sync_text ); ?></strong>
                        </div>
                    </div>
                </div>

                <div class="cm-stats-row">
                    <div class="cm-stat-item">
                        <span class="cm-stat-label">Toplam Ödeme</span>
                        <div class="cm-stat-value"><?php echo $this->tr_num( $global_payment, 2, '₺' ); ?></div>
                    </div>

                    <div class="cm-stat-item">
                        <span class="cm-stat-label">Harcanan</span>
                        <div class="cm-stat-value"><?php echo $this->tr_num( $totals['spent'], 2, '₺' ); ?></div>
                    </div>

                    <div class="cm-stat-item <?php echo $balance < 0 ? 'cm-negative' : 'cm-highlight'; ?>">
                        <span class="cm-stat-label">Kalan Bakiye</span>
                        <div class="cm-stat-value <?php echo esc_attr( $balance_class ); ?>">
                            <?php echo $this->tr_num( $balance, 2, '₺' ); ?>
                        </div>
                    </div>

                    <?php if ( $is_v2 ) : ?>
                        <div class="cm-stat-item">
                            <span class="cm-stat-label">Toplam Dönüşüm</span>
                            <div class="cm-stat-value"><?php echo $this->tr_num( $totals['conversions'], 0 ); ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="cm-balance-progress cm-progress-<?php echo esc_attr( $progress_status ); ?>">
                    <div class="cm-progress-labels">
                        <span>Ödeme Bakiyesi Kullanımı</span>
                        <span>%<?php echo esc_html( number_format( $usage_percent, 1, ',', '.' ) ); ?></span>
                    </div>
                    <div class="cm-progress-track">
                        <div class="cm-progress-fill" style="width: <?php echo esc_attr( $progress_width ); ?>%;"></div>
                    </div>
                </div>
            </div>

            <?php if ( ! empty( $campaign_options ) ) : ?>
                <div class="cm-global-filters">
                    <div class="cm-filter-title">
                        <span class="dashicons dashicons-filter"></span>
                        Genel Filtreler
                    </div>

                    <div class="cm-filter-grid <?php echo $country_filter_enabled ? '' : 'cm-filter-grid-single'; ?>">
                        <div class="cm-filter-item">
                            <label for="cmFilterCampaign">Kampanya</label>
                            <select id="cmFilterCampaign" class="cm-filter-select">
                                <option value="">Tümü</option>
                                <?php foreach ( $campaign_options as $cid => $ctitle ) : ?>
                                    <option value="<?php echo esc_attr( $cid ); ?>"><?php echo esc_html( $ctitle ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ( $country_filter_enabled ) : ?>
                            <div class="cm-filter-item">
                                <label for="cmFilterCountry">Ülke</label>
                                <select id="cmFilterCountry" class="cm-filter-select">
                                    <option value="">Tümü</option>
                                    <?php foreach ( $countries as $country ) : ?>
                                        <option value="<?php echo esc_attr( $country ); ?>"><?php echo esc_html( $country ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="cm-filter-item cm-filter-actions">
                            <button type="button" id="cmFilterReset" class="cm-filter-btn">Sıfırla</button>
                        </div>
                    </div>

                    <div class="cm-filter-hint">
                        <?php if ( $country_filter_enabled ) : ?>
                            Kampanya filtresi kartları daraltır; ülke filtresi ilgili satırları gösterir.
                        <?php else : ?>
                            Kampanya seçerek rapor kartlarını filtreleyebilirsiniz.
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="cm-campaigns-wrapper">
                <?php if ( empty( $my_campaigns ) ) : ?>
                    <div class="cm-alert">Bu kullanıcıya atanmış aktif kampanya bulunmuyor.</div>
                <?php endif; ?>

                <?php foreach ( $my_campaigns as $camp ) :
                    $rows = get_post_meta( $camp->ID, 'campaign_rows', true );
                    if ( ! is_array( $rows ) || empty( $rows ) ) {
                        continue;
                    }

                    $t_spent       = (float) get_post_meta( $camp->ID, 'total_spent', true );
                    $t_plan        = (float) get_post_meta( $camp->ID, 'total_planned', true );
                    $t_imp         = (float) get_post_meta( $camp->ID, 'total_imp', true );
                    $t_clk         = (float) get_post_meta( $camp->ID, 'total_clk', true );
                    $t_conversions = (float) get_post_meta( $camp->ID, 'total_conversions', true );

                    $real_total_ctr = $t_imp > 0 ? ( $t_clk / $t_imp ) * 100 : 0;
                    $real_total_cpm = $t_imp > 0 ? $t_spent / ( $t_imp / 1000 ) : 0;
                    $real_total_cpc = $t_clk > 0 ? $t_spent / $t_clk : 0;
                    $c_percent      = $t_plan > 0 ? ( $t_spent / $t_plan ) * 100 : 0;
                    ?>
                    <div class="cm-campaign-card" data-campaign-id="<?php echo esc_attr( $camp->ID ); ?>">
                        <div class="cm-card-header">
                            <div class="cm-card-title">
                                <h3><?php echo esc_html( get_the_title( $camp ) ); ?></h3>
                                <span class="cm-badge">Aktif</span>
                            </div>

                            <div class="cm-card-meta">
                                <span class="meta-box">Planlanan: <strong><?php echo $this->tr_num( $t_plan, 0, '₺' ); ?></strong></span>
                                <span class="meta-box">Harcanan: <strong class="text-blue"><?php echo $this->tr_num( $t_spent, 0, '₺' ); ?></strong></span>
                                <?php if ( $is_v2 ) : ?>
                                    <span class="meta-box">Dönüşüm: <strong><?php echo $this->tr_num( $t_conversions, 0 ); ?></strong></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="cm-card-progress">
                            <div class="cm-bar" style="width: <?php echo esc_attr( min( 100, max( 0, $c_percent ) ) ); ?>%;"></div>
                        </div>

                        <div class="cm-table-container">
                            <table class="cm-datatable display nowrap" style="width:100%">
                                <thead>
                                    <tr>
                                        <th><?php echo $is_v2 ? 'Lokasyon' : 'Ülke / Mecra'; ?></th>
                                        <th>Tarih</th>
                                        <th class="text-right">Impression</th>
                                        <th class="text-right">Click</th>
                                        <th class="text-center">CTR</th>
                                        <?php if ( $is_v2 ) : ?>
                                            <th class="text-right">Dönüşüm</th>
                                        <?php endif; ?>
                                        <th class="text-right">Maliyetler</th>
                                        <th class="text-right">Harcama</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach ( $rows as $row ) :
                                        $row_ctr         = isset( $row['ctr'] ) ? (float) $row['ctr'] : 0;
                                        $row_cpm         = isset( $row['cpm'] ) ? (float) $row['cpm'] : 0;
                                        $row_cpc         = isset( $row['cpc'] ) ? (float) $row['cpc'] : 0;
                                        $row_conversions = isset( $row['conversions'] ) ? (float) $row['conversions'] : 0;
                                        $location        = ! empty( $row['location'] ) ? $row['location'] : ( $row['ulke'] ?? '' );
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="cm-country-badge"><?php echo esc_html( $location ); ?></div>
                                                <?php if ( ! $is_v2 && ! empty( $row['mecra'] ) ) : ?>
                                                    <div class="cm-media-name"><?php echo esc_html( $row['mecra'] ); ?></div>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <?php echo esc_html( $row['baslangic'] ?? '' ); ?><br>
                                                <span class="text-muted"><?php echo esc_html( $row['bitis'] ?? '' ); ?></span>
                                            </td>

                                            <td class="text-right"><span class="cm-val-bold"><?php echo $this->tr_num( $row['imp'] ?? 0 ); ?></span></td>
                                            <td class="text-right"><span class="cm-val-bold"><?php echo $this->tr_num( $row['click'] ?? 0 ); ?></span></td>

                                            <td class="text-center">
                                                <div class="cm-ctr-badge">%<?php echo esc_html( $this->tr_plain_num( $row_ctr, 2 ) ); ?></div>
                                            </td>

                                            <?php if ( $is_v2 ) : ?>
                                                <td class="text-right">
                                                    <span class="cm-val-bold"><?php echo $this->tr_num( $row_conversions, 0 ); ?></span>
                                                </td>
                                            <?php endif; ?>

                                            <td class="text-right cm-cost-cell">
                                                CPM: <?php echo esc_html( $this->tr_plain_num( $row_cpm, 2 ) ); ?> ₺<br>
                                                CPC: <?php echo esc_html( $this->tr_plain_num( $row_cpc, 2 ) ); ?> ₺
                                            </td>

                                            <td class="text-right text-blue">
                                                <span class="cm-val-bold"><?php echo $this->tr_num( $row['spent'] ?? 0, 2, '₺' ); ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>

                                <tfoot>
                                    <tr class="cm-total-row">
                                        <td colspan="2">GENEL TOPLAM:</td>
                                        <td class="text-right"><span class="cm-val-bold"><?php echo $this->tr_num( $t_imp ); ?></span></td>
                                        <td class="text-right"><span class="cm-val-bold"><?php echo $this->tr_num( $t_clk ); ?></span></td>
                                        <td class="text-center"><div class="cm-ctr-badge">%<?php echo esc_html( $this->tr_plain_num( $real_total_ctr, 2 ) ); ?></div></td>

                                        <?php if ( $is_v2 ) : ?>
                                            <td class="text-right"><span class="cm-val-bold"><?php echo $this->tr_num( $t_conversions, 0 ); ?></span></td>
                                        <?php endif; ?>

                                        <td class="text-right cm-cost-cell">
                                            CPM: <?php echo esc_html( $this->tr_plain_num( $real_total_cpm, 2 ) ); ?> ₺<br>
                                            CPC: <?php echo esc_html( $this->tr_plain_num( $real_total_cpc, 2 ) ); ?> ₺
                                        </td>

                                        <td class="text-right text-blue">
                                            <span class="cm-val-bold cm-total-spent"><?php echo $this->tr_num( $t_spent, 2, '₺' ); ?></span>
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
            var cmCountryFilterEnabled = <?php echo $country_filter_enabled ? 'true' : 'false'; ?>;

            if (cmCountryFilterEnabled) {
                $.fn.dataTable.ext.search.push(function(settings, data) {
                    if (!cmSelectedCountry) return true;
                    var col0 = (data[0] || "").toString().toLowerCase();
                    return col0.indexOf(cmSelectedCountry.toLowerCase()) !== -1;
                });
            }

            $('.cm-datatable').each(function(){
                var dt = $(this).DataTable({
                    responsive: false,
                    scrollX: true,
                    scrollCollapse: true,
                    autoWidth: false,
                    pageLength: 20,
                    lengthMenu: [[10, 20, 50, -1], [10, 20, 50, "Tümü"]],
                    language: {
                        url: "//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json",
                        search: "_INPUT_",
                        searchPlaceholder: "Tabloda ara..."
                    },
                    dom: '<"cm-table-top"lf>rt<"cm-table-bottom"ip>',
                    columnDefs: [
                        { targets: 0, width: 190 },
                        { targets: 1, width: 135 }
                    ]
                });
                cmTables.push(dt);
            });

            function redrawAllTables() {
                cmTables.forEach(function(dt){
                    dt.draw();
                    dt.columns.adjust();
                });

                if (!cmCountryFilterEnabled) return;

                $('.cm-campaign-card').each(function(){
                    var $card = $(this);
                    var table = $card.find('table.cm-datatable');
                    if (!table.length) return;

                    var dt = table.DataTable();
                    var visibleCount = dt.rows({ filter: 'applied' }).count();
                    $card.toggleClass('is-hidden-by-country', !!cmSelectedCountry && visibleCount === 0);
                });
            }

            $('#cmFilterCampaign').on('change', function(){
                var id = $(this).val();

                $('.cm-campaign-card').each(function(){
                    var match = !id || $(this).data('campaign-id').toString() === id.toString();
                    $(this).toggleClass('is-hidden', !match);
                });

                cmTables.forEach(function(dt){ dt.columns.adjust(); });
            });

            if (cmCountryFilterEnabled) {
                $('#cmFilterCountry').on('change', function(){
                    cmSelectedCountry = $(this).val() || "";
                    redrawAllTables();
                });
            }

            $('#cmFilterReset').on('click', function(){
                $('#cmFilterCampaign').val('');
                if (cmCountryFilterEnabled) {
                    $('#cmFilterCountry').val('');
                    cmSelectedCountry = "";
                }
                $('.cm-campaign-card').removeClass('is-hidden is-hidden-by-country');
                redrawAllTables();
            });

            redrawAllTables();

            $(window).on('resize', function(){
                cmTables.forEach(function(dt){ dt.columns.adjust(); });
            });
        });
        </script>
        <?php

        return ob_get_clean();
    }

    public function print_global_styles() {
        ?>
        <style>
            .cm-dashboard-container {
                font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                max-width: 1200px;
                width: 100%;
                margin: 30px auto;
                color: #1d2327;
                padding: 0 12px;
            }
            .cm-dashboard-container * { box-sizing: border-box; }

            .cm-header-card,
            .cm-global-filters,
            .cm-campaign-card {
                background: #fff;
                border: 1px solid #e8eaed;
                box-shadow: 0 8px 28px rgba(0,0,0,.035);
            }

            .cm-header-card {
                border-radius: 14px;
                padding: 30px;
                margin-bottom: 18px;
            }

            .cm-header-top {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 28px;
            }

            .cm-welcome-label {
                display: block;
                margin-bottom: 4px;
                font-size: 11px;
                font-weight: 700;
                color: #8c9094;
                text-transform: uppercase;
                letter-spacing: .08em;
            }

            .cm-user-name {
                margin: 0;
                font-size: 26px;
                line-height: 1.15;
                font-weight: 800;
                letter-spacing: -.03em;
            }

            .cm-sync-badge {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                margin-top: 9px;
                padding: 6px 11px;
                border-radius: 999px;
                border: 1px solid #e1e3e5;
                background: #f7f8f8;
                color: #5f6368;
                font-size: 12px;
            }

            .cm-stats-row {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 14px;
                margin-bottom: 24px;
            }

            .cm-stat-item {
                min-width: 0;
                padding: 18px;
                border-radius: 12px;
                border: 1px solid #e8eaed;
                background: #fff;
            }

            .cm-stat-item.cm-highlight {
                background: linear-gradient(135deg, #f0fdf4, #fff);
                border-color: #bbf7d0;
            }

            .cm-stat-item.cm-negative {
                background: linear-gradient(135deg, #fff1f2, #fff);
                border-color: #fecdd3;
            }

            .cm-stat-label {
                display: block;
                margin-bottom: 7px;
                color: #6b7280;
                font-size: 10px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: .06em;
            }

            .cm-stat-value {
                font-size: 22px;
                font-weight: 800;
                letter-spacing: -.025em;
                font-variant-numeric: tabular-nums;
            }

            .cm-balance-progress {
                position: relative;
                height: 32px;
                overflow: hidden;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                background: #f3f4f6;
            }

            .cm-progress-labels {
                position: absolute;
                z-index: 2;
                inset: 0;
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 0 14px;
                color: #4b5563;
                font-size: 11px;
                font-weight: 800;
            }

            .cm-progress-track,
            .cm-progress-fill { height: 100%; }

            .cm-progress-fill {
                opacity: .18;
                background: #16a34a;
                transition: width .4s ease;
            }

            .cm-progress-warning .cm-progress-fill { background: #d97706; }
            .cm-progress-over .cm-progress-fill { background: #dc2626; }

            .cm-global-filters {
                margin-bottom: 26px;
                padding: 18px 20px;
                border-radius: 12px;
            }

            .cm-filter-title {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-bottom: 12px;
                font-size: 14px;
                font-weight: 800;
            }

            .cm-filter-title .dashicons { color: #2271b1; }

            .cm-filter-grid {
                display: grid;
                grid-template-columns: 1fr 1fr auto;
                gap: 14px;
                align-items: end;
            }

            .cm-filter-grid.cm-filter-grid-single {
                grid-template-columns: minmax(240px, 1fr) auto;
            }

            .cm-filter-item label {
                display: block;
                margin-bottom: 6px;
                color: #6b7280;
                font-size: 10px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: .06em;
            }

            .cm-filter-select {
                width: 100%;
                min-height: 40px;
                padding: 8px 11px;
                border: 1px solid #dcdcde;
                border-radius: 9px;
                background: #fff;
                font-size: 13px;
            }

            .cm-filter-actions {
                display: flex;
                justify-content: flex-end;
            }

            .cm-filter-btn {
                min-height: 40px;
                padding: 8px 15px;
                border: 0;
                border-radius: 9px;
                background: #1d2327;
                color: #fff;
                font-size: 13px;
                font-weight: 700;
                cursor: pointer;
            }

            .cm-filter-hint {
                margin-top: 9px;
                color: #8c9094;
                font-size: 11px;
            }

            .cm-campaign-card {
                overflow: hidden;
                margin-bottom: 28px;
                border-radius: 14px;
            }

            .cm-card-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 14px;
                padding: 22px 24px;
                border-bottom: 1px solid #f0f1f2;
            }

            .cm-card-title {
                display: flex;
                align-items: center;
                gap: 9px;
            }

            .cm-card-title h3 {
                margin: 0;
                font-size: 17px;
                font-weight: 800;
                letter-spacing: -.02em;
            }

            .cm-badge {
                display: inline-flex;
                padding: 4px 8px;
                border-radius: 999px;
                background: #eef2ff;
                color: #4f46e5;
                font-size: 9px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: .04em;
            }

            .cm-card-meta {
                display: flex;
                flex-wrap: wrap;
                gap: 7px;
            }

            .meta-box {
                padding: 7px 10px;
                border: 1px solid #eceef0;
                border-radius: 7px;
                background: #f8f9fa;
                color: #646970;
                font-size: 11px;
            }

            .cm-card-progress {
                width: 100%;
                height: 4px;
                background: #f0f0f1;
            }

            .cm-bar {
                height: 100%;
                background: #3498db;
                transition: width .35s ease;
            }

            .cm-table-container {
                overflow-x: auto;
                padding: 18px 24px 24px;
                -webkit-overflow-scrolling: touch;
            }

            .cm-table-container .dataTables_wrapper { width: 100%; }

            .cm-table-container table.dataTable {
                width: 100% !important;
                min-width: 900px;
            }

            .dataTables_scrollHeadInner,
            .dataTables_scrollHeadInner table { width: 100% !important; }

            .cm-table-top {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 10px;
                margin-bottom: 13px;
            }

            .dataTables_filter input {
                padding: 7px 10px;
                border: 1px solid #dcdcde;
                border-radius: 7px;
                font-size: 12px;
            }

            .dataTables_length select {
                padding: 5px 24px 5px 8px;
                border: 1px solid #dcdcde;
                border-radius: 7px;
                font-size: 12px;
            }

            table.dataTable.no-footer { border-bottom: 1px solid #eee; }

            table.dataTable thead th {
                padding: 13px 9px;
                border-bottom: 1px solid #e5e7eb;
                background: #f8f9fa;
                color: #6b7280;
                font-size: 10px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: .045em;
                white-space: nowrap;
            }

            table.dataTable tbody td {
                padding: 13px 9px;
                border-bottom: 1px solid #f0f1f2;
                color: #3c434a;
                font-size: 12px;
                vertical-align: middle;
                white-space: nowrap;
            }

            table.dataTable tfoot td {
                padding: 14px 9px !important;
                border-top: 1px solid #dfe2e5;
                background: #fcfcfc;
                color: #2c3e50;
                font-weight: 800;
                white-space: nowrap;
            }

            .cm-country-badge {
                font-size: 13px;
                font-weight: 800;
                color: #1d2327;
            }

            .cm-media-name,
            .text-muted {
                color: #9ca3af;
                font-size: 10px;
            }

            .cm-ctr-badge {
                display: inline-block;
                padding: 4px 7px;
                border: 1px solid #e5e7eb;
                border-radius: 6px;
                background: #f3f4f6;
                color: #4b5563;
                font-size: 11px;
                font-weight: 800;
                font-variant-numeric: tabular-nums;
            }

            .cm-cost-cell {
                font-size: 10px !important;
                line-height: 1.55;
                font-variant-numeric: tabular-nums;
            }

            .cm-val-bold {
                font-weight: 700;
                color: #1d2327;
                font-variant-numeric: tabular-nums;
            }

            .cm-total-spent { font-size: 14px; }

            .cm-table-bottom {
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 10px;
                margin-top: 13px;
                padding-top: 13px;
                border-top: 1px solid #eee;
                color: #888;
                font-size: 11px;
            }

            .cm-campaign-card.is-hidden,
            .cm-campaign-card.is-hidden-by-country {
                display: none !important;
            }

            .cm-alert {
                padding: 16px 18px;
                border: 1px solid #e5e7eb;
                border-radius: 10px;
                background: #fff;
                color: #4b5563;
            }

            .cm-menu-item {
                display: inline-flex;
                align-items: center;
                margin-left: 10px;
            }

            .cm-menu-balance a {
                padding: 6px 12px !important;
                border: 1px solid #d1d5db;
                border-radius: 6px;
                background: #f8fafc !important;
                color: #334155 !important;
                font-size: 13px;
                font-weight: 700;
            }

            .cm-menu-positive { color: #15803d; }
            .cm-menu-negative { color: #dc2626; }

            .cm-menu-btn a {
                padding: 6px 15px !important;
                border-radius: 6px;
                background: #1d2327 !important;
                color: #fff !important;
                font-size: 13px;
                font-weight: 700;
            }

            .text-blue { color: #2271b1; }
            .text-green { color: #16a34a; }
            .text-red { color: #dc2626; }
            .text-right { text-align: right; }
            .text-center { text-align: center; }

            @media (max-width: 768px) {
                .cm-header-card { padding: 18px; }
                .cm-header-top { margin-bottom: 20px; }
                .cm-user-name { font-size: 22px; }
                .cm-stats-row { grid-template-columns: 1fr 1fr; }
                .cm-stat-value { font-size: 18px; }

                .cm-filter-grid,
                .cm-filter-grid.cm-filter-grid-single {
                    grid-template-columns: 1fr;
                }

                .cm-filter-actions { justify-content: stretch; }
                .cm-filter-btn { width: 100%; }

                .cm-card-header {
                    align-items: flex-start;
                    flex-direction: column;
                    padding: 18px;
                }

                .cm-card-meta { width: 100%; }
                .cm-table-container { padding: 12px; }
                .cm-table-top { align-items: stretch; flex-direction: column; }
                .dataTables_filter input { width: 100%; margin-left: 0; }
            }

            @media (max-width: 520px) {
                .cm-stats-row { grid-template-columns: 1fr; }
            }
        </style>
        <?php
    }
}

new Pro_SCM_Frontend();
