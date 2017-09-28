<?php
/**
 * Tiny-Tiny-RSS plugin
 * Setup custom fetch options per feed
 * - proxy host and port
 * - user-agent header
 * - ssl certificate verification
 *
 * Hook: hook_fetch_feed
 *
 * Depends: curl
 *
 * @author: Sergey Dryabzhinsky <sergey.dryabzhinsky@gmail.com>
 * @version: 1.2
 * @since: 2017-09-28
 * @copyright: GPLv3
 */

class Options_Per_Feed extends Plugin {

	private $host;

	function about() {
		return array(1.2,
			"Try to set options to only selected feeds",
			"SergeyD");
	}

	function flags() {
		return array("needs_curl" => true);
	}

	/**
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	function csrf_ignore($method) {
		return false;
	}

	/**
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	function before($method) {
		return true;
	}

	function after() {
		return true;
	}


	function init($host)
	{
		$this->host = $host;

		$host->add_hook($host::HOOK_FETCH_FEED, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
		$host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);

		$host->add_filter_action($this, "action_inline", __("Inline content"));
	}

	function hook_fetch_feed($feed_data, $fetch_url, $owner_uid, $feed, $last_article_timestamp, $auth_login, $auth_pass) {

		global $fetch_last_error;
		global $fetch_last_error_code;
		global $fetch_last_error_content;
		global $fetch_last_content_type;
		global $fetch_last_modified;
		global $fetch_curl_used;

		$fetch_last_error = false;
		$fetch_last_error_code = -1;
		$fetch_last_error_content = "";
		$fetch_last_content_type = "";
		$fetch_curl_used = false;
		$fetch_last_modified = "";


		/* Try to use cache first */
		$cache_filename = CACHE_DIR . "/simplepie/" . sha1($fetch_url) . ".xml";

		if (!$feed_data &&
			file_exists($cache_filename) &&
			is_readable($cache_filename) &&
			!$auth_login && !$auth_pass &&
			filemtime($cache_filename) > time() - 30) {

			_debug("plugin[options_per_feed]: using local cache [$cache_filename].", $debug_enabled);

			@$feed_data = file_get_contents($cache_filename);

			if ($feed_data) {
				return $feed_data;
			}
		}

		$options_feeds = $this->host->get($this, "options_feeds");
		if (!is_array($options_feeds)) return $feed_data;

		$options = isset($options_feeds[$key]) !== FALSE ? $options_feeds[$key] : array(
			"proxy_host": "",
			"proxy_port": "",
			"user_agent": "",
			"ssl_verify": true,
		);
		if (empty($options["proxy_host"]) && empty($options["user_agent"]) && !empty($options["ssl_verify"])) return $feed_data;

		$ch = curl_init($fetch_url);

		curl_setopt($ch, CURLOPT_TIMEOUT, defined('FEED_FETCH_TIMEOUT') ? FEED_FETCH_TIMEOUT : 15);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, defined('FEED_FETCH_CONNECT_TIMEOUT') ? FEED_FETCH_CONNECT_TIMEOUT : 5);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 20);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_ENCODING, "");

		if (defined("CURLOPT_TCP_FASTOPEN")) {
			curl_setopt($ch, CURLOPT_TCP_FASTOPEN, true);
		}

		if ($auth_login || $auth_pass) {
			curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
			curl_setopt($ch, CURLOPT_USERPWD, "$auth_login:$auth_pass");
		}

		if (!empty($options["proxy_host"])) {
			$proxy_host = $options["proxy_host"];
			$proxy_port = $options["proxy_port"];
			curl_setopt($ch, CURLOPT_PROXY, "$proxy_host:$proxy_port");
		}

		if (!empty($options["user_agent"])) {
			curl_setopt($ch, CURLOPT_USERAGENT, $options["user_agent"]);
		} else {
			curl_setopt($ch, CURLOPT_USERAGENT, SELF_USER_AGENT);
		}

		if (empty($options["ssl_verify"])) {
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		}

		$feed_data = @curl_exec($ch);

		if (curl_errno($ch) === 23 || curl_errno($ch) === 61) {
			curl_setopt($ch, CURLOPT_ENCODING, 'none');
			$feed_data = @curl_exec($ch);
		}

		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$fetch_last_content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

		$fetch_last_error_code = $http_code;

		if ($http_code != 200 || $type && strpos($fetch_last_content_type, "$type") === false) {
			if (curl_errno($ch) != 0) {
				$fetch_last_error = curl_errno($ch) . " " . curl_error($ch);
			} else {
				$fetch_last_error = "HTTP Code: $http_code";
			}
			$fetch_last_error_content = $feed_data;
			curl_close($ch);
			return false;
		}

		if (!$feed_data) {
			$fetch_last_error = curl_errno($ch) . " " . curl_error($ch);
			curl_close($ch);
			return false;
		}

		curl_close($ch);

		return $feed_data;
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		print "<div dojoType=\"dijit.layout.AccordionPane\" title=\"".__('Feed custom fetch options (options_per_feed)')."\">";

		print_notice("Enable the plugin for specific feeds in the feed editor.");

		print "<form dojoType=\"dijit.form.Form\">";

		print "<script type=\"dojo/method\" event=\"onSubmit\" args=\"evt\">
			evt.preventDefault();
			if (this.validate()) {
				console.log(dojo.objectToQuery(this.getValues()));
				new Ajax.Request('backend.php', {
					parameters: dojo.objectToQuery(this.getValues()),
					onComplete: function(transport) {
						notify_info(transport.responseText);
					}
				});
				//this.reset();
			}
			</script>";

		print_hidden("op", "pluginhandler");
		print_hidden("method", "save");
		print_hidden("plugin", "options_per_feed");

		$enable_flag = $this->host->get($this, "enable_options_per_feed");

		print_checkbox("enable_options_per_feed", $enable_flag);
		print "&nbsp;<label for=\"enable_options_per_feed\">" . __("Use custom fetch options for feed.") . "</label>";

		print "<p>"; print_button("submit", __("Save"));
		print "</form>";

		$enabled_feeds = $this->host->get($this, "enabled_feeds");
		if (!is_array($enabled_feeds)) $enabled_feeds = array();

		$enabled_feeds = $this->filter_unknown_feeds($enabled_feeds);
		$this->host->set($this, "enabled_feeds", $enabled_feeds);

		if (count($enabled_feeds) > 0) {
			print "<h3>" . __("Currently enabled for (click to edit):") . "</h3>";

			print "<ul class=\"browseFeedList\" style=\"border-width : 1px\">";
			foreach ($enabled_feeds as $f) {
				print "<li>" .
					"<img src='images/pub_set.png'
						style='vertical-align : middle'> <a href='#'
						onclick='editFeed($f)'>".
					Feeds::getFeedTitle($f) . "</a></li>";
			}
			print "</ul>";
		}

		print "</div>";
	}

	function hook_prefs_edit_feed($feed_id) {
		print "<div class=\"dlgSec\">".__("Options per feed")."</div>";
		print "<div class=\"dlgSecCont\">";

		$enabled_feeds = $this->host->get($this, "enabled_feeds");
		if (!is_array($enabled_feeds)) $enabled_feeds = array();

		$options_feeds = $this->host->get($this, "options_feeds");
		if (!is_array($options_feeds)) $options_feeds = array();

		$key = isset($options_feeds[$feed_id]);
		$checked = $key !== FALSE ? "checked" : "";

		$options = isset($options_feeds[$feed_id]) !== FALSE ? $options_feeds[$feed_id] : array(
			"proxy_host": "",
			"proxy_port": "",
			"user_agent": "",
			"ssl_verify": true,
		);

		$proxy_host = $options["proxy_host"];
		$proxy_port = $options["proxy_port"];
		$user_agent = $options["user_agent"];
		$ssl_verify = !empty($options["ssl_verify"]) ? "checked" : "";

		print "<hr/><input dojoType=\"dijit.form.CheckBox\" type=\"checkbox\" id=\"options_per_feed_enabled\"
			name=\"options_per_feed_enabled\"
			$checked>&nbsp;<label for=\"options_per_feed_enabled\">".__('Options per feed enabled')."</label>";

		print "<br/>
				<input dojoType=\"dijit.form.TextBox\"
				style=\"width : 20em;\"
				name=\"options_per_feed_proxy_host\" value=\"$proxy_host\"
				id=\"options_per_feed_proxy_host\">&nbsp;<label for=\"options_per_feed_proxy_host\">".__('Proxy host')."</label>";

		print "<br/><input dojoType=\"dijit.form.NumberTextBox\"
				style=\"width : 20em;\"
				name=\"options_per_feed_proxy_port\" value=\"$proxy_port\"
				id=\"options_per_feed_proxy_port\">&nbsp;<label for=\"options_per_feed_proxy_port\">".__('Proxy port')."</label>";

		print "<br/>
				<input dojoType=\"dijit.form.TextBox\"
				style=\"width : 20em;\"
				name=\"options_per_feed_useragent\" value=\"$user_agent\"
				id=\"options_per_feed_useragent\">&nbsp;<label for=\"options_per_feed_useragent\">".__('User-Agent')."</label>";

		print "<br/><input dojoType=\"dijit.form.CheckBox\" type=\"checkbox\" id=\"options_per_feed_sslverify\"
			name=\"options_per_feed_sslverify\"
			$checked>&nbsp;<label for=\"options_per_feed_sslverify\">".__('Verify SSL certificate')."</label>";


		print "</div>";
	}

	function hook_prefs_save_feed($feed_id) {
		$enabled_feeds = $this->host->get($this, "enabled_feeds");
		if (!is_array($enabled_feeds)) $enabled_feeds = array();

		$options_feeds = $this->host->get($this, "options_feeds");
		if (!is_array($options_feeds)) $options_feeds = array();

		$enable = checkbox_to_sql_bool($_POST["options_per_feed_enabled"]) == 'true';
		$key = array_search($feed_id, $enabled_feeds);

		$options = isset($options_feeds[$feed_id]) !== FALSE ? $options_feeds[$feed_id] : array(
			"proxy_host": "",
			"proxy_port": "",
			"user_agent": "",
			"ssl_verify": true,
		);

		$proxy_host = isset($_POST["options_per_feed_proxy_host"]) ? db_escape_string($_POST["options_per_feed_proxy_host"]) : '';
		$proxy_port = isset($_POST["options_per_feed_proxy_port"]) ? db_escape_string($_POST["options_per_feed_proxy_port"]) : '';
		$user_agent = isset($_POST["options_per_feed_useragent"]) ? db_escape_string($_POST["options_per_feed_useragent"]) : '';
		$ssl_verify = isset($_POST["options_per_feed_sslverify"]) ? db_escape_string($_POST["options_per_feed_sslverify"]) : '';

		if ($enable) {
			if ($key === FALSE) {
				array_push($enabled_feeds, $feed_id);
			}
			$options["proxy_host"] = $proxy_host;
			$options["proxy_port"] = (int)$proxy_port;
			$options["user_agent"] = $user_agent;
			$options["ssl_verify"] = (bool)$ssl_verify;
			$options_feeds[$feed_id] = $options;
		} else {
			if ($key !== FALSE) {
				unset($enabled_feeds[$key]);
				unset($options_feeds[$feed_id]);
			}
		}

		$this->host->set($this, "enabled_feeds", $enabled_feeds);
		$this->host->set($this, "options_feeds", $options_feeds);
	}

	function api_version() {
		return 2;
	}

	private function filter_unknown_feeds($enabled_feeds) {
		$tmp = array();

		foreach ($enabled_feeds as $feed) {

			$result = db_query("SELECT id FROM ttrss_feeds WHERE id = '$feed' AND owner_uid = " . $_SESSION["uid"]);

			if (db_num_rows($result) != 0) {
				array_push($tmp, $feed);
			}
		}

		return $tmp;
	}

}
