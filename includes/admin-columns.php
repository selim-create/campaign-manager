<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Pro_SCM_Admin_Columns {

    public function __construct() {
        // Line Item Sütunları
        add_filter( 'manage_line_item_posts_columns', array($this, 'add_columns') );
        add_action( 'manage_line_item_posts_custom_column' , array($this, 'render_columns'), 10, 2 );
        
        // Kampanya Sütunları
        add_filter( 'manage_campaign_posts_columns', array($this, 'add_campaign_columns') );
        add_action( 'manage_campaign_posts_custom_column' , array($this, 'render_campaign_columns'), 10, 2 );
    }

    // Line Item Sütun Başlıkları
    public function add_columns($columns) {
        $new_columns = array();
        foreach($columns as $key => $title) {
            $new_columns[$key] = $title;
            if ($key == 'title') {
                $new_columns['parent_campaign'] = 'Bağlı Kampanya';
                $new_columns['dates'] = 'Tarihler';
                $new_columns['targets'] = 'Hedefler';
                $new_columns['progress'] = 'İlerleme';
                $new_columns['metrics'] = 'Özet Metrikler';
            }
        }
        return $new_columns;
    }

    // Line Item Sütun İçerikleri
    public function render_columns( $column, $post_id ) {
        switch ( $column ) {
            case 'parent_campaign':
                $pid = get_post_meta( $post_id, '_scm_parent_campaign', true );
                echo $pid ? edit_post_link( get_the_title($pid), '', '', $pid ) : '-';
                break;
            case 'dates':
                $s = get_post_meta( $post_id, '_scm_start_date', true );
                $e = get_post_meta( $post_id, '_scm_end_date', true );
                echo "<small>Baş: $s<br>Bit: $e</small>";
                break;
            case 'targets':
                $pt = get_post_meta( $post_id, '_scm_target_p_type', true );
                $pv = get_post_meta( $post_id, '_scm_target_p_val', true );
                if($pt) echo "<strong>$pt:</strong> $pv<br>";
                
                $st = get_post_meta( $post_id, '_scm_target_s_type', true );
                $sv = get_post_meta( $post_id, '_scm_target_s_val', true );
                if($st) echo "<small>$st: $sv</small>";
                break;
            
            case 'progress':
                $t_val      = (float) get_post_meta( $post_id, '_scm_target_p_val', true );
                $t_real_raw = get_post_meta( $post_id, '_scm_target_p_real', true );
                $imp        = (float) get_post_meta( $post_id, '_scm_impressions', true );
                
                $current_val = ( $t_real_raw !== '' ) ? (float)$t_real_raw : $imp;

                if ( $t_val > 0 ) {
                    $percent = min(100, max(0, ($current_val / $t_val) * 100));
                    $color = '#2ecc71'; 
                    if($percent < 30) $color = '#e74c3c'; 
                    elseif($percent < 70) $color = '#f1c40f'; 
                    
                    echo '<div style="background:#eee; border-radius:3px; width:100%; height:15px; overflow:hidden;">';
                    echo '<div style="background:'.$color.'; width:'.$percent.'%; height:100%;"></div>';
                    echo '</div>';
                    echo '<small style="font-weight:bold;">%' . number_format($percent, 1) . '</small>';
                } else {
                    echo '<span style="color:#ccc;">-</span>';
                }
                break;

            case 'metrics':
                $imp = get_post_meta( $post_id, '_scm_impressions', true );
                $clk = get_post_meta( $post_id, '_scm_clicks', true );
                echo "Imp: " . number_format((float)$imp) . "<br>Click: " . number_format((float)$clk);
                break;
        }
    }

    // ---------------------------------------------------------
    // KAMPANYA SÜTUNLARI (GÜNCELLENDİ)
    // ---------------------------------------------------------
    
    public function add_campaign_columns($columns) {
        $new_columns = array();
        
        foreach($columns as $key => $title) {
            $new_columns[$key] = $title;
            // Başlık (Title) sütunundan hemen sonra ID sütununu ekle
            if ($key == 'title') {
                $new_columns['campaign_id'] = 'Kampanya ID'; 
            }
        }
        
        $new_columns['assigned_users'] = 'Yetkili Müşteriler';
        return $new_columns;
    }

    public function render_campaign_columns( $column, $post_id ) {
        
        // 1. Kampanya ID Sütunu (YENİ)
        if( $column == 'campaign_id' ) {
            $id = get_post_meta( $post_id, 'campaign_id', true );
            if ( $id ) {
                echo '<strong style="font-family:monospace; font-size:13px; background:#f0f0f1; padding:3px 6px; border-radius:3px;">' . esc_html($id) . '</strong>';
            } else {
                echo '<span style="color:#ccc;">-</span>';
            }
        }

        // 2. Yetkili Müşteriler Sütunu
        if( $column == 'assigned_users' ) {
            $users = get_post_meta( $post_id, '_scm_assigned_users', true );
            if( !empty($users) && is_array($users) ) {
                foreach($users as $uid) {
                    $u = get_userdata($uid);
                    if($u) echo '<div style="margin-bottom:2px;"><span style="background:#e3f2fd; color:#0c3e6d; padding:2px 6px; border-radius:3px; font-size:11px; border:1px solid #d1e4f3;">' . $u->display_name . '</span></div>';
                }
            } else {
                echo '<span style="color:#ccc;">-</span>';
            }
        }
    }
}

new Pro_SCM_Admin_Columns();