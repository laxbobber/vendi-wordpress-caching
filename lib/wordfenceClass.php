<?php

namespace Vendi\Wordfence\Caching;

class wordfence {
    // public static $printStatus = false;
    // public static $wordfence_wp_version = false;
    // /**
    //  * @var WP_Error
    //  */
    // public static $authError;
    // private static $passwordCodePattern = '/\s+wf([a-z0-9 ]+)$/i'; 
    // protected static $lastURLError = false;
    // protected static $curlContent = "";
    // protected static $curlDataWritten = 0;
    // protected static $hasher = '';
    // protected static $itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    // protected static $ignoreList = false;
    // public static $newVisit = false;
    // private static $wfLog = false;
    // private static $hitID = 0;
    // private static $debugOn = null;
    private static $runInstallCalled = false;
    // public static $commentSpamItems = array();

    public static $diagnosticParams = array(
        'addCacheComment',
        'debugOn',
        'startScansRemotely',
        'ssl_verify',
        'disableConfigCaching',
        'betaThreatDefenseFeed',
    );

    public static function installPlugin(){
        self::runInstall();
        //Used by MU code below
        update_option('wordfenceActivated', 1);
    }
    public static function uninstallPlugin(){
        //Check if caching is enabled and if it is, disable it and fix the .htaccess file.
        $cacheType = wfConfig::get('cacheType', false);
        if($cacheType == 'falcon'){
            wfCache::addHtaccessCode('remove');
            wfConfig::set('cacheType', false);

            //We currently don't clear the cache when plugin is disabled because it will take too long if done synchronously and won't work because plugin is disabled if done asynchronously.
            //wfCache::scheduleCacheClear();
        } else if($cacheType == 'php'){
            wfConfig::set('cacheType', false);
        }


        //Used by MU code below
        update_option('wordfenceActivated', 0);
    }
    public static function runInstall(){
        if(self::$runInstallCalled){ return; }
        self::$runInstallCalled = true;
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        $previous_version = get_option('wordfence_version', '0.0.0');
        update_option('wordfence_version', WORDFENCE_VERSION); //In case we have a fatal error we don't want to keep running install.
        //EVERYTHING HERE MUST BE IDEMPOTENT

        $schema = new wfSchema();
        $schema->createAll(); //if not exists
        
        /** @var wpdb $wpdb */
        global $wpdb;
        
        //6.1.15
        $configTable = "{$wpdb->base_prefix}wfConfig";
        $hasAutoload = $wpdb->get_col($wpdb->prepare(<<<SQL
SELECT * FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
AND COLUMN_NAME='autoload'
AND TABLE_NAME=%s
SQL
            , $configTable));
        if (!$hasAutoload) {
            $wpdb->query("ALTER TABLE {$configTable} ADD COLUMN autoload ENUM('no', 'yes') NOT NULL DEFAULT 'yes'");
            $wpdb->query("UPDATE {$configTable} SET autoload = 'no' WHERE name = 'wfsd_engine' OR name LIKE 'wordfence_chunked_%'");
        }
        
        wfConfig::setDefaults(); //If not set

        $db = new wfDB();
        
        $prefix = $wpdb->base_prefix;
        $db->queryWriteIgnoreError("alter table $prefix"."wfConfig modify column val longblob");

        if (wfConfig::get('cacheType') == 'php' || wfConfig::get('cacheType') == 'falcon') {
            wfCache::removeCacheDirectoryHtaccess();
        }

        //6.2.0
        wfConfig::migrateCodeExecutionForUploadsPHP7();
        
        //6.2.1
        //VENDI-TODO
        if ((wfConfig::get('cacheType') == 'php' || wfConfig::get('cacheType') == 'falcon') && !wfConfig::get('wf621HadFalconEnabled')) {
            wfConfig::set('wf621HadFalconEnabled', true);
            
            // wp_schedule_single_event(time(), 'wordfence_sendFalconDeprecationNotice');
        }

        //Must be the final line
    }
    public static function install_actions(){
        register_activation_hook(  WORDFENCE_FCPATH, array( __NAMESPACE__ . '\wordfence', 'installPlugin')   );
        register_deactivation_hook(WORDFENCE_FCPATH, array( __NAMESPACE__ . '\wordfence', 'uninstallPlugin') );

        $versionInOptions = get_option('wordfence_version', false);
        if( (! $versionInOptions) || version_compare(WORDFENCE_VERSION, $versionInOptions, '>')){
            //Either there is no version in options or the version in options is greater and we need to run the upgrade
            self::runInstall();
        }

        //These access wfConfig::get('apiKey') and will fail if runInstall hasn't executed.
        wfCache::setupCaching();

        if(defined('MULTISITE') && MULTISITE === true){
            global $blog_id;
            if($blog_id == 1 && get_option('wordfenceActivated') != 1){ return; } //Because the plugin is active once installed, even before it's network activated, for site 1 (WordPress team, why?!)
        }

        add_action('publish_future_post', array( __NAMESPACE__ . '\wordfence', 'publishFuturePost' ) );
        add_action('mobile_setup', array( __NAMESPACE__ . '\wordfence', 'jetpackMobileSetup' ) ); //Action called in Jetpack Mobile Theme: modules/minileven/minileven.php

        if(is_admin()){
            add_action('admin_init', array( __NAMESPACE__ . '\wordfence', 'admin_init' ) );
            // add_action('admin_head', array( __NAMESPACE__ . '\wordfence', '_retargetWordfenceSubmenuCallout' ) );
            if(is_multisite()){
                if(wfUtils::isAdminPageMU()){
                    add_action('network_admin_menu', array( __NAMESPACE__ . '\wordfence', 'admin_menus' ) );
                } //else don't show menu
            } else {
                add_action('admin_menu', array( __NAMESPACE__ . '\wordfence', 'admin_menus' ) );
            }
            add_filter('pre_update_option_permalink_structure', array( __NAMESPACE__ . '\wordfence', 'disablePermalinksFilter' ) , 10, 2);
            if( preg_match('/^(?:falcon|php)$/', wfConfig::get('cacheType')) ){
                add_filter('post_row_actions', array( __NAMESPACE__ . '\wordfence', 'postRowActions' ) , 0, 2);
                add_filter('page_row_actions', array( __NAMESPACE__ . '\wordfence', 'pageRowActions' ) , 0, 2);
                add_action('post_submitbox_start', array( __NAMESPACE__ . '\wordfence', 'postSubmitboxStart' ) );
            }
        }
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function jetpackMobileSetup(){
        define('WFDONOTCACHE', true); //Don't cache jetpack mobile theme pages.
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajaxReceiver(){
        if(! wfUtils::isAdmin()){
            die(json_encode(array('errorMsg' => "You appear to have logged out or you are not an admin. Please sign-out and sign-in again.")));
        }
        $func = (isset($_POST['action']) && $_POST['action']) ? $_POST['action'] : $_GET['action'];
        $nonce = (isset($_POST['nonce']) && $_POST['nonce']) ? $_POST['nonce'] : $_GET['nonce'];
        if(! wp_verify_nonce($nonce, 'wp-ajax')){
            die(json_encode(array('errorMsg' => "Your browser sent an invalid security token to Wordfence. Please try reloading this page or signing out and in again.")));
        }
        //func is e.g. wordfence_ticker so need to munge it
        $func = str_replace('wordfence_', '', $func);
        $fq_func = array( __NAMESPACE__ . '\wordfence', 'ajax_' . $func . '_callback' );
        if( ! is_callable( $fq_func ) )
        {
            die(json_encode(array('errorMsg' => "Could not find AJAX func $func")));
        }
        $returnArr = call_user_func( $fq_func );
        if($returnArr === false){
            $returnArr = array('errorMsg' => "Wordfence encountered an internal error executing that request.");
        }

        if(! is_array($returnArr)){
            error_log("Function " . wp_kses($func, array()) . " did not return an array and did not generate an error.");
            $returnArr = array();
        }
        if(isset($returnArr['nonce'])){
            error_log("Wordfence ajax function return an array with 'nonce' already set. This could be a bug.");
        }
        $returnArr['nonce'] = wp_create_nonce('wp-ajax');
        die(json_encode($returnArr));
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function publishFuturePost($id){
        if(wfConfig::get('clearCacheSched')){
            wfCache::scheduleCacheClear();
        }
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function postRowActions($actions, $post){
        if(wfUtils::isAdmin()){
            $actions = array_merge($actions, array(
                'wfCachePurge' => '<a href="#" onclick="wordfenceExt.removeFromCache(\'' . $post->ID . '\'); return false;">Remove from Wordfence cache</a>'
                ));
        }
        return $actions;
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function pageRowActions($actions, $post){
        if(wfUtils::isAdmin()){
            $actions = array_merge($actions, array(
                'wfCachePurge' => '<a href="#" onclick="wordfenceExt.removeFromCache(\'' . $post->ID . '\'); return false;">Remove from Wordfence cache</a>'
                ));
        }
        return $actions;
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function postSubmitboxStart(){
        if(wfUtils::isAdmin()){
            global $post;
            echo '<div><a href="#" onclick="wordfenceExt.removeFromCache(\'' . $post->ID . '\'); return false;">Remove from Wordfence cache</a></div>';
        }
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function disablePermalinksFilter($newVal, $oldVal){
        if(wfConfig::get('cacheType', false) == 'falcon' && $oldVal && (! $newVal) ){ //Falcon is enabled and admin is disabling permalinks
            wfCache::addHtaccessCode('remove');
            wfConfig::set('cacheType', false);
        }
        return $newVal;
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_suPHPWAFUpdateChoice_callback() {
        $choice = $_POST['choice'];
        wfConfig::set('suPHPWAFUpdateChoice', '1');
        return array('ok' => 1);
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_removeFromCache_callback(){
        $id = $_POST['id'];
        $link = get_permalink($id);
        if(preg_match('/^https?:\/\/([^\/]+)(.*)$/i', $link, $matches)){
            $host = $matches[1];
            $URI = $matches[2];
            if(! $URI){
                $URI = '/';
            }
            $sslFile = wfCache::fileFromURI($host, $URI, true); //SSL
            $normalFile = wfCache::fileFromURI($host, $URI, false); //non-SSL
            @unlink($sslFile);
            @unlink($sslFile . '_gzip');
            @unlink($normalFile);
            @unlink($normalFile . '_gzip');
        }
        return array('ok' => 1);
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_saveCacheOptions_callback(){
        $changed = false;
        if($_POST['allowHTTPSCaching'] != wfConfig::get('allowHTTPSCaching', false)){
            $changed = true;
        }
        wfConfig::set('allowHTTPSCaching', $_POST['allowHTTPSCaching'] == '1' ? 1 : 0);
        wfConfig::set('addCacheComment', $_POST['addCacheComment'] == 1 ? '1' : 0);
        wfConfig::set('clearCacheSched', $_POST['clearCacheSched'] == 1 ? '1' : 0);
        if($changed && wfConfig::get('cacheType', false) == 'falcon'){
            $err = wfCache::addHtaccessCode('add');
            if($err){
                return array('updateErr' => "Wordfence could not edit your .htaccess file. The error was: " . $err, 'code' => wfCache::getHtaccessCode() );
            }
        }
        wfCache::scheduleCacheClear();
        return array('ok' => 1);
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_saveCacheConfig_callback(){
        $cacheType = $_POST['cacheType'];
        if($cacheType == 'falcon' || $cacheType == 'php'){
            $plugins = get_plugins();
            $badPlugins = array();
            foreach($plugins as $pluginFile => $data){
                if(is_plugin_active($pluginFile)){
                    if($pluginFile == 'w3-total-cache/w3-total-cache.php'){
                        $badPlugins[] = "W3 Total Cache";
                    } else if($pluginFile == 'quick-cache/quick-cache.php'){
                        $badPlugins[] = "Quick Cache";
                    } else if($pluginFile == "wp-super-cache/wp-cache.php"){
                        $badPlugins[] = "WP Super Cache";
                    } else if($pluginFile == "wp-fast-cache/wp-fast-cache.php"){
                        $badPlugins[] = "WP Fast Cache";
                    } else if($pluginFile == "wp-fastest-cache/wpFastestCache.php"){
                        $badPlugins[] = "WP Fastest Cache";
                    }
                }
            }
            if(count($badPlugins) > 0){
                return array('errorMsg' => "You can not enable caching in Wordfence with other caching plugins enabled. This may cause conflicts. You need to disable other caching plugins first. Wordfence caching is very fast and does not require other caching plugins to be active. The plugins you have that conflict are: " . implode(', ', $badPlugins) . ". Disable these plugins, then return to this page and enable Wordfence caching.");
            }
            $siteURL = site_url();
            if(preg_match('/^https?:\/\/[^\/]+\/[^\/]+\/[^\/]+\/.+/i', $siteURL)){
                return array('errorMsg' => "Wordfence caching currently does not support sites that are installed in a subdirectory and have a home page that is more than 2 directory levels deep. e.g. we don't support sites who's home page is http://example.com/levelOne/levelTwo/levelThree");
            }
        }
        if($cacheType == 'falcon'){
            if(! get_option('permalink_structure', '')){
                return array('errorMsg' => "You need to enable Permalinks for your site to use Falcon Engine. You can enable Permalinks in WordPress by going to the Settings - Permalinks menu and enabling it there. Permalinks change your site URL structure from something that looks like /p=123 to pretty URLs like /my-new-post-today/ that are generally more search engine friendly.");
            }
        }
        $warnHtaccess = false;
        if($cacheType == 'disable' || $cacheType == 'php'){
            $removeError = wfCache::addHtaccessCode('remove');
            if($removeError || $removeError2){
                $warnHtaccess = true;
            }
        }
        if($cacheType == 'php' || $cacheType == 'falcon'){
            $err = wfCache::cacheDirectoryTest();
            if($err){
                return array('ok' => 1, 'heading' => "Could not write to cache directory", 'body' => "To enable caching, Wordfence needs to be able to create and write to the /wp-content/wfcache/ directory. We did some tests that indicate this is not possible. You need to manually create the /wp-content/wfcache/ directory and make it writable by Wordfence. The error we encountered was during our tests was: $err");
            }
        }

        //Mainly we clear the cache here so that any footer cache diagnostic comments are rebuilt. We could just leave it intact unless caching is being disabled.
        if($cacheType != wfConfig::get('cacheType', false)){
            wfCache::scheduleCacheClear();
        }
        $htMsg = "";
        if($warnHtaccess){
            $htMsg = " <strong style='color: #F00;'>Warning: We could not remove the caching code from your .htaccess file. you need to remove this manually yourself.</strong> ";
        }
        if($cacheType == 'disable'){
            wfConfig::set('cacheType', false);
            return array('ok' => 1, 'heading' => "Caching successfully disabled.", 'body' => "{$htMsg}Caching has been disabled on your system.<br /><br /><center><input type='button' name='wfReload' value='Click here now to refresh this page' onclick='window.location.reload(true);' /></center>");
        } else if($cacheType == 'php'){
            wfConfig::set('cacheType', 'php');
            return array('ok' => 1, 'heading' => "Wordfence Basic Caching Enabled", 'body' => "{$htMsg}Wordfence basic caching has been enabled on your system.<br /><br /><center><input type='button' name='wfReload' value='Click here now to refresh this page' onclick='window.location.reload(true);' /></center>");
        } else if($cacheType == 'falcon'){
            if($_POST['noEditHtaccess'] != '1'){
                $err = wfCache::addHtaccessCode('add');
                if($err){
                    return array('ok' => 1, 'heading' => "Wordfence could not edit .htaccess", 'body' => "Wordfence could not edit your .htaccess code. The error was: " . $err);
                }
            }
            wfConfig::set('cacheType', 'falcon');
            // wfCache::scheduleUpdateBlockedIPs(); //Runs every 5 mins until we change cachetype
            return array('ok' => 1, 'heading' => "Wordfence Falcon Engine Activated!", 'body' => "Wordfence Falcon Engine has been activated on your system. You will see this icon appear on the Wordfence admin pages as long as Falcon is active indicating your site is running in high performance mode:<div class='wfFalconImage'></div><center><input type='button' name='wfReload' value='Click here now to refresh this page' onclick='window.location.reload(true);' /></center>");
        }
        return array('errorMsg' => "An error occurred.");
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_getCacheStats_callback(){
        $s = wfCache::getCacheStats();
        if($s['files'] == 0){
            return array('ok' => 1, 'heading' => 'Cache Stats', 'body' => "The cache is currently empty. It may be disabled or it may have been recently cleared.");
        }
        $body = 'Total files in cache: ' . $s['files'] .
            '<br />Total directories in cache: ' . $s['dirs'] .
            '<br />Total data: ' . $s['data'] . 'KB';
        if($s['compressedFiles'] > 0){
            $body .= '<br />Files: ' . $s['uncompressedFiles'] .
                '<br />Data: ' . $s['uncompressedKBytes'] . 'KB' .
                '<br />Compressed files: ' . $s['compressedFiles'] .
                '<br />Compressed data: ' . $s['compressedKBytes'] . 'KB';
        }
        if($s['largestFile'] > 0){
            $body .= '<br />Largest file: ' . $s['largestFile'] . 'KB';
        }
        if($s['oldestFile'] !== false){
            $body .= '<br />Oldest file in cache created ';
            if(time() - $s['oldestFile'] < 300){
                $body .= (time() - $s['oldestFile']) . ' seconds ago';
            } else {
                $body .= human_time_diff($s['oldestFile']) . ' ago.';
            }
        }
        if($s['newestFile'] !== false){
            $body .= '<br />Newest file in cache created ';
            if(time() - $s['newestFile'] < 300){
                $body .= (time() - $s['newestFile']) . ' seconds ago';
            } else {
                $body .= human_time_diff($s['newestFile']) . ' ago.';
            }
        }

        return array('ok' => 1, 'heading' => 'Cache Stats', 'body' => $body);
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_clearPageCache_callback(){
        $stats = wfCache::clearPageCache();
        if($stats['error']){
            $body = "A total of " . $stats['totalErrors'] . " errors occurred while trying to clear your cache. The last error was: " . $stats['error'];
            return array('ok' => 1, 'heading' => 'Error occurred while clearing cache', 'body' => $body );
        }
        $body = "A total of " . $stats['filesDeleted'] . ' files were deleted and ' . $stats['dirsDeleted'] . ' directories were removed. We cleared a total of ' . $stats['totalData'] . 'KB of data in the cache.';
        if($stats['totalErrors'] > 0){
            $body .=  ' A total of ' . $stats['totalErrors'] . ' errors were encountered. This probably means that we could not remove some of the files or directories in the cache. Please use your CPanel or file manager to remove the rest of the files in the directory: ' . WP_CONTENT_DIR . '/wfcache/';
        }
        return array('ok' => 1, 'heading' => 'Page Cache Cleared', 'body' => $body );
    }
    public static function ajax_updateConfig_callback(){
        $key = $_POST['key'];
        $val = $_POST['val'];
        wfConfig::set($key, $val);
        return array('ok' => 1);
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_checkFalconHtaccess_callback(){
        if(wfUtils::isNginx()){
            return array('nginx' => 1);
        }
        $file = wfCache::getHtaccessPath();
        if(! $file){
            return array('err' => "We could not find your .htaccess file to modify it.", 'code' => wfCache::getHtaccessCode() );
        }
        $fh = @fopen($file, 'r+');
        if(! $fh){
            $err = error_get_last();
            return array('err' => "We found your .htaccess file but could not open it for writing: " . $err['message'], 'code' => wfCache::getHtaccessCode() );
        }
        return array('ok' => 1);
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_downloadHtaccess_callback(){
        $url = site_url();
        $url = preg_replace('/^https?:\/\//i', '', $url);
        $url = preg_replace('/[^a-zA-Z0-9\.]+/', '_', $url);
        $url = preg_replace('/^_+/', '', $url);
        $url = preg_replace('/_+$/', '', $url);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="htaccess_Backup_for_' . $url . '.txt"');
        $file = wfCache::getHtaccessPath();
        readfile($file);
        die();
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_addCacheExclusion_callback(){
        $ex = wfConfig::get('cacheExclusions', false);
        if($ex){
            $ex = unserialize($ex);
        } else {
            $ex = array();
        }
        $ex[] = array(
            'pt' => $_POST['patternType'],
            'p' => $_POST['pattern'],
            'id' => microtime(true)
            );
        wfConfig::set('cacheExclusions', serialize($ex));
        wfCache::scheduleCacheClear();
        if(wfConfig::get('cacheType', false) == 'falcon' && preg_match('/^(?:uac|uaeq|cc)$/', $_POST['patternType'])){
            if(wfCache::addHtaccessCode('add')){ //rewrites htaccess rules
                return array('errorMsg' => "We added the rule you requested but could not modify your .htaccess file. Please delete this rule, check the permissions on your .htaccess file and then try again.");
            }
        }
        return array('ok' => 1);
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_removeCacheExclusion_callback(){
        $id = $_POST['id'];
        $ex = wfConfig::get('cacheExclusions', false);
        if(! $ex){
            return array('ok' => 1);
        }
        $ex = unserialize($ex);
        $rewriteHtaccess = false;
        for($i = 0; $i < sizeof($ex); $i++){
            if((string)$ex[$i]['id'] == (string)$id){
                if(wfConfig::get('cacheType', false) == 'falcon' && preg_match('/^(?:uac|uaeq|cc)$/', $ex[$i]['pt'])){
                    $rewriteHtaccess = true;
                }
                array_splice($ex, $i, 1);
                //Dont break in case of dups
            }
        }
        wfConfig::set('cacheExclusions', serialize($ex));
        if($rewriteHtaccess && wfCache::addHtaccessCode('add')){ //rewrites htaccess rules
            return array('errorMsg', "We removed that rule but could not rewrite your .htaccess file. You're going to have to manually remove this rule from your .htaccess file. Please reload this page now.");
        }
        return array('ok' => 1);
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_loadCacheExclusions_callback(){
        $ex = wfConfig::get('cacheExclusions', false);
        if(! $ex){
            return array('ex' => false);
        }
        $ex = unserialize($ex);
        return array('ex' => $ex);
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_saveConfig_callback(){
        $reload = '';
        $opts = wfConfig::parseOptions();

        // These are now on the Diagnostics page, so they aren't sent across.
        foreach (self::$diagnosticParams as $param) {
            $opts[$param] = wfConfig::get($param);
        }

        $opts['alertEmails'] = '';
        $opts['scan_exclude'] = wfUtils::cleanupOneEntryPerLine($opts['scan_exclude']);

        foreach (explode("\n", $opts['scan_include_extra']) as $regex) {
            if (@preg_match("/$regex/", "") === FALSE) {
                return array('errorMsg' => "\"" . esc_html($regex). "\" is not a valid regular expression");
            }
        }

        $whiteIPs = array();
        foreach(explode(',', preg_replace('/[\r\n\s\t]+/', ',', $opts['whitelisted'])) as $whiteIP){
            if(strlen($whiteIP) > 0){
                $whiteIPs[] = $whiteIP;
            }
        }
        $opts['whitelisted'] = '';
        $validUsers = array();
        $invalidUsers = array();
        foreach(explode(',', $opts['liveTraf_ignoreUsers']) as $val){
            $val = trim($val);
            if(strlen($val) > 0){
                if(get_user_by('login', $val)){
                    $validUsers[] = $val;
                } else {
                    $invalidUsers[] = $val;
                }
            }
        }
        $opts['loginSec_userBlacklist'] = wfUtils::cleanupOneEntryPerLine($opts['loginSec_userBlacklist']);

        $opts['apiKey'] = trim($opts['apiKey']);
        if($opts['apiKey'] && (! preg_match('/^[a-fA-F0-9]+$/', $opts['apiKey'])) ){ //User entered something but it's garbage.
            return array('errorMsg' => "You entered an API key but it is not in a valid format. It must consist only of characters A to F and 0 to 9.");
        }

        if(sizeof($invalidUsers) > 0){
            return array('errorMsg' => "The following users you selected to ignore in live traffic reports are not valid on this system: " . wp_kses(implode(', ', $invalidUsers), array()) );
        }
        if(sizeof($validUsers) > 0){
            $opts['liveTraf_ignoreUsers'] = implode(',', $validUsers);
        } else {
            $opts['liveTraf_ignoreUsers'] = '';
        }

        $validIPs = array();
        $invalidIPs = array();
        foreach(explode(',', preg_replace('/[\r\n\s\t]+/', '', $opts['liveTraf_ignoreIPs'])) as $val){
            if(strlen($val) > 0){
                if(wfUtils::isValidIP($val)){
                    $validIPs[] = $val;
                } else {
                    $invalidIPs[] = $val;
                }
            }
        }
        if(sizeof($invalidIPs) > 0){
            return array('errorMsg' => "The following IPs you selected to ignore in live traffic reports are not valid: " . wp_kses(implode(', ', $invalidIPs), array()) );
        }
        if(sizeof($validIPs) > 0){
            $opts['liveTraf_ignoreIPs'] = implode(',', $validIPs);
        }

        if(preg_match('/[a-zA-Z0-9\d]+/', $opts['liveTraf_ignoreUA'])){
            $opts['liveTraf_ignoreUA'] = trim($opts['liveTraf_ignoreUA']);
        } else {
            $opts['liveTraf_ignoreUA'] = '';
        }
        if(! $opts['other_WFNet']){
            $wfdb = new wfDB();
            global $wpdb;
            $p = $wpdb->base_prefix;
            $wfdb->queryWrite("delete from $p"."wfBlocks where wfsn=1 and permanent=0");
        }
        if($opts['howGetIPs'] != wfConfig::get('howGetIPs', '')){
            $reload = 'reload';
        }
        $regenerateHtaccess = false;
        if (isset($opts['bannedURLs'])) {
            $opts['bannedURLs'] = preg_replace('/[\n\r]+/',',', $opts['bannedURLs']);
        }
        if(wfConfig::get('bannedURLs', false) != $opts['bannedURLs']){
            $regenerateHtaccess = true;
        }

        if (!is_numeric($opts['liveTraf_maxRows'])) {
            return array(
                'errorMsg' => 'Please enter a number for the amount of Live Traffic data to store.',
            );
        }


        foreach($opts as $key => $val){
            if($key != 'apiKey'){ //Don't save API key yet
                wfConfig::set($key, $val);
            }
        }
        if($regenerateHtaccess && wfConfig::get('cacheType') == 'falcon'){
            wfCache::addHtaccessCode('add');
        }

        try {
            if ($opts['disableCodeExecutionUploads']) {
                wfConfig::disableCodeExecutionForUploads();
            } else {
                wfConfig::removeCodeExecutionProtectionForUploads();
            }
        } catch (wfConfigException $e) {
            return array('errorMsg' => $e->getMessage());
        }

        if (!empty($opts['email_summary_enabled'])) {
            wfConfig::set('email_summary_enabled', 1);
            wfConfig::set('email_summary_interval', $opts['email_summary_interval']);
            wfConfig::set('email_summary_excluded_directories', $opts['email_summary_excluded_directories']);
        } else {
            wfConfig::set('email_summary_enabled', 0);
        }

        $paidKeyMsg = false;


        // if(! $opts['apiKey']){ //Empty API key (after trim above), then try to get one.
        //     $api = new wfAPI('', wfUtils::getWPVersion());
        //     try {
        //         $keyData = $api->call('get_anon_api_key');
        //         if($keyData['ok'] && $keyData['apiKey']){
        //             wfConfig::set('apiKey', $keyData['apiKey']);
        //             wfConfig::set('isPaid', 0);
        //             $reload = 'reload';
        //             self::licenseStatusChanged();
        //         } else {
        //             throw new Exception("We could not understand the Wordfence server's response because it did not contain an 'ok' and 'apiKey' element.");
        //         }
        //     } catch(Exception $e){
        //         return array('errorMsg' => "Your options have been saved, but we encountered a problem. You left your API key blank, so we tried to get you a free API key from the Wordfence servers. However we encountered a problem fetching the free key: " . wp_kses($e->getMessage(), array()) );
        //     }
        // } else if($opts['apiKey'] != wfConfig::get('apiKey')){
        //     $api = new wfAPI($opts['apiKey'], wfUtils::getWPVersion());
        //     try {
        //         $res = $api->call('check_api_key', array(), array());
        //         if($res['ok'] && isset($res['isPaid'])){
        //             wfConfig::set('apiKey', $opts['apiKey']);
        //             $reload = 'reload';
        //             wfConfig::set('isPaid', $res['isPaid']); //res['isPaid'] is boolean coming back as JSON and turned back into PHP struct. Assuming JSON to PHP handles bools.
        //             if($res['isPaid']){
        //                 $paidKeyMsg = true;
        //             }
        //             self::licenseStatusChanged();
        //         } else {
        //             throw new Exception("We could not understand the Wordfence API server reply when updating your API key.");
        //         }
        //     } catch (Exception $e){
        //         return array('errorMsg' => "Your options have been saved. However we noticed you changed your API key and we tried to verify it with the Wordfence servers and received an error: " . wp_kses($e->getMessage(), array()) );
        //     }
        // } else {
        //     $api = new wfAPI($opts['apiKey'], wfUtils::getWPVersion());
        //     $api->call('ping_api_key', array(), array());
        // }
        return array('ok' => 1, 'reload' => $reload, 'paidKeyMsg' => $paidKeyMsg );
    }

    public static function ajax_saveDebuggingConfig_callback() {
        foreach (self::$diagnosticParams as $param) {
            wfConfig::set($param, array_key_exists($param, $_POST) ? '1' : '0');
        }
        return array('ok' => 1, 'reload' => false, 'paidKeyMsg' => '');
    }
    public static function ajax_bulkOperation_callback(){
        $op = sanitize_text_field($_POST['op']);
        if($op == 'del' || $op == 'repair'){
            $ids = $_POST['ids'];
            $filesWorkedOn = 0;
            $errors = array();
            $issues = new wfIssues();
            foreach($ids as $id){
                $id = intval($id); //Make sure input is a number.
                $issue = $issues->getIssueByID($id);
                if(! $issue){
                    $errors[] = "Could not delete one of the files because we could not find the issue. Perhaps it's been resolved?";
                    continue;
                }
                $file = $issue['data']['file'];
                $localFile = ABSPATH . '/' . $file;
                $localFile = realpath($localFile);
                if(strpos($localFile, ABSPATH) !== 0){
                    $errors[] = "An invalid file was requested: " . wp_kses($file, array());
                    continue;
                }
                if($op == 'del'){
                    if(@unlink($localFile)){
                        $issues->updateIssue($id, 'delete');
                        $filesWorkedOn++;
                    } else {
                        $err = error_get_last();
                        $errors[] = "Could not delete file " . wp_kses($file, array()) . ". Error was: " . wp_kses($err['message'], array());
                    }
                } else if($op == 'repair'){
                    $dat = $issue['data'];
                    if($result['cerrorMsg']){
                        $errors[] = $result['cerrorMsg'];
                        continue;
                    } else if(! $result['fileContent']){
                        $errors[] = "We could not get the original file of " . wp_kses($file, array()) . " to do a repair.";
                        continue;
                    }

                    if(preg_match('/\.\./', $file)){
                        $errors[] = "An invalid file " . wp_kses($file, array()) . " was specified for repair.";
                        continue;
                    }
                    $fh = fopen($localFile, 'w');
                    if(! $fh){
                        $err = error_get_last();
                        if(preg_match('/Permission denied/i', $err['message'])){
                            $errMsg = "You don't have permission to repair " . wp_kses($file, array()) . ". You need to either fix the file manually using FTP or change the file permissions and ownership so that your web server has write access to repair the file.";
                        } else {
                            $errMsg = "We could not write to " . wp_kses($file, array()) . ". The error was: " . $err['message'];
                        }
                        $errors[] = $errMsg;
                        continue;
                    }
                    flock($fh, LOCK_EX);
                    $bytes = fwrite($fh, $result['fileContent']);
                    flock($fh, LOCK_UN);
                    fclose($fh);
                    if($bytes < 1){
                        $errors[] = "We could not write to " . wp_kses($file, array()) . ". ($bytes bytes written) You may not have permission to modify files on your WordPress server.";
                        continue;
                    }
                    $filesWorkedOn++;
                    $issues->updateIssue($id, 'delete');
                }
            }
            $verb = $op == 'del' ? 'Deleted' : 'Repaired';
            $verb2 = $op == 'del' ? 'delete' : 'repair';
            if($filesWorkedOn > 0 && sizeof($errors) > 0){
                $headMsg = "$verb some files with errors";
                $bodyMsg = "$verb $filesWorkedOn files but we encountered the following errors with other files: " . implode('<br />', $errors);
            } else if($filesWorkedOn > 0){
                $headMsg = "$verb $filesWorkedOn files successfully";
                $bodyMsg = "$verb $filesWorkedOn files successfully. No errors were encountered.";
            } else if(sizeof($errors) > 0){
                $headMsg = "Could not $verb2 files";
                $bodyMsg = "We could not $verb2 any of the files you selected. We encountered the following errors: " . implode('<br />', $errors);
            } else {
                $headMsg = "Nothing done";
                $bodyMsg = "We didn't $verb2 anything and no errors were found.";
            }

            return array('ok' => 1, 'bulkHeading' => $headMsg, 'bulkBody' => $bodyMsg);
        } else {
            return array('errorMsg' => "Invalid bulk operation selected");
        }
    }

    /**
     * @vendi_flag  KEEP
     */
    public static function ajax_deleteFile_callback($issueID = null){
        if ($issueID === null) {
            $issueID = intval($_POST['issueID']);
        }
        $wfIssues = new wfIssues();
        $issue = $wfIssues->getIssueByID($issueID);
        if(! $issue){
            return array('errorMsg' => "Could not delete file because we could not find that issue.");
        }
        if(! $issue['data']['file']){
            return array('errorMsg' => "Could not delete file because that issue does not appear to be a file related issue.");
        }
        $file = $issue['data']['file'];
        $localFile = ABSPATH . '/' . $file;
        $localFile = realpath($localFile);
        if(strpos($localFile, ABSPATH) !== 0){
            return array('errorMsg' => "An invalid file was requested for deletion.");
        }
        if ($localFile === ABSPATH . 'wp-config.php' && file_exists(ABSPATH . 'wp-config.php') && empty($_POST['forceDelete'])) {
            return array(
                'errorMsg' => "You must first download a backup copy of your <code>wp-config.php</code> prior to deleting the infected file.
                The <code>wp-config.php</code> file contains your database credentials which you will need to restore normal site operations.
                Your site will <b>NOT</b> function once the <code>wp-config.php</code> has been deleted.
                <p>
                    <a class='button' href='/?_wfsf=download&nonce=" . wp_create_nonce('wp-ajax') . "&file=". rawurlencode($file) ."' target='_blank' onclick=\"jQuery('#wp-config-force-delete').show();\">Download a backup copy</a>
                    <a style='display:none' id='wp-config-force-delete' class='button' href='#' target='_blank' onclick='WFAD.deleteFile($issueID, true); return false;'>Delete wp-config.php</a>
                </p>",
            );
        }

        /** @var WP_Filesystem_Base $wp_filesystem */
        global $wp_filesystem;

        $adminURL = network_admin_url('admin.php?' . http_build_query(array(
                'page'               => 'Wordfence',
                'wfScanAction'       => 'promptForCredentials',
                'wfFilesystemAction' => 'deleteFile',
                'issueID'            => $issueID,
                'nonce'              => wp_create_nonce('wp-ajax'),
            )));

        if (!self::requestFilesystemCredentials($adminURL, null, true, false)) {
            return array(
                'ok'               => 1,
                'needsCredentials' => true,
                'redirect'         => $adminURL,
            );
        }

        if($wp_filesystem->delete($localFile)){
            $wfIssues->updateIssue($issueID, 'delete');
            return array(
                'ok' => 1,
                'localFile' => $localFile,
                'file' => $file
                );
        } else {
            $err = error_get_last();
            return array('errorMsg' => "Could not delete file " . wp_kses($file, array()) . ". The error was: " . wp_kses($err['message'], array()));
        }
    }
    public static function ajax_deleteDatabaseOption_callback(){
        /** @var wpdb $wpdb */
        global $wpdb;
        $issueID = intval($_POST['issueID']);
        $wfIssues = new wfIssues();
        $issue = $wfIssues->getIssueByID($issueID);
        if (!$issue) {
            return array('errorMsg' => "Could not remove the option because we could not find that issue.");
        }
        if (empty($issue['data']['option_name'])) {
            return array('errorMsg' => "Could not remove the option because that issue does not appear to be a database related issue.");
        }
        $prefix = $wpdb->get_blog_prefix($issue['data']['site_id']);
        if ($wpdb->query($wpdb->prepare("DELETE FROM {$prefix}options WHERE option_name = %s", $issue['data']['option_name']))) {
            $wfIssues->updateIssue($issueID, 'delete');
            return array(
                'ok'          => 1,
                'option_name' => $issue['data']['option_name'],
            );
        } else {
            return array('errorMsg' => "Could not remove the option " . esc_html($issue['data']['option_name']) . ". The error was: " . esc_html($wpdb->last_error));
        }
    }
    public static function ajax_restoreFile_callback($issueID = null){
        if ($issueID === null) {
            $issueID = intval($_POST['issueID']);
        }
        $wfIssues = new wfIssues();
        $issue = $wfIssues->getIssueByID($issueID);
        if(! $issue){
            return array('cerrorMsg' => "We could not find that issue in our database.");
        }

        /** @var WP_Filesystem_Base $wp_filesystem */
        global $wp_filesystem;

        $adminURL = network_admin_url('admin.php?' . http_build_query(array(
                'page'               => 'Wordfence',
                'wfScanAction'       => 'promptForCredentials',
                'wfFilesystemAction' => 'restoreFile',
                'issueID'            => $issueID,
                'nonce'              => wp_create_nonce('wp-ajax'),
            )));

        if (!self::requestFilesystemCredentials($adminURL, null, true, false)) {
            return array(
                'ok'               => 1,
                'needsCredentials' => true,
                'redirect'         => $adminURL,
            );
        }

        $dat = $issue['data'];
        $result = self::getWPFileContent($dat['file'], $dat['cType'], (isset($dat['cName']) ? $dat['cName'] : ''), (isset($dat['cVersion']) ? $dat['cVersion'] : ''));
        $file = $dat['file'];
        if(isset($result['cerrorMsg']) && $result['cerrorMsg']){
            return $result;
        } else if(! $result['fileContent']){
            return array('cerrorMsg' => "We could not get the original file to do a repair.");
        }

        if(preg_match('/\.\./', $file)){
            return array('cerrorMsg' => "An invalid file was specified for repair.");
        }
        $localFile = rtrim(ABSPATH, '/') . '/' . preg_replace('/^[\.\/]+/', '', $file);
        if ($wp_filesystem->put_contents($localFile, $result['fileContent'])) {
            $wfIssues->updateIssue($issueID, 'delete');
            return array(
                'ok'   => 1,
                'file' => $localFile,
            );
        }
        return array(
            'cerrorMsg' => "We could not write to that file. You may not have permission to modify files on your WordPress server.",
        );
    }
    public static function admin_init(){
        if(! wfUtils::isAdmin()){ return; }
        foreach(array(
            'activate', 'restoreFile', 'startPasswdAudit',
            'exportSettings', 'importSettings', 'bulkOperation', 'deleteFile', 'deleteDatabaseOption', 'removeExclusion',
            'ticker', 'loadIssues', 'updateIssueStatus', 'updateAllIssues',
            'loadBlockRanges', 'unblockRange', 'whois',
            'loadStaticPanel', 'saveConfig', 'downloadHtaccess', 'checkFalconHtaccess',
            'updateConfig', 'saveCacheConfig', 'removeFromCache', 'adminEmailChoice', 'suPHPWAFUpdateChoice', 'falconDeprecationChoice', 'saveCacheOptions', 'clearPageCache',
            'getCacheStats', 'clearAllBlocked', 'killScan', 'saveCountryBlocking', 'saveScanSchedule', 'tourClosed',
            'welcomeClosed', 'startTourAgain', 'downgradeLicense', 'addTwoFactor', 'twoFacActivate', 'twoFacDel',
            'loadTwoFactor', 'loadAvgSitePerf', 'sendTestEmail', 'addCacheExclusion', 'removeCacheExclusion',
            'loadCacheExclusions',
            'sendDiagnostic', 'whitelistWAFParamKey',
            'fixFPD',
            'hideFileHtaccess', 'saveDebuggingConfig', 'wafConfigureAutoPrepend',
            'whitelistBulkDelete', 'whitelistBulkEnable', 'whitelistBulkDisable',
        ) as $func){
            // if( is_callable( array( __NAMESPACE__ . '\wordfence', 'ajaxReceiver' )  ) )
            // {
                add_action('wp_ajax_wordfence_' . $func, array( __NAMESPACE__ . '\wordfence', 'ajaxReceiver' ) );
            // }
        }

        if(isset($_GET['page']) && preg_match('/^Wordfence/', @$_GET['page']) ){
            wp_enqueue_style('wp-pointer');
            wp_enqueue_script('wp-pointer');
            wp_enqueue_style('wordfence-main-style', wfUtils::getBaseURL() . 'css/main.css', '', WORDFENCE_VERSION);
            wp_enqueue_style('wordfence-colorbox-style', wfUtils::getBaseURL() . 'css/colorbox.css', '', WORDFENCE_VERSION);
            wp_enqueue_style('wordfence-dttable-style', wfUtils::getBaseURL() . 'css/dt_table.css', '', WORDFENCE_VERSION);


            wp_enqueue_script('json2');
            wp_enqueue_script('jquery.wftmpl', wfUtils::getBaseURL() . 'js/jquery.tmpl.min.js', array('jquery'), WORDFENCE_VERSION);
            wp_enqueue_script('jquery.wfcolorbox', wfUtils::getBaseURL() . 'js/jquery.colorbox-min.js', array('jquery'), WORDFENCE_VERSION);
            wp_enqueue_script('jquery.wfdataTables', wfUtils::getBaseURL() . 'js/jquery.dataTables.min.js', array('jquery'), WORDFENCE_VERSION);
            wp_enqueue_script('jquery.qrcode', wfUtils::getBaseURL() . 'js/jquery.qrcode.min.js', array('jquery'), WORDFENCE_VERSION);
            //wp_enqueue_script('jquery.tools', wfUtils::getBaseURL() . 'js/jquery.tools.min.js', array('jquery'));
            wp_enqueue_script('wordfenceAdminjs', wfUtils::getBaseURL() . 'js/admin.js', array('jquery'), WORDFENCE_VERSION);
            wp_enqueue_script('wordfenceAdminExtjs', wfUtils::getBaseURL() . 'js/tourTip.js', array('jquery'), WORDFENCE_VERSION);
            self::setupAdminVars();
        } else {
            wp_enqueue_style('wp-pointer');
            wp_enqueue_script('wp-pointer');
            wp_enqueue_script('wordfenceAdminjs', wfUtils::getBaseURL() . 'js/tourTip.js', array('jquery'), WORDFENCE_VERSION);
            self::setupAdminVars();
        }
    }
    private static function setupAdminVars(){
        $updateInt = wfConfig::get('actUpdateInterval', 2);
        if(! preg_match('/^\d+$/', $updateInt)){
            $updateInt = 2;
        }
        $updateInt *= 1000;

        wp_localize_script('wordfenceAdminjs', 'WordfenceAdminVars', array(
            'ajaxURL' => admin_url('admin-ajax.php'),
            'firstNonce' => wp_create_nonce('wp-ajax'),
            'siteBaseURL' => wfUtils::getSiteBaseURL(),
            'debugOn' => wfConfig::get('debugOn', 0),
            'actUpdateInterval' => $updateInt,
            'tourClosed' => wfConfig::get('tourClosed', 0),
            'welcomeClosed' => wfConfig::get('welcomeClosed', 0),
            'cacheType' => wfConfig::get('cacheType'),
            'liveTrafficEnabled' => wfConfig::liveTrafficEnabled()
            ));
    }
    public static function activation_warning(){
        $activationError = get_option('wf_plugin_act_error', '');
        if(strlen($activationError) > 400){
            $activationError = substr($activationError, 0, 400) . '...[output truncated]';
        }
        if($activationError){
            echo '<div id="wordfenceConfigWarning" class="updated fade"><p><strong>Wordfence generated an error on activation. The output we received during activation was:</strong> ' . wp_kses($activationError, array()) . '</p></div>';
        }
        delete_option('wf_plugin_act_error');
    }
    public static function adminEmailWarning(){
        $url = network_admin_url('admin.php?page=WordfenceSecOpt&wafAction=useMineForAdminEmailAlerts');
        $dismissURL = network_admin_url('admin.php?page=WordfenceSecOpt&wafAction=dismissAdminEmailNotice&nonce=' .
            rawurlencode(wp_create_nonce('wfDismissAdminEmailWarning')));
        echo '<div id="wordfenceAdminEmailWarning" class="fade error"><p><strong>You have not set an administrator email address to receive alerts for Wordfence.</strong> Please <a href="' . self::getMyOptionsURL() . '">click here to go to the Wordfence Options Page</a> and set an email address where you will receive security alerts from this site.</p><p><a class="button button-small" href="#" onclick="wordfenceExt.adminEmailChoice(\'mine\'); return false;"">Use My Email Address</a>
        <a class="button button-small wf-dismiss-link" href="#" onclick="wordfenceExt.adminEmailChoice(\'no\'); return false;">Dismiss</a></p></div>';
    }
    public static function falconDeprecationWarning() {
        $url = network_admin_url('admin.php?page=WordfenceSitePerf');
        $cacheName = (wfConfig::get('cacheType') == 'php' ? 'Basic' : 'Falcon');
        echo '<div id="wordfenceFalconDeprecationWarning" class="fade error"><p><strong>Support for the Falcon and Basic cache will be removed.</strong> This site currently has the ' . $cacheName . ' cache enabled, and it is scheduled to be removed in an upcoming release. Please investigate other caching options and then <a href="' . $url . '">click here to visit the cache settings page</a> to manually disable the cache. It will be disabled automatically when support is removed.</p><p>
        <a class="button button-small wf-dismiss-link" href="#" onclick="wordfenceExt.falconDeprecationChoice(\'no\'); return false;">Dismiss</a></p></div>';
    }
    public static function admin_menus(){
        if(! wfUtils::isAdmin()){ return; }
        $warningAdded = false;
        if(get_option('wf_plugin_act_error', false)){
            if(wfUtils::isAdminPageMU()){
                add_action('network_admin_notices', array( __NAMESPACE__ . '\wordfence', 'activation_warning' ) );
            } else {
                add_action('admin_notices', array( __NAMESPACE__ . '\wordfence', 'activation_warning' ) );
            }
            $warningAdded = true;
        }

        add_menu_page('Vendi Wordfence', 'Performance Setup', 'activate_plugins', 'WordfenceSitePerf', array( __NAMESPACE__ . '\wordfence', 'menu_sitePerf' ) , wfUtils::getBaseURL() . 'images/wordfence-logo-16x16.png');
    }
    public static function menu_sitePerf(){
        require 'menu_sitePerf.php';
    }
    public static function _retargetWordfenceSubmenuCallout() {
        echo <<<JQUERY
<script type="text/javascript">
jQuery(document).ready(function($) {
    $('#wfMenuCallout').closest('a').attr('target', '_blank');
});
</script>
JQUERY;

    }
    public static function getMyHomeURL(){
        return network_admin_url('admin.php?page=Wordfence', 'http');
    }
    public static function getMyOptionsURL(){
        return network_admin_url('admin.php?page=WordfenceSecOpt', 'http');
    }
    //PUBLIC API
    public static function doNotCache(){ //Call this to prevent Wordfence from caching the current page.
        wfCache::doNotCache();
        return true;
    }

    /**
     * @return bool|string
     */
    private static function getCurrentUserRole() {
        if (current_user_can('administrator') || is_super_admin()) {
            return 'administrator';
        }
        $roles = array('editor', 'author', 'contributor', 'subscriber');
        foreach ($roles as $role) {
            if (current_user_can($role)) {
                return $role;
            }
        }
        return false;
    }

    /**
     * @param string $adminURL
     * @param string $homePath
     * @param bool $relaxedFileOwnership
     * @param bool $output
     * @return bool
     */
    public static function requestFilesystemCredentials($adminURL, $homePath = null, $relaxedFileOwnership = true, $output = true) {
        if ($homePath === null) {
            $homePath = get_home_path();
        }

        global $wp_filesystem;

        !$output && ob_start();
        if (false === ($credentials = request_filesystem_credentials($adminURL, '', false, $homePath,
                array('version', 'locale'), $relaxedFileOwnership))
        ) {
            !$output && ob_end_clean();
            return false;
        }

        if (!WP_Filesystem($credentials, $homePath, $relaxedFileOwnership)) {
            // Failed to connect, Error and request again
            request_filesystem_credentials($adminURL, '', true, ABSPATH, array('version', 'locale'),
                $relaxedFileOwnership);
            !$output && ob_end_clean();
            return false;
        }

        if ($wp_filesystem->errors->get_error_code()) {
            !$output && ob_end_clean();
            return false;
        }
        !$output && ob_end_clean();
        return true;
    }
}
