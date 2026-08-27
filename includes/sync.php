<?php

function cm_get_csv_data($url) {
    $response = wp_remote_get($url, array('timeout' => 30));

    if (is_wp_error($response)) {
        error_log('AdOps Sync Error: ' . $response->get_error_message());
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    if (empty($body)) return false;

    // Satırları böl (Windows, Mac ve Linux uyumlu)
    $lines = preg_split('/\r\n|\r|\n/', $body);
    
    // Boş satırları temizle ve CSV olarak parse et
    $data = array_map('str_getcsv', array_filter($lines));
    
    return $data;
}

function cm_sync_google_sheet_data($dash_url, $pay_url) {
    if (empty($dash_url) || empty($pay_url)) return ['success' => false, 'message' => 'API Endpoints tanımlanmamış.'];

    // Düzeltme: file() yerine yeni fonksiyon kullanılıyor
    $dash_data = cm_get_csv_data($dash_url);
    
    if (!$dash_data) return ['success' => false, 'message' => 'Data Source bağlantısı başarısız. (Error: 101 - Stream Unreachable)'];
    
    $header = array_map(function($h) { return trim(strtolower(str_replace(["\xEF\xBB\xBF", ' '], ['', '_'], $h))); }, array_shift($dash_data));

    $campaigns = [];
    foreach ($dash_data as $row) {
        // Satır sütun sayısı başlık sayısıyla eşleşmiyorsa atla
        if (count($row) !== count($header)) continue;
        
        $d = array_combine($header, $row);
        $id = $d['kampanya_id'] ?? '';
        if (!$id) continue;

        $imp = (float)str_replace([',','TL'], '', $d['toplam_imp'] ?? 0);
        $clk = (float)str_replace([',','TL'], '', $d['toplam_click'] ?? 0);
        $spent = (float)str_replace([',','TL'], '', $d['harcanan_butce_ulke'] ?? 0);
        $planned = (float)str_replace([',','TL'], '', $d['planlanan_butce_genel'] ?? 0);

        $campaigns[$id]['rows'][] = [
            'ulke' => $d['ulke_adi'] ?? '',
            'mecra' => $d['mecra'] ?? '',
            'baslangic' => $d['baslangic'] ?? '',
            'bitis' => $d['bitis'] ?? '',
            'imp' => $imp,
            'click' => $clk,
            'spent' => $spent,
            'planned' => $planned,
            'cpm' => $d['gerceklesen_cpm'] ?? 0,
            'cpc' => $d['gerceklesen_cpc'] ?? 0,
            'ctr' => $d['ctr'] ?? 0
        ];
    }

    // Düzeltme: file() yerine yeni fonksiyon kullanılıyor
    $pay_data = cm_get_csv_data($pay_url);
    
    $total_payment_pool = 0;
    if ($pay_data) {
        $p_header = array_map('strtolower', array_shift($pay_data));
        foreach ($pay_data as $p_row) {
            $amount = isset($p_row[1]) ? (float)str_replace([',','TL','"'], '', $p_row[1]) : 0;
            $total_payment_pool += $amount;
        }
    } else {
        error_log('AdOps Core: Payment stream empty or unreachable.');
    }

    $log = [];
    $users = get_users();
    foreach($users as $user) update_user_meta($user->ID, '_scm_calculated_spent', 0);

    foreach ($campaigns as $camp_id => $data) {
        $posts = get_posts(['post_type'=>'campaign', 'meta_key'=>'campaign_id', 'meta_value'=>$camp_id]);
        
        if ($posts) {
            $post_id = $posts[0]->ID;
            
            $t_imp = 0; $t_clk = 0; $t_spent = 0; $t_planned = 0;
            
            foreach ($data['rows'] as $r) {
                $t_imp += $r['imp'];
                $t_clk += $r['click'];
                $t_spent += $r['spent'];
                $t_planned = max($t_planned, $r['planned']);
            }

            $t_ctr = ($t_imp > 0) ? ($t_clk / $t_imp) * 100 : 0;
            $t_cpm = ($t_imp > 0) ? ($t_spent / ($t_imp/1000)) : 0;
            $t_cpc = ($t_clk > 0) ? ($t_spent / $t_clk) : 0;

            update_post_meta($post_id, 'campaign_rows', $data['rows']);
            update_post_meta($post_id, 'total_imp', $t_imp);
            update_post_meta($post_id, 'total_clk', $t_clk);
            update_post_meta($post_id, 'total_spent', $t_spent);
            update_post_meta($post_id, 'total_planned', $t_planned);
            update_post_meta($post_id, 'total_ctr', number_format($t_ctr, 2));
            update_post_meta($post_id, 'total_cpm', number_format($t_cpm, 2));
            update_post_meta($post_id, 'total_cpc', number_format($t_cpc, 2));
            update_post_meta($post_id, 'last_sync_time', current_time('mysql'));

            $assigned_users = get_post_meta($post_id, '_scm_assigned_users', true) ?: [];
            foreach ($assigned_users as $uid) {
                $current_user_spent = (float)get_user_meta($uid, '_scm_calculated_spent', true);
                update_user_meta($uid, '_scm_calculated_spent', $current_user_spent + $t_spent);
            }

            $log[] = "CID: $camp_id synced.";
        }
    }

    update_option('cm_global_total_payment', $total_payment_pool);
    update_option('cm_last_successful_sync_time', current_time('mysql'));

    return ['success' => true, 'message' => "Data Stream Sync Successful. Total Pool: " . number_format($total_payment_pool, 2) . " TL. <br>" . implode('<br>', $log)];
}


function cm_auto_create_campaigns() {
    $campaigns_to_create = [
        '56467159' => 'Uluslararası Tekstil Portalları',
        '56473878' => 'Programmatic Premium (DV360)',
        '56473848' => 'Programmatic Premium (DV360)',
        '56468206' => 'Programmatic Premium (DV360)',
        '56468542' => 'ADEX Display Network',
        '56467075' => 'ADEX Display Network',
        '56468197' => 'ADEX Display Network',
        '56463419' => 'TradeArabia / ArabianBusiness'
    ];

    $log = [];
    $count = 0;

    foreach ($campaigns_to_create as $id => $name) {
        $existing = get_posts([
            'post_type'  => 'campaign',
            'meta_key'   => 'campaign_id',
            'meta_value' => $id,
            'posts_per_page' => 1
        ]);

        if ($existing) continue;

        $post_id = wp_insert_post([
            'post_title'  => $name,
            'post_type'   => 'campaign',
            'post_status' => 'publish'
        ]);

        if ($post_id) {
            update_post_meta($post_id, 'campaign_id', $id);
            $count++;
        }
    }

    if ($count == 0) return ['success' => true, 'message' => "Tüm kampanyalar zaten mevcuttu."];
    return ['success' => true, 'message' => "$count adet kampanya başarıyla oluşturuldu."];
}