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
 * @version: 1.4.0
 * @since: 2017-09-28
 * @copyright: GPLv3
 */

class Options_Per_Feed extends Plugin
{

	private $host;

	public function about()
	{
		return array(1.40,	// 1.4.0
			"Try to set options to only selected feeds (CURL needed)",
			"SergeyD");
	}

	public function flags()
	{
		return array("needs_curl" => true);
	}

	/**
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function csrf_ignore($method)
	{
		return false;
	}

	/**
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function before($method)
	{
		return true;
	}

	public function after()
	{
		return true;
	}


	public function init($host)
	{
		$this->host = $host;

		$host->add_hook($host::HOOK_FETCH_FEED, $this);
		$host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
		$host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
	}

	public function hook_fetch_feed($feed_data, $fetch_url, $owner_uid, $feed, $last_article_timestamp, $auth_login, $auth_pass)
	{

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

			@$feed_data = file_get_contents($cache_filename);

			if ($feed_data) {
				return $feed_data;
			}
		}

		$options_feeds = $this->host->get($this, "options_feeds");
		if (!is_array($options_feeds)) return $feed_data;

		$options = isset($options_feeds[$feed]) !== FALSE ? $options_feeds[$feed] : array(
			"proxy_host" => "",
			"proxy_port" => "",
			"user_agent" => "",
			"cookies" => "",
			"ssl_verify" => true,
			"calc_referer" => false,
		);
		if (empty($options["proxy_host"]) && empty($options["user_agent"]) && empty($options["cookies"]) && !empty($options["ssl_verify"]) && empty($options["calc_referer"])) return $feed_data;

		$fetch_curl_used = true;

		$ch = curl_init($fetch_url);

		curl_setopt($ch, CURLOPT_TIMEOUT, defined('FEED_FETCH_TIMEOUT') ? FEED_FETCH_TIMEOUT : 15);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, defined('FEED_FETCH_CONNECT_TIMEOUT') ? FEED_FETCH_CONNECT_TIMEOUT : 5);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 20);
		curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_ENCODING, "");

		if (defined('CURL_HTTP_VERSION_1_1')) {
			curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		}

		$moreHeaders = array(
			'Accept: application/atom+xml,application/rss+xml;q=0.9,application/rdf+xml;q=0.8,application/xml;q=0.7,text/xml;q=0.7,*/*;q=0.1',
			'Accept-Language: ru,en;q=0.7,en-US;q=0.3',
			'Connection: keep-alive',
			'Cache-Control: max-age=0',
		);

		if (!empty($options["cookies"])) {
			$moreHeaders[] = 'Cookie: ' . $options['cookies'];
		}

		curl_setopt($ch, CURLOPT_HTTPHEADER, $moreHeaders);

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

		if (!empty($options["calc_referer"])) {
			$refArr = parse_url($fetch_url);
			$referer = $refArr["scheme"] . '://' . $refArr["host"];
			if (!empty($refArr["port"])) $referer .= ':' . $refArr["port"];
			if (empty($refArr["query"])) {
				$path = rtrim($refArr["path"], "/");
				$path = explode("/", $path);
				array_pop($path);
				if (!$path) $path = array("/");
				$referer .= join("/", $path);
			} else {
				$referer .= $refArr["path"];
			}

			error_log(__METHOD__ . " - Referer: ".$referer);
			curl_setopt($ch, CURLOPT_REFERER, $referer);
		}

		$ret = @curl_exec($ch);

		if (curl_errno($ch) === 23 || curl_errno($ch) === 61) {
			curl_setopt($ch, CURLOPT_ENCODING, 'none');
			$ret = @curl_exec($ch);
		}

		$headers_length = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$headers_raw = substr($ret, 0, $headers_length);
		$headers = explode("\r\n", $headers_raw);
		$feed_data = substr($ret, $headers_length);

		foreach ($headers as $header) {
			list ($key, $value) = explode(": ", $header);

			if (strtolower($key) == "last-modified") {
				$fetch_last_modified = $value;
			}
		}

		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$fetch_last_content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

		$fetch_last_error_code = $http_code;

		if ($http_code != 200) {
			if (curl_errno($ch) != 0) {
				$fetch_last_error = curl_errno($ch) . " " . curl_error($ch);
			} else {
				$fetch_last_error = "HTTP Code: $http_code";
			}
			error_log(__METHOD__ . " - Error headers:\n".$headers_raw);
			error_log(__METHOD__ . " - Error data:\n".$feed_data);

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

	public function hook_prefs_edit_feed($feed_id)
	{
		print "<div class=\"dlgSec\">".__("Options per feed")."</div>";
		print "<div class=\"dlgSecCont\">";

		$options_feeds = $this->host->get($this, "options_feeds");
		if (!is_array($options_feeds)) $options_feeds = array();

		$has = isset($options_feeds[$feed_id]);
		$checked = $has ? "checked" : "";

		$options = $has ? $options_feeds[$feed_id] : array(
			"proxy_host" => "",
			"proxy_port" => "",
			"user_agent" => "",
			"cookies" => "",
			"ssl_verify" => true,
			"calc_referer" => false,
		);

		$proxy_host = $options["proxy_host"];
		$proxy_port = $options["proxy_port"];
		$user_agent = $options["user_agent"];
		$cookies = $options["cookies"];
		$ssl_verify = !empty($options["ssl_verify"]) ? "checked" : "";
		$calc_referer = !empty($options["calc_referer"]) ? "checked" : "";

		print "<hr/><input dojoType=\"dijit.form.CheckBox\" type=\"checkbox\" id=\"options_per_feed_enabled\"
			name=\"options_per_feed_enabled\"
			$checked>&nbsp;<label for=\"options_per_feed_enabled\">".__('Options per feed enabled')."</label>";

		print "<br/>
				<input dojoType=\"dijit.form.TextBox\"
				style=\"width : 20em;\"
				name=\"options_per_feed_proxy_host\" value=\"$proxy_host\"
				id=\"options_per_feed_proxy_host\" placeholder=\"Example: www.example.com\">&nbsp;<label for=\"options_per_feed_proxy_host\">".__('Proxy host')."</label>";

		print "<br/><input type=\"text\" data-dojo-type=\"dijit/form/NumberTextBox\"
				style=\"width : 20em;\"
				name=\"options_per_feed_proxy_port\" value=\"$proxy_port\"
				id=\"options_per_feed_proxy_port\" placeholder=\"Example: 3128\">&nbsp;<label for=\"options_per_feed_proxy_port\">".__('Proxy port')."</label>";

		print "<br/>
				<input dojoType=\"dijit.form.TextBox\"
				style=\"width : 20em;\"
				name=\"options_per_feed_useragent\" value=\"$user_agent\"
				id=\"options_per_feed_useragent\" placeholder=\"Example: Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:55.0) Gecko/20100101 Firefox/55.0\">&nbsp;<label for=\"options_per_feed_useragent\">".__('User-Agent')."</label>"
			."<br/><small>Try: Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:55.0) Gecko/20100101 Firefox/55.0</small>"
			;

		print "<br/>
				<input dojoType=\"dijit.form.TextBox\"
				style=\"width : 20em;\"
				name=\"options_per_feed_cookies\" value=\"$cookies\"
				id=\"options_per_feed_cookies\" placeholder=\"Example: PHPSESSID=1234567890;\">&nbsp;<label for=\"options_per_feed_cookies\">".__('Cookies header')."</label>"
			;

		print "<br/><input dojoType=\"dijit.form.CheckBox\" type=\"checkbox\" id=\"options_per_feed_sslverify\"
			name=\"options_per_feed_sslverify\"
			$ssl_verify>&nbsp;<label for=\"options_per_feed_sslverify\">".__('Verify SSL certificate')."</label>";

		print "<br/><input dojoType=\"dijit.form.CheckBox\" type=\"checkbox\" id=\"options_per_feed_calcreferer\"
			name=\"options_per_feed_calcreferer\"
			$calc_referer>&nbsp;<label for=\"options_per_feed_calcreferer\">".__('Calculate feed referer')."</label>";


		print "</div>";
	}

	public function hook_prefs_save_feed($feed_id)
	{
		$options_feeds = $this->host->get($this, "options_feeds");
		if (!is_array($options_feeds)) $options_feeds = array();

		$enable = checkbox_to_sql_bool($_POST["options_per_feed_enabled"]) === 1;
		$has = isset($options_feeds[$feed_id]);

		$options = $has ? $options_feeds[$feed_id] : array(
			"proxy_host" => "",
			"proxy_port" => "",
			"user_agent" => "",
			"cookies" => "",
			"ssl_verify" => true,
			"calc_referer" => false,
		);

		$proxy_host = isset($_POST["options_per_feed_proxy_host"]) ? db_escape_string($_POST["options_per_feed_proxy_host"]) : '';
		$proxy_port = isset($_POST["options_per_feed_proxy_port"]) ? db_escape_string($_POST["options_per_feed_proxy_port"]) : '';
		$user_agent = isset($_POST["options_per_feed_useragent"]) ? db_escape_string($_POST["options_per_feed_useragent"]) : '';
		$cookies = isset($_POST["options_per_feed_cookies"]) ? db_escape_string($_POST["options_per_feed_cookies"]) : '';
		$ssl_verify = isset($_POST["options_per_feed_sslverify"]) ? checkbox_to_sql_bool($_POST["options_per_feed_sslverify"]) === 1 : false;
		$calc_referer = isset($_POST["options_per_feed_calcreferer"]) ? checkbox_to_sql_bool($_POST["options_per_feed_calcreferer"]) === 1 : false;

		if ($enable) {
			$options["proxy_host"] = $proxy_host;
			$options["proxy_port"] = (int)$proxy_port;
			$options["user_agent"] = $user_agent;
			$options["cookies"] = $cookies;
			$options["ssl_verify"] = (bool)$ssl_verify;
			$options["calc_referer"] = (bool)$calc_referer;
			$options_feeds[$feed_id] = $options;
		} else {
			if ($has) {
				unset($options_feeds[$feed_id]);
			}
		}

		$this->host->set($this, "options_feeds", $options_feeds);
	}

	public function api_version()
	{
		return 2;
	}
}
