# options_per_feed

Tiny-Tiny-RSS plugin to setup feed fetch options:

- proxy settings per feed
- user-agent per feed
- ssl certificate verification

This plugin uses php-curl extension. Install it and restart your apache/php-fpm instance.

To install it - put files into `options_per_feed` directory inside `plugins` of TTRSS installation.

## Used config options

- `FEED_FETCH_TIMEOUT` - (int) seconds, curl option to limit time to fetch RSS content, defaults to 30
- `FILE_FETCH_CONNECT_TIMEOUT` - (int) seconds, curl option to limit time to connect to RSS host, defaults to 5

### New version (scheme after 140)

Use construction like: `putenv('TTRSS_FILE_FETCH_CONNECT_TIMEOUT=5');` inside `config.php`.

### Old version (scheme before 140)

Use construction like: `define('FILE_FETCH_CONNECT_TIMEOUT', 5);` inside `config.php`.
