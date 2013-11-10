#!/usr/bin/php
<?php
/**************************************************
 * PV取得スクリプト
 * 
 * GoogleAnalyticsからPageViewsを取得しCustomPostに入力する
 * CustomPostGAPageviews::updatePageviews()を呼び出すだけ
 *
 **************************************************/

// Wordpressライブラリを読み込み
if (!defined('ABSPATH')) {
    require_once __DIR__.'/../../../../wp-load.php';
}
require_once __DIR__.'/../CustomPostGAPageviews.php';

if (!defined('STDIN')) {
    if (WP_DEBUG) {
        echo 'コマンドラインから実行してください', PHP_EOL;
    }
    exit;
}

global $wpdb;

$cp_pageviews = new CustomPostGAPageviews();

if (!$cp_pageviews->updatePageviews()) {
    echo 'updatePageviews failed.', PHP_EOL;
    exit;
}
