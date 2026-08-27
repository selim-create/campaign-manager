<?php

/**
 * Campaign Manager data sync.
 *
 * Supports both the legacy dashboard schema and Dashboard_API schema v2.
 */

function cm_get_csv_data($url) {
    $response = wp_remote_get($url, array('timeout' => 30));

    if (is_wp_error($response)) {
        error_log('AdOps Sync Error: ' . $response->get_error_message());
        return false;
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        error_log('AdOps Sync Error: HTTP ' . $status);
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    if (empty($body)) return false;

    $lines = preg_split('/\r\n|\r|\n/', $body);
    $lines = array_values(array_filter($lines, static function($line) {
        return trim($line) !== '';
    }));

    return array_map('str_getcsv', $lines);
}

function cm_normalize_header($header) {
    return array_map(static function($h) {
        $h = str_replace("\xEF\xBB\xBF", '', (string) $h);
        $h = trim(mb_strtolower($h, 'UTF-8'));
        $h = preg_replace('/\s+/', '_', $h);
        return $h;
    }, $header);
}

/**
 * Parse Google Sheets values in either Turkish or English number formatting.
 * Examples: 3.547.777, 252.542,14 ₺, 373,000.00, 0,41%.
 */
function cm_parse_number($value) {
    if (is_int($value) || is_float($value)) return (float) $value;

    $value = trim((string) $value);
    if ($value === '' || $value === '-') return 0.0;

    $value = str_replace(array("\xc2\xa0", '₺', 'TL', 'TRY', '%', ' '), '', $value);
    $value = preg_replace('/[^0-9,\.\-]/u', '', $value);
    if ($value === '' || $value === '-') return 0.0;

    $last_comma = strrpos($value, ',');
    $last_dot   = strrpos($value, '.');

    if ($last_comma !== false && $last_dot !== false) {
        if ($last_comma > $last_dot) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } elseif ($last_comma !== false) {
        $parts = explode(',', $value);
        $tail  = end($parts);
        if (count($parts) > 2 || strlen($tail) === 3) {
            $value = str_replace(',', '', $value);
        } else {
            $value = str_replace(',', '.', $value);
        }
    } elseif ($last_dot !== false) {
        $parts = explode('.', $value);
        $tail  = end($parts);
        if (count($parts) > 2 || strlen($tail) === 3) {
            $value = str_replace('.', '', $value);
        }
    }

    return is_numeric($value) ? (float) $value : 0.0;
}

function cm_parse_percent($value) {
    return cm_parse_number($value);
}

function cm_find_campaign_post($key) {
    $posts = get_posts(array(
        'post_type'      => 'campaign',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'meta_query'     => array(
            'relation' => 'OR',
            array('key' => 'campaign_key', 'value' => $key),
            array('key' => 'campaign_id', 'value' => $key),
        ),
    ));

    return $posts ? $posts[0] : null;
}

function cm_ensure_campaign_post($key, $name) {
    $post = cm_find_campaign_post($key);
    if ($post) {
        if ($name && $post->post_title !== $name) {
            wp_update_post(array('ID' => $post->ID, 'post_title' => $name));
        }
        return array($post->ID, false);
    }

    $post_id = wp_insert_post(array(
        'post_title'  => $name ?: $key,
        'post_type'   => 'campaign',
        'post_status' => 'publish',
    ));

    if (is_wp_error($post_id) || !$post_id) return array(0, false);

    update_post_meta($post_id, 'campaign_key', $key);
    update_post_meta($post_id, 'campaign_id', $key);

    return array((int) $post_id, true);
}

function cm_build_campaigns_from_dashboard($dash_data) {
    if (!$dash_data || count($dash_data) < 2) {
        return array('schema' => null, 'campaigns' => array(), 'message' => 'Dashboard boş.');
    }

    $header = cm_normalize_header(array_shift($dash_data));
    $is_v2  = in_array('campaign_key', $header, true) && in_array('campaign_name', $header, true);
    $is_v1  = in_array('kampanya_id', $header, true);

    if (!$is_v2 && !$is_v1) {
        return array('schema' => null, 'campaigns' => array(), 'message' => 'Desteklenmeyen Dashboard şeması.');
    }

    $campaigns = array();

    foreach ($dash_data as $row) {
        if (count($row) !== count($header)) continue;
        $d = array_combine($header, $row);
        if (!$d) continue;

        if ($is_v2) {
            $key  = sanitize_key($d['campaign_key'] ?? '');
            $name = sanitize_text_field($d['campaign_name'] ?? '');
            if (!$key || !$name) continue;

            $imp         = cm_parse_number($d['impressions'] ?? 0);
            $clk         = cm_parse_number($d['clicks'] ?? 0);
            $spent       = cm_parse_number($d['spent'] ?? 0);
            $planned     = cm_parse_number($d['planned_budget'] ?? 0);
            $target_imp  = cm_parse_number($d['target_impressions'] ?? 0);
            $remaining   = cm_parse_number($d['remaining_impressions'] ?? 0);
            $conversions = cm_parse_number($d['conversions'] ?? 0);
            $ctr         = cm_parse_percent($d['ctr'] ?? 0);
            $cpm         = cm_parse_number($d['cpm'] ?? 0);
            $cpc         = $clk > 0 ? $spent / $clk : 0;

            if (!isset($campaigns[$key])) {
                $campaigns[$key] = array('name' => $name, 'schema' => 'v2', 'rows' => array());
            }

            $campaigns[$key]['rows'][] = array(
                'row_key'               => sanitize_key($d['row_key'] ?? ''),
                'location'              => sanitize_text_field($d['location'] ?? ''),
                'country'               => sanitize_text_field($d['country'] ?? ''),
                'ulke'                  => sanitize_text_field($d['location'] ?? ''),
                'mecra'                 => sanitize_text_field($d['country'] ?? ''),
                'baslangic'             => sanitize_text_field($d['start_date'] ?? ''),
                'bitis'                 => sanitize_text_field($d['end_date'] ?? ''),
                'imp'                   => $imp,
                'click'                 => $clk,
                'spent'                 => $spent,
                'planned'               => $planned,
                'target_impressions'    => $target_imp,
                'remaining_impressions' => $remaining,
                'conversions'           => $conversions,
                'cpm'                   => $cpm,
                'cpc'                   => $cpc,
                'ctr'                   => $ctr,
            );
        } else {
            $key = sanitize_text_field($d['kampanya_id'] ?? '');
            if (!$key) continue;

            $imp     = cm_parse_number($d['toplam_imp'] ?? 0);
            $clk     = cm_parse_number($d['toplam_click'] ?? 0);
            $spent   = cm_parse_number($d['harcanan_butce_ulke'] ?? 0);
            $planned = cm_parse_number($d['planlanan_butce_genel'] ?? 0);

            if (!isset($campaigns[$key])) {
                $campaigns[$key] = array(
                    'name' => sanitize_text_field($d['kampanya_adi'] ?? $d['mecra'] ?? $key),
                    'schema' => 'v1',
                    'rows' => array(),
                );
            }

            $campaigns[$key]['rows'][] = array(
                'ulke'        => sanitize_text_field($d['ulke_adi'] ?? ''),
                'mecra'       => sanitize_text_field($d['mecra'] ?? ''),
                'baslangic'   => sanitize_text_field($d['baslangic'] ?? ''),
                'bitis'       => sanitize_text_field($d['bitis'] ?? ''),
                'imp'         => $imp,
                'click'       => $clk,
                'spent'       => $spent,
                'planned'     => $planned,
                'conversions' => 0,
                'cpm'         => cm_parse_number($d['gerceklesen_cpm'] ?? 0),
                'cpc'         => cm_parse_number($d['gerceklesen_cpc'] ?? 0),
                'ctr'         => cm_parse_percent($d['ctr'] ?? 0),
            );
        }
    }

    return array('schema' => $is_v2 ? 'v2' : 'v1', 'campaigns' => $campaigns, 'message' => '');
}

function cm_sync_google_sheet_data($dash_url, $pay_url) {
    if (empty($dash_url) || empty($pay_url)) {
        return array('success' => false, 'message' => 'API Endpoints tanımlanmamış.');
    }

    $dash_data = cm_get_csv_data($dash_url);
    if (!$dash_data) {
        return array('success' => false, 'message' => 'Data Source bağlantısı başarısız. (Error: 101 - Stream Unreachable)');
    }

    $parsed = cm_build_campaigns_from_dashboard($dash_data);
    $campaigns = $parsed['campaigns'];
    if (!$parsed['schema']) return array('success' => false, 'message' => $parsed['message']);
    if (!$campaigns) return array('success' => false, 'message' => 'Dashboard verisi okundu ancak kampanya satırı bulunamadı.');

    $pay_data = cm_get_csv_data($pay_url);
    $total_payment_pool = 0;
    if ($pay_data) {
        array_shift($pay_data);
        foreach ($pay_data as $p_row) {
            $total_payment_pool += isset($p_row[1]) ? cm_parse_number($p_row[1]) : 0;
        }
    } else {
        error_log('AdOps Core: Payment stream empty or unreachable.');
    }

    $log = array();
    $created_count = 0;

    foreach (get_users() as $user) update_user_meta($user->ID, '_scm_calculated_spent', 0);

    foreach ($campaigns as $campaign_key => $data) {
        list($post_id, $created) = cm_ensure_campaign_post($campaign_key, $data['name']);
        if (!$post_id) {
            $log[] = 'Kampanya oluşturulamadı: ' . $campaign_key;
            continue;
        }
        if ($created) $created_count++;

        $t_imp = 0; $t_clk = 0; $t_spent = 0; $t_planned = 0;
        $t_target_imp = 0; $t_remaining_imp = 0; $t_conversions = 0;

        foreach ($data['rows'] as $r) {
            $t_imp += (float) ($r['imp'] ?? 0);
            $t_clk += (float) ($r['click'] ?? 0);
            $t_spent += (float) ($r['spent'] ?? 0);
            $t_conversions += (float) ($r['conversions'] ?? 0);

            if ($data['schema'] === 'v2') {
                $t_planned += (float) ($r['planned'] ?? 0);
                $t_target_imp += (float) ($r['target_impressions'] ?? 0);
                $t_remaining_imp += (float) ($r['remaining_impressions'] ?? 0);
            } else {
                $t_planned = max($t_planned, (float) ($r['planned'] ?? 0));
            }
        }

        $t_ctr = $t_imp > 0 ? ($t_clk / $t_imp) * 100 : 0;
        $t_cpm = $t_imp > 0 ? ($t_spent / ($t_imp / 1000)) : 0;
        $t_cpc = $t_clk > 0 ? ($t_spent / $t_clk) : 0;

        update_post_meta($post_id, 'campaign_key', $campaign_key);
        if ($data['schema'] === 'v2') update_post_meta($post_id, 'campaign_id', $campaign_key);
        update_post_meta($post_id, 'cm_data_schema', $data['schema']);
        update_post_meta($post_id, 'campaign_rows', $data['rows']);
        update_post_meta($post_id, 'total_imp', $t_imp);
        update_post_meta($post_id, 'total_clk', $t_clk);
        update_post_meta($post_id, 'total_spent', $t_spent);
        update_post_meta($post_id, 'total_planned', $t_planned);
        update_post_meta($post_id, 'total_target_imp', $t_target_imp);
        update_post_meta($post_id, 'total_remaining_imp', $t_remaining_imp);
        update_post_meta($post_id, 'total_conversions', $t_conversions);
        update_post_meta($post_id, 'total_ctr', number_format($t_ctr, 2, '.', ''));
        update_post_meta($post_id, 'total_cpm', number_format($t_cpm, 2, '.', ''));
        update_post_meta($post_id, 'total_cpc', number_format($t_cpc, 2, '.', ''));
        update_post_meta($post_id, 'last_sync_time', current_time('mysql'));

        $assigned_users = get_post_meta($post_id, '_scm_assigned_users', true) ?: array();
        foreach ($assigned_users as $uid) {
            $current_user_spent = (float) get_user_meta($uid, '_scm_calculated_spent', true);
            update_user_meta($uid, '_scm_calculated_spent', $current_user_spent + $t_spent);
        }

        $log[] = strtoupper($campaign_key) . ': senkronize edildi.';
    }

    update_option('cm_global_total_payment', $total_payment_pool);
    update_option('cm_data_schema', $parsed['schema']);
    update_option('cm_last_successful_sync_time', current_time('mysql'));

    return array(
        'success' => true,
        'message' => sprintf(
            'Veri akışı başarıyla senkronize edildi. Şema: %s. %d kampanya işlendi, %d yeni kampanya oluşturuldu. Toplam ödeme: %s ₺.<br>%s',
            esc_html(strtoupper($parsed['schema'])),
            count($campaigns),
            $created_count,
            number_format($total_payment_pool, 2, ',', '.'),
            implode('<br>', array_map('esc_html', $log))
        ),
    );
}

function cm_auto_create_campaigns() {
    $dash_url = get_option('cm_dashboard_csv_url');

    if ($dash_url) {
        $dash_data = cm_get_csv_data($dash_url);
        $parsed = $dash_data ? cm_build_campaigns_from_dashboard($dash_data) : null;

        if ($parsed && !empty($parsed['campaigns'])) {
            $count = 0;
            foreach ($parsed['campaigns'] as $key => $data) {
                list($post_id, $created) = cm_ensure_campaign_post($key, $data['name']);
                if ($post_id && $created) $count++;
            }

            return array(
                'success' => true,
                'message' => $count ? $count . ' adet kampanya Dashboard_API üzerinden oluşturuldu.' : 'Dashboard_API içindeki tüm kampanyalar zaten mevcut.',
            );
        }
    }

    $campaigns_to_create = array(
        '56467159' => 'Uluslararası Tekstil Portalları',
        '56473878' => 'Programmatic Premium (DV360)',
        '56473848' => 'Programmatic Premium (DV360)',
        '56468206' => 'Programmatic Premium (DV360)',
        '56468542' => 'ADEX Display Network',
        '56467075' => 'ADEX Display Network',
        '56468197' => 'ADEX Display Network',
        '56463419' => 'TradeArabia / ArabianBusiness',
    );

    $count = 0;
    foreach ($campaigns_to_create as $id => $name) {
        list($post_id, $created) = cm_ensure_campaign_post($id, $name);
        if ($post_id && $created) $count++;
    }

    return array('success' => true, 'message' => $count ? $count . ' adet kampanya oluşturuldu.' : 'Tüm kampanyalar zaten mevcuttu.');
}
