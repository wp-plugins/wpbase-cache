<?php
/*
Plugin Name: WPBase-Cache
Plugin URI: https://github.com/baseapp/wpbase-cache
Description: A wordpress plugin for using all caches on varnish, nging, php-fpm stack with php-apc. This plugin includes db-cache-reloaded-fix for dbcache.
Version: 1.0.0
Author: Tarun Bansal
Author URI: http://blog.wpoven.com
License: GPL2

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*/

defined('ABSPATH') or die();
define('WPBASE_CACHE_DIR', WP_PLUGIN_DIR.'/wpbase-cache');
define('WPBASE_CACHE_INC_DIR', WP_PLUGIN_DIR.'/wpbase-cache/inc');

class WPBase_Cache {

    public $wp_db_cache_reloaded = null;
    private $action_keys = array();
    private $view_meta = "";

    public function __construct() {

        $this->load_plugins();

        register_activation_hook(WPBASE_CACHE_DIR.'/wpbase-cache.php', array($this, 'activate'));
        register_deactivation_hook(WPBASE_CACHE_DIR.'/wpbase-cache.php', array($this, 'deactivate'));

        // add flush hooks
        $this->add_flush_actions();

        if(is_admin()){
            require_once(WPBASE_CACHE_DIR.'/wpbase-cache-admin.php');
        }
    }

    public function activate() {
        $options = array(
            'db_cache' => '1',
            'varnish_cache' => '1',
            //'reject_url' => '',
            //'reject_cookie' => '',
        );

        update_option('wpbase_cache_options', $options);

        // activate and enable db-cache-reloaded-fix
        $this->activate_db_cache();
    }

    public function deactivate() {
        $this->deactivate_db_cache();
        delete_option('wpbase_cache_options');
    }

    public function activate_db_cache() {
        require_once(WPBASE_CACHE_INC_DIR.'/db-cache-reloaded-fix/db-cache-reloaded.php');
        $this->wp_db_cache_reloaded = new DBCacheReloaded();
        $options = array(
            'enabled' => true,
            'filter' => '_posts|_postmeta',
            'loadstat' => '',
            'wrapper' => false,
            'save' => 1
        );
        $this->wp_db_cache_reloaded->options_page($options);
    }

    public function deactivate_db_cache() {
        if($this->wp_db_cache_reloaded != null){
            $this->wp_db_cache_reloaded->dbcr_uninstall();
        }
    }

    public function load_plugins() {
        $options = get_option('wpbase_cache_options');

        require_once(WPBASE_CACHE_INC_DIR.'/nginx-compatibility/nginx-compatibility.php');

        if(isset($options['db_cache']) && $options['db_cache'] == '1'){
            require_once(WPBASE_CACHE_INC_DIR.'/db-cache-reloaded-fix/db-cache-reloaded.php');
            $this->wp_db_cache_reloaded = new DBCacheReloaded();
        }

        if(!isset($options['varnish_cache']) || $options['varnish_cache'] != '1'){
            add_action('init', array($this, 'set_cookie'));
        } else {
            if(isset($options['view_meta']) && !empty($options['view_meta']) && !is_admin()){
                $this->view_meta = $options['view_meta'];
                add_action('wp_footer', array($this, 'view_count_script_footer'));
            }

            $action_keys = explode("\n", $options['action_key']);
            foreach ($action_keys as $action_key) {
                $action_key = explode(",", $action_key);
                if(count($action_key) == 2) {
                    $action = $action_key[0];
                    $key = $action_key[1];
                    $this->action_keys[$action] = $key;
                    add_action('wp_ajax_' . $action, array($this, 'flush_action_key'),9);
                    add_action('wp_ajax_nopriv_' . $action, array($this, 'flush_action_key'),9);
                }
            }
        }
    }

    public function set_cookie() {
        if (!isset($_COOKIE['wpoven-no-cache'])) {
            setcookie('wpoven-no-cache', 1, time() + 120);
        }
    }

    public function add_flush_actions(){
        add_action('switch_theme', array($this, 'flush_all_cache'));
        add_action('publish_post', array($this, 'flush_post'));
        add_action('edit_post', array($this, 'flush_post'));
        add_action('save_post', array($this, 'flush_post'));
        add_action('wp_trash_post', array($this, 'flush_post'));
        add_action('delete_post', array($this, 'flush_post'));
        add_action('trackback_post', array($this, 'flush_comment'));
        add_action('pingback_post', array($this, 'flush_comment'));
        add_action('comment_post', array($this, 'flush_comment'));
        add_action('edit_comment', array($this, 'flush_comment'));
        add_action('wp_set_comment_status', array($this, 'flush_comment'));
        add_action('delete_comment', array($this, 'flush_comment'));
    }

    public function flush_action_key() {
        $action = $_REQUEST['action'];
        $key = $this->action_keys[$action];

        $post_id = intval($_REQUEST[$key]);
        $this->flush_post($post_id);
    }

    public function flush_post($post_id) {
        $url = get_permalink($post_id);

        $this->flush_varnish_cache($url);
    }

    public function flush_comment($comment_id) {
        $comment = get_comment($comment_id);
        $post_id = $comment->comment_post_ID;

        $this->flush_post($post_id);
    }

    public function flush_all_cache() {
        $url = get_site_url();
        $url = $url . '/';
        $this->flush_varnish_cache($url);

        if($this->wp_db_cache_reloaded != null){
            $this->wp_db_cache_reloaded->dbcr_clear();
        }
    }

    public function flush_varnish_cache($url) {
        if(!(defined('WPBASE_CACHE_SANDBOX') && WPBASE_CACHE_SANDBOX)) {
            wp_remote_request($url, array('method' => 'PURGE'));
        }
    }

    function view_count_script_footer() {
        $single = is_single();
        if($single == 1) {
            $post_id = get_the_ID();
            $site_url = get_option("siteurl");
    ?>
            <script type="text/javascript">
                var data = {
                    post_id : "<?php echo $post_id; ?>",
                    view_meta : "<?php echo $this->view_meta; ?>"
                };
                var url = "<?php echo $site_url; ?>/wp-content/plugins/wpbase-cache/views.php";
                $.post(url, data, function(data){});
            </script>
    <?php } }
}

$wpbase_cache = new WPBase_Cache();