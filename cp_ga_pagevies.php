<?php
/*
Plugin Name: Custom Post Google Analytics Pageviews
Plugin URI: 
Description: GoogleAnalytycsを使ってpageviewsを取得するプラグイン
Version: 0.1
Author: 
Author URI: 
*/

require_once __DIR__.'/CustomPostGAPageviews.php';

// ライブラリ生成
$cp_ga_pageviews = new CustomPostGAPageviews();

// アクティベーションフック登録
register_activation_hook(__FILE__, array($cp_ga_pageviews, 'activate'));
// ディアクティベーションフック登録
register_deactivation_hook(__FILE__, array($cp_ga_pageviews, 'deactivate'));
// トラッキングコード表示
add_action('wp_footer', array($cp_ga_pageviews, 'printTrackingCode'));
// ダッシュボード登録
add_action('wp_dashboard_setup', array($cp_ga_pageviews, 'showDashbord'));
// 管理ツールメニュー登録
add_action('admin_menu', array($cp_ga_pageviews, 'showAdminMenu'));
// wp-cron設定
add_filter('cron_schedules', array($cp_ga_pageviews, 'cronIntervals'), 20);
add_action(CustomPostGAPageviews::UPDATE_PAGEVIEWS_CRON_HANDLER, array($cp_ga_pageviews, 'updateScheduledPageviews'));
if ($cp_ga_pageviews->isEnableCron()) {
    if (!wp_next_scheduled(CustomPostGAPageviews::UPDATE_PAGEVIEWS_CRON_HANDLER)) {
        wp_schedule_event(time(), CustomPostGAPageviews::UPDATE_PAGEVIEWS_CRON_INTERVAL, CustomPostGAPageviews::UPDATE_PAGEVIEWS_CRON_HANDLER);
    }
} else {
    if (wp_next_scheduled(CustomPostGAPageviews::UPDATE_PAGEVIEWS_CRON_HANDLER)) {
        wp_clear_scheduled_hook(CustomPostGAPageviews::UPDATE_PAGEVIEWS_CRON_HANDLER);
    }
}
