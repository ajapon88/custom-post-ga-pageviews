<?php
require_once __DIR__.'/lib/GAPI.php';


class CustomPostGAPageviews
{
    const VERSION = "0.1";                                                      // バージョン
    const OAUTH2_TOKEN_REFRESH_LIMIT_REMAINING_TIME = 600;                      // トークンをリフレッシュする期限(秒)
    const OPTION_PREFIX = 'cp_ga_option_';                                      // wp_optionsにデータを保存するときのプリフィックス。オプション名が被りそうだったらここを変更する。formでデータ送信する際にも使用する
    const MAX_SELECT_DB_LIMIT = 100;                                            // DBから一度に取得するpost_idの数
    const UPDATE_PAGEVIEWS_CRON_HANDLER = 'cp_ga_cron_handler';                 // WP-Cronに登録するハンドラ名
    const UPDATE_PAGEVIEWS_CRON_INTERVAL = 'update_pageviews_cron_interval';    // wp-cronで実行するインターバル
    const OPTION_PAGE_NAME = 'cp-ga-pageviews';                                 // 設定ページ名

    public $version = self::VERSION;
    public $auth_types = array('OAuth2', 'ClientLogin');
    public $option_uri;
    public $gapi = null;
    public $ga_access_faild = null;
    public $error_msg = array();
    public $timezone = null;
    public $auth = array();
    public $analytics = array();
    public $tracking = array();
    public $cron = array();
    public $access_token = null;
    public $ranking = array();
    public $enable_analytics_post_type = array();
    public $analytics_test_max_results = 10;
    public $analytics_test_results = array();
    
    /**
    　* __construct
    　* 
    　* 設定の読み込み、設定の更新、GAPIの初期化などを行う
    　*
    　* @return instance
    　*/
    function __construct()
    {
        $this->error_msg = array();
        $code = null;
        $analytics_test = false;
        $this->version = self::VERSION;
        
        $this->option_uri = $this->getBaseUrl($_SERVER['REQUEST_URI']) . '?page=' . urlencode(self::OPTION_PAGE_NAME);
        
        // DBから設定読み込み
        $this->getOptions();
        
        // 設定ページのとき
        if (is_admin() and isset($_GET['page']) and $_GET['page'] == self::OPTION_PAGE_NAME) {
            // オプション更新
            if (isset($_POST[self::OPTION_PREFIX.'backup']['code'])) {
                // バックアップ
                $backup_code = array();
                $backup_options_array = json_decode($_POST[self::OPTION_PREFIX.'backup']['code'], true);
                if (is_array($backup_options_array)) {
                    foreach($backup_options_array as $option => $data) {
                        $backup_code[self::OPTION_PREFIX.$option] = $data;
                    }
                    if (!$this->updateOptions($backup_code)) {
                        $this->error_msg[] = 'Backup: リストアに失敗しました';
                    }
                } else {
                    $this->error_msg[] = 'Backup: 有効なバックアップコードではありません';
                }
            } else {
                $this->updateOptions($_POST);
            }
            // code
            if (isset($_GET[self::OPTION_PREFIX.'auth_code'])) {
                $code = $_GET[self::OPTION_PREFIX.'auth_code'];
            }
            // 解析テスト
            if (isset($_POST[self::OPTION_PREFIX.'analytics_test'])) {
                $analytics_test = true;
            }
        }
        // 解析でフィルタするpost_type
        if (isset($this->analytics['analytics_post_type']) and $this->analytics['analytics_post_type']) {
            $this->enable_analytics_post_type = array();
            $enable_analytics_post_type = explode(',', $this->analytics['analytics_post_type']);
            foreach($enable_analytics_post_type as $pt) {
                $this->enable_analytics_post_type[] = trim($pt);
            }
        } else {
            $this->enable_analytics_post_type = null;
        }
        
        // GAPIライブラリ初期化
        $this->initGapi($code);

        // 解析テスト
        $this->analytics_test_results = array();
        if ($analytics_test) {
            $this->testAnalytics();
        }
    }
    
    /**
     * activate
     * 
     * アクティベーション
     *
     * @param void
     * @return void
     */
    function activate()
    {
        $this->updateOptions();
    }
    
    /**
     * deactivate
     * 
     * ディアクティベーション
     *
     * @param void
     * @return void
     */
    function deactivate()
    {
        global $wpdb;
        // スケジュール解除
        if (wp_next_scheduled(self::UPDATE_PAGEVIEWS_CRON_HANDLER)) {
            wp_clear_scheduled_hook(self::UPDATE_PAGEVIEWS_CRON_HANDLER);
        }
        // pageviews 削除
        $pageviews_meta_key = $this->getData('analytics', 'meta_key');
        if ($pageviews_meta_key) {
            $wpdb->query( $wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE `meta_key` = %s", $pageviews_meta_key));
        }
        // オプション削除
        delete_option(self::OPTION_PREFIX.'data');    // 設定
        
    }
    
    /**
     * updateOptions
     * 
     * 設定を保存する
     *
     * @param array : 上書き設定するオプション
     * @return boolean : 成否
     */
    function updateOptions($options = array())
    {
        global $wpdb;
        $update = false;
        $error_msg = array();
        // バージョン
        if (isset($options[self::OPTION_PREFIX.'version'])) {
            $update = true;
            $this->version = $options[self::OPTION_PREFIX.'version'];
        }
        // 認証設定
        if (isset($options[self::OPTION_PREFIX.'auth'])) {
            $update = true;
            $auth = $options[self::OPTION_PREFIX.'auth'];
            if (isset($auth['auth_type'])) {
                $this->auth['auth_type'] = $auth['auth_type'];
            }
            if (isset($auth['auth_type'])) {
                $this->auth['client_id'] = $auth['client_id'];
            }
            if (isset($auth['client_secret'])) {
                $this->auth['client_secret'] = $auth['client_secret'];
            }
            if (isset($auth['api_key'])) {
                $this->auth['api_key'] = $auth['api_key'];
            }
            if (isset($auth['redirect_uri'])) {
                $this->auth['redirect_uri'] = $auth['redirect_uri'];
            }
            
            if (!in_array($this->auth['auth_type'], $this->auth_types)) {
                $error_msg[] = 'Auth: AuthTypeが不正です';
            }
            if (!$this->auth['client_id']) {
                $error_msg[] = 'Auth: ClientIdを入力してください';
            }
            if (!$this->auth['client_secret']) {
                $error_msg[] = 'Auth: ClientSecretを入力してください';
            }
        }
        // 解析設定
        if (isset($options[self::OPTION_PREFIX.'analytics'])) {
            $update = true;
            $analytics = $options[self::OPTION_PREFIX.'analytics'];
            if (isset($analytics['profile_id'])) {
                $this->analytics['profile_id'] = $analytics['profile_id'];
            }
            if (isset($analytics['start_date'])) {
                $this->analytics['start_date'] = $analytics['start_date'];
            }
            if (isset($analytics['end_date'])) {
                $this->analytics['end_date'] = $analytics['end_date'];
            }
            if (isset($analytics['max_results'])) {
                $this->analytics['max_results'] = $analytics['max_results'];
            }
            if (isset($analytics['max_session_results'])) {
                $this->analytics['max_session_results'] = $analytics['max_session_results'];
            }
            if (isset($analytics['analytics_post_type'])) {
                $this->analytics['analytics_post_type'] = $analytics['analytics_post_type'];
            }
            if (isset($analytics['meta_key'])) {
                // meta_key が変更されたらpostmetaのmeta_keyも書き換える
                $pre_pageviews_meta_key = $this->getData('analytics', 'meta_key');
                $pageviews_meta_key = trim($analytics['meta_key']);
                if ($pre_pageviews_meta_key and $pre_pageviews_meta_key != $pageviews_meta_key) {
                    $wpdb->update($wpdb->postmeta, array('meta_key' => $pageviews_meta_key), array('meta_key' => $pre_pageviews_meta_key));
                }
                $this->analytics['meta_key'] = $pageviews_meta_key;
            }
            
            if (!filter_var($this->analytics['profile_id'], FILTER_VALIDATE_INT)) {
                $error_msg[] = 'Analytics: ProfileIDは数値で指定してください';
            }
            $start_date = strtotime($this->analytics['start_date']);
            if (!$start_date) {
                $error_msg[] = 'Analytics: StartDateはstrtotimeで評価できる値を入力してください';
            }
            $end_date = strtotime($this->analytics['end_date']);
            if (!$end_date) {
                $error_msg[] = 'Analytics: EndDateはstrtotimeで評価できる値を入力してください';
            }
            if ($start_date and $end_date and $start_date > $end_date) {
                $error_msg[] = 'Analytics: EndDateはStartDateよりも後の日付を指定してください';
            }
            if (!empty($this->analytics['max_results']) and !filter_var($this->analytics['max_results'], FILTER_VALIDATE_INT)) {
                $error_msg[] = 'Analytics: MaxResultsは数値で指定してください';
            }
            if (!empty($this->analytics['max_session_results']) and !filter_var($this->analytics['max_session_results'], FILTER_VALIDATE_INT)) {
                $error_msg[] = 'Analytics: MaxSessionResultsは数値で指定してください';
            }
            if (!$this->analytics['meta_key']) {
                $error_msg[] = 'Analytics: MetaKeyを入力してください';
            }
        }
        // トラッキング設定
        if (isset($options[self::OPTION_PREFIX.'tracking'])) {
            $update = true;
            $tracking = $options[self::OPTION_PREFIX.'tracking'];
            if (isset($tracking['tracking_code'])) {
                $this->tracking['tracking_code'] = $tracking['tracking_code'];
            }
            if (isset($tracking['post_type'])) {
                $this->tracking['tracking_post_type'] = $tracking['post_type'];
            }
        }
        // cron設定
        if (isset($options[self::OPTION_PREFIX.'cron'])) {
            $update = true;
            $cron = $options[self::OPTION_PREFIX.'cron'];
            if ($cron['enable']) {
                $this->cron['enable'] = $cron['enable']?true:false;
            }
            if (isset($cron['schedule']['type'])) {
                $this->cron['schedule']['type'] = $cron['schedule']['type'];
            }
            if (isset($cron['schedule']['day'])) {
                $this->cron['schedule']['day']  = $cron['schedule']['day'];
            }
            if (isset($cron['schedule']['week'])) {
                $this->cron['schedule']['week'] = $cron['schedule']['week'];
            }
            if (isset($cron['schedule']['hour'])) {
                $this->cron['schedule']['hour'] = $cron['schedule']['hour'];
            }
            if (isset($cron['schedule']['min'])) {
                $this->cron['schedule']['min']  = $cron['schedule']['min'];
            }

            if (!in_array($this->cron['schedule']['day'], range(1,31))) {
                $error_msg[] = 'WP-Cron: 日付は1～31のみ指定可能です';
            }
            if (!in_array($this->cron['schedule']['week'], range(0,6))) {
                $error_msg[] = 'WP-Cron: 曜日が不正です';
            }
            if (!in_array($this->cron['schedule']['hour'], range(0,23))) {
                $error_msg[] = 'WP-Cron: 時間は0～23のみ指定可能です';
            }
            if (!in_array($this->cron['schedule']['min'], range(0,59))) {
                $error_msg[] = 'WP-Cron: 分は0～59のみ指定可能です';
            }
            if (!$error_msg) {
                $this->cron['next_schedule'] = $this->nextCronScheduled();
                if ($this->cron['next_schedule'] <= time()) {
                    $error_msg[] = 'WP-Cron: スケジュールの設定に失敗しました';
                }
            }
        }
        // access_token設定
        if (isset($options[self::OPTION_PREFIX.'access_token'])) {
            $update = true;
            $access_token = $options[self::OPTION_PREFIX.'access_token'];
            if (isset($access_token['token'])) {
                $this->access_token['token'] = $access_token['token'];
            }
            if (isset($access_token['created'])) {
                $this->access_token['created']  = $access_token['created'];
            }
            
            if (!$this->access_token['token']) {
                $error_msg[] = 'AccessToken: access_tokenがありません';
            }
            if (!$this->access_token['created']) {
                $error_msg[] = 'AccessToken: 生成日が設定されていません';
            }
        }
        // ranking設定
        if (isset($options[self::OPTION_PREFIX.'ranking'])) {
            $update = true;
            $ranking = $options[self::OPTION_PREFIX.'ranking'];
            if (isset($ranking['update_date'])) {
                $this->ranking['update_date'] = $ranking['update_date'];
            }
            if (isset($ranking['start_date'])) {
                $this->ranking['start_date']  = $ranking['start_date'];
            }
            if (isset($ranking['end_date'])) {
                $this->ranking['end_date']  = $ranking['end_date'];
            }
            
            if (isset($this->ranking['update_date']) and strtotime($this->ranking['update_date']) <= 0) {
                $error_msg[] = 'Ranking: update_dateが有効な日付ではありません';
            }
            if (isset($this->ranking['start_date']) and strtotime($this->ranking['start_date']) <= 0) {
                $error_msg[] = 'Ranking: start_dateが有効な日付ではありません';
            }
            if (isset($this->ranking['end_date']) and strtotime($this->ranking['end_date']) <= 0) {
                $error_msg[] = 'Ranking: end_dateが有効な日付ではありません';
            }
        }
        
        if ($update and !$error_msg) {
            $update_data = array(
                'version' => $this->version,
                'auth' => $this->auth,
                'analytics' => $this->analytics,
                'tracking' => $this->tracking,
                'cron' => $this->cron,
                'access_token' => $this->access_token,
                'ranking' => $this->ranking,
            );
            update_option(self::OPTION_PREFIX.'data', $update_data);    // 設定
            
            return true;
        } else {
            $this->error_msg = array_merge($this->error_msg, $error_msg);
        }
        
        return false;
    }

    /**
    　* getOptions
    　* 
    　* DBからオプションを取得する
    　*
    　* @param void
    　* @return void
    　*/
    function getOptions()
    {
        $data = get_option(self::OPTION_PREFIX.'data', array());
        if (isset($data['version'])) {
            $this->version = $data['version'];
            $this->auth = $this->getData($data, 'auth', array());
            $this->analytics = $this->getData($data, 'analytics', array());
            $this->tracking = $this->getData($data, 'tracking', array());
            $this->cron = $this->getData($data, 'cron', array());
            $this->access_token = $this->getData($data, 'access_token', array());
            $this->ranking = $this->getData($data, 'ranking', array());
        } else {
            // バージョン情報がない場合はデータがないと判断
            $this->version = self::VERSION;
            $this->auth = array();
            $this->analytics = array();
            $this->tracking = array();
            $this->cron = array();
            $this->access_token = array();
            $this->ranking = array();
        }
        $this->timezone = get_option('timezone_string');    // タイムゾーン
    }
    
    /**
    　* isEnableCron
    　* 
    　* cronが有効かどうかを判定する
    　*
    　* @param void
    　* @return boolean : 有効/無効
    　*/
    function isEnableCron()
    {
        if (isset($this->cron['enable']) and $this->cron['enable']) {
            return true;
        }
        return false;
    }
    
    /**
    　* nextCronScheduled
    　* 
    　* cronの設定に沿って次にcronを実行する時間（秒）を返す
    　*
    　* @param void
    　* @return int : 実行予定日（秒）
    　*/
    function nextCronScheduled()
    {
        $preset_tz = date_default_timezone_get();
        if ($this->timezone) {
            date_default_timezone_set($this->timezone);
        }
        $next_schedule = null;
        switch($this->cron['schedule']['type']) {
            case 'monthly':
                $next_schedule = date("Y-m") . "-{$this->cron['schedule']['day']} {$this->cron['schedule']['hour']}:{$this->cron['schedule']['min']}:00";
                if (strtotime($next_schedule) <= time()) {
                    $next_schedule = date("Y-m-d H:i:s", strtotime("{$next_schedule} +1 months"));
                }
                break;
            case 'weekly':
                $dateinfo = getdate();
                $add_days = intval($this->cron['schedule']['week'] - $dateinfo['wday']);
                if ($add_days < 0) {
                    $add_days += 7;
                }
                $next_schedule = date('Y-m-d', strtotime("+{$add_days} days")) . " {$this->cron['schedule']['hour']}:{$this->cron['schedule']['min']}:00";
                if (strtotime($next_schedule) <= time()) {
                    $next_schedule = date("Y-m-d H:i:s", strtotime("{$next_schedule} +1 weeks"));
                }
                break;
            case 'daily':
                $next_schedule = date("Y-m-d") . " {$this->cron['schedule']['hour']}:{$this->cron['schedule']['min']}:00";
                if (strtotime($next_schedule) <= time()) {
                    $next_schedule = date("Y-m-d H:i:s", strtotime("{$next_schedule} +1 days"));
                }
                break;
            case 'hourly':
                $next_schedule = date("Y-m-d H") .  ":{$this->cron['schedule']['min']}:00";;
                if (strtotime($next_schedule) <= time()) {
                    $next_schedule = date("Y-m-d H:i:s", strtotime("{$next_schedule} +1 hours"));
                }
                break;
            default:
                $this->error_msg[] = 'WP-Cron: Typeが不正です';
        }
        $next_schedule_time = strtotime($next_schedule);
        date_default_timezone_set($preset_tz);
        
        return $next_schedule_time;
    }
    
    /**
    　* initGapi
    　* 
    　* GAPIを初期化する
    　*
    　* @param String $code :OAuth2.0に必要なcode
    　* @return void
    　*/
    function initGapi($code = null)
    {
        try {
            if (!isset($this->auth['auth_type'])) {
                throw new Exception('認証設定がされていません');
            }
            $this->gapi = new GAPI($this->auth['auth_type']);
            $this->gapi->setClientId($this->auth['client_id']);
            $this->gapi->setClientSecret($this->auth['client_secret']);
            if (isset($this->auth['api_key'])) {
                $this->gapi->setDeveloperKey($this->auth['api_key']);
            }
            if (isset($this->auth['redirect_uri'])) {
                $this->gapi->setRedirectUri($this->auth['redirect_uri']);
            }
            // 認証
            if ($code) {
                $this->gapi->authenticate($code);
                $access_token = $this->gapi->getAccessToken();
                if ($access_token) {
                    $update_options = array(
                        self::OPTION_PREFIX.'access_token' => array(
                            'token' => $access_token,
                            'created' => time()
                        )
                    );
                    $this->updateOptions($update_options);
                } else {
                    $this->ga_access_faild = true;
                }
            } elseif (isset($this->access_token['token'])) {
                $this->gapi->setAccessToken($this->access_token['token']);
                $token_obj = json_decode($this->access_token['token']);
                // トークンリフレッシュ
                if (isset($_GET[self::OPTION_PREFIX.'refresh']) or $token_obj->created + $token_obj->expires_in - self::OAUTH2_TOKEN_REFRESH_LIMIT_REMAINING_TIME < time()) {
                    $this->gapi->refreshToken($token_obj->refresh_token);
                    $access_token = $this->gapi->getAccessToken();
                    if ($access_token) {
                        $update_options = array(self::OPTION_PREFIX.'access_token' => array('token' => $access_token));
                        $this->updateOptions($update_options);
                    } else {
                        $this->ga_access_faild = true;
                    }
                } else {
                    $this->gapi->setAccessToken($this->access_token['token']);
                }
            }
        } catch(Exception $e) {
            $this->ga_access_faild = true;
            $this->error_msg[] = 'GAPIライブラリの初期化に失敗しました: ' . $e->getMessage();
        }
    }
    
    /**
    　* testAnalytics
    　* 
    　* GAPIで解析設定をテストする
    　*
    　* @param void
    　* @return void
    　*/
    function testAnalytics()
    {
        if (!$this->gapi) {
            $this->error_msg[] = 'GAPIライブラリの初期化に失敗したため解析テストを行えませんでした';
        } else {
            try {
                $preset_tz = date_default_timezone_get();
                if ($this->timezone) {
                    date_default_timezone_set($this->timezone);
                }
                $this->gapi->setMaxSessionResults($this->analytics['max_session_results']);
                $this->gapi->requestReportData($this->analytics['profile_id'], array('pagePath','pageTitle'), 'pageviews', '-pageviews', null, date('Y-m-d',strtotime($this->analytics['start_date'])), date('Y-m-d',strtotime($this->analytics['end_date'])), 1, $this->analytics['max_results']);
                $i=0;
                $this->analytics_test_max_results = intval($_POST[self::OPTION_PREFIX.'analytics_test']['max_results']);
                while($result = $this->gapi->fetchResult(GAPI::FETCH_OBJECT)) {
                    $this->analytics_test_results[] = $result;
                    $i++;
                    if ($this->analytics_test_max_results > 0 and $i >= $this->analytics_test_max_results) {
                        break;
                    }
                }
            } catch (Exception $e) {
                $this->error_msg[] = '解析テストエラー: '.$e->getMessage();
            }
            date_default_timezone_set($preset_tz);
        }
    }

    /**
    　* printTrackingCode
    　* 
    　* トラッキングコードをフッターに登録する
    　*
    　* @param void
    　* @return void
    　*/
    function printTrackingCode()
    {
        // 投稿ページかつ公開ページにのみ表示
        if ($this->tracking['tracking_code'] and is_single() and 'publish' == get_post_status()) {
            if ($this->tracking['tracking_post_type']) {
                $enable_post_type = explode(',', $this->tracking['tracking_post_type']);
            } else {
                $enable_post_type = null;
            }
            if (!$enable_post_type or in_array(get_post_type(), $enable_post_type)) {
                echo $this->tracking['tracking_code'];
            }
        }
    }
    
    /**
    　* showDashbord
    　* 
    　* ダッシュボードに登録する
    　*
    　* @param void
    　* @return void
    　*/
    function showDashbord() {
        global $wp_meta_boxes;

        wp_add_dashboard_widget('custom_help_widget', 'Tangle Custom Post GoogleAnalytics Pageviews', array($this, 'showDashbordOauth2TokenText'));
    }

    /**
    　* showDashbordOauth2TokenText
    　* 
    　* ダッシュボードに表示する文字列
    　*
    　* @param void
    　* @return void
    　*/
    function showDashbordOauth2TokenText()
    {
        $error_msg = array();
        if (!$this->gapi or !$this->gapi->getClientId()) {
            $error_msg[] = '認証設定がされていません。設定ページから認証設定を行ってください';
        } elseif ($this->ga_access_faild) {
            $error_msg[] = '認証に失敗しました。認証設定を確認してください';
        } elseif(!$this->gapi->getAccessToken()) {
            $error_msg[] = 'トークンを取得していません。設定ページから認証を行ってください';
        }
        if (!$this->analytics['profile_id']) {
            $error_msg[] = '解析設定がされていません。設定ページから解析設定を行ってください';
        }
        if ($error_msg) {
            foreach($error_msg as $msg) {
                echo '<p style="color:red;">', esc_attr($msg), '</p>';
            }
        } else {
            echo '<p>お知らせはありません</p>';
        }
    }

    /**
    　* showAdminMenu
    　* 
    　* 管理ページの「設定」に登録する
    　*
    　* @param void
    　* @return void
    　*/
    function showAdminMenu() {
        add_options_page( 'My Plugin Options', 'Pageviews設定', 'manage_options', self::OPTION_PAGE_NAME, array($this, 'showOptions'));
    }

    /**
    　* getBaseUrl
    　* 
    　* GETクエリを除いたURLを返す
    　*
    　* @param string $uri : 元のURI
    　* @return string : GETクエリを除いたURL
    　*/
    function getBaseUrl($uri)
    {
        if ($pos = strpos($uri, '?')) {
            $uri = substr($uri, 0, $pos);
        }
        
        return $uri;
    }

    /**
    　* updateScheduledPageviews
    　* 
    　* スケジュールに沿ってcronを実行する
    　*
    　* @param void
    　* @return void
    　*/
    function updateScheduledPageviews()
    {
        if ($this->cron['enable'] and $this->cron['next_schedule'] <= time()) {
            $this->updatePageviews();
            // 次回スケジュール設定のためにcron設定更新
            $update_options = array(
                self::OPTION_PREFIX.'cron' => array()
            );
            $this->updateOptions($update_options);
        }
    }

    /**
    　* updatePageviews
    　* 
    　* 設定したパラメータを用いて記事にpage_viewsを更新する
    　*
    　* @param void
    　* @return boolean : 成否
    　*/
    function updatePageviews()
    {
        global $wpdb;
        
        // GoogleAnalytics設定
        $ga_profile_id = $this->analytics['profile_id'];    // profile_id
        $ga_metrics = 'pageviews';                    // ディメンション
        $ga_dimensions = array('pagePath');            // 指標
        $ga_sort_metric = array('-pageviews');    // ソート
        $filter = '';
        $ga_start_index = 1;
        $ga_max_results = $this->analytics['max_results'];

        // タイムゾーン設定
        // 時間を計算するので一時的に設定する
        $preset_tz = date_default_timezone_get();
        if ($this->timezone) {
            date_default_timezone_set($this->timezone);
        }
        // 開始日・終了日
        if ($this->analytics['start_date']) {
            $ga_start_date = date('Y-m-d', strtotime($this->analytics['start_date']));
        } else {
            $ga_start_date = date('Y-m-d');
        }
        if ($this->analytics['end_date']) {
            $ga_end_date = date('Y-m-d', strtotime($this->analytics['end_date']));
        } else {
            $ga_end_date = date('Y-m-d');
        }
        $now_date = date('Y-m-d H:i:s');
        // タイムゾーン戻す
        date_default_timezone_set($preset_tz);


        try {
            if (!$this->gapi) {
                throw new Exception('GAPIの初期化に失敗しました。設定を確認してください');
            }
            $post_pageviews = array();
            //GoogleAnalyticsからデータ取得（GAPI準拠）
            $this->gapi->setMaxSessionResults($this->analytics['max_session_results']);
            $this->gapi->requestReportData($ga_profile_id, $ga_dimensions, $ga_metrics, $ga_sort_metric, $filter, $ga_start_date, $ga_end_date, $ga_start_index, $ga_max_results);
            // データを一つずつ取得
            while($result = $this->gapi->fetchResult(GAPI::FETCH_OBJECT)) {
//                echo $this->gapi->getSessionIndex(), '-', $this->gapi->getResultIndex(), "\tURL:", $result->pagePath, '    pageviews:', $result->pageviews, PHP_EOL;
                // pagePathからページを取得する
                $post_id = url_to_postid($result->getPagePath());
                if (!$post_id) {
                    // post_idが無いので無視
                    continue;
                }
                $post_type = get_post_type($post_id);
                $pageviews = intval($result->getPageviews());
                if (!$post_type) {
                    // post_type がないので無視
                    continue;
                }
                // post_idをキーにpageviewsのリスト作成
                if (empty($this->enable_analytics_post_type) or in_array($post_type, $this->enable_analytics_post_type)) {
                    if (isset($post_pageviews[$post_id])) {
                        $post_pageviews[$post_id] += $pageviews;
                    } else {
                        $post_pageviews[$post_id] = $pageviews;
                    }
                }
            }
            
            // post_idを取得するクエリ組み立て
            $from = "FROM {$wpdb->posts}";
            $where = "WHERE `post_status` = 'publish'";
            if ($this->enable_analytics_post_type) {
                $esc_pt = array();
                foreach($this->enable_analytics_post_type as $pt){
                    $esc_pt[] = "'{$wpdb->escape($pt)}'";
                }
                
                $where .= " AND `post_type` IN (".implode(',',$esc_pt).")";
            }
            $query = "SELECT `ID` {$from} {$where}";
            // pageviews保存
            // self::MAX_SELECT_DB_LIMITページずつ保存する
            $offset = 0;
            $pageviews_meta_key = $this->getData('analytics', 'meta_key', 'pageviews');
            do {
                $limit_query = $query." LIMIT ".intval($offset).",".intval(self::MAX_SELECT_DB_LIMIT);

                $results = $wpdb->get_results($limit_query);
                foreach($results as $row) {
                    $post_id = $row->ID;
                    update_post_meta($post_id, $pageviews_meta_key, isset($post_pageviews[$post_id])?$post_pageviews[$post_id]:0);
                }
                $offset += self::MAX_SELECT_DB_LIMIT;
            } while(count($results) >= self::MAX_SELECT_DB_LIMIT);
            
            // ランキング更新情報保存
            $update_options = array(
                self::OPTION_PREFIX.'ranking' => array(
                    'update_date' => $now_date,        // ランキング更新日時
                    'start_date' => $ga_start_date,    // データ取得開始日
                    'end_date' => $ga_end_date,        // データ取得終了日
                ),
            );
            $this->updateOptions($update_options);
        } catch (Exception $e) {
            $msg = date('Y-m-d H:i:s') . ': ' . $e->getMessage();
            echo $msg, PHP_EOL;
            return false;
        }
        
        return true;
    }
    

    /**
    　* cronIntervals
    　* 
    　* wp-cronのインターバルを作成する
    　*
    　* @param void
    　* @return array : インターバル
    　*/
    function cronIntervals()
    {
        $intervals[self::UPDATE_PAGEVIEWS_CRON_INTERVAL] = array('interval' => '60', 'display' => __('UpdatePageviews', 'update_pageviews'));
        return $intervals;
    }
    
    /**
    　* getData
    　* 
    　* 配列もしくはプロパティからデータを取得する
    　*
    　* @param mixid : 取得元の配列データもしくはプロパティ名
     * @param mixid : 配列のキー
     * @param mixid : データがないときのデフォルト値
    　* @return mixid : データ
    　*/
    function getData($data, $key, $default = null)
    {
        // 配列を与えられていたらその中から取得
        if (is_array($data) and isset($data[$key])) {
            return $data[$key];
        }
        // 文字列を与えられていたらプロパティから取得
        if (is_string($data)) {
            // 存在しないプロパティを指定するはずがないので存在チェックはしていない
            $propaty = $this->$data;
            if (is_array($propaty) and isset($propaty[$key])) {
                return $propaty[$key];
            }
        }
        
        // 見つからなかったのでデフォルト値
        return $default;
    }

    /**
    　* showOptions
    　* 
    　* 管理ページの「設定」で表示するHTML
    　*
    　* @param void
    　* @return void
    　*/
    function showOptions() {
        if ( !current_user_can('manage_options') ) {
            wp_die( __('You do not have sufficient permissions to access this page.') );
        }
        // 時間を使うので一時的にタイムゾーンを設定
        $preset_tz = date_default_timezone_get();
        if ($this->timezone) {
            date_default_timezone_set($this->timezone);
        }
        
        ?>
        <style type="text/css">
        .cp_ga_section {
            margin-left:20px;
        }
        .cp_ga_click {
            cursor: pointer;
        }
        p.error {
            color:red;
        }
        .clearfix:after {
            content: ".";
            display: block;
            clear: both;
            height: 0;
            visibility: hidden;
        }
        .clearfix {
            min-height: 1px;
        }
        * html .clearfix {
            height: 1px;
            /*¥*//*/
            height: auto;
            overflow: hidden;
            /**/
        }
        </style>
        <script type="text/javascript">
        <!--
        function switchDisplay(id){
            var elem = document.getElementById(id);
            if (elem.style.display == 'none') {
                elem.style.display = 'block';
            } else {
                elem.style.display = 'none';
            }
        }
        -->
        </script>
        <div class="wrap">
            <div id="icon-options-general" class="icon32"><br /></div><h2>Tangle Custom Post Google Analytics Pageviews 設定</h2>
            <br />
            <div style="margin-left:20px;">
                <?php foreach($this->error_msg as $err) : ?>
                    <p class="error"><?php echo esc_attr($err); ?></p>
                <?php endforeach; ?>
                <?php $this->showAuthOption(isset($_POST[self::OPTION_PREFIX.'auth']) or isset($_GET[self::OPTION_PREFIX.'refresh']) or isset($_GET[self::OPTION_PREFIX.'auth_code'])); ?>
                <?php $this->showAnalyticsOption(isset($_POST[self::OPTION_PREFIX.'analytics']) or isset($_POST[self::OPTION_PREFIX.'analytics_test'])); ?>
                <?php $this->showTrackingOption(isset($_POST[self::OPTION_PREFIX.'tracking'])); ?>
                <?php $this->showWpCronOption(isset($_POST[self::OPTION_PREFIX.'cron'])); ?>
                <?php $this->showBackupOption(); ?>
            </div>
        </div>
        <?php
        
        date_default_timezone_set($preset_tz);
    }

    /**
    　* showAuthOption
    　* 
    　* 設定項目：認証
    　*
    　* @param boolean : 表示するかどうか
    　* @return void
    　*/
    function showAuthOption($open=false)
    {
        ?>
        <div class="cp_ga_click" onClick="switchDisplay('auth')"><span class="icon16 icon-settings"></span><h3>認証設定</h3></div>
        <div id="auth" class="cp_ga_section" style="display:<?php if ($open) : ?>block<?php else: ?>none<?php endif; ?>" >
            <form method="post" action="<?php esc_attr_e($this->option_uri); ?>">
                <table>
                    <tr><td>AuthType:&nbsp;</td><td>
                        <select name="<?php esc_attr_e(self::OPTION_PREFIX.'auth'); ?>[auth_type]">
                    <?php foreach($this->auth_types as $k => $v) : ?>
                            <option value="<?php esc_attr_e($v); ?>" <?php if ($this->getData('auth', 'auth_type') == $v) : ?>selected<?php endif; ?> ><?php esc_attr_e($v); ?></option>
                    <?php endforeach; ?>
                        </select></td></tr>
                    <tr><td>ClientID(GoogleAccount):&nbsp;</td><td><input type="text" name="<?php esc_attr_e(self::OPTION_PREFIX.'auth'); ?>[client_id]" value="<?php esc_attr_e($this->getData('auth', 'client_id')); ?>" style="width:300px;" /></td></tr>
                    <tr><td>ClientSecret(Password):&nbsp;</td><td><input type="text" name="<?php esc_attr_e(self::OPTION_PREFIX.'auth'); ?>[client_secret]" value="<?php esc_attr_e($this->getData('auth', 'client_secret')); ?>" style="width:300px;" /></td></tr>
                    <tr><td>ApiKey:&nbsp;</td><td><input type="text" name="<?php esc_attr_e(self::OPTION_PREFIX.'auth'); ?>[api_key]" value="<?php esc_attr_e($this->getData('auth', 'api_key')); ?>" style="width:300px;" /></td></tr>
                    <tr><td>RedirecURI:&nbsp;</td><td><input type="text" name="<?php esc_attr_e(self::OPTION_PREFIX.'auth'); ?>[redirect_uri]" value="<?php esc_attr_e($this->getData('auth', 'redirect_uri')); ?>" style="width:300px;" /></td></tr>
                </table>
                <input type="hidden" name="page" value="<?php esc_attr_e(self::OPTION_PAGE_NAME); ?>" />
                <input type="submit" value="保存" />
            </form>

        <?php if ($this->gapi and ($auth_url = $this->gapi->createAuthUrl())) : ?>
            <form method="get" action="<?php esc_attr_e($this->option_uri); ?>">
                code:<input type="text" name="<?php esc_attr_e(self::OPTION_PREFIX.'auth_code'); ?>" style="width:400px;" />
                <input type="hidden" name="page" value="<?php esc_attr_e(self::OPTION_PAGE_NAME); ?>" />
                <input type="submit" value="認証" />
            </form>
            <a href="<?php esc_attr_e($auth_url); ?>" target="_blank">code取得ページ</a>
        <?php endif; ?>

        <?php if ('OAuth2' == $this->getData('auth', 'auth_type')) : ?>
            <?php $this->showOAuth2TokenOption(isset($_GET[self::OPTION_PREFIX.'refresh']) or isset($_GET[self::OPTION_PREFIX.'auth_code'])); ?>
        <?php endif; ?>
    
        </div>
        <?php
    }

    /**
    　* showAnalyticsOption
    　* 
    　* 設定項目：解析
    　*
    　* @param boolean : 表示するかどうか
    　* @return void
    　*/
    function showAnalyticsOption($open=false)
    {
        ?>
        <div class="cp_ga_click" onClick="switchDisplay('analytics')"><span class="icon16 icon-settings"></span><h3>解析設定</h3></div>
        <div id="analytics" class="cp_ga_section" style="display:<?php if ($open) : ?>block<?php else: ?>none<?php endif; ?>">
            <form method="post" action="<?php esc_attr_e($this->option_uri); ?>">
                <table>
                    <tr><td>ProfileID:&nbsp;</td><td><input type="text" name="<?php esc_attr_e(self::OPTION_PREFIX.'analytics'); ?>[profile_id]" value="<?php esc_attr_e($this->getData('analytics', 'profile_id')); ?>" /></td></tr>
                    <tr><td>StartDate:&nbsp;</td><td><input type="text" name="<?php esc_attr_e(self::OPTION_PREFIX.'analytics'); ?>[start_date]" value="<?php esc_attr_e($this->getData('analytics', 'start_date')); ?>" /></td></tr>
                    <tr><td>EndDate:&nbsp;</td><td><input type="text" name="<?php esc_attr_e(self::OPTION_PREFIX.'analytics'); ?>[end_date]" value="<?php esc_attr_e($this->getData('analytics', 'end_date')); ?>" /></td></tr>
                    <tr><td>MaxResults:&nbsp;</td><td><input type="text" name="<?php esc_attr_e(self::OPTION_PREFIX.'analytics'); ?>[max_results]" value="<?php esc_attr_e($this->getData('analytics', 'max_results')); ?>" /></td></tr>
                    <tr><td>MaxSessionResults:&nbsp;</td><td><input type="text" name="<?php esc_attr_e(self::OPTION_PREFIX.'analytics'); ?>[max_session_results]" value="<?php esc_attr_e($this->getData('analytics', 'max_session_results')); ?>" /></td></tr>
                    <tr><td>PostType:&nbsp;</td><td><input type="text" name="<?php esc_attr_e(self::OPTION_PREFIX.'analytics'); ?>[analytics_post_type]" value="<?php esc_attr_e($this->getData('analytics', 'analytics_post_type')); ?>" /></td></tr>
                    <tr><td>MetaKey:&nbsp;</td><td><input type="text" name="<?php esc_attr_e(self::OPTION_PREFIX.'analytics'); ?>[meta_key]" value="<?php esc_attr_e($this->getData('analytics', 'meta_key', 'pageviews')); ?>" /></td></tr>
                </table>
                <input type="hidden" name="page" value="<?php esc_attr_e(self::OPTION_PAGE_NAME); ?>" />
                <input type="submit" value="保存" />
            </form>
            <div class="cp_ga_click" onClick="switchDisplay('analytics_test')"><h4>▼解析テスト</h4></div>
            <div id="analytics_test" class="cp_ga_section" style="display:block">
                <form method="post" action="<?php esc_attr_e($this->option_uri); ?>">
                    取得件数:&nbsp;<input type="text" name="<?php esc_attr_e(self::OPTION_PREFIX.'analytics_test'); ?>[max_results]" value="<?php esc_attr_e($this->analytics_test_max_results); ?>" />
                    <input type="submit" value="テスト" />
                </form>
        <?php if ($this->analytics_test_results) : ?>
                <table border="1">
                    <tr><th>順位</th><th>postID</th><th>ページ名</th><th>ページアドレス</th><th>投稿タイプ</th><th>PageViews</th></tr>
                    <caption>解析テスト結果<?php if ($this->analytics_test_max_results > 0) : ?>（上位<?php esc_attr_e($this->analytics_test_max_results); ?>件）<?php endif; ?></caption>
                <?php
                foreach($this->analytics_test_results as $rank => $result) :
                    $post_id = url_to_postid($result->getPagePath());
                    $post_type = get_post_type($post_id);
                    if ($this->enable_analytics_post_type and !in_array($post_type, $this->enable_analytics_post_type)) {
                        continue;
                    }
                ?>
                    <tr>
                        <td align="right"><?php esc_attr_e($rank+1); ?></td>
                        <td align="right"><?php esc_attr_e($post_id)?:'<br />'; ?></td>
                        <td><?php esc_attr_e($result->getPageTitle()); ?></td>
                        <td><?php esc_attr_e($result->getPagePath()); ?></td>
                        <td align="center"><?php esc_attr_e($post_type)?:'-'; ?></td>
                        <td align="right"><?php esc_attr_e($result->getPageviews()); ?></td>
                    </tr>
                <?php endforeach; ?>
                </table>
        <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
    　* showTrackingOption
    　* 
    　* 設定項目：トラッキング
    　*
    　* @param boolean : 表示するかどうか
    　* @return void
    　*/
    function showTrackingOption($open=false)
    {
        ?>
        <div class="cp_ga_click" onClick="switchDisplay('tracking')"><span class="icon16 icon-settings"></span><h3>トラッキング設定</h3></div>
        <div id="tracking" class="cp_ga_section" style="display:<?php if ($open) : ?>block<?php else: ?>none<?php endif; ?>">
            <form method="post" action="<?php esc_attr_e($this->option_uri); ?>">
                <table>
                    <tr><td>TrackingCode:&nbsp;</td><td><textarea name="<?php esc_attr_e(self::OPTION_PREFIX.'tracking'); ?>[tracking_code]" style="resize: both;" cols=50 rows=10><?php esc_attr_e($this->getData('tracking', 'tracking_code')); ?></textarea></td></tr>
                    <tr><td>PostType:&nbsp;</td><td><input type="text" name="<?php esc_attr_e(self::OPTION_PREFIX.'tracking'); ?>[post_type]" value="<?php esc_attr_e($this->getData('tracking', 'tracking_post_type')); ?>" /></td></tr>
                </table>
                <input type="hidden" name="page" value="<?php esc_attr_e(self::OPTION_PAGE_NAME); ?>" />
                <input type="submit" value="保存" />
            </form>
        </div>
        <?php
    }

    /**
    　* showOAuth2TokenOption
    　* 
    　* 設定項目：OAuth2トークン
    　*
    　* @param boolean : 表示するかどうか
    　* @return void
    　*/
    function showOAuth2TokenOption($open=false)
    {
        ?>
        <div class="cp_ga_click" onClick="switchDisplay('token')"><h4>▼トークンの状態</h4></div>
        <div id="token" class="cp_ga_section" style="display:<?php if ($open) : ?>block<?php else: ?>none<?php endif; ?>">
        <?php if (!isset($this->access_token['token'])) : ?>
            <p class="error">トークンがセットされていません</p><br />
        <?php else : ?>
            <br />
            <?php
            if ($token = json_decode($this->access_token['token'])) :
                $create_date = date('Y/m/d H:i:s', $this->getData('access_token', 'created'));
                $refresh_date = date('Y/m/d H:i:s', $token->created);
                $limit_date = date('Y/m/d H:i:s', $token->created + $token->expires_in);
                $next_refresh_date = date('Y/m/d H:i:s', $token->created + $token->expires_in - self::OAUTH2_TOKEN_REFRESH_LIMIT_REMAINING_TIME);
            ?>
                <table style="border:solid 1px;">
                    <caption>*****トークンの状態*****</caption>
                    <tr><td>トークン&nbsp;</td><td>:</td><td align="center"><?php echo esc_attr($token->access_token); ?></td></tr>
                    <tr><td>リフレッシュトークン&nbsp;</td><td>:</td><td align="center"><?php echo esc_attr($token->refresh_token); ?></td></tr>
                    <tr><td>トークン生成日時&nbsp;</td><td>:</td><td align="center"><?php echo esc_attr($create_date); ?></td></tr>
                    <tr><td>トークンリフレッシュ日時</td><td>:</td><td align="center"><?php echo esc_attr($refresh_date); ?></td></tr>
                    <tr><td>トークン有効期限(自動でリフレッシュされます)</td><td>:</td><td align="center"><?php echo esc_attr($limit_date); ?></td></tr>
                    <tr><td>トークンリフレッシュ予定日時</td><td>:</td><td align="center"><?php echo esc_attr($next_refresh_date); ?></td></tr>
                </table>
                <form method="get" action="<?php echo esc_attr($this->option_uri); ?>">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::OPTION_PAGE_NAME); ?>">
                    <input type="submit" name="<?php echo esc_attr(self::OPTION_PREFIX.'refresh'); ?>" value="トークンリフレッシュ">
                </form>
            <?php else: ?>
                <p class="error">*****トークンを取得していません*****<p>
            <?php endif; ?>
        <?php endif; ?>
        </div>
        <?php
    }

    /**
    　* showWpCronOption
    　* 
    　* 設定項目：WP-Cron
    　*
    　* @param boolean : 表示するかどうか
    　* @return void
    　*/
    function showWpCronOption($open=false)
    {
        $schedule = $this->getData('cron', 'schedule', array());
        ?>
        <div class="cp_ga_click" onClick="switchDisplay('cron')"><span class="icon16 icon-settings"></span><h3>WP-Cron設定</h3></div>
        <div id="cron"class="cp_ga_section" style="display:<?php if ($open) : ?>block<?php else: ?>none<?php endif; ?>">
            <script type="text/javascript">
            <!--
            function switchCronType(){
                var elem_type = document.getElementById('cron_type');
                var elem_week = document.getElementById('cron_week');
                var elem_day  = document.getElementById('cron_day');
                var elem_hour = document.getElementById('cron_hour');
                var elem_min  = document.getElementById('cron_min');
                if (elem_type.value == 'monthly') {
                    elem_week.style.display = 'none';
                    elem_day.style.display  = 'block';
                    elem_hour.style.display = 'block';
                    elem_min.style.display  = 'block';
                } else if (elem_type.value == 'weekly') {
                    elem_week.style.display = 'block';
                    elem_day.style.display  = 'none';
                    elem_hour.style.display = 'block';
                    elem_min.style.display  = 'block';
                } else if (elem_type.value == 'daily') {
                    elem_week.style.display = 'none';
                    elem_day.style.display  = 'none';
                    elem_hour.style.display = 'block';
                    elem_min.style.display  = 'block';
                } else if (elem_type.value == 'hourly') {
                    elem_week.style.display = 'none';
                    elem_day.style.display  = 'none';
                    elem_hour.style.display = 'none';
                    elem_min.style.display  = 'block';
                }
            }
            -->
            </script>
            <form method="post" action="<?php esc_attr_e($this->option_uri); ?>">
                <input type="checkbox" name="<?php esc_attr_e(self::OPTION_PREFIX.'cron'); ?>[enable]" value="1" <?php if ($this->getData('cron', 'enable')) : ?>checked<?php endif; ?>>有効<br />
                <div class="clearfix">
                    <div style="float:left;">
                        Type:<select id="cron_type" name="<?php esc_attr_e(self::OPTION_PREFIX.'cron'); ?>[schedule][type]" onChange="switchCronType();">
                        <?php
                        $cron_type=array('monthly','weekly','daily','hourly',);
                        foreach($cron_type as $t) :
                        ?>
                            <option value="<?php esc_attr_e($t); ?>" <?php if ($t == $this->getData($schedule, 'type')) : ?>selected<?php endif; ?>><?php esc_attr_e($t); ?></option>
                        <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="cron_week" style="float:left;display:none;">
                        Week:<select name="<?php esc_attr_e(self::OPTION_PREFIX.'cron'); ?>[schedule][week]">
                        <?php
                        $week=array('Sunday','Monday','Tuesday','Wednesday','Thursday','Fryday','Saturday',);
                        foreach($week as $k=>$w) :
                        ?>
                            <option value="<?php esc_attr_e($k); ?>" <?php if ($k == $this->getData($schedule, 'week')) : ?>selected<?php endif; ?>><?php esc_attr_e($w); ?></option>
                        <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="cron_day" style="float:left;">
                        Day:<select name="<?php esc_attr_e(self::OPTION_PREFIX.'cron'); ?>[schedule][day]">
                        <?php foreach(range(1,31) as $day) : ?>
                            <option value="<?php esc_attr_e($day); ?>" <?php if ($day == $this->getData($schedule, 'day')) : ?>selected<?php endif; ?>><?php esc_attr_e($day); ?></option>
                        <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="cron_hour" style="float:left;">
                        Hour:<select name="<?php esc_attr_e(self::OPTION_PREFIX.'cron'); ?>[schedule][hour]">
                        <?php foreach(range(0,23) as $hour) : ?>
                            <option value="<?php esc_attr_e($hour); ?>" <?php if ($hour == $this->getData($schedule, 'hour')) : ?>selected<?php endif; ?>><?php esc_attr_e($hour); ?></option>
                        <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="cron_min" style="float:left;">
                        Min:<select name="<?php esc_attr_e(self::OPTION_PREFIX.'cron'); ?>[schedule][min]">
                        <?php foreach(range(0,59) as $min) : ?>
                            <option value="<?php esc_attr_e($min); ?>" <?php if ($min == $this->getData($schedule, 'min')) : ?>selected<?php endif; ?>><?php esc_attr_e($min); ?></option>
                        <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="submit" value="設定">
                </div>
            </form>
            <?php if ($this->getData('cron', 'enable')) : ?>
                次回実行日:<?php esc_attr_e(date('Y-m-d H:i:s', $this->cron['next_schedule'])); ?><br />
            <?php endif; ?>
            <script type="text/javascript">
            <!--
            switchCronType();
            -->
            </script>
            <?php if ($this->ranking) : ?>
                <table style="border:solid 1px;">
                    <caption>*****ランキング更新日*****</caption>
                    <tr><td>更新日時</td><td>:</td><td><?php esc_attr_e($this->getData('ranking', 'update_date', '-')); ?></td></tr>
                    <tr><td>取得期間</td><td>:</td><td><?php esc_attr_e($this->getData('ranking', 'start_date', '-')); ?>&nbsp;～&nbsp;<?php esc_attr_e($this->getData('ranking', 'end_date', '-')); ?></td></tr>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
    　* showBackupOption
    　* 
    　* 設定項目：バックアップ
    　*
    　* @param boolean : 表示するかどうか
    　* @return void
    　*/
    function showBackupOption($open=false)
    {
        $data = get_option(self::OPTION_PREFIX.'data', array());
        if ($data) {
            $backup_code = json_encode($data);
        } else {
            $backup_code = '';
        }
        ?>
        <div class="cp_ga_click" onClick="switchDisplay('backup')"><span class="icon16 icon-settings"></span><h3>バックアップ</h3></div>
        <div id="backup" class="cp_ga_section" style="display:<?php if ($open) : ?>block<?php else: ?>none<?php endif; ?>">
            <form method="post" action="<?php esc_attr_e($this->option_uri); ?>">
                <table>
                    <tr><td>BackupCode:&nbsp;</td><td><textarea name="<?php esc_attr_e(self::OPTION_PREFIX.'backup'); ?>[code]" style="resize: both;" cols=50 rows=10><?php esc_attr_e($backup_code); ?></textarea></td></tr>
                </table>
                <input type="hidden" name="page" value="<?php esc_attr_e(self::OPTION_PAGE_NAME); ?>" />
                <input type="submit" value="リストア" />
            </form>
        </div>
        <?php
    }
}
