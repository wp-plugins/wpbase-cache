<?php
class WPBase_Cache_Admin {

    public function __construct(){
        add_action('admin_menu', array($this, 'add_wpbase_cache_page'));
        add_action('admin_init', array($this, 'wpbase_cache_page_init'));
        add_action('update_option_wpbase_cache_options', array($this, 'update_options'), 10, 2);

        add_action('admin_footer', array($this, 'add_javascript'));
        add_action('wp_ajax_wpbase_cache_flush_all', array($this, 'ajax_flush_cache'));
    }

    public function add_wpbase_cache_page() {
        add_options_page('WPBase Cache', 'WPBase', 'manage_options', 'wpbasecache', array($this, 'create_wpbase_cache_page'));
    }

    public function create_wpbase_cache_page() {
        if( ! current_user_can('manage_options') ) {
            wp_die( __('You do not have sufficient permissions to access this page.') );
        }
        ?>
        <div class="wrap">
            <?php screen_icon(); ?>
            <h2>WPBase Cache</h2>
            <form method="post" action="options.php">
                <?php
                    settings_fields('wpbase_cache_options');
                    do_settings_sections('wpbasecache');
                ?>
                <?php submit_button(); ?>
                <a class="button" id="wpbase_cache_flush_all">Empty All Caches</a>
            </form>
        </div>
        <?php
    }

    public function wpbase_cache_page_init() {
        register_setting('wpbase_cache_options', 'wpbase_cache_options');

        add_settings_section(
            'wpbase_cache_section',
            'WPBase Cache Settings',
            array($this, 'wpbase_cache_section_desc'),
            'wpbasecache'
        );

        add_settings_field(
            'wpbase_cache_options_db_cache',
            'Enable DB Cache',
            array($this, 'db_cache_input'),
            'wpbasecache',
            'wpbase_cache_section'
        );

        add_settings_field(
            'wpbase_cache_options_varnish_cache',
            'Enable Varnish Cache',
            array($this, 'varnish_cache_input'),
            'wpbasecache',
            'wpbase_cache_section'
        );

        add_settings_field(
            'wpbase_cache_options_view_meta',
            'Enable View Meta',
            array($this, 'view_meta_input'),
            'wpbasecache',
            'wpbase_cache_section'
        );

        add_settings_field(
            'wpbase_cache_options_action_key',
            'Action and key pairs',
            array($this, 'action_key_input'),
            'wpbasecache',
            'wpbase_cache_section'
        );

    }

    public function wpbase_cache_section_desc() {
        //echo 'These settings are part of wpoven manager plugin.';
    }

    public function db_cache_input() {
        $options = get_option('wpbase_cache_options');

        $checked = checked(1, $options['db_cache'], FALSE);
        echo "<input id='wpbase_cache_db_cache' name='wpbase_cache_options[db_cache]' type='checkbox' value='1' $checked />";
    }

    public function varnish_cache_input() {
        $options = get_option('wpbase_cache_options');

        $checked = checked(1, $options['varnish_cache'], FALSE);
        if(!(defined('WPBASE_CACHE_SANDBOX') && WPBASE_CACHE_SANDBOX)) {
            echo "<input id='wpbase_cache_varnish_cache' name='wpbase_cache_options[varnish_cache]' type='checkbox' value='1' $checked />";
        } else {
            echo "<input id='wpbase_cache_varnish_cache' disabled='disabled' name='wpbase_cache_options[varnish_cache]' type='checkbox' value='1' $checked />";
        }
    }

    public function view_meta_input() {
        $options = get_option('wpbase_cache_options');

        $view_meta = $options['view_meta'];
        echo "<input id='wpbase_cache_view_meta' name='wpbase_cache_options[view_meta]' type='text' value='$view_meta' />";
        echo "<p class='description'>Mostly view counts are handeld by themes and you have to know which postmeta key is used to store view count of each post.<br />Fill in value of that postmeta key here.</p>";
    }

    public function action_key_input() {
        $options = get_option('wpbase_cache_options');

        $action_key = $options['action_key'];
        echo "<textarea id='wpbase_cache_action_key' name='wpbase_cache_options[action_key]' rows='3' cols='20' >$action_key</textarea>";
        echo "<p class='description'>Fill in the comma seperated values of action name and post id key for flushing post with the given id on that action.<br />For using multiple action,key pair write them one on each line for example - <br />postratings,pid<br />myaction,mypostid<br />First one is the setting value for famous wp-postrating plugin and second one is a dummy entry</p>";
    }

    public function add_javascript() {
        $nonce = wp_create_nonce('wpbase_cache_flush_all');
        ?>
        <script type="text/javascript" >
        jQuery(document).ready(function($) {

            $('#wpbase_cache_flush_all').click(function(){
                var element = $(this);
                var data = {
                    action: 'wpbase_cache_flush_all',
                    _ajax_nonce: '<?php echo $nonce; ?>'
                };

                $.post(ajaxurl, data, function(response) {
                    if(response == 1) {
                        message = 'Sucessfully flushed all caches';
                    } else if(response == -1) {
                        message = 'Unauthorised request';
                    } else {
                        message = response;
                    }
                    element.replaceWith(message);
                });
            });

            $('#wpbase_cache_varnish_cache').change(function(){
                if($(this).is(':checked'))
                    $('#wpbase_cache_view_meta').parent().parent().show();
                else
                    $('#wpbase_cache_view_meta').parent().parent().hide();
            });

            if($('#wpbase_cache_varnish_cache').is(':checked'))
                $('#wpbase_cache_view_meta').parent().parent().show();
            else
                $('#wpbase_cache_view_meta').parent().parent().hide();
        });
        </script>
        <?php
    }

    public function ajax_flush_cache() {
        check_ajax_referer('wpbase_cache_flush_all');

        if(!current_user_can('manage_options')) {
            wp_die( __('You do not have sufficient permissions to perform this action.') );
        }

        // flush db cache
        global $wpbase_cache;
        $wpbase_cache->flush_all_cache();

        echo 1;
        die;
    }

    public function update_options($oldvalue, $newvalue) {
        // disable/enable db_cache if needed
        if($oldvalue['db_cache'] !== $newvalue['db_cache']){
            global $wpbase_cache;
            if($newvalue['db_cache'] == '1'){
                $wpbase_cache->activate_db_cache();
            } else {
                $wpbase_cache->deactivate_db_cache();
            }
        }
    }

}

$wpbase_cache_admin = new WPBase_Cache_Admin();