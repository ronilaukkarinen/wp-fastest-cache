<?php
/*
Plugin Name: WP Fastest Cache
Plugin URI: http://wordpress.org/plugins/wp-fastest-cache/
Description: The simplest and fastest WP Cache system
Version: 0.8.9.6
Author: Emre Vona
Author URI: http://tr.linkedin.com/in/emrevona
Text Domain: wp-fastest-cache
Domain Path: /languages/

Copyright (C)2013 Emre Vona

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
*/
	//test6
	if (!defined('WPFC_WP_CONTENT_BASENAME')) {
		if (!defined('WPFC_WP_PLUGIN_DIR')) {
			if(preg_match("/(\/trunk\/|\/wp-fastest-cache\/)$/", plugin_dir_path( __FILE__ ))){
				define("WPFC_WP_PLUGIN_DIR", preg_replace("/(\/trunk\/|\/wp-fastest-cache\/)$/", "", plugin_dir_path( __FILE__ )));
			}else if(preg_match("/\\\wp-fastest-cache\/$/", plugin_dir_path( __FILE__ ))){
				//D:\hosting\LINEapp\public_html\wp-content\plugins\wp-fastest-cache/
				define("WPFC_WP_PLUGIN_DIR", preg_replace("/\\\wp-fastest-cache\/$/", "", plugin_dir_path( __FILE__ )));
			}
		}
		define("WPFC_WP_CONTENT_DIR", dirname(WPFC_WP_PLUGIN_DIR));
		define("WPFC_WP_CONTENT_BASENAME", basename(WPFC_WP_CONTENT_DIR));
	}

	if (!defined('WPFC_MAIN_PATH')) {
		define("WPFC_MAIN_PATH", plugin_dir_path( __FILE__ ));
	}

	if(!isset($GLOBALS["wp_fastest_cache_options"])){
		if($wp_fastest_cache_options = get_option("WpFastestCache")){
			$GLOBALS["wp_fastest_cache_options"] = json_decode($wp_fastest_cache_options);
		}else{
			$GLOBALS["wp_fastest_cache_options"] = array();
		}
	}

	function wpfastestcache_activate(){
		if($options = get_option("WpFastestCache")){
			$post = json_decode($options, true);

			include_once('inc/admin.php');
			$wpfc = new WpFastestCacheAdmin();
			$wpfc->modifyHtaccess($post);
		}
	}

	function wpfastestcache_deactivate(){
		$wpfc = new WpFastestCache();

		$path = ABSPATH;
		
		if($wpfc->is_subdirectory_install()){
			$path = $wpfc->getABSPATH();
		}

		if(is_file($path.".htaccess") && is_writable($path.".htaccess")){
			$htaccess = file_get_contents($path.".htaccess");
			$htaccess = preg_replace("/#\s?BEGIN\s?WpFastestCache.*?#\s?END\s?WpFastestCache/s", "", $htaccess);
			$htaccess = preg_replace("/#\s?BEGIN\s?GzipWpFastestCache.*?#\s?END\s?GzipWpFastestCache/s", "", $htaccess);
			$htaccess = preg_replace("/#\s?BEGIN\s?LBCWpFastestCache.*?#\s?END\s?LBCWpFastestCache/s", "", $htaccess);
			$htaccess = preg_replace("/#\s?BEGIN\s?WEBPWpFastestCache.*?#\s?END\s?WEBPWpFastestCache/s", "", $htaccess);
			@file_put_contents($path.".htaccess", $htaccess);
		}

		$wpfc->deleteCache();
	}

	register_activation_hook( __FILE__, "wpfastestcache_activate");
	register_deactivation_hook( __FILE__, "wpfastestcache_deactivate");

	class WpFastestCache{
		private $systemMessage = "";
		private $options = array();
		public $noscript = "";
		public $content_url = "";

		public function __construct(){
			$this->set_content_url();
			
			$optimize_image_ajax_requests = array("wpfc_revert_image_ajax_request", 
												  "wpfc_statics_ajax_request",
												  "wpfc_optimize_image_ajax_request",
												  "wpfc_update_image_list_ajax_request"
												  );

			add_action('wp_ajax_wpfc_delete_cache', array($this, "deleteCacheToolbar"));
			add_action('wp_ajax_wpfc_delete_cache_and_minified', array($this, "deleteCssAndJsCacheToolbar"));
			add_action('wp_ajax_wpfc_delete_current_page_cache', array($this, "delete_current_page_cache"));
			add_action( 'wp_ajax_wpfc_save_timeout_pages', array($this, 'wpfc_save_timeout_pages_callback'));
			add_action( 'wp_ajax_wpfc_save_exclude_pages', array($this, 'wpfc_save_exclude_pages_callback'));
			add_action( 'wp_ajax_wpfc_cdn_options', array($this, 'wpfc_cdn_options_ajax_request_callback'));
			add_action( 'wp_ajax_wpfc_remove_cdn_integration', array($this, 'wpfc_remove_cdn_integration_ajax_request_callback'));
			add_action( 'wp_ajax_wpfc_save_cdn_integration', array($this, 'wpfc_save_cdn_integration_ajax_request_callback'));
			add_action( 'wp_ajax_wpfc_cdn_template', array($this, 'wpfc_cdn_template_ajax_request_callback'));
			add_action( 'wp_ajax_wpfc_check_url', array($this, 'wpfc_check_url_ajax_request_callback'));
			add_action( 'wp_ajax_wpfc_cache_statics_get', array($this, 'wpfc_cache_statics_get_callback'));
			add_action( 'wp_ajax_wpfc_db_statics', array($this, 'wpfc_db_statics_callback'));
			add_action( 'wp_ajax_wpfc_db_fix', array($this, 'wpfc_db_fix_callback'));
			add_action( 'rate_post', array($this, 'wp_postratings_clear_fastest_cache'), 10, 2);
			add_action( 'user_register', array($this, 'modify_htaccess_for_new_user'), 10, 1);
			add_action( 'profile_update', array($this, 'modify_htaccess_for_new_user'), 10, 1);
			add_action( 'edit_terms', array($this, 'delete_cache_of_term'), 10, 1);

			// to check nonce is timeout or not
			//add_action('init', array($this, "nonce_timeout"));

			// to clear cache after new Woocommerce orders
			add_action( 'woocommerce_checkout_order_processed', array($this, 'clear_cache_after_woocommerce_checkout_order_processed'), 1, 1);

			// kk Star Ratings: to clear the cache of the post after voting
			add_action( 'kksr_rate', array($this, 'clear_cache_on_kksr_rate'));

			// to clear cache after ajax request by other plugins
			if(isset($_POST["action"])){
				// All In One Schema.org Rich Snippets
				if(preg_match("/bsf_(update|submit)_rating/i", $_POST["action"])){
					if(isset($_POST["post_id"])){
						$this->singleDeleteCache(false, $_POST["post_id"]);
					}
				}

				// Yet Another Stars Rating
				if($_POST["action"] == "yasr_send_visitor_rating"){
					if(isset($_POST["post_id"])){
						// to need call like that because get_permalink() does not work if we call singleDeleteCache() directly
						add_action('init', array($this, "singleDeleteCache"));
					}
				}
			}

			// to clear /tmpWpfc folder
			if(is_dir($this->getWpContentDir("/cache/tmpWpfc"))){
				$this->rm_folder_recursively($this->getWpContentDir("/cache/tmpWpfc"));
			}

			if($this->isPluginActive('wp-polls/wp-polls.php')){
					//for WP-Polls 
					require_once "inc/wp-polls.php";
					$wp_polls = new WpPollsForWpFc();
					$wp_polls->hook();
			}

			if(isset($_GET) && isset($_GET["action"]) && in_array($_GET["action"], $optimize_image_ajax_requests)){
				if($this->isPluginActive("wp-fastest-cache-premium/wpFastestCachePremium.php")){
					include_once $this->get_premium_path("image.php");
					$img = new WpFastestCacheImageOptimisation();
					$img->hook();
				}
			}else if(isset($_GET) && isset($_GET["action"])  && $_GET["action"] == "wpfastestcache"){
				if(isset($_GET) && isset($_GET["type"])  && $_GET["type"] == "preload"){
					// /?action=wpfastestcache&type=preload
					
					add_action('init', array($this, "create_preload_cache"), 11);
				}

				if(isset($_GET) && isset($_GET["type"]) && preg_match("/^clearcache(andminified)*$/i", $_GET["type"])){
					// /?action=wpfastestcache&type=clearcache&token=123
					// /?action=wpfastestcache&type=clearcacheandminified&token=123

					if(isset($_GET["token"]) && $_GET["token"]){
						if(defined("WPFC_CLEAR_CACHE_URL_TOKEN") && WPFC_CLEAR_CACHE_URL_TOKEN){
							if(WPFC_CLEAR_CACHE_URL_TOKEN == $_GET["token"]){
								if($this->isPluginActive("wp-fastest-cache-premium/wpFastestCachePremium.php")){
									include_once $this->get_premium_path("mobile-cache.php");
								}

								if($_GET["type"] == "clearcache"){
									$this->deleteCache();
								}

								if($_GET["type"] == "clearcacheandminified"){
									$this->deleteCache(true);
								}

								die("Done");
							}else{
								die("Wrong token");
							}
						}else{
							die("WPFC_CLEAR_CACHE_URL_TOKEN must be defined");
						}
					}else{
						die("Security token must be set.");
					}
				}
			}else{
				$this->setCustomInterval();

				$this->options = $this->getOptions();

				add_action('transition_post_status',  array($this, 'on_all_status_transitions'), 10, 3 );

				$this->commentHooks();

				$this->checkCronTime();

				if($this->isPluginActive("wp-fastest-cache-premium/wpFastestCachePremium.php")){
					include_once $this->get_premium_path("mobile-cache.php");

					if(file_exists(WPFC_WP_PLUGIN_DIR."/wp-fastest-cache-premium/pro/library/statics.php")){
						include_once $this->get_premium_path("statics.php");
					}

					if(!defined('DOING_AJAX')){
						include_once $this->get_premium_path("powerful-html.php");
					}
				}

				if(is_admin()){
					add_action('wp_loaded', array($this, "load_column"));
					
					if(defined('DOING_AJAX') && DOING_AJAX){
						//do nothing
					}else{
						// to avoid loading menu and optionPage() twice
						if(!class_exists("WpFastestCacheAdmin")){
							//for wp-panel
							
							if($this->isPluginActive("wp-fastest-cache-premium/wpFastestCachePremium.php")){
								include_once $this->get_premium_path("image.php");
							}

							if($this->isPluginActive("wp-fastest-cache-premium/wpFastestCachePremium.php")){
								include_once $this->get_premium_path("logs.php");
							}

							add_action('plugins_loaded', array($this, 'wpfc_load_plugin_textdomain'));
							add_action('wp_loaded', array($this, "load_admin_toolbar"));

							$this->admin();
						}
					}
				}else{
					if(preg_match("/wpfc-minified\/([^\/]+)\/([^\/]+)/", $this->current_url(), $path)){
						// for security
						if(preg_match("/\.{2,}/", $this->current_url())){
							die("May be Directory Traversal Attack");
						}

						if($sources = @scandir(WPFC_WP_CONTENT_DIR."/cache/wpfc-minified/".$path[1], 1)){
							if(isset($sources[0])){
								// $exist_url = str_replace($path[2], $sources[0], $this->current_url());
								// header('Location: ' . $exist_url, true, 301);
								// exit;

								if(preg_match("/\.css/", $this->current_url())){
									header('Content-type: text/css');
								}else if(preg_match("/\.js/", $this->current_url())){
									header('Content-type: text/js');
								}

								echo file_get_contents(WPFC_WP_CONTENT_DIR."/cache/wpfc-minified/".$path[1]."/".$sources[0]);
								exit;
							}
						}

						//for non-exists files
						if(preg_match("/\.css/", $this->current_url())){
							header('Content-type: text/css');
							die("/* File not found */");
						}else if(preg_match("/\.js/", $this->current_url())){
							header('Content-type: text/js');
							die("//File not found");
						}
					}else{
						// to show if the user is logged-in
						add_action('wp_loaded', array($this, "load_admin_toolbar"));

						//for cache
						$this->cache();
					}
				}
			}
		}

		public function notify($message = array()){
			if(isset($message[0]) && $message[0]){
				if(function_exists("add_settings_error")){
					add_settings_error('wpfc-notice', esc_attr( 'settings_updated' ), $message[0], $message[1]);
				}
			}
		}

		public function set_content_url(){
			$content_url = content_url();

			// Hide My WP
			if($this->isPluginActive('hide_my_wp/hide-my-wp.php')){
				$hide_my_wp = get_option("hide_my_wp");

				if(isset($hide_my_wp["new_content_path"]) && $hide_my_wp["new_content_path"]){
					$hide_my_wp["new_content_path"] = trim($hide_my_wp["new_content_path"], "/");
					$content_url = str_replace(basename(WPFC_WP_CONTENT_DIR), $hide_my_wp["new_content_path"], $content_url);
				}
			}

			if (!defined('WPFC_WP_CONTENT_URL')) {
				define("WPFC_WP_CONTENT_URL", $content_url);
			}

			$this->content_url = $content_url;
		}

		public function clear_cache_on_kksr_rate($id){
			$this->singleDeleteCache(false, $id);
		}

		public function nonce_timeout(){
			if(!is_user_logged_in()){
				$run = false;
				$list = array(
							  "caldera-forms/caldera-core.php",
							  "contact-form-7/wp-contact-form-7.php",
							  "js_composer/js_composer.php",
							  "kk-star-ratings/index.php",
							  "ninja-forms/ninja-forms.php",
							  "yet-another-stars-rating/yet-another-stars-rating.php"
							  );

				foreach ($list as $key => $value) {
					if($this->isPluginActive($value)){
						$run = true;
					}
				}

				if($run){
					include_once('inc/nonce-timeout.php');
					
					$wpfc_nonce = new WPFC_NONCE_TIMEOUT(WPFC_WP_CONTENT_DIR."/cache/all");
					
					if(!$wpfc_nonce->verify_nonce()){
						$this->deleteCache();
					}
				}
			}
		}

		public function clear_cache_after_woocommerce_checkout_order_processed($order_id = false){
			if(function_exists("wc_get_order")){
				if($order_id){
					$order = wc_get_order($order_id);

					if($order){
						foreach($order->get_items() as $item_key => $item_values ){
							if(method_exists($item_values, 'get_product_id')){
								$this->singleDeleteCache(false, $item_values->get_product_id());
							}
						}
					}
				}
			}
		}

		public function wpfc_db_fix_callback(){
			if($this->isPluginActive("wp-fastest-cache-premium/wpFastestCachePremium.php")){
				include_once $this->get_premium_path("db.php");

				if(class_exists("WpFastestCacheDatabaseCleanup")){
					WpFastestCacheDatabaseCleanup::clean($_GET["type"]);
				}else{
					die(json_encode(array("success" => false, "showupdatewarning" => true, "message" => "Only available in Premium version")));
				}

			}else{
				die(json_encode(array("success" => false, "message" => "Only available in Premium version")));
			}
		}

		public function wpfc_db_statics_callback(){
			global $wpdb;

            $statics = array("all_warnings" => 0,
                             "post_revisions" => 0,
                             "trashed_contents" => 0,
                             "trashed_spam_comments" => 0,
                             "trackback_pingback" => 0,
                             "transient_options" => 0
                            );


            $statics["post_revisions"] = $wpdb->get_var("SELECT COUNT(*) FROM `$wpdb->posts` WHERE post_type = 'revision';");
            $statics["all_warnings"] = $statics["all_warnings"] + $statics["post_revisions"];

            $statics["trashed_contents"] = $wpdb->get_var("SELECT COUNT(*) FROM `$wpdb->posts` WHERE post_status = 'trash';");
            $statics["all_warnings"] = $statics["all_warnings"] + $statics["trashed_contents"];

            $statics["trashed_spam_comments"] = $wpdb->get_var("SELECT COUNT(*) FROM `$wpdb->comments` WHERE comment_approved = 'spam' OR comment_approved = 'trash' ;");
            $statics["all_warnings"] = $statics["all_warnings"] + $statics["trashed_spam_comments"];

            $statics["trackback_pingback"] = $wpdb->get_var("SELECT COUNT(*) FROM `$wpdb->comments` WHERE comment_type = 'trackback' OR comment_type = 'pingback' ;");
            $statics["all_warnings"] = $statics["all_warnings"] + $statics["trackback_pingback"];

            $element = "SELECT COUNT(*) FROM `$wpdb->options` WHERE option_name LIKE '%\_transient\_%' ;";
            $statics["transient_options"] = $wpdb->get_var( $element ) > 20 ? $wpdb->get_var( $element ) : 0;
            $statics["all_warnings"] = $statics["all_warnings"] + $statics["transient_options"];

            die(json_encode($statics));
		}

		public function is_trailing_slash(){
			// no need to check if Custom Permalinks plugin is active (https://tr.wordpress.org/plugins/custom-permalinks/)
			if($this->isPluginActive("custom-permalinks/custom-permalinks.php")){
				return false;
			}

			if($permalink_structure = get_option('permalink_structure')){
				if(preg_match("/\/$/", $permalink_structure)){
					return true;
				}
			}

			return false;
		}

		public function wpfc_cache_statics_get_callback(){
			if($this->isPluginActive("wp-fastest-cache-premium/wpFastestCachePremium.php")){
				if(file_exists(WPFC_WP_PLUGIN_DIR."/wp-fastest-cache-premium/pro/library/statics.php")){
					include_once $this->get_premium_path("statics.php");
					
					$cache_statics = new WpFastestCacheStatics();
					$res = $cache_statics->get();
					echo json_encode($res);
					exit;
				}
			}
		}

		public function wpfc_check_url_ajax_request_callback(){
			include_once('inc/cdn.php');
			CdnWPFC::check_url();
		}

		public function wpfc_cdn_template_ajax_request_callback(){
			include_once('inc/cdn.php');
			CdnWPFC::cdn_template();
		}

		public function wpfc_save_cdn_integration_ajax_request_callback(){
			include_once('inc/cdn.php');
			CdnWPFC::save_cdn_integration();

		}

		public function wpfc_remove_cdn_integration_ajax_request_callback(){
			include_once('inc/cdn.php');
			CdnWPFC::remove_cdn_integration();
		}

		public function wpfc_cdn_options_ajax_request_callback(){
			include_once('inc/cdn.php');
			CdnWPFC::cdn_options();
		}

		public function wpfc_save_exclude_pages_callback(){
			if(!wp_verify_nonce($_POST["security"], 'wpfc-save-exclude-ajax-nonce')){
				die( 'Security check' );
			}
			
			if(current_user_can('manage_options')){
				if(isset($_POST["rules"])){
					foreach ($_POST["rules"] as $key => &$value) {
						$value["prefix"] = strip_tags($value["prefix"]);
						$value["content"] = strip_tags($value["content"]);

						$value["prefix"] = preg_replace("/\'|\"/", "", $value["prefix"]);
						$value["content"] = preg_replace("/\'|\"/", "", $value["content"]);

						$value["content"] = trim($value["content"], "/");

						$value["content"] = preg_replace("/(\#|\s|\(|\)|\*)/", "", $value["content"]);

						if($value["prefix"] == "homepage"){
							$this->deleteHomePageCache(false);
						}
					}

					$data = json_encode($_POST["rules"]);

					if(get_option("WpFastestCacheExclude")){
						update_option("WpFastestCacheExclude", $data);
					}else{
						add_option("WpFastestCacheExclude", $data, null, "yes");
					}
				}else{
					delete_option("WpFastestCacheExclude");
				}

				$this->modify_htaccess_for_exclude();

				echo json_encode(array("success" => true));
				exit;
			}else{
				wp_die("Must be admin");
			}
		}

		public function modify_htaccess_for_exclude(){
			$path = ABSPATH;

			if($this->is_subdirectory_install()){
				$path = $this->getABSPATH();
			}

			$htaccess = @file_get_contents($path.".htaccess");

			if(preg_match("/\#\s?Start\sWPFC\sExclude/", $htaccess)){
				$exclude_rules = $this->excludeRules();

				$htaccess = preg_replace("/\#\s?Start\sWPFC\sExclude[^\#]*\#\s?End\sWPFC\sExclude\s+/", $exclude_rules, $htaccess);
			}

			@file_put_contents($path.".htaccess", $htaccess);
		}

		public function wpfc_save_timeout_pages_callback(){
			if(!wp_verify_nonce($_POST["security"], 'wpfc-save-timeout-ajax-nonce')){
				die( 'Security check' );
			}

			if(current_user_can('manage_options')){
				$this->setCustomInterval();
			
		    	$crons = _get_cron_array();

		    	foreach ($crons as $cron_key => $cron_value) {
		    		foreach ( (array) $cron_value as $hook => $events ) {
		    			if(preg_match("/^wp\_fastest\_cache(.*)/", $hook, $id)){
		    				if(!$id[1] || preg_match("/^\_(\d+)$/", $id[1])){
		    					foreach ( (array) $events as $event_key => $event ) {
			    					if($id[1]){
			    						wp_clear_scheduled_hook("wp_fastest_cache".$id[1], $event["args"]);
			    					}else{
			    						wp_clear_scheduled_hook("wp_fastest_cache", $event["args"]);
			    					}
		    					}
		    				}
		    			}
		    		}
		    	}

				if(isset($_POST["rules"]) && count($_POST["rules"]) > 0){
					$i = 0;

					foreach ($_POST["rules"] as $key => $value) {
						if(preg_match("/^(daily|onceaday)$/i", $value["schedule"]) && isset($value["hour"]) && isset($value["minute"]) && strlen($value["hour"]) > 0 && strlen($value["minute"]) > 0){
							$args = array("prefix" => $value["prefix"], "content" => $value["content"], "hour" => $value["hour"], "minute" => $value["minute"]);

							$timestamp = mktime($value["hour"],$value["minute"],0,date("m"),date("d"),date("Y"));

							$timestamp = $timestamp > time() ? $timestamp : $timestamp + 60*60*24;
						}else{
							$args = array("prefix" => $value["prefix"], "content" => $value["content"]);
							$timestamp = time();
						}

						wp_schedule_event($timestamp, $value["schedule"], "wp_fastest_cache_".$i, array(json_encode($args)));
						$i = $i + 1;
					}
				}

				echo json_encode(array("success" => true));
				exit;
			}else{
				wp_die("Must be admin");
			}
		}

		public function wp_postratings_clear_fastest_cache($rate_userid, $post_id){
			// to remove cache if vote is from homepage or category page or tag
			if(isset($_SERVER["HTTP_REFERER"]) && $_SERVER["HTTP_REFERER"]){
				$url =  parse_url($_SERVER["HTTP_REFERER"]);

				$url["path"] = isset($url["path"]) ? $url["path"] : "/index.html";

				if(isset($url["path"])){
					if($url["path"] == "/"){
						$this->rm_folder_recursively($this->getWpContentDir("/cache/all/index.html"));
					}else{
						// to prevent changing path with ../ or with another method
						if($url["path"] == realpath(".".$url["path"])){
							$this->rm_folder_recursively($this->getWpContentDir("/cache/all").$url["path"]);
						}
					}
				}
			}

			if($post_id){
				$this->singleDeleteCache(false, $post_id);
			}
		}

		private function admin(){			
			if(isset($_GET["page"]) && $_GET["page"] == "wpfastestcacheoptions"){
				include_once('inc/admin.php');
				$wpfc = new WpFastestCacheAdmin();
				$wpfc->addMenuPage();
			}else{
				add_action('admin_menu', array($this, 'register_my_custom_menu_page'));
			}
		}

		public function load_column(){
			if(!defined('WPFC_HIDE_CLEAR_CACHE_BUTTON') || (defined('WPFC_HIDE_CLEAR_CACHE_BUTTON') && !WPFC_HIDE_CLEAR_CACHE_BUTTON)){
				include_once plugin_dir_path(__FILE__)."inc/column.php";

				$column = new WpFastestCacheColumn();
				$column->add();
			}
		}

		public function load_admin_toolbar(){
			if(!defined('WPFC_HIDE_TOOLBAR') || (defined('WPFC_HIDE_TOOLBAR') && !WPFC_HIDE_TOOLBAR)){
				$show = false;

				// Admin
				$show = (current_user_can( 'manage_options' ) || current_user_can('edit_others_pages')) ? true : false;

				// Author
				if(defined('WPFC_TOOLBAR_FOR_AUTHOR') && WPFC_TOOLBAR_FOR_AUTHOR){
					if(current_user_can( 'delete_published_posts' ) || current_user_can('edit_published_posts')) {
						$show = true;
					}
				}
				
				if($show){
					include_once plugin_dir_path(__FILE__)."inc/admin-toolbar.php";

					$toolbar = new WpFastestCacheAdminToolbar();
					$toolbar->add();
				}

			}
		}

		public function tmp_saveOption(){
			if(!empty($_POST)){
				if(isset($_POST["wpFastestCachePage"])){
					include_once('inc/admin.php');
					$wpfc = new WpFastestCacheAdmin();
					$wpfc->optionsPageRequest();
				}
			}
		}

		public function register_mysettings(){
			register_setting('wpfc-group', 'wpfc-group', array($this, 'tmp_saveOption'));
		}

    public function register_my_custom_menu_page(){
      if(function_exists('add_menu_page')){

        $page_title = 'WP Fastest Cache Settings';
        $menu_title = 'WP Fastest Cache';
        $capability = 'manage_options';
        $menu_slug = 'wpfastestcacheoptions';
        $function = array($this, 'optionsPage');
        $icon_svg = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="28" height="28"><path d="M114.98 46.11c9.09-.33 17.97 1.49 27.05 1.29 4.78-.06 9.5.32 14.12 1.63 8.49 2.44 16.81 4.81 24.38 9.52 8.92 5.68 21.22 1.39 31.16 3 3.53.78 5.64 4.1 5.83 7.57.29 5.81-.15 12-1.05 17.75-.82 4.75-4.23 7.84-5.81 12.17.22 3.83 3.18 3.95 6.21 4.46 9.56 1.03 19.38 3.42 26.89 9.76 2.32 2.01 3.98 4.73 2.32 7.74-1.81 2.58-5.49 1.82-7.98.81-3.98-1.78-7.82-3.4-12.2-3.96 1.96 2.35 5.03 4.65 6.13 7.54.38 4.53-3.37 9.5-5.25 13.54-5.82-.6-11.27-2.92-17.08-3.15-.22 2.55 1.38 3.92 2.86 5.71 4.41 4.7 9.14 10.55 10.85 16.86.67 2.6-.52 4.78-2.82 6.01-2.97.49-5.78-2.26-7.47-4.39-2.85-3.79-5.61-7.55-9.09-10.8-6.73 4.19-12.42 9.38-16.98 15.88-4.71 6.3-7.16 13.36-11.05 20.09-2.1 3.75-4.31 7.13-5.68 11.24-1.72 5.07-2.14 10.11-5.44 14.52-5.09 6.82-12.69 10.42-20.89 11.99-7.47 1.64-15.51-.39-22.27-3.63-6.16-2.93-8.88-8.82-12.98-13.95-5.49-6.78-8.66-13.92-16.5-18.49-2.04 1.19-4.18 2.77-6.49 3.35-2.86-.31-5.59-1.6-8.54-1.75-3.23-.25-6.74.32-9.69-1.09-3.83-1.8-7.24-4.25-11.28-5.64-1.97-.9-5.07-1.56-6.19-3.56-3.43-6.02-5.89-12.43-6.62-19.35-.55-6.23-6.01-9.01-6.91-14.93-1.13-8.04-2.08-15.93-4.46-23.75-1.04-3.42-3.18-6.02-5.58-8.58-5.04-5.32-9.72-11-14.01-16.95-4.12-5.88-7.22-12.23-7.19-19.58-.06-4.76.99-10.72 4.45-14.25 3.71-3.53 9.52-3.42 14.3-3.35 11.96.52 23.92 2.67 35.8 4.09 5.47.63 9.65-1.88 14.43-4.07 5.59-2.74 11.32-5.13 17.37-6.69 7.95-2.04 15.03-4.6 23.35-4.61z" fill="#bebdbc"/><path d="M134.95 51.45c8.82-1.36 17.68.01 25.82 3.57 4.39 1.18 8.52 2.19 12.35 4.81 4.64 2.35 9.51 6.41 14.86 5.54 5.39-.58 10.62-1.28 16.07-.66 3.82.06 7.81.36 10.09 3.86.56 4.81.44 10.92-.39 15.7-.79 4.82-3.68 7.87-5.71 12.08-1.45 3.04-.41 7.38 2.58 9.1 5.33 2.36 10.59 1.88 16.04 3.9 5.19 1.96 10.14 4.24 14.56 7.65-6.11-1.72-11.95-7.51-18.22-6.29 1.11.66 2.24 1.3 3.38 1.92-3.48-.21-7.04-.46-10.51-.14-1.71.66-2.43 3.59-3.41 5.1 4.05 1.45 8.02 2.81 11.62 5.23-3.47-1.12-6.82-2.6-10.3-3.74-.77 2.91-1.44 5.84-2.12 8.77 2.66 1.01 5.28 2.09 7.76 3.49-2.46-.68-4.86-1.52-7.27-2.33-2.03 2.33-4.09 4.63-6.15 6.93.34 1.71.67 3.42 1.1 5.11-.59.12-1.18.25-1.77.38 3.19 3.38 6.38 6.68 8.81 10.69-2.47-2.5-4.9-5.06-7.32-7.61-2.88 1.11-5.66 2.19-8.09 4.16-6.3 5.14-11.79 11.16-16.27 17.95-3.56 5.22-5.68 10.91-8.8 16.34-2.36 4.25-5.04 7.83-6.48 12.52-1.69 5.27-2.19 10.49-5.91 14.88-3.79 4.31-9.44 7.17-14.94 8.6-3.6.89-7.11 1.88-10.83 1.2-5-.99-10.44-2.36-14.75-5.18-3.79-2.36-6.33-6.98-9.16-10.41-2.28-3.23-5.16-5.27-5.15-9.5-4.51-3.61-8.32-9.17-13.53-11.58-2.67-.31-5.42.39-8.01.94.63-2.55 1.31-5.08 2.02-7.6 3.45 3.17 7.71 4.91 10.86 8.37 2.84 2.94 5.35 5.9 8.84 8.14 5.21 4 9.07 8.88 13.63 13.44 2.82 2.96 7.1 3.94 10.88 5.18l.68.29c.74.39 1.53.55 2.38.49h.77c5.77 1.33 11.33.98 16.2-2.63l1.1-.7c1.66-1.03 2.96-2.61 4.19-4.1 2.69-3.15 5.11-6.13 2.65-10.32-11.99-3.68-22.42-.54-33.94 2.78-2.75-5.14-5.49-10.19-9.2-14.73-6.88-8.59-14.1-17.38-22.55-24.47-1.73-1.61-3.31-3.1-3.51-5.61-.19-4.3.98-8.71 1.42-12.98-7.92 4.84-12.78 12-17.49 19.76 3.45-7.96 8.56-15.11 15.55-20.35 3.86-2.32 3.97-8.33 5.52-12.3-7.39-.01-14.59 1.41-21.38 4.32-2.16 2.27-3.55 5.25-5.66 7.61-3.13-2.6-4.1-5.43-3.2-9.4 1.29-2.22 3.72-3.89 5.5-5.72-1.7.18-3.4.4-5.1.66 3.16-2 5.75-4.55 8.5-7.07 2.44-2.21 2.81-4.97 3.74-7.97-4.63.11-9.44.53-13.88 1.9-5.28 2.02-10.22 5.11-15.38 7.43 4.96-3.99 10.52-7.1 16.49-9.3 4.22-1.5 9-2.06 13.41-2.86.92-2.58 1.99-4.83 2.03-7.62.25-3.57-.48-7.92.91-11.25 1.71-2.78 5.52-4.37 8.15-6.19 2.52 1.16 4.44 2.65 7.38 2 7.52-1.68 15.19-1.28 22.65.48 1.43.25 3.09.88 4.53.56 2.33-1.81 3.95-4.58 5.85-6.82.62 4.04 1.55 7.6 3.35 11.27-2.88-.6-5.7-1.39-8.53-2.16-.69 2.38-1.42 4.75-2.1 7.14 3.97 1.18 6.19-.82 9.69-2.17 2.64-1.17 4.84.37 7.31 1.22-3.45.08-6.6.32-9.82 1.68.29 1.96.55 3.92.85 5.88 4.69-1.8 7.2-5.77 12.21-4.99-2.36 1.38-4.79 2.63-7.16 4.01.51.99 1.01 1.98 1.52 2.97 3.86-2.13 6.88-4.12 11.46-2.77-1.67 1.23-3.37 2.4-5.05 3.62 2.78.99 5.25 2.62 8.24 2.04 7.33-1.13 14.54-.83 20.97 3.21 1.86 1.67 3.72 3.33 5.49 5.09-1.85 2.47-4.32 4.64-5.66 7.38l-.36.66c-1.9 3.21-4.77 5.17-7.8 7.19-2.13.88-4.31 1.65-6.44 2.55 6.03 4.38 12.78 7.83 19.29 11.47 1.9-1.58 3.76-3.2 5.44-5.01 2.67-5.36 3.72-10.56 1.97-16.46-.75-4.04-2.38-7.15-4.95-10.32-2.32-2.13-4.24-4.29-7.68-4.14-1.1-2.25-1.76-4.75-3.04-6.89-2.16-1-4.52-1.56-6.75-2.38 1.85-.61 3.73-1.15 5.6-1.69-1.91-3.68-4.61-5.76-8.08-7.89 1.44-.37 2.88-.72 4.33-1.06-.25-2.32-.42-4.64-.42-6.98 1.95 2.52 3.42 5.96 6.74 6.92 5 .24 9.29-1.01 13.46-3.73 2.12-1.63 3.73-.31 5.98.16-.36 1.66-1.2 3.56-.91 5.26 1.05 2.86 3.72 5.2 4.46 8.13.4 3.87.17 7.79.51 11.67 2.81-.49 5.6-1.02 8.41-1.48-.31-1.73-.56-3.55-1.13-5.22-1.03-2.91-3.64-5.44-4.1-8.48-.44-2.88-.22-5.97.06-8.85.97 3.18 1.45 6.41 1.97 9.68 2.16-2.37 4.43-4.68 6.42-7.21 1.36-1.55.96-4.21 1.18-6.16.06-3.16 1.08-5.97 1.82-9-.3-1.32-.42-2.94-1.23-4.06a9.71 9.71 0 0 0-2.96-.77c-6.69.07-13.38.42-20.06.58-7 .9-13.4-6.43-19.51-8.88-2.02-.02-3.99.94-6.05.86-2.04-1.05-3.41-4-5.82-4.08-4.54-.52-9.1-.97-13.67-.99 3.58 2.55 6.55 5.17 9.47 8.46-.81 2.58-1.87 5.11-2.52 7.73.48 1.11 1.14 2.14 1.73 3.19-.23 1.39-.47 2.79-.73 4.18-1.04-1.47-2.25-2.92-3.05-4.54-.29-4.45-1.16-8.8-2.43-13.06-2.18-2.51-4.79-4.37-8.23-4.47-2.95-.09-5.22-1.96-8.1-2.19-3.41-.33-6.88-.53-10.3-.55-1.21 1.09-2.33 3.1-3.92 3.54-3.08-.35-5.46-1.95-8.45-1.47-5.83.99-11.65 2.48-17.19 4.57.12 1.44.25 2.88.37 4.33-3.14-.01-6.25-.35-9.38-.61.58 1.08 1.16 2.16 1.74 3.23-.98.47-1.96.95-2.94 1.43-.97-.75-1.94-1.52-2.9-2.28-2.42 1.16-5.25 3.11-8.01 2.81-6.37-.34-12.67-1.37-19.05-1.78-8.38-.64-16.73-3.86-25.07-3.74-3.02.01-6.4.87-7.65 3.94-1.57 3.61.06 8.53 1.33 12.06C17.09 87.75 22 95.31 28 102.01c4.83 5.92 9.87 11.75 14.24 18.02-1.98-1.41-3.87-2.93-5.62-4.62-.89-.93-1.75-1.89-2.62-2.83-.77-.15-2.1.08-2.7-.46-1.5-2.12-1.8-4.72-3.81-6.62-2.48-2.31-4.05-5.21-6.2-7.8-4.18-5.24-9.56-11.11-11.52-17.61-1.67-5.65-1.36-12.96 2.3-17.8 4.81-2.68 8.89-1.34 13.93-.83 9.29-.01 18.68 2.09 27.9 3.15 2.79.25 5.67 1.16 8.42.93 2.82-.07 5.52-1.98 8.21-2.95 4.03-1.5 7.75-3.6 11.65-5.38 4.83-2.23 9.66-2.84 14.86-4.31 4.45-1.26 8.78-2.54 13.37-3.22 8.43-1.01 16.34.55 24.54 1.77z"/><path d="M114.73 53.42c3.42.02 6.89.22 10.3.55 2.88.23 5.15 2.1 8.1 2.19 3.44.1 6.05 1.96 8.23 4.47 1.27 4.26 2.14 8.61 2.43 13.06.8 1.62 2.01 3.07 3.05 4.54.26-1.39.5-2.79.73-4.18-.59-1.05-1.25-2.08-1.73-3.19.65-2.62 1.71-5.15 2.52-7.73-2.92-3.29-5.89-5.91-9.47-8.46 4.57.02 9.13.47 13.67.99 2.41.08 3.78 3.03 5.82 4.08 2.06.08 4.03-.88 6.05-.86 6.11 2.45 12.51 9.78 19.51 8.88 6.68-.16 13.37-.51 20.06-.58 1.35 1.65 2.75 3.26 4.19 4.83-.74 3.03-1.76 5.84-1.82 9-.22 1.95.18 4.61-1.18 6.16-1.99 2.53-4.26 4.84-6.42 7.21-.52-3.27-1-6.5-1.97-9.68-.28 2.88-.5 5.97-.06 8.85.46 3.04 3.07 5.57 4.1 8.48.57 1.67.82 3.49 1.13 5.22-2.81.46-5.6.99-8.41 1.48-.34-3.88-.11-7.8-.51-11.67-.74-2.93-3.41-5.27-4.46-8.13-.29-1.7.55-3.6.91-5.26-2.25-.47-3.86-1.79-5.98-.16-4.17 2.72-8.46 3.97-13.46 3.73-3.32-.96-4.79-4.4-6.74-6.92 0 2.34.17 4.66.42 6.98-1.45.34-2.89.69-4.33 1.06 3.47 2.13 6.17 4.21 8.08 7.89-1.87.54-3.75 1.08-5.6 1.69 2.23.82 4.59 1.38 6.75 2.38 1.28 2.14 1.94 4.64 3.04 6.89l.7 1.59c-6.43-4.04-13.64-4.34-20.97-3.21-2.99.58-5.46-1.05-8.24-2.04 1.68-1.22 3.38-2.39 5.05-3.62-4.58-1.35-7.6.64-11.46 2.77-.51-.99-1.01-1.98-1.52-2.97 2.37-1.38 4.8-2.63 7.16-4.01-5.01-.78-7.52 3.19-12.21 4.99-.3-1.96-.56-3.92-.85-5.88 3.22-1.36 6.37-1.6 9.82-1.68-2.47-.85-4.67-2.39-7.31-1.22-3.5 1.35-5.72 3.35-9.69 2.17.68-2.39 1.41-4.76 2.1-7.14 2.83.77 5.65 1.56 8.53 2.16-1.8-3.67-2.73-7.23-3.35-11.27-1.9 2.24-3.52 5.01-5.85 6.82-1.44.32-3.1-.31-4.53-.56-7.46-1.76-15.13-2.16-22.65-.48-2.94.65-4.86-.84-7.38-2-2.63 1.82-6.44 3.41-8.15 6.19-1.39 3.33-.66 7.68-.91 11.25-.04 2.79-1.11 5.04-2.03 7.62-4.41.8-9.19 1.36-13.41 2.86-5.97 2.2-11.53 5.31-16.49 9.3 5.16-2.32 10.1-5.41 15.38-7.43 4.44-1.37 9.25-1.79 13.88-1.9-.93 3-1.3 5.76-3.74 7.97-2.75 2.52-5.34 5.07-8.5 7.07 1.7-.26 3.4-.48 5.1-.66-1.78 1.83-4.21 3.5-5.5 5.72-.9 3.97.07 6.8 3.2 9.4 2.11-2.36 3.5-5.34 5.66-7.61 6.79-2.91 13.99-4.33 21.38-4.32-1.55 3.97-1.66 9.98-5.52 12.3-6.99 5.24-12.1 12.39-15.55 20.35 4.71-7.76 9.57-14.92 17.49-19.76-.44 4.27-1.61 8.68-1.42 12.98.2 2.51 1.78 4 3.51 5.61 8.45 7.09 15.67 15.88 22.55 24.47 3.71 4.54 6.45 9.59 9.2 14.73 11.52-3.32 21.95-6.46 33.94-2.78 2.46 4.19.04 7.17-2.65 10.32-2.79-.11-4.3 1.27-4.19 4.1l-1.1.7c-5.23 2.15-10.63 2.41-16.2 2.63h-.77c.33-1.88-1.84-2.2-2.38-.49l-.68-.29c-2.63-1.35-5.46-2.46-7.91-4.12-5.71-4.26-10.13-11.52-16.6-14.5-3.49-2.24-6-5.2-8.84-8.14-3.15-3.46-7.41-5.2-10.86-8.37-.71 2.52-1.39 5.05-2.02 7.6-.72.15-1.44.3-2.16.46-.74-1.29-1.49-2.58-2.2-3.88-.95-1.8-1.85-3.62-2.86-5.39a57.27 57.27 0 0 0-2.37 3.6c-1.96 2.16-3.17 3.02-6.2 3.07-1.6-.75-3.22-1.47-4.88-2.09-1.39-2.11-2.44-4.39-3.42-6.72l-.72.56-.62.39c-1.17-1.44-1.73-1.32-1.68.35l-.84-.11c-1.34-.2-2.67-.39-4-.6-.83-3.88-2.69-7-4.88-10.24-2.38-3.11-.19-6.21-.94-9.98-1-4.46-6.09-7.63-7.24-11.98-.62-4.07-.54-8.16-1.63-12.17-1-5.11-3.54-9.1-1.64-14.29 1.75 1.69 3.64 3.21 5.62 4.62-4.37-6.27-9.41-12.1-14.24-18.02-6-6.7-10.91-14.26-14.39-22.56-1.27-3.53-2.9-8.45-1.33-12.06 1.25-3.07 4.63-3.93 7.65-3.94 8.34-.12 16.69 3.1 25.07 3.74 6.38.41 12.68 1.44 19.05 1.78 2.76.3 5.59-1.65 8.01-2.81.96.76 1.93 1.53 2.9 2.28.98-.48 1.96-.96 2.94-1.43-.58-1.07-1.16-2.15-1.74-3.23 3.13.26 6.24.6 9.38.61-.12-1.45-.25-2.89-.37-4.33 5.54-2.09 11.36-3.58 17.19-4.57 2.99-.48 5.37 1.12 8.45 1.47 1.59-.44 2.71-2.45 3.92-3.54z" fill="#9ea4aa"/><path d="M116.51 54.9c-1.16 7.55 3.41 12.83 3.84 20.1.26 1.79-2.24 3-3.42 4.02-.33-1.33-.67-2.65-1-3.97-1.5.2-2.99.42-4.49.62.24-3.38 1.45-6.38 2.32-9.59-.21-2.36-1.05-4.67-1.24-7.04.38-1.73 2.75-2.96 3.99-4.14zM137.83 59.76c.8 1.54 1.51 3.13 2.2 4.73-1.4.09-2.81.18-4.21.25-.21-1.11-.44-2.25-.52-3.39.81-.57 1.65-1.11 2.53-1.59zM161.39 63.88c1.87.25 3.84.4 5.65.96 3.12 2.75 4.37 4.78 3.4 9 1.23 1.1 2.44 2.21 3.63 3.36-2.46 2.7-5.55 2.84-8.86 3.75.31-2.57 1.03-5.23.9-7.82-1.29-3.22-3.46-5.99-4.72-9.25zM87.14 65.1c2.02-.06 3.07.67 4.6 1.88-1.22.31-2.45.57-3.68.79-.52-.77-.83-1.66-.92-2.67zM85.53 66.88c-1.15 1.21-1.65 3.13-3.57 2.97-2.05.14-2.59-.94-2.9-2.76 2.16-.74 4.25-.44 6.47-.21z"/><path d="M204 67.18c1.05.11 2.04.36 2.96.77.81 1.12.93 2.74 1.23 4.06-1.44-1.57-2.84-3.18-4.19-4.83z" fill="#fff"/><path d="M22.89 68.15c5.26.59 10.44 2.25 15.57 3.51 6.91 2.16 13.91 3.97 20.65 6.58-3.28.22-6.57.41-9.85.61.72 1.44 1.44 2.89 2.15 4.33-3.56.9-7.14 1.69-10.66 2.7 2.52 1.19 5.06 2.34 7.53 3.64-3.94 3.24-8.59 4.11-11.84 7.96 3.65.5 7.27.37 10.94.31-.63 3.62-1.29 7.21-2.36 10.72C33.65 102.7 23.26 93.3 19 80.98c-1.03-2.91-2.46-6.56-1.83-9.66.77-2.54 3.27-3.41 5.72-3.17z"/><path d="M210.97 68.7c1.26-.68 1.47 4.5-.01 3.83-.87-.89-.73-2.9.01-3.83z" fill="#bebdbc"/><path d="M200.05 69.74c2.67-.36 4.45.93 6.55 2.31-3.35.37-6.78.06-10.11.57-2.3 1.09-4.3 2.82-6.47 4.17-1.4-1.95-2.77-3.92-4.12-5.91 4.75-.15 9.43-.72 14.15-1.14z"/><path d="M210.55 75.94c.19 3 .23 6 .35 9.01l-1.07-.01c.26-3 .43-6.01.72-9z" fill="#fff"/><path d="M148.42 78.46a7.3 7.3 0 0 1 2.34-.16c.66 3.43-.13 3.89-3 5.55-1.66-1.21-1.03-4.79.66-5.39z"/><path d="M99.77 84.62c5.67-.19 12.33-.97 17.73 1.02 1.72 1.52.93 4.14.26 6-1.81 4.54-8.4 5.76-12.54 4.35-4.49-2.2-4.57-7.07-5.45-11.37z" fill="#65686b"/><path d="M114.19 86.17c-.29.85-.59 1.7-.9 2.54-1.19-.19-2.38-.36-3.57-.51.63 1.5 1.37 2.94 2.08 4.39l-.55 1.44c-3.65-1.32-5.42-2.79-7.51-6.07 3.23-2.87 6.51-2.05 10.45-1.79z"/><path d="M91.84 86.66c.34 3.01.26 6.05.57 9.05.71 4 4.43 6.63 7.73 8.5 4.59.69 9.45.59 13.68-1.51 2.82-1.33 5.44-2.22 8.62-2.21-1.76 4.06-6.58 5.14-10.09 7.83-2.23 1.14-4.29 3.98-6.98 2.51-6.36-2.47-12.99-3.31-19.71-4.17.76-5.47.34-10.93.7-16.41.1-2.41 3.74-2.86 5.48-3.59z" fill="#9ea4aa"/><path d="M109.72 88.2c1.19.15 2.38.32 3.57.51-.48 1.3-.98 2.59-1.49 3.88-.71-1.45-1.45-2.89-2.08-4.39z" fill="#fff"/><path d="M179.43 87.97c-.28 2.31-.3 5.05-1.37 7.16-1.57 1.99-4.13 1.76-6.31 1.15-2.3-.69-3.08-3.08-4.22-4.94 1.2-.79 2.41-1.58 3.62-2.37.04 3.35.3 4.5 2.61 7.02.93-.72 1.84-1.45 2.74-2.2.48-2.23.68-2.71-1.01-4.37 1.29-.54 2.62-.99 3.94-1.45z" fill="#65686b"/><path d="M175.49 89.42c1.69 1.66 1.49 2.14 1.01 4.37-1-.73-2-1.48-3-2.23.65-.73 1.31-1.45 1.99-2.14zM204.9 92.24l-.09 3.31c-1.04.05-2.08.11-3.12.16 1.08-1.15 2.14-2.31 3.21-3.47z" fill="#fff"/><path d="M187.66 94.94c1.81 4.84 4.25 8.65 3.83 14.11-3.2 1.24-6.37 2.51-9.52 3.87-2.42-3.29-5.21-6.23-7.93-9.25 4.03-.08 7.62.07 10.94-2.62 1.8-1.36 2.02-4.08 2.68-6.11z" fill="#9ea4aa"/><path d="M205.26 101.54c1.45-.39 1.35 2.27.56 2.87-1.4.38-1.37-2.29-.56-2.87zM122.94 104.28c4.64 1.14 8.75 1.13 13.49.91-4.55.88-8.94.16-13.49.14-3.15.49-6.19 2.28-9.1 3.54 2.55-1.76 5.98-4.39 9.1-4.59z" fill="#fff"/><path d="M122.94 105.33c4.55.02 8.94.74 13.49-.14l1.87-.05c-.94 1.38-1.81 2.81-2.62 4.27 1.39 2.69 2.99 5.3 4.21 8.07.67 2.22 1.1 5.04 3.05 6.52 2.55 2.1 5.71 3.68 8.69 5.08 1.05.93 1.84 2.25 2.63 3.4-4.42.51-8.73-.36-13.14-.29-3.72.41-7.34 1.56-11.08 2.04-1.32-.74-2.39-.49-3.21.75l-.78-.07c-1.07-.26-1.72.17-1.97 1.28-3.42.86-7.91 2.25-11.36 1.11-3.45-1.54-7.81-4.76-7.54-8.99 2.36-1.22 2.43-3.52-.04-4.65-.08-5.59 4.48-11.48 8.7-14.79 2.91-1.26 5.95-3.05 9.1-3.54z" fill="#9ea4aa"/><path d="M138.3 105.14l1.8-.06a82.07 82.07 0 0 1-4.42 4.33c.81-1.46 1.68-2.89 2.62-4.27zM148.03 106.97c7.59-.91 15.42-.62 22.54 2.41-8.15-1.12-16.4-1.14-24.62-.8.66 1.43 1.34 2.84 1.98 4.28-2.79-.81-5.27-1.73-7.51-3.63 2.45-1.11 4.92-1.97 7.61-2.26zM171.68 107.21c3.44-.15 5.36 2.01 7.68 4.14-1.85 4.25-3.6 6.95-7.15 9.92 1.34-2.74 3.81-4.91 5.66-7.38-1.77-1.76-3.63-3.42-5.49-5.09l-.7-1.59z" fill="#fff"/><path d="M85.8 109.41c6.72.33 13.38 1.63 19.61 4.21-1.1.93-2.19 1.92-3.46 2.63-2.65.02-5.3-.49-7.96-.59-3.99-.24-7.65.47-11.59.88 1.07-2.4 2.15-4.82 3.4-7.13z" fill="#9ea4aa"/><path d="M218.55 110.22c1.01-1.46 3.07.2 1.42 1.25-1.08.13-1.55-.29-1.42-1.25z" fill="#bebdbc"/><path d="M193.66 110.81c1.66.01 3.66-.36 5.21.29 2.15.63 3.63 3.01 5.21 4.53-2.64-.21-5.23-.34-7.87-.12-.87-1.56-1.72-3.12-2.55-4.7z" fill="#9ea4aa"/><path d="M179.36 111.35c2.57 3.17 4.2 6.28 4.95 10.32-3.66 1.68-5.76 3.36-8.19 6.51l2.19.08 1.63-.5.74 1.36.32 1.81c-2.07.5-3.72 1.56-5.38 2.84 2.11.19 4.22.32 6.34.34.13 1.34.25 2.68.38 4.02-1.68 1.81-3.54 3.43-5.44 5.01-6.51-3.64-13.26-7.09-19.29-11.47 2.13-.9 4.31-1.67 6.44-2.55 3.88-1.49 6.69-2.87 7.8-7.19l.36-.66c3.55-2.97 5.3-5.67 7.15-9.92z" fill="#9ea4aa"/><path d="M191.75 111.96c.34 1.43.67 2.86.99 4.29-3.16.2-6.22.47-9.39.24.45-3.05 5.99-3.59 8.4-4.53z" fill="#9ea4aa"/><path d="M145.73 113.16c3.68 1.07 6.89 5.14 7.63 8.82-.28 2.11-2.26 2.4-3.82 3.39-.01-2.48 1-4.86-1.03-6.81-2.05-2.33-4.44-1.91-7.24-2.39 1.34-1.53 1.99-3.61 4.46-3.01zM160.38 113.2l1.61.19c1.29 6.15-1.73 10.65-4.79 15.6 1.87-5.24 2.46-10.33 3.18-15.79z" fill="#fff"/><path d="M206.27 113.16c1.7-1.02 2.81 1.15 1.59 2.38-1.65.94-2.87-1.16-1.59-2.38z" fill="#bebdbc"/><path d="M112.35 114.18c1.42-.9 2.82 1.44.89 1.74-1.03-.33-1.32-.91-.89-1.74z"/><path d="M168.51 114.45c2.21-.4 4.25.04 6.37.62-4.44.58-6.73 2.1-10.08 4.78.99-1.83 1.96-4.18 3.71-5.4z" fill="#fff"/><path d="M110.12 117.16h.85l.43.7-.43.7h-.85l-.43-.7.43-.7z" fill="#bebdbc"/><path d="M84.04 118.2c5.5-1 11.32-.68 16.88-.41-1.76 2.46-3.5 4.93-5.12 7.48-7.73-.26-14.42 2.36-21.64 4.61.73-2.53 1.2-4.65 3.3-6.41 1.96-1.68 3.85-5.07 6.58-5.27z" fill="#9ea4aa"/><path d="M114.18 117.9c.55.68 1.09 1.38 1.62 2.08-3.07-.27-1.78 3.1.65 2.38-.49.57-2.18 2.06-2.48.64-1.24-1.87-.38-3.23.21-5.1zM139.89 117.48c1.06 2.03 1.83 4.56 3.74 5.91 2.61 2.05 5.51 3.43 8 5.69-2.98-1.4-6.14-2.98-8.69-5.08-1.95-1.48-2.38-4.3-3.05-6.52z" fill="#fff"/><path d="M195.7 117.59c.26 2.58.53 5.16.74 7.74-3.38.06-6.76.06-10.14-.05-.37-2.25-.78-4.48-1.27-6.7 3.55-.51 7.08-.93 10.67-.99zM196.82 117.56c2.61.06 5.22.14 7.82.27-.46 2.52-1.98 8.68-5.59 7.6-1.08-2.45-1.63-5.27-2.23-7.87z" fill="#9ea4aa"/><path d="M208.37 118.12c.39 1-.06 3.17-1.34 3.32-1.68-.58-.24-4.59 1.34-3.32z" fill="#fff"/><path d="M52.92 120.19c2.11.01 4.2.22 6.22.84.68 1.6-.47 3.82-.79 5.46-1.91-2.07-4.05-3.93-5.43-6.3zM115.8 119.98c.74.82.96 1.61.65 2.38-2.43.72-3.72-2.65-.65-2.38zM108.52 121.15c1.24-1.24 3.3.35 2.39 1.86-1.29 1.25-3.22-.32-2.39-1.86zM48.9 120.95c.81.7 1.6 1.43 2.36 2.2.02 2.44 1.1 4.52-2.35 4.44-3.09-1.56-.64-4.26-.01-6.64z"/><path d="M171.85 121.93c-1.11 4.32-3.92 5.7-7.8 7.19 3.03-2.02 5.9-3.98 7.8-7.19z" fill="#fff"/><path d="M184.31 121.67c1.75 5.9.7 11.1-1.97 16.46-.13-1.34-.25-2.68-.38-4.02-2.12-.02-4.23-.15-6.34-.34 1.66-1.28 3.31-2.34 5.38-2.84 1.25.16 1.95-.18 2.11-1.04-.44-.85-1.25-1.1-2.43-.77l-.74-1.36c-1.07-1.42-1.62-1.25-1.63.5l-2.19-.08c2.43-3.15 4.53-4.83 8.19-6.51z" fill="#bebdbc"/><path d="M206.24 122.4c1.5.09 1.2 2.27.1 2.77-1.33.14-1.13-2.47-.1-2.77zM105.14 123.66c2.47 1.13 2.4 3.43.04 4.65a80.11 80.11 0 0 1-.04-4.65z" fill="#fff"/><path d="M109.58 126.25c.59-.9 3.07-1.04 2.76.46-.56.94-3.08 1.01-2.76-.46zM179.94 127.76l-1.63.5c.01-1.75.56-1.92 1.63-.5z"/><path d="M186.43 127.07c3.09-.74 6.51-.48 9.68-.53.49 3.17 1.17 6.28 1.97 9.39-3.87-1.93-7.95-3.35-11.83-5.25.01-1.21.07-2.41.18-3.61z" fill="#9ea4aa"/><path d="M116.99 128.04v3.11c-1.74-.44-3.49-.88-5.22-1.34 1.73-.61 3.47-1.2 5.22-1.77z" fill="#bebdbc"/><path d="M180.68 129.12c1.18-.33 1.99-.08 2.43.77-.16.86-.86 1.2-2.11 1.04l-.32-1.81zM57.09 129.52c1.6-.11 3.46.77 3.59 2.55-.75 2.38-2 4.61-3.04 6.87-1.02-.86-2.13-1.72-2.98-2.74-.93-2.26-.08-5.81 2.43-6.68z"/><path d="M108.27 129.97l.71-.42.71.42-.01.84-.7.42-.71-.42v-.84z" fill="#bebdbc"/><path d="M203.34 129.8c2.9-1.49.24 4.77-1.39 3.8-.45-1.12.72-2.93 1.39-3.8z" fill="#fff"/><path d="M42.15 132.29c1.61.44 3.19 1.11 4.74 1.75-2.14.7-4.31 1.08-6.54 1.33.56-1.05 1.16-2.08 1.8-3.08z"/><path d="M186.91 132.56c3.45 1.34 7.16 2.66 10.22 4.77.6 2.11-.45 3.52-1.93 4.88-3.67 3.25-7.57 6.24-11.25 9.5-5.95 3.29-7.85 8.69-10.88 14.36-3.37 6.09-6.76 12.19-10.18 18.26-.8-.78-1.6-1.56-2.41-2.33-.08-.94-.15-1.87-.21-2.81.86-1.31.71-2.41-.46-3.31l.24-.71c3.49-6.15 5.51-12.9 8.85-19.11 1.9-3.72 3.57-7.51 6.02-10.93 1.69 2.64 3.27 5.28 5.51 7.51a72.422 72.422 0 0 0-3.55-6.83c2.32-1.35 4.56-2.54 6.25-4.7 1.88-2.35 2.66-5.76 3.78-8.55z" fill="#9ea4aa"/><path d="M130.04 134.23c-.94.97-2.01 1.22-3.21.75.82-1.24 1.89-1.49 3.21-.75zM126.05 134.91c-.27.98-.92 1.41-1.97 1.28.25-1.11.9-1.54 1.97-1.28z" fill="#fff"/><path d="M54.49 141.05c-1.1 3.57-3.25 6.86-4.96 10.17-1.33-.85-2.83-1.69-3.93-2.82-.75-3.65-.26-7.63-.09-11.32 4.08-1.38 7.14.25 8.98 3.97zM73.1 135.77c.91 1.42 1.75 2.6 1.78 4.36-2.68.31-3.08 0-4.75-2.09.97-.79 1.96-1.54 2.97-2.27z"/><path d="M124.79 137.92c2.87.92 6.37 1.1 7.88 4.08-.91 1.81-1.85 3.84-4.16 2.37-3.65-2.06-7.03-2.87-11.16-3.52 1.89-2.1 4.54-3.71 7.44-2.93zM141.56 138.38c1.59 2.13.4 4.78-.31 7.01-2.12 4.9-4.6 9.48-8.49 13.22.03-6.22-.43-12.92.74-19.02 2.13-1.26 5.81-3 8.06-1.21zM202.18 138.26l.57.01v.57l-.57-.01v-.57zM144.16 140.27c5.53-1.87 12.31.28 18.11.23-.58 2.49-1.37 4.41-2.8 6.55-.6-1.34-1.17-2.68-1.73-4.04-.9.98-1.81 1.95-2.73 2.91-.88-1.03-1.73-2.07-2.59-3.11-.79 1.16-1.58 2.33-2.4 3.48-.54-1.11-1.06-2.21-1.59-3.32-.93.94-1.86 1.86-2.79 2.78-.68-1.8-1.4-3.52-1.48-5.48zM170.99 141.81c-2.21 6.16-4.96 12.89-9.51 17.71-.23-6.41 1.2-12.93 2.13-19.26 2.98-.26 4.74.11 7.38 1.55z" fill="#fff"/><path d="M60.07 141.53c2.05-.32 3.95 1.43 5.68 2.36-1.58 3.92-3.11 7.72-5.92 10.97-1.65-2.23-3.48-4-3.44-6.97-.12-2.78 1.13-5.13 3.68-6.36z"/><path d="M200.05 141.63c-2.06 3.01-4.52 5.68-7.56 7.72 1.64-3.41 4.43-5.73 7.56-7.72z" fill="#bebdbc"/><path d="M70.2 142.78c2.23-1.31 6.51 2.17 5.47 4.82-1.05 1.78-3.11 3.05-4.69 4.31-1.15-3.14-3.54-6.07-.78-9.13z"/><path d="M174.46 143.99c.84.57 1.65 1.18 2.42 1.82 1.3 2.21 2.49 4.49 3.55 6.83-2.24-2.23-3.82-4.87-5.51-7.51-2.45 3.42-4.12 7.21-6.02 10.93-3.34 6.21-5.36 12.96-8.85 19.11 1.28-4.6 3.38-8.65 5.14-13.08 2.74-6.32 5.4-12.37 9.27-18.1z" fill="#fff"/><path d="M120.71 145.3c3.08.38 6.51 1.26 9.08 3.01.7 3.18.49 6.62 1.23 9.78.15 1.1.79 1.9 1.91 2.42 3.26-2.33 5.54-5.98 8.11-9.08 1.04 8.57 2.23 17.07 2.71 25.7.73-4.67 1.93-9.32 2.41-14.01-.39-5.07-5.21-10.38-4.39-15.8 3.93.2 7.8.59 11.7 1.09 2 4.85 4 9.33 4.25 14.67.39 5.73-1 12.4-4.75 16.88-4.14 4.23-9.2 3.63-14.62 3.65-1.86-3.3-3.66-6.63-5.56-9.91-1 2.09-1.97 4.2-3 6.29-5.17-4.95-5.55-11.07-8.68-17.11-2.56-5.08-5.58-9.89-7.95-15.07 2.33-1.14 4.86-2.89 7.55-2.51z" fill="#65686b"/><path d="M183.95 151.71c-2.85 3.05-5.65 5.99-7.52 9.78-4.64 9.03-9.78 17.78-14.68 26.67-.46-2.08-.98-4.07-1.27-6.16.81.77 1.61 1.55 2.41 2.33 3.42-6.07 6.81-12.17 10.18-18.26 3.03-5.67 4.93-11.07 10.88-14.36z" fill="#fff"/><path d="M65.08 152.89c.92-.48 1.99-.51 3.2-.09.52 2.64-1.4 4.61-3.49 5.88-.11-1.97-.27-3.86.29-5.79z"/><path d="M185.33 155.36c2.11.37-.87 3.94-2.04 3.13-.54-1.14.86-3 2.04-3.13z" fill="#bebdbc"/><path d="M59.35 156.58c.68 2.66.59 6.07-.05 8.74-.88 2.07-3.32 2.35-5.17 3.1-1.6-4.07-1.9-8.06.62-11.84 1.66-1.36 2.9-1.14 4.6 0z"/><path d="M108.86 160.6c1.5-.21 3-.33 4.5-.47 2.9 6.96 9.9 12.08 12.63 19.02 1.82 1.38 3.4 3.01 5.04 4.6-.37 1.19-.74 2.37-1.1 3.56-6.91-6.7-12.52-14.31-18.33-21.93-1.12-1.49-2.16-2.99-2.74-4.78zM181.31 159.69c2.79-.77 1.08 3.1-.78 1.88-.26-.69 0-1.32.78-1.88z" fill="#fff"/><path d="M63.85 161.69c.57 2.24.58 4.62 1.85 6.48.97 1.84 2.37 3.25 1.94 5.47-.17 1.76-1.27 2.42-2.71 3.17-2.65-1.93-1.15-5.9-1.59-8.74-.39-2.49-.76-4.05.51-6.38z"/><path d="M179.34 163.03c1.86-.13-.68 4.97-1.98 3.74-.79-1.26.78-3.32 1.98-3.74zM176.22 168.14c1.62-.07-.41 4.64-1.76 3.71-.54-1.18.63-3.22 1.76-3.71zM59.47 174.43l-1.68.35c-.05-1.67.51-1.79 1.68-.35z" fill="#bebdbc"/><path d="M175 172.65c-.77 2.2-1.77 4.02-3.25 5.83.63-2.41 1.6-4.02 3.25-5.83z" fill="#fff"/><path d="M59.47 174.43l.62-.39c-.21 1.63-.38 3.26-.58 4.88-1.16-.61-2.32-1.22-3.49-1.84.31-.8.62-1.61.93-2.41l.84.11 1.68-.35zM77.68 175.62c1.01 1.77 1.91 3.59 2.86 5.39-.47.79-.95 1.59-1.42 2.39-1.78.07-3.57.17-5.35.27.5-1.48.99-2.98 1.54-4.45a57.27 57.27 0 0 1 2.37-3.6z"/><path d="M159.81 175.88c1.17.9 1.32 2 .46 3.31-.86-1.03-1.02-2.14-.46-3.31z" fill="#bebdbc"/><path d="M132.92 176.74c1.87 2.99 3.58 6.29 4.53 9.7.92 2.72-2.13 4.7-3.62 6.53-.9-.94-1.69-1.95-2.39-3.02-.36-4.36.56-8.95 1.48-13.21zM158.02 176.46c1.64 4.75 1.86 9.67 1.63 14.65-1.69-.62-3.99-.32-4.53-2.41-.25-2.99.49-6.14 1.14-9.05.56-1.08 1.14-2.14 1.76-3.19z" fill="#fff"/><path d="M64.23 180.2c1.66.62 3.28 1.34 4.88 2.09.77.78 1.51 1.58 2.23 2.4-2.98-.84-5.48-1.65-7.11-4.49z"/><path d="M170.5 181.15c.49 1.04-1.28 4.46-2.62 3.68-.95-1.09 1.28-4.24 2.62-3.68zM145.01 184.91c.39 1.25.78 2.5 1.17 3.76.62-1.28 1.22-2.56 1.83-3.84.67.97 1.33 1.95 1.99 2.93.77-.66 1.54-1.32 2.32-1.97.28 1.6.55 3.2.8 4.8-4.53.39-9.15 1.06-13.71.9-.13-1.98.14-3.9.41-5.85.88 1.09 1.73 2.19 2.58 3.31.68-1.6 1.08-3.06 2.61-4.04z" fill="#fff"/><path d="M167.06 185.89c-1.2 3.26-2.67 6.48-4.23 9.59.34-3.67 2.09-6.69 4.23-9.59z" fill="#bebdbc"/><path d="M106.62 193.34c6.47 2.98 10.89 10.24 16.6 14.5 2.45 1.66 5.28 2.77 7.91 4.12-3.78-1.24-8.06-2.22-10.88-5.18-4.56-4.56-8.42-9.44-13.63-13.44zM162.27 196.64c1.62-.46 1.75 2.45.06 2.06-.72-.65-.74-1.34-.06-2.06z" fill="#fff"/><path d="M161.18 200.55c1.67-.97 2.82 1.18 1.57 2.39-1.7 1.09-2.77-1.19-1.57-2.39z" fill="#bebdbc"/><path d="M156.45 205.31c-1.23 1.49-2.53 3.07-4.19 4.1-.11-2.83 1.4-4.21 4.19-4.1zM159.41 205.13c1.71-1.37 2.13 1.19.46 1.69-.89-.09-1.05-.65-.46-1.69zM151.16 210.11c-4.87 3.61-10.43 3.96-16.2 2.63 5.57-.22 10.97-.48 16.2-2.63z" fill="#fff"/><path d="M154.18 210.3c1.68-1.17 2.39 1.15.62 1.64-1.01-.18-1.21-.73-.62-1.64z" fill="#bebdbc"/><path d="M134.19 212.74c-.85.06-1.64-.1-2.38-.49.54-1.71 2.71-1.39 2.38.49z" fill="#fff"/><path d="M153.5 211.86c-1.82 1.55-3.63 2.62-5.93 3.32 1.86-1.57 3.62-2.55 5.93-3.32zM122.22 213.26l.57.01v.57h-.58l.01-.58z" fill="#bebdbc"/><path d="M146.07 214.31l.82-.05.45.7-.37.73-.83.05-.45-.7.38-.73z"/><path d="M143.09 215.29c1.77-1.15 3.15 1.32.93 1.88-1.23-.28-1.54-.91-.93-1.88zM131.39 216.37c3.38-.04 6.79-.03 10.14.34-2.91.83-5.89.81-8.9.67l-1.24-1.01z" fill="#bebdbc"/></svg>' );

        add_menu_page(
          $page_title,
          $menu_title,
          $capability,
          $menu_slug,
          $function,
          $icon_svg
        );

        /**
         * WordPress recolors svgs so this makes sure the menu_icon is same than others
         */
        add_action( 'admin_head', 'wpfastestcache_fix_admin_svg' );

        function wpfastestcache_fix_admin_svg() {
          echo '
          <style type="text/css">
            .wp-menu-image.svg {
              filter: brightness(121.5%) !important;
              background-size: 25px auto !important;
              background-position: 6px 4px !important;
            }
          }
          </style>';
        }

        wp_enqueue_style("wp-fastest-cache", plugins_url("wp-fastest-cache/css/style.css"), array(), time(), "all");
      }

      if(isset($_GET["page"]) && $_GET["page"] == "wpfastestcacheoptions"){
        wp_enqueue_style("wp-fastest-cache-buycredit", plugins_url("wp-fastest-cache/css/buycredit.css"), array(), time(), "all");
        wp_enqueue_style("wp-fastest-cache-flaticon", plugins_url("wp-fastest-cache/css/flaticon.css"), array(), time(), "all");
        wp_enqueue_style("wp-fastest-cache-dialog", plugins_url("wp-fastest-cache/css/dialog.css"), array(), time(), "all");
      }
    }

		public function deleteCacheToolbar(){
			$this->deleteCache();
		}

		public function deleteCssAndJsCacheToolbar(){
			$this->deleteCache(true);
		}

		public function delete_current_page_cache(){
			include_once('inc/cdn.php');
			CdnWPFC::cloudflare_clear_cache();

			if(isset($_GET["path"])){
				if($_GET["path"]){
					if($_GET["path"] == "/"){
						$_GET["path"] = $_GET["path"]."index.html";
					}
				}else{
					$_GET["path"] = "/index.html";
				}

				$_GET["path"] = urldecode(esc_url_raw($_GET["path"]));

				$paths = array();

				array_push($paths, $this->getWpContentDir("/cache/all").$_GET["path"]);

				if(class_exists("WpFcMobileCache")){
					$wpfc_mobile = new WpFcMobileCache();
					array_push($paths, $this->getWpContentDir("/cache/wpfc-mobile-cache").$_GET["path"]);
				}

				foreach ($paths as $key => $value){
					if(file_exists($value)){
						if(preg_match("/\/(all|wpfc-mobile-cache)\/index\.html$/i", $value)){
							@unlink($value);
						}else{
							$this->rm_folder_recursively($value);
						}
					}
				}

				die(json_encode(array("The cache of page has been cleared","success")));
			}else{
				die(json_encode(array("Path has NOT been defined", "error", "alert")));
			}

			exit;
		}

		private function cache(){
			include_once('inc/cache.php');
			$wpfc = new WpFastestCacheCreateCache();
			$wpfc->createCache();
		}

		protected function slug(){
			return "wp_fastest_cache";
		}

		public function getWpContentDir($path = false){
			/*
			Sample Paths;

			/cache/

			/cache/all/
			/cache/all
			/cache/all/page
			/cache/all/index.html

			/cache/wpfc-minified

			/cache/wpfc-widget-cache

			/cache/wpfc-mobile-cache/
			/cache/wpfc-mobile-cache/page
			/cache/wpfc-mobile-cache/index.html
			
			/cache/tmpWpfc
			/cache/tmpWpfc/
			/cache/tmpWpfc/mobile_
			/cache/tmpWpfc/m
			/cache/tmpWpfc/w

			
			/cache/testWpFc/

			/cache/all/testWpFc/
			
			/cache/wpfc-widget-cache/
			/cache/wpfc-widget-cache
			/cache/wpfc-widget-cache/".$args["widget_id"].".html
			*/
			
			if($path){
				//WPML language switch
				//https://wpml.org/forums/topic/wpml-language-switch-wp-fastest-cache-issue/
				$language_negotiation_type = apply_filters('wpml_setting', false, 'language_negotiation_type');
				if(($language_negotiation_type == 2) && $this->isPluginActive('sitepress-multilingual-cms/sitepress.php')){
				    $path = preg_replace("/\/cache\/(all|wpfc-mobile-cache)/", "/cache/".$_SERVER['HTTP_HOST']."/$1", $path);
				}

				if(is_multisite()){
					$path = preg_replace("/\/cache\/(all|wpfc-minified|wpfc-widget-cache|wpfc-mobile-cache)/", "/cache/".$_SERVER['HTTP_HOST']."/$1", $path);
				}

				return WPFC_WP_CONTENT_DIR.$path;
			}else{
				return WPFC_WP_CONTENT_DIR;
			}
		}

		protected function getOptions(){
			return $GLOBALS["wp_fastest_cache_options"];
		}

		protected function getSystemMessage(){
			return $this->systemMessage;
		}

		protected function get_excluded_useragent(){
			return "facebookexternalhit|Twitterbot|LinkedInBot|WhatsApp|Mediatoolkitbot";
		}

		// protected function detectNewPost(){
		// 	if(isset($this->options->wpFastestCacheNewPost) && isset($this->options->wpFastestCacheStatus)){
		// 		add_filter ('save_post', array($this, 'deleteCache'));
		// 	}
		// }

		public function deleteWidgetCache(){
			$widget_cache_path = $this->getWpContentDir("/cache/wpfc-widget-cache");
			
			if(is_dir($widget_cache_path)){
				if(!is_dir($this->getWpContentDir("/cache/tmpWpfc"))){
					if(@mkdir($this->getWpContentDir("/cache/tmpWpfc"), 0755, true)){
						//tmpWpfc has been created
					}
				}

				if(@rename($widget_cache_path, $this->getWpContentDir("/cache/tmpWpfc/w").time())){
					//DONE
				}
			}
		}

		public function on_all_status_transitions($new_status, $old_status, $post){
			if(!wp_is_post_revision($post->ID)){
				if(isset($post->post_type)){
					if($post->post_type == "nf_sub"){
						return 0;
					}
				}

				if(isset($this->options->wpFastestCacheNewPost) && isset($this->options->wpFastestCacheStatus)){
					if($new_status == "publish" && $old_status != "publish"){
						if(isset($this->options->wpFastestCacheNewPost_type) && $this->options->wpFastestCacheNewPost_type){
							if($this->options->wpFastestCacheNewPost_type == "all"){
								$this->deleteCache();
							}else if($this->options->wpFastestCacheNewPost_type == "homepage"){
								$this->deleteHomePageCache();

								//to clear category cache and tag cache
								$this->singleDeleteCache(false, $post->ID);

								//to clear widget cache
								$this->deleteWidgetCache();
							}
						}else{
							$this->deleteCache();
						}
					}
				}

				if($new_status == "publish" && $old_status == "publish"){
					if(isset($this->options->wpFastestCacheUpdatePost) && isset($this->options->wpFastestCacheStatus)){

						if($this->options->wpFastestCacheUpdatePost_type == "post"){
							$this->singleDeleteCache(false, $post->ID);
						}else if($this->options->wpFastestCacheUpdatePost_type == "all"){
							$this->deleteCache();
						}
						
					}
				}

				if($new_status == "trash" && $old_status == "publish"){
					$this->singleDeleteCache(false, $post->ID);
				}else if(($new_status == "draft" || $new_status == "pending" || $new_status == "private") && $old_status == "publish"){
					$this->deleteCache();
				}
			}
		}

		protected function commentHooks(){
			//it works when the status of a comment changes
			add_filter ('wp_set_comment_status', array($this, 'singleDeleteCache'));

			//it works when a comment is saved in the database
			add_filter ('comment_post', array($this, 'detectNewComment'));
		}

		public function detectNewComment($comment_id){
			if(current_user_can( 'manage_options') || !get_option('comment_moderation')){
				$this->singleDeleteCache($comment_id);
			}
		}

		public function singleDeleteCache($comment_id = false, $post_id = false){
			include_once('inc/cdn.php');
			CdnWPFC::cloudflare_clear_cache();

			$to_clear_parents = true;

			// not to clear cache of homepage/cats/tags after ajax request by other plugins
			if(isset($_POST) && isset($_POST["action"])){
				// kk Star Rating
				if($_POST["action"] == "kksr_ajax"){
					$to_clear_parents = false;
				}

				// All In One Schema.org Rich Snippets
				if(preg_match("/bsf_(update|submit)_rating/i", $_POST["action"])){
					$to_clear_parents = false;
				}

				// Yet Another Stars Rating
				if($_POST["action"] == "yasr_send_visitor_rating"){
					$to_clear_parents = false;
					$post_id = $_POST["post_id"];
				}
			}

			if($comment_id){
				$comment_id = intval($comment_id);
				
				$comment = get_comment($comment_id);
				
				if($comment && $comment->comment_post_ID){
					$post_id = $comment->comment_post_ID;
				}
			}

			if($post_id){
				$post_id = intval($post_id);

				$permalink = get_permalink($post_id);

				$permalink = urldecode(get_permalink($post_id));

				//for trash contents
				$permalink = rtrim($permalink, "/");
				$permalink = preg_replace("/__trashed$/", "", $permalink);
				//for /%postname%/%post_id% : sample-url__trashed/57595
				$permalink = preg_replace("/__trashed\/(\d+)$/", "/$1", $permalink);

				if(preg_match("/https?:\/\/[^\/]+\/(.+)/", $permalink, $out)){
					$path = $this->getWpContentDir("/cache/all/").$out[1];
					$mobile_path = $this->getWpContentDir("/cache/wpfc-mobile-cache/").$out[1];

					if($this->isPluginActive("wp-fastest-cache-premium/wpFastestCachePremium.php")){
						include_once $this->get_premium_path("logs.php");
						$log = new WpFastestCacheLogs("delete");
						$log->action();
					}

					$files = array();

					if(is_dir($path)){
						array_push($files, $path);
					}

					if(is_dir($mobile_path)){
						array_push($files, $mobile_path);
					}

					if(defined('WPFC_CACHE_QUERYSTRING') && WPFC_CACHE_QUERYSTRING){
						$files_with_query_string = glob($path."\?*");
						$mobile_files_with_query_string = glob($mobile_path."\?*");

						if(is_array($files_with_query_string) && (count($files_with_query_string) > 0)){
							$files = array_merge($files, $files_with_query_string);
						}

						if(is_array($mobile_files_with_query_string) && (count($mobile_files_with_query_string) > 0)){
							$files = array_merge($files, $mobile_files_with_query_string);
						}
					}

					foreach((array)$files as $file){
						$this->rm_folder_recursively($file);
					}
				}

				if($to_clear_parents){
					// to clear cache of homepage
					$this->deleteHomePageCache();

					// to clear cache of author page
					$this->delete_author_page_cache($post_id);

					// to clear cache of cats and  tags which contains the post (only first page)
					global $wpdb;
					$terms = $wpdb->get_results("SELECT * FROM `".$wpdb->prefix."term_relationships` WHERE `object_id`=".$post_id, ARRAY_A);

					foreach ($terms as $term_key => $term_val){
						$this->delete_cache_of_term($term_val["term_taxonomy_id"]);
					}
				}
			}
		}

		public function delete_author_page_cache($post_id){
			$author_id = get_post_field ('post_author', $post_id);
			$permalink = get_author_posts_url($author_id);

			if(preg_match("/https?:\/\/[^\/]+\/(.+)/", $permalink, $out)){
				$path = $this->getWpContentDir("/cache/all/").$out[1];
				$mobile_path = $this->getWpContentDir("/cache/wpfc-mobile-cache/").$out[1];
					
				$this->rm_folder_recursively($path);
				$this->rm_folder_recursively($mobile_path);
			}
		}

		public function delete_cache_of_term($term_taxonomy_id){
			$term = get_term_by("term_taxonomy_id", $term_taxonomy_id);

			if(!$term || is_wp_error($term)){
				return false;
			}

			//if(preg_match("/cat|tag|store|listing/", $term->taxonomy)){}

			$url = get_term_link($term->term_id, $term->taxonomy);

			if(preg_match("/^http/", $url)){
				$path = preg_replace("/https?\:\/\/[^\/]+/i", "", $url);
				$path = trim($path, "/");
				$path = urldecode($path);

				// to remove the cache of tag/cat
				@unlink($this->getWpContentDir("/cache/all/").$path."/index.html");
				@unlink($this->getWpContentDir("/cache/wpfc-mobile-cache/").$path."/index.html");

				// to remove the cache of the pages
				$this->rm_folder_recursively($this->getWpContentDir("/cache/all/").$path."/page");
				$this->rm_folder_recursively($this->getWpContentDir("/cache/wpfc-mobile-cache/").$path."/page");
			}

			if($term->parent > 0){
				$parent = get_term_by("id", $term->parent, $term->taxonomy);
				$this->delete_cache_of_term($parent->term_taxonomy_id);
			}



		}

		public function deleteHomePageCache($log = true){
			include_once('inc/cdn.php');
			CdnWPFC::cloudflare_clear_cache();

			$site_url_path = preg_replace("/https?\:\/\/[^\/]+/i", "", site_url());
			$home_url_path = preg_replace("/https?\:\/\/[^\/]+/i", "", home_url());

			if($site_url_path){
				$site_url_path = trim($site_url_path, "/");

				if($site_url_path){
					@unlink($this->getWpContentDir("/cache/all/").$site_url_path."/index.html");
					@unlink($this->getWpContentDir("/cache/wpfc-mobile-cache/").$site_url_path."/index.html");

					//to clear pagination of homepage cache
					$this->rm_folder_recursively($this->getWpContentDir("/cache/all/").$site_url_path."/page");
					$this->rm_folder_recursively($this->getWpContentDir("/cache/wpfc-mobile-cache/").$site_url_path."/page");
				}
			}

			if($home_url_path){
				$home_url_path = trim($home_url_path, "/");

				if($home_url_path){
					@unlink($this->getWpContentDir("/cache/all/").$home_url_path."/index.html");
					@unlink($this->getWpContentDir("/cache/wpfc-mobile-cache/").$home_url_path."/index.html");

					//to clear pagination of homepage cache
					$this->rm_folder_recursively($this->getWpContentDir("/cache/all/").$home_url_path."/page");
					$this->rm_folder_recursively($this->getWpContentDir("/cache/wpfc-mobile-cache/").$home_url_path."/page");
				}
			}

			if(file_exists($this->getWpContentDir("/cache/all/index.html"))){
				@unlink($this->getWpContentDir("/cache/all/index.html"));
			}

			if(file_exists($this->getWpContentDir("/cache/wpfc-mobile-cache/index.html"))){
				@unlink($this->getWpContentDir("/cache/wpfc-mobile-cache/index.html"));
			}

			//to clear pagination of homepage cache
			$this->rm_folder_recursively($this->getWpContentDir("/cache/all/page"));
			$this->rm_folder_recursively($this->getWpContentDir("/cache/wpfc-mobile-cache/page"));


			if($log){
				if($this->isPluginActive("wp-fastest-cache-premium/wpFastestCachePremium.php")){
					include_once $this->get_premium_path("logs.php");

					$log = new WpFastestCacheLogs("delete");
					$log->action();
				}
			}
		}

		public function deleteCache($minified = false){
			include_once('inc/cdn.php');
			CdnWPFC::cloudflare_clear_cache();

			$this->set_preload();

			$created_tmpWpfc = false;
			$cache_deleted = false;
			$minifed_deleted = false;

			$cache_path = $this->getWpContentDir("/cache/all");
			$minified_cache_path = $this->getWpContentDir("/cache/wpfc-minified");

			if(class_exists("WpFcMobileCache")){




				if(is_dir($this->getWpContentDir("/cache/wpfc-mobile-cache"))){
					if(is_dir($this->getWpContentDir("/cache/tmpWpfc"))){
						rename($this->getWpContentDir("/cache/wpfc-mobile-cache"), $this->getWpContentDir("/cache/tmpWpfc/mobile_").time());
					}else if(@mkdir($this->getWpContentDir("/cache/tmpWpfc"), 0755, true)){
						rename($this->getWpContentDir("/cache/wpfc-mobile-cache"), $this->getWpContentDir("/cache/tmpWpfc/mobile_").time());
					}
				}


			}
			
			if(!is_dir($this->getWpContentDir("/cache/tmpWpfc"))){
				if(@mkdir($this->getWpContentDir("/cache/tmpWpfc"), 0755, true)){
					$created_tmpWpfc = true;
				}else{
					$created_tmpWpfc = false;
					//$this->systemMessage = array("Permission of <strong>/wp-content/cache</strong> must be <strong>755</strong>", "error");
				}
			}else{
				$created_tmpWpfc = true;
			}

			//to clear widget cache path
			$this->deleteWidgetCache();

			if(is_dir($cache_path)){
				if(@rename($cache_path, $this->getWpContentDir("/cache/tmpWpfc/").time())){
					delete_option("WpFastestCacheHTML");
					delete_option("WpFastestCacheHTMLSIZE");
					delete_option("WpFastestCacheMOBILE");
					delete_option("WpFastestCacheMOBILESIZE");

					$cache_deleted = true;
				}
			}else{
				$cache_deleted = true;
			}

			if($minified){
				if(is_dir($minified_cache_path)){
					if(@rename($minified_cache_path, $this->getWpContentDir("/cache/tmpWpfc/m").time())){
						delete_option("WpFastestCacheCSS");
						delete_option("WpFastestCacheCSSSIZE");
						delete_option("WpFastestCacheJS");
						delete_option("WpFastestCacheJSSIZE");

						$minifed_deleted = true;
					}
				}else{
					$minifed_deleted = true;
				}
			}else{
				$minifed_deleted = true;
			}

			if($created_tmpWpfc && $cache_deleted && $minifed_deleted){
				do_action('wpfc_delete_cache');
				
				$this->notify(array("All cache files have been deleted", "updated"));

				if($this->isPluginActive("wp-fastest-cache-premium/wpFastestCachePremium.php")){
					include_once $this->get_premium_path("logs.php");

					$log = new WpFastestCacheLogs("delete");
					$log->action();
				}
			}else{
				$this->notify(array("Permissions Problem: <a href='http://www.wpfastestcache.com/warnings/delete-cache-problem-related-to-permission/' target='_blank'>Read More</a>", "error"));
			}

			// for ajax request
			if(isset($_GET["action"]) && in_array($_GET["action"], array("wpfc_delete_cache", "wpfc_delete_cache_and_minified"))){
				die(json_encode($this->systemMessage));
			}
		}

		public function checkCronTime(){
			$crons = _get_cron_array();

	    	foreach ((array)$crons as $cron_key => $cron_value) {
	    		foreach ( (array) $cron_value as $hook => $events ) {
	    			if(preg_match("/^wp\_fastest\_cache(.*)/", $hook, $id)){
	    				if(!$id[1] || preg_match("/^\_(\d+)$/", $id[1])){
		    				foreach ( (array) $events as $event_key => $event ) {
		    					add_action("wp_fastest_cache".$id[1],  array($this, 'setSchedule'));
		    				}
		    			}
		    		}
		    	}
		    }

		    add_action($this->slug()."_Preload",  array($this, 'create_preload_cache'), 11);
		}

		public function set_preload(){
			include_once('inc/preload.php');
			PreloadWPFC::set_preload($this->slug());
		}

		public function create_preload_cache(){
			$this->options = $this->getOptions();
			
			include_once('inc/preload.php');
			PreloadWPFC::create_preload_cache($this->options);
		}

		public function wpfc_remote_get($url, $user_agent){
			//$response = wp_remote_get($url, array('timeout' => 10, 'sslverify' => false, 'headers' => array("cache-control" => array("no-store, no-cache, must-revalidate", "post-check=0, pre-check=0"),'user-agent' => $user_agent)));
			$response = wp_remote_get($url, array('user-agent' => $user_agent, 'timeout' => 10, 'sslverify' => false, 'headers' => array("cache-control" => "no-store, no-cache, must-revalidate, post-check=0, pre-check=0")));

			if (!$response || is_wp_error($response)){
				echo $response->get_error_message()." - ";

				return false;
			}else{
				if(wp_remote_retrieve_response_code($response) != 200){
					return false;
				}
			}

			return true;
		}

		public function setSchedule($args = ""){
			if($args){
				$rule = json_decode($args);

				if($rule->prefix == "all"){
					$this->deleteCache();
				}else if($rule->prefix == "homepage"){
					@unlink($this->getWpContentDir("/cache/all/index.html"));
					@unlink($this->getWpContentDir("/cache/wpfc-mobile-cache/index.html"));

					if(isset($this->options->wpFastestCachePreload_homepage) && $this->options->wpFastestCachePreload_homepage){
						$this->wpfc_remote_get(get_option("home"), "WP Fastest Cache Preload Bot - After Cache Timeout");
						$this->wpfc_remote_get(get_option("home"), "WP Fastest Cache Preload iPhone Mobile Bot - After Cache Timeout");
					}
				}else if($rule->prefix == "startwith"){
						if(!is_dir($this->getWpContentDir("/cache/tmpWpfc"))){
							if(@mkdir($this->getWpContentDir("/cache/tmpWpfc"), 0755, true)){}
						}

						$rule->content = trim($rule->content, "/");

						$files = glob($this->getWpContentDir("/cache/all/").$rule->content."*");

						foreach ((array)$files as $file) {
							$mobile_file = str_replace("/cache/all/", "/cache/wpfc-mobile-cache/", $file);
							
							@rename($file, $this->getWpContentDir("/cache/tmpWpfc/").time());
							@rename($mobile_file, $this->getWpContentDir("/cache/tmpWpfc/mobile_").time());
						}
				}else if($rule->prefix == "exact"){
					$rule->content = trim($rule->content, "/");

					@unlink($this->getWpContentDir("/cache/all/").$rule->content."/index.html");
					@unlink($this->getWpContentDir("/cache/wpfc-mobile-cache/").$rule->content."/index.html");
				}

				if($rule->prefix != "all"){
					if($this->isPluginActive("wp-fastest-cache-premium/wpFastestCachePremium.php")){
						include_once $this->get_premium_path("logs.php");
						$log = new WpFastestCacheLogs("delete");
						$log->action($rule);
					}
				}
			}else{
				//for old cron job
				$this->deleteCache();
			}
		}

		public function modify_htaccess_for_new_user($user_id){
			$path = ABSPATH;

			if($this->is_subdirectory_install()){
				$path = $this->getABSPATH();
			}

			$htaccess = @file_get_contents($path.".htaccess");

			if(preg_match("/\#\s?Start_WPFC_Exclude_Admin_Cookie/", $htaccess)){
				$rules = $this->excludeAdminCookie();

				$htaccess = preg_replace("/\#\s?Start_WPFC_Exclude_Admin_Cookie[^\#]*\#\s?End_WPFC_Exclude_Admin_Cookie\s+/", $rules, $htaccess);
			}

			@file_put_contents($path.".htaccess", $htaccess);
		}

		public function excludeAdminCookie(){
			$rules = "";
			$users_groups = array_chunk(get_users(array("role" => "administrator", "fields" => array("user_login"))), 5);

			foreach ($users_groups as $group_key => $group) {
				$tmp_users = "";
				$tmp_rule = "";

				foreach ($group as $key => $value) {
					if($tmp_users){
						$tmp_users = $tmp_users."|".sanitize_user(wp_unslash($value->user_login), true);
					}else{
						$tmp_users = sanitize_user(wp_unslash($value->user_login), true);
					}

					// to replace spaces with \s
					$tmp_users = preg_replace("/\s/", "\s", $tmp_users);

					if(!next($group)){
						$tmp_rule = "RewriteCond %{HTTP:Cookie} !wordpress_logged_in_[^\=]+\=".$tmp_users;
					}
				}

				if($rules){
					$rules = $rules."\n".$tmp_rule;
				}else{
					$rules = $tmp_rule;
				}
			}

			return "# Start_WPFC_Exclude_Admin_Cookie\n".$rules."\n# End_WPFC_Exclude_Admin_Cookie\n";
		}

		public function excludeRules(){
			$htaccess_page_rules = "";
			$htaccess_page_useragent = "";
			$htaccess_page_cookie = "";

			if($rules_json = get_option("WpFastestCacheExclude")){
				if($rules_json != "null"){
					$rules_std = json_decode($rules_json);

					foreach ($rules_std as $key => $value) {
						$value->type = isset($value->type) ? $value->type : "page";

						// escape the chars
						$value->content = str_replace("?", "\?", $value->content);

						if($value->type == "page"){
							if($value->prefix == "startwith"){
								$htaccess_page_rules = $htaccess_page_rules."RewriteCond %{REQUEST_URI} !^/".$value->content." [NC]\n";
							}

							if($value->prefix == "contain"){
								$htaccess_page_rules = $htaccess_page_rules."RewriteCond %{REQUEST_URI} !".$value->content." [NC]\n";
							}

							if($value->prefix == "exact"){
								$htaccess_page_rules = $htaccess_page_rules."RewriteCond %{REQUEST_URI} !\/".$value->content." [NC]\n";
							}
						}else if($value->type == "useragent"){
							$htaccess_page_useragent = $htaccess_page_useragent."RewriteCond %{HTTP_USER_AGENT} !".$value->content." [NC]\n";
						}else if($value->type == "cookie"){
							$htaccess_page_cookie = $htaccess_page_cookie."RewriteCond %{HTTP:Cookie} !".$value->content." [NC]\n";
						}
					}
				}
			}

			return "# Start WPFC Exclude\n".$htaccess_page_rules.$htaccess_page_useragent.$htaccess_page_cookie."# End WPFC Exclude\n";
		}

		public function getABSPATH(){
			$path = ABSPATH;
			$siteUrl = site_url();
			$homeUrl = home_url();
			$diff = str_replace($homeUrl, "", $siteUrl);
			$diff = trim($diff,"/");

		    $pos = strrpos($path, $diff);

		    if($pos !== false){
		    	$path = substr_replace($path, "", $pos, strlen($diff));
		    	$path = trim($path,"/");
		    	$path = "/".$path."/";
		    }
		    return $path;
		}

		public function rm_folder_recursively($dir, $i = 1) {
			if(is_dir($dir)){
				$files = @scandir($dir);
			    foreach((array)$files as $file) {
			    	if($i > 50 && !preg_match("/wp-fastest-cache-premium/i", $dir)){
			    		return true;
			    	}else{
			    		$i++;
			    	}
			        if ('.' === $file || '..' === $file) continue;
			        if (is_dir("$dir/$file")){
			        	$this->rm_folder_recursively("$dir/$file", $i);
			        }else{
			        	if(file_exists("$dir/$file")){
			        		@unlink("$dir/$file");
			        	}
			        }
			    }
			}
	
		    if(is_dir($dir)){
			    $files_tmp = @scandir($dir);
			    
			    if(!isset($files_tmp[2])){
			    	@rmdir($dir);
			    }
		    }

		    return true;
		}

		public function is_subdirectory_install(){
			if(strlen(site_url()) > strlen(home_url())){
				return true;
			}
			return false;
		}

		protected function getMobileUserAgents(){
			return implode("|", $this->get_mobile_browsers())."|".implode("|", $this->get_operating_systems());
		}

		public function get_premium_path($name){
			return WPFC_WP_PLUGIN_DIR."/wp-fastest-cache-premium/pro/library/".$name;
		}

		public function cron_add_minute( $schedules ) {
			$schedules['everyminute'] = array(
			    'interval' => 60*1,
			    'display' => __( 'Once Every 1 Minute' ),
			    'wpfc' => false
		    );

			$schedules['everyfiveminute'] = array(
			    'interval' => 60*5,
			    'display' => __( 'Once Every 5 Minutes' ),
			    'wpfc' => false
		    );

		   	$schedules['everyfifteenminute'] = array(
			    'interval' => 60*15,
			    'display' => __( 'Once Every 15 Minutes' ),
			    'wpfc' => true
		    );

		    $schedules['twiceanhour'] = array(
			    'interval' => 60*30,
			    'display' => __( 'Twice an Hour' ),
			    'wpfc' => true
		    );

		    $schedules['onceanhour'] = array(
			    'interval' => 60*60,
			    'display' => __( 'Once an Hour' ),
			    'wpfc' => true
		    );

		    $schedules['everytwohours'] = array(
			    'interval' => 60*60*2,
			    'display' => __( 'Once Every 2 Hours' ),
			    'wpfc' => true
		    );

		    $schedules['everythreehours'] = array(
			    'interval' => 60*60*3,
			    'display' => __( 'Once Every 3 Hours' ),
			    'wpfc' => true
		    );

		    $schedules['everyfourhours'] = array(
			    'interval' => 60*60*4,
			    'display' => __( 'Once Every 4 Hours' ),
			    'wpfc' => true
		    );

		    $schedules['everyfivehours'] = array(
			    'interval' => 60*60*5,
			    'display' => __( 'Once Every 5 Hours' ),
			    'wpfc' => true
		    );

		    $schedules['everysixhours'] = array(
			    'interval' => 60*60*6,
			    'display' => __( 'Once Every 6 Hours' ),
			    'wpfc' => true
		    );

		    $schedules['everysevenhours'] = array(
			    'interval' => 60*60*7,
			    'display' => __( 'Once Every 7 Hours' ),
			    'wpfc' => true
		    );

		    $schedules['everyeighthours'] = array(
			    'interval' => 60*60*8,
			    'display' => __( 'Once Every 8 Hours' ),
			    'wpfc' => true
		    );

		    $schedules['everyninehours'] = array(
			    'interval' => 60*60*9,
			    'display' => __( 'Once Every 9 Hours' ),
			    'wpfc' => true
		    );

		    $schedules['everytenhours'] = array(
			    'interval' => 60*60*10,
			    'display' => __( 'Once Every 10 Hours' ),
			    'wpfc' => true
		    );

		    $schedules['onceaday'] = array(
			    'interval' => 60*60*24,
			    'display' => __( 'Once a Day' ),
			    'wpfc' => true
		    );

		    $schedules['everythreedays'] = array(
			    'interval' => 60*60*24*3,
			    'display' => __( 'Once Every 3 Days' ),
			    'wpfc' => true
		    );

		    $schedules['weekly'] = array(
			    'interval' => 60*60*24*7,
			    'display' => __( 'Once a Week' ),
			    'wpfc' => true
		    );

		    $schedules['everytendays'] = array(
			    'interval' => 60*60*24*10,
			    'display' => __( 'Once Every 10 Days' ),
			    'wpfc' => true
		    );

		    $schedules['montly'] = array(
			    'interval' => 60*60*24*30,
			    'display' => __( 'Once a Month' ),
			    'wpfc' => true
		    );

		    $schedules['yearly'] = array(
			    'interval' => 60*60*24*30*12,
			    'display' => __( 'Once a Year' ),
			    'wpfc' => true
		    );

		    return $schedules;
		}

		public function setCustomInterval(){
			add_filter( 'cron_schedules', array($this, 'cron_add_minute'));
		}

		public function isPluginActive( $plugin ) {
			return in_array( $plugin, (array) get_option( 'active_plugins', array() ) ) || $this->isPluginActiveForNetwork( $plugin );
		}
		
		public function isPluginActiveForNetwork( $plugin ) {
			if ( !is_multisite() )
				return false;

			$plugins = get_site_option( 'active_sitewide_plugins');
			if ( isset($plugins[$plugin]) )
				return true;

			return false;
		}

		public function current_url(){
			global $wp;
		    $current_url = home_url($_SERVER['REQUEST_URI']);

		    return $current_url;


			// if(defined('WP_CLI')){
			// 	$_SERVER["SERVER_NAME"] = isset($_SERVER["SERVER_NAME"]) ? $_SERVER["SERVER_NAME"] : "";
			// 	$_SERVER["SERVER_PORT"] = isset($_SERVER["SERVER_PORT"]) ? $_SERVER["SERVER_PORT"] : 80;
			// }
			
		 //    $pageURL = 'http';
		 
		 //    if(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on'){
		 //        $pageURL .= 's';
		 //    }
		 
		 //    $pageURL .= '://';
		 
		 //    if($_SERVER['SERVER_PORT'] != '80'){
		 //        $pageURL .= $_SERVER['SERVER_NAME'].':'.$_SERVER['SERVER_PORT'].$_SERVER['REQUEST_URI'];
		 //    }else{
		 //        $pageURL .= $_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'];
		 //    }
		 
		 //    return $pageURL;
		}

		public function wpfc_load_plugin_textdomain(){
			load_plugin_textdomain('wp-fastest-cache', FALSE, basename( dirname( __FILE__ ) ) . '/languages/' );
		}

		public function cdn_replace_urls($matches){
			if(count($this->cdn) > 0){
				foreach ($this->cdn as $key => $cdn) {
					if($cdn->id == "cloudflare"){
						continue;
					}

					if(preg_match("/manifest\.json\.php/i", $matches[0])){
						return $matches[0];
					}

					//https://cdn.shortpixel.ai/client/q_glossy,ret_img,w_736/http://wpfc.com/stories.png
					if(preg_match("/cdn\.shortpixel\.ai\/client/i", $matches[0])){
						return $matches[0];
					}

					//https://i0.wp.com/i0.wp.com/wpfc.com/stories.png
					if(preg_match("/i\d\.wp\.com/i", $matches[0])){
						return $matches[0];
					}


					if(preg_match("/^\/\/random/", $cdn->cdnurl) || preg_match("/\/\/i\d\.wp\.com/", $cdn->cdnurl)){
						if(preg_match("/^\/\/random/", $cdn->cdnurl)){
							$cdnurl = "//i".rand(0,3).".wp.com/".str_replace("www.", "", $_SERVER["HTTP_HOST"]);
							$cdnurl = preg_replace("/\/\/i\d\.wp\.com/", "//i".rand(0,3).".wp.com", $cdnurl);
						}else{
							$cdnurl = $cdn->cdnurl;
						}

						//to add www. if exists
						if(preg_match("/\/\/www\./", $matches[0])){
							$cdnurl = preg_replace("/(\/\/i\d\.wp\.com\/)(www\.)?/", "$1www.", $cdnurl);
						}
					}else{
						$cdnurl = $cdn->cdnurl;
					}

					$cdn->file_types = str_replace(",", "|", $cdn->file_types);

					if(preg_match("/\.(".$cdn->file_types.")[\"\'\?\)\s]/i", $matches[0])){
						//nothing
					}else{
						if(preg_match("/js/", $cdn->file_types)){
							if(!preg_match("/\/revslider\/public\/assets\/js/", $matches[0])){
								continue;
							}
						}else{
							continue;
						}
					}

					if($cdn->keywords){
						$cdn->keywords = str_replace(",", "|", $cdn->keywords);

						if(!preg_match("/".preg_quote($cdn->keywords, "/")."/i", $matches[0])){
							continue;
						}
					}

					if(preg_match("/data-product_variations\=[\"\'][^\"\']+[\"\']/i", $matches[0])){
						$cdn->originurl = preg_quote($cdn->originurl, "/");
						$cdn->originurl = str_replace("\/", "\\\\\/", $cdn->originurl);
						
						if(preg_match("/".$cdn->originurl."/", $matches[0])){
							$matches[0] = preg_replace("/(quot\;)(http(s?)\:)?".preg_quote("\/\/", "/")."(www\.)?/i", "$1", $matches[0]);
							$matches[0] = preg_replace("/".$cdn->originurl."/i", $cdnurl, $matches[0]);
						}
					}else if(preg_match("/\{\"concatemoji\"\:\"[^\"]+\"\}/i", $matches[0])){
						$matches[0] = preg_replace("/(http(s?)\:)?".preg_quote("\/\/", "/")."(www\.)?/i", "", $matches[0]);
						$matches[0] = preg_replace("/".preg_quote($cdn->originurl, "/")."/i", $cdnurl, $matches[0]);
					}else if(isset($matches[2]) && preg_match("/".preg_quote($cdn->originurl, "/")."/", $matches[2])){
						$matches[0] = preg_replace("/(http(s?)\:)?\/\/(www\.)?".preg_quote($cdn->originurl, "/")."/i", $cdnurl, $matches[0]);
					}else if(isset($matches[2]) && preg_match("/^(\/?)(wp-includes|wp-content)/", $matches[2])){
						$matches[0] = preg_replace("/(\/?)(wp-includes|wp-content)/i", $cdnurl."/"."$2", $matches[0]);
					}else if(preg_match("/[\"\']https?\:\\\\\/\\\\\/[^\"\']+[\"\']/i", $matches[0])){
						if(preg_match("/^(logo|url|image)$/i", $matches[1])){
							//If the url is called with "//", it causes an error on https://search.google.com/structured-data/testing-tool/u/0/
							//<script type="application/ld+json">"logo":{"@type":"ImageObject","url":"\/\/cdn.site.com\/image.png"}</script>
							//<script type="application/ld+json">{"logo":"\/\/cdn.site.com\/image.png"}</script>
							//<script type="application/ld+json">{"image":"\/\/cdn.site.com\/image.jpg"}</script>
						}else{
							//<script>var loaderRandomImages=["https:\/\/www.site.com\/wp-content\/uploads\/2016\/12\/image.jpg"];</script>
							$matches[0] = preg_replace("/\\\\\//", "/", $matches[0]);
							
							if(preg_match("/".preg_quote($cdn->originurl, "/")."/", $matches[0])){
								$matches[0] = preg_replace("/(http(s?)\:)?\/\/(www\.)?".preg_quote($cdn->originurl, "/")."/i", $cdnurl, $matches[0]);
								$matches[0] = preg_replace("/\//", "\/", $matches[0]);
							}
						}
					}
				}
			}

			return $matches[0];
		}

		public function read_file($url){
			if(!preg_match("/\.php/", $url)){
				$url = preg_replace("/\?.*/", "", $url);

				if(preg_match("/wp-content/", $url)){
					$path = preg_replace("/.+\/wp-content\/(.+)/", WPFC_WP_CONTENT_DIR."/"."$1", $url);
				}else if(preg_match("/wp-includes/", $url)){
					$path = preg_replace("/.+\/wp-includes\/(.+)/", ABSPATH."wp-includes/"."$1", $url);
				}

				if(@file_exists($path)){
					$filesize = filesize($path);

					if($filesize > 0){
						$myfile = fopen($path, "r") or die("Unable to open file!");
						$data = fread($myfile, $filesize);
						fclose($myfile);

						return $data;
					}else{
						return false;
					}
				}
			}

			return false;
		}

		public function get_operating_systems(){
			$operating_systems  = array(
									'Android',
									'blackberry|\bBB10\b|rim\stablet\sos',
									'PalmOS|avantgo|blazer|elaine|hiptop|palm|plucker|xiino',
									'Symbian|SymbOS|Series60|Series40|SYB-[0-9]+|\bS60\b',
									'Windows\sCE.*(PPC|Smartphone|Mobile|[0-9]{3}x[0-9]{3})|Window\sMobile|Windows\sPhone\s[0-9.]+|WCE;',
									'Windows\sPhone\s10.0|Windows\sPhone\s8.1|Windows\sPhone\s8.0|Windows\sPhone\sOS|XBLWP7|ZuneWP7|Windows\sNT\s6\.[23]\;\sARM\;',
									'\biPhone.*Mobile|\biPod|\biPad',
									'Apple-iPhone7C2',
									'MeeGo',
									'Maemo',
									'J2ME\/|\bMIDP\b|\bCLDC\b', // '|Java/' produces bug #135
									'webOS|hpwOS',
									'\bBada\b',
									'BREW'
							    );
			return $operating_systems;
		}

		public function get_mobile_browsers(){
			$mobile_browsers  = array(
								'\bCrMo\b|CriOS|Android.*Chrome\/[.0-9]*\s(Mobile)?',
								'\bDolfin\b',
								'Opera.*Mini|Opera.*Mobi|Android.*Opera|Mobile.*OPR\/[0-9.]+|Coast\/[0-9.]+',
								'Skyfire',
								'Mobile\sSafari\/[.0-9]*\sEdge',
								'IEMobile|MSIEMobile', // |Trident/[.0-9]+
								'fennec|firefox.*maemo|(Mobile|Tablet).*Firefox|Firefox.*Mobile|FxiOS',
								'bolt',
								'teashark',
								'Blazer',
								'Version.*Mobile.*Safari|Safari.*Mobile|MobileSafari',
								'Tizen',
								'UC.*Browser|UCWEB',
								'baiduboxapp',
								'baidubrowser',
								'DiigoBrowser',
								'Puffin',
								'\bMercury\b',
								'Obigo',
								'NF-Browser',
								'NokiaBrowser|OviBrowser|OneBrowser|TwonkyBeamBrowser|SEMC.*Browser|FlyFlow|Minimo|NetFront|Novarra-Vision|MQQBrowser|MicroMessenger',
								'Android.*PaleMoon|Mobile.*PaleMoon'
							    );
			return $mobile_browsers;
		}


	}

	// Load WP CLI command(s) on demand.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
	    require_once "inc/cli.php";
	}

	$GLOBALS["wp_fastest_cache"] = new WpFastestCache();
?>