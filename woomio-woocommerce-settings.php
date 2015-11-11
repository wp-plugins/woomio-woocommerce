<?php

if (!function_exists('is_admin')) {
	header('Status: 403 Forbidden');
	header('HTTP/1.1 403 Forbidden');
	exit();
}


if (!class_exists("Woomio_Woocommerce_Settings")) {
	class Woomio_Woocommerce_Settings {
		
        /**
         * Holds the values to be used in the fields callbacks
         */
        private $options;

        public function __construct() {
            add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
            add_action( 'admin_init', array( $this, 'page_init' ) );
        }

        /**
         * Add options page
         */
        public function add_plugin_page() {
            // This page will be under "Settings"
            add_options_page(
                'Settings Admin', 
                'Woomio WooCommerce', 
                'manage_options', 
                'woomio-setting-admin', 
                array( $this, 'create_admin_page')
            );
        }

        /**
         * Options page callback
         */
        public function create_admin_page() {
            // Set class property
            $this->options = get_option('woomio_allow_js');
?>
<div class="wrap">
    <h2>Woomio Settings</h2>
    <form method="post" action="options.php">
<?php
            // This prints out all hidden setting fields
            settings_fields('my_option_group');   
            do_settings_sections( 'woomio-setting-admin' );
            submit_button(); 
?>
    </form>
</div>
<?php
        }

        /**
         * Register and add settings
         */
        public function page_init() {        
            register_setting(
                'my_option_group', // Option group
                'woomio_allow_js' // Option name
                //array( $this, 'sanitize' ) // Sanitize
            );

            add_settings_section(
                'setting_section_id', // ID
                'Tracking Settings', // Title
                array( $this, 'print_section_info' ), // Callback
                'woomio-setting-admin' // Page
            );  

            add_settings_field(
                'woomio_checkbox_id', // ID
                'Allow tracking', // Title 
                array( $this, 'woomio_checkbox_callback' ), // Callback
                'woomio-setting-admin', // Page
                'setting_section_id' // Section           
            );     
        }

    
        /** 
         * Print the Section text
         */
        public function print_section_info() {
            print 'Woomio enables you to get detailed statistics on your customers\' buying behavior.<br/>Allow or disallow these statistics below.';
        }

        /** 
         * Get the settings option array and print one of its values
         */
        public function woomio_checkbox_callback() {
            printf('<input type="checkbox" id="woomio_checkbox_id" name="woomio_allow_js"  %s />', get_option("woomio_allow_js") == "on" ? 'checked' : '');
        }
    }
}