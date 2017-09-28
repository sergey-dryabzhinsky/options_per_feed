<?php
/**
 * Tiny-Tiny-RSS plugin
 * Setup proxy settings per feed
 *
 * Hook: hook_fetch_feed
 *
 * Depends: curl
 *
 * @author: Sergey Dryabzhinsky <sergey.dryabzhinsky@gmail.com>
 * @version: 1.0
 * @since: 2017-09-28
 * @copyright: GPLv3
 */

class Proxy_Per_Feed extends Plugin {

	private $host;

	function about() {
		return array(1.0,
			"Try to set proxy to only selected feeds",
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


		$proxy_feeds = $this->host->get($this, "proxy_feeds");
		if (!is_array($proxy_feeds)) return $feed_data;

		$proxy = isset($proxy_feeds[$key]) !== FALSE ? $proxy_feeds[$key] : array("","");
		if (empty($proxy[0])) return $feed_data;

		$ch = curl_init($fetch_url);

		curl_setopt($ch, CURLOPT_TIMEOUT, defined('FEED_FETCH_TIMEOUT') ? FEED_FETCH_TIMEOUT : 15);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, defined('FEED_FETCH_CONNECT_TIMEOUT') ? FEED_FETCH_CONNECT_TIMEOUT : 5);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 20);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, SELF_USER_AGENT);
		curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_ENCODING, "");
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);

		if ($auth_login || $auth_pass)
			curl_setopt($ch, CURLOPT_USERPWD, "$auth_login:$auth_pass");

		curl_setopt($ch, CURLOPT_PROXY, "$proxy[0]:$proxy[1]");

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

		print "<div dojoType=\"dijit.layout.AccordionPane\" title=\"".__('Proxy settings (proxy_per_feed)')."\">";

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
		print_hidden("plugin", "proxy_per_feed");

		$enable_proxy = $this->host->get($this, "enable_proxy");

		print_checkbox("enable_proxy", $enable_proxy);
		print "&nbsp;<label for=\"enable_proxy\">" . __("Use proxy for feed.") . "</label>";

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
		print "<div class=\"dlgSec\">".__("Proxy per feed")."</div>";
		print "<div class=\"dlgSecCont\">";

		$enabled_feeds = $this->host->get($this, "enabled_feeds");
		if (!is_array($enabled_feeds)) $enabled_feeds = array();

		$proxy_feeds = $this->host->get($this, "proxy_feeds");
		if (!is_array($proxy_feeds)) $proxy_feeds = array();

		$key = array_search($feed_id, $enabled_feeds);
		$checked = $key !== FALSE ? "checked" : "";

		$proxy = isset($proxy_feeds[$feed_id]) !== FALSE ? $proxy_feeds[$feed_id] : array("","");

		print "<hr/><input dojoType=\"dijit.form.CheckBox\" type=\"checkbox\" id=\"proxy_per_feed_enabled\"
			name=\"proxy_per_feed_enabled\"
			$checked>&nbsp;<label for=\"proxy_per_feed_enabled\">".__('Proxy enabled')."</label>";

		print "<br>
				<input dojoType=\"dijit.form.TextBox\"
				style=\"width : 20em;\"
				name=\"proxy_per_feed_host\" value=\"$proxy[0]\"
				id=\"proxy_per_feed_host\">&nbsp;<label for=\"proxy_per_feed_host\">".__('Proxy host')."</label>";

		print "<br><input dojoType=\"dijit.form.NumberTextBox\"
				style=\"width : 20em;\"
				name=\"proxy_per_feed_port\" value=\"$proxy[1]\"
				id=\"proxy_per_feed_port\">&nbsp;<label for=\"proxy_per_feed_port\">".__('Proxy port')."</label>";

		print "</div>";
	}

	function hook_prefs_save_feed($feed_id) {
		$enabled_feeds = $this->host->get($this, "enabled_feeds");
		if (!is_array($enabled_feeds)) $enabled_feeds = array();

		$proxy_feeds = $this->host->get($this, "proxy_feeds");
		if (!is_array($proxy_feeds)) $proxy_feeds = array();

		$enable = checkbox_to_sql_bool($_POST["proxy_per_feed_enabled"]) == 'true';
		$key = array_search($feed_id, $enabled_feeds);

		$proxy = isset($proxy_feeds[$feed_id]) !== FALSE ? $proxy_feeds[$feed_id] : array("","");

		$proxy_host = isset($_POST["proxy_per_feed_host"]) ? db_escape_string($_POST["proxy_per_feed_host"]) : '';
		$proxy_port = isset($_POST["proxy_per_feed_port"]) ? db_escape_string($_POST["proxy_per_feed_port"]) : '';

		if ($enable) {
			if ($key === FALSE) {
				array_push($enabled_feeds, $feed_id);
			}
			$proxy[0] = $proxy_host;
			$proxy[1] = $proxy_port;
			$proxy_feeds[$feed_id] = $proxy;
		} else {
			if ($key !== FALSE) {
				unset($enabled_feeds[$key]);
				unset($proxy_feeds[$feed_id]);
			}
		}

		$this->host->set($this, "enabled_feeds", $enabled_feeds);
		$this->host->set($this, "proxy_feeds", $proxy_feeds);
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
