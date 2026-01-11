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

    // Line Item
    public function add_columns($columns) {
        $new_columns = array();
        foreach($columns as $key => $title) {
            $new_columns[$key] = $title;
            if ($key == 'title') {
                $new_columns['parent_campaign'] = 'Bağlı Kampanya';
                $new_columns['dates'] = 'Tarihler';
                $new_columns['targets'] = 'Hedefler';
                $new_columns['metrics'] = 'Özet Metrikler';
            }
        }
        return $new_columns;
    }

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
            case 'metrics':
                $imp = get_post_meta( $post_id, '_scm_impressions', true );
                $clk = get_post_meta( $post_id, '_scm_clicks', true );
                echo "Imp: " . number_format((float)$imp) . "<br>Click: " . number_format((float)$clk);
                break;
        }
    }

    // Kampanya
    public function add_campaign_columns($columns) {
        $columns['assigned_users'] = 'Yetkili Müşteriler';
        return $columns;
    }

    public function render_campaign_columns( $column, $post_id ) {
        if( $column == 'assigned_users' ) {
            $users = get_post_meta( $post_id, '_scm_assigned_users', true );
            if( !empty($users) && is_array($users) ) {
                foreach($users as $uid) {
                    $u = get_userdata($uid);
                    if($u) echo '<span style="background:#eee; padding:2px 5px; border-radius:3px; margin-right:3px; font-size:11px;">' . $u->display_name . '</span>';
                }
            } else {
                echo '-';
            }
        }
    }
}

new Pro_SCM_Admin_Columns();