<?php
/*
	Plugin Name: Woomio Woocommerce
	Plugin URI: https://woomio.com
	Description: Woomio Integration into WooCommerce made easy
	Version: 1.1.6
	Author: Woomio.com
	Author URI: https://woomio.com
*/

if (!defined('ABSPATH') || !function_exists('is_admin')) {
	header('Status: 403 Forbidden');
	header('HTTP/1.1 403 Forbidden');
	exit();
}

if (!class_exists("Woomio_Woocommerce")) {
	class Woomio_Woocommerce {
        const WOOMIO_WOOCOMMERCE_VERSION = '1.1.6';
        const WOOMIO_WOOCOMMERCE_RELEASE = '1430431200';
        const WOOMIO_WOOCOMMERCE_URL = 'https://www.woomio.com';
        const WOOMIO_WOOCOMMERCE_LINK = 'Woomio.com';

        const WOOMIO_WOOCOMMERCE_ACCESS = 'ping.woomio.com';
        const WOOMIO_WOOCOMMERCE_API = 'https://www.woomio.com/';        
        
        public $WOOMIO_WOOCOMMERCE_RID = 0;

        public $settings;
        public $options_page;

        public static function w_error_handler($errno, $errstr, $errfile, $errline, $errcontext) {
            error_log('An error occurred communication with woomio servers, and was bypassed. ' . $errno . ': ' . $errstr);
            return true;
        }

        function __construct() {
            if (is_admin()) {
                if (!class_exists("Woomio_Woocommerce_Settings")) {
                    require_once plugin_dir_path(__FILE__) . 'woomio-woocommerce-settings.php';
                }
                $this->settings = new Woomio_Woocommerce_Settings();
            }

            add_action('init', array($this, 'init'));

            register_activation_hook(__FILE__, array($this, 'activate'));
            register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        }


        function activate($networkwide) {
            $this->network_propagate(array($this, '_activate'), $networkwide);
        }

        function deactivate($networkwide) {
            $this->network_propagate(array($this, '_deactivate'), $networkwide);
        }

        function network_propagate($pfunction, $networkwide) {
            global $wpdb;

            if (function_exists('is_multisite') && is_multisite()) {
                if ($networkwide) {
                    $old_blog = $wpdb->blogid;
                    $blogids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
                    foreach ($blogids as $blog_id) {
                        switch_to_blog($blog_id);
                        call_user_func($pfunction, $networkwide);
                    }
                    switch_to_blog($old_blog);
                    return;
                }
            }
            call_user_func($pfunction, $networkwide);
        }


        function _activate() {
            $this->installDB();
            $Response = $this->registerSite();
            update_option('woomio_rid', $Response);
        }


        function _deactivate() {
        }


        function init() {
            load_plugin_textdomain('woomio_woocommerce', plugin_dir_path(__FILE__) . 'lang', basename(dirname(__FILE__)) . '/lang');

            if (isset($_GET['woomio']) && in_array($_GET['woomio'], array('orders', 'customers', 'products'))) {
                $this->getData($_GET['woomio']);
            }

            if (get_option('woomio_allow_js')=='on') {
                add_action('wp_print_scripts', array($this, 'add_woomio_script'));
            }

            add_action('woocommerce_thankyou', array($this, 'registerOrder'));
        }

        function add_woomio_script() {
            if(!isset($WOOMIO_WOOCOMMERCE_RID)) {
                $WOOMIO_WOOCOMMERCE_RID = 0;
            }
            if ($WOOMIO_WOOCOMMERCE_RID == 0) {
                $WOOMIO_WOOCOMMERCE_RID = get_option('woomio_rid');
            }
                    
?>
<script type="text/javascript" src="https://www.woomio.com/assets/js/analytics/r.js" id="wa" data-r="<?=$WOOMIO_WOOCOMMERCE_RID?>" data-v="<?=self::WOOMIO_WOOCOMMERCE_VERSION?>"></script>
<?php
        }


        function installDB() {
            global $wpdb;

            $table_name = $wpdb->prefix . 'woomio_woocommerce';

            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE IF NOT EXISTS $table_name (
				orderid int(11) NOT NULL,
				wacsid varchar(100),
				UNIQUE KEY orderid (orderid)
			) $charset_collate;";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }


        function registerSite() {
            $url = self::WOOMIO_WOOCOMMERCE_API . 'umbraco/api/Endpoints/RetailerSignup?name=%s&domain=%s&country=%s&email=%s&platform=3';
            $name = urlencode(get_bloginfo('name'));
            $domain = urlencode(get_bloginfo('url'));
            $lang = urlencode(substr(get_bloginfo('language'), 0, 2));
            $email = urlencode(get_bloginfo('admin_email'));
            $callBack = sprintf($url, $name, $domain, $lang, $email);

            //Ignore errors returned by the server
            $context = stream_context_create(array(
                'http' => array('ignore_errors' => true)
            ));

            set_error_handler(array('Woomio_Woocommerce', 'w_error_handler'));
            $Response = @file_get_contents($callBack, false, $context);
            restore_error_handler();

            return $Response;
        }


        function registerOrder($order_id) {
            $order = new WC_Order($order_id);
            $wacsid = null;
            if(isset($_COOKIE['wacsid'])) {
                $wacsid = $_COOKIE['wacsid'];
            }

            //We do not log purchases that are not affiliates
            if(!$wacsid) {
                return true;
            }

            global $wpdb;

            $r = $wpdb->insert(
                $wpdb->prefix . 'woomio_woocommerce',
                array(
                    'orderid' => (string) $order_id,
                    'wacsid' => (string) $wacsid
                ),
				array('%d','%d')
            );

            //The following should be optimized instead of building a URL and then splitting it up again.
            $sid = urlencode($wacsid);
            $oid = urlencode($order_id);
            $ot = urlencode($order->get_subtotal());
            $oc = urlencode($order->get_order_currency());
            $email = urlencode($order->billing_email);
            $url = urlencode($_SERVER['SERVER_NAME']);

            $purchase_url = "https://www.woomio.com/endpoints/purchase?sid=" . $sid . "&oid=" . $oid . "&ot=" . $ot . "&oc=" . $oc . "&email=" . $email . "&url=" . $url;

            //Ignore errors returned by the server
            $context = stream_context_create(array(
                'http' => array(
                    'ignore_errors' => true,
                    'timeout' => 10 //seconds
                )
            ));

            set_error_handler(array('Woomio_Woocommerce', 'w_error_handler'));
            @file_get_contents($purchase_url, false, $context);
            restore_error_handler();

            //TODO: Figure out how to make fsockopen stable, since it is a faster connection.
            /*$parts = parse_url($purchase_url);

            $host = $parts['host'];

            $path = $parts['path'];
            if($parts['query'] != "") {
                $path .= "?" . $parts['query'];
            }

            set_error_handler(array('Woomio_Woocommerce', 'w_error_handler'));
            $file_pointer = fsockopen("ssl://" . $host, 443, $errno, $errstr, 10);
            restore_error_handler();

            if(!$file_pointer) {
                error_log("Error opening socket to woomio server: " . $errstr .  "(" . $errno . ").", 0);
            }
            else {
                $out = "GET " . $path . " HTTP/1.1\r\n";
                $out .= "Host: " . $host . "\r\n";
                $out .= "Connection: Close\r\n\r\n";
                $fwrite = fwrite($file_pointer, $out);
                stream_set_timeout($file_pointer, 2);

                if($fwrite === false) {
                    error_log("Error sending request to woomio server: Error writing to socket.", 0);
                }
                fclose($file_pointer);
            }*/

            return true;
        }

        /** 
         * @param url: {base_url}/?woomio=orders&hrs=all&affiliated=false
         */
        function getData($type) {
            $AllowedIP = gethostbyname(self::WOOMIO_WOOCOMMERCE_ACCESS);
            
            $response = new stdClass();
            $response->platform = 'woocommerce';

            if(in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option( 'active_plugins' ))) === false) {
                return;
            }

            if ($_SERVER['REMOTE_ADDR'] !== $AllowedIP) {
                return;
            }

            $hrs = ((isset($_GET['hrs']) && is_numeric($_GET['hrs'])) ? $_GET['hrs'] : null);
            $affiliated = (isset($_GET['affiliated']) && $_GET['affiliated'] === 'true');
            $id = (isset($_GET['id']) ? $_GET['id'] : 0);

            switch ($type) {
                case 'orders':
                    $response->orders = $this->get_orders($affiliated, $id, $hrs);
                    break;
                case 'customers':
                    $response->customers = $this->get_customers($id);
                    break;
                case 'products':
                    $response->products = $this->get_products($id);
                    break;
            }

            echo json_encode($response);
            die;
        }

        function get_orders($affiliated, $id, $hrs) {
            global $wpdb;

            //If no orders return empty order array
            $orders = array();
            
            $table_order_items = $wpdb->prefix . "woocommerce_order_items";
            $table_order_itemmeta = $wpdb->prefix . "woocommerce_order_itemmeta";
            $table_posts = $wpdb->prefix . "posts";
            $table_woomio = $wpdb->prefix . "woomio_woocommerce";
            $now = new DateTime(null, new DateTimeZone('UTC'));

            $query = "SELECT DISTINCT " . $table_order_items . ".order_id, post_date_gmt, order_item_name, order_item_type, " . $table_order_items . ".order_item_id, meta_key, meta_value";
            if($affiliated === true) {
                $query .= ", wacsid";
            }
            $query .= " FROM " . $table_order_items . ", " . $table_order_itemmeta . ", " . $table_posts;
            if($affiliated === true) {
                $query .= ", " . $table_woomio;
            }
            $query .= " WHERE " . $table_order_itemmeta . ".order_item_id = " . $table_order_items . ".order_item_id AND " . $table_posts . ".ID = " . $table_order_items . ".order_id";
            if($affiliated === true) {
                $query .= " AND " . $table_order_items . ".order_id IN (SELECT orderid AS order_id FROM " . $table_woomio . ")";
            }
            if($id) {
                $query .= " AND " . $table_order_items . ".order_id=%d";
            }
            if($hrs !== null) {
                $now->sub(new DateInterval('PT' . $hrs . 'H'));
                $query .= " AND post_date_gmt >= '%s'";
            }
            $query .= " ORDER BY order_id;";

            if ($id && $hrs !== null) {
                $query = $wpdb->prepare($query, $id, $now->format('Y-m-d H:i:s'));
            }
            else if($id) {
                $query = $wpdb->prepare($query, $id);
            }
            else if($hrs !== null) {
                $query = $wpdb->prepare($query, $now->format('Y-m-d H:i:s'));
            }
            
            $order_set = $wpdb->get_results($query);

            //Woocommerce separates shipping and items giving them same order id
            //Scan rows and separate into items and shipping
            $current_order_id = null;
            $order_count = 0;
            foreach ($order_set as $order_row) {
                if($order_row->order_id !== $current_order_id) {
                    //New order
                    $orders[$order_count] = new stdClass();
                    $orders[$order_count]->id = $order_row->order_id;
                    $orders[$order_count]->time = $order_row->post_date_gmt;
                    $orders[$order_count]->items = array();
                    $orders[$order_count]->shippings = array();
                    $current_order_id = $order_row->order_id;
                    $order_count++;

                    $current_order_item_id = null;
                    $order_items_count = 0;
                    $order_shippings_count = 0;
                }
                if($order_row->order_item_id !== $current_order_item_id) {
                    //New item or shipping within same order
                    if($order_row->order_item_type === 'line_item') {
                        $orders[$order_count - 1]->items[$order_items_count] = new stdClass();
                        $orders[$order_count - 1]->items[$order_items_count]->name = $order_row->order_item_name;
                        $order_items_count++;
                    }
                    if($order_row->order_item_type === 'shipping') {
                        $orders[$order_count - 1]->shippings[$order_shippings_count] = new stdClass();
                        $orders[$order_count - 1]->shippings[$order_shippings_count]->shipping_type = $order_row->order_item_name;
                        $order_shippings_count++;
                    }
                    $current_order_item_id = $order_row->order_item_id;
                }
                
                if($order_row->order_item_type === 'line_item') {
                    $this->process_order_item_row($orders[$order_count - 1]->items[$order_items_count - 1], $order_row);
                }
                if($order_row->order_item_type === 'shipping') {
                    $this->process_order_shipping_row($orders[$order_count - 1]->shippings[$order_shippings_count - 1], $order_row);
                }
                
            }
            unset($order);

            $table_posts = $wpdb->prefix . "posts";
            $table_postmeta = $wpdb->prefix . "postmeta";
            $now = new DateTime(null, new DateTimeZone('UTC'));
            $query = "SELECT ID, post_date_gmt, meta_key, meta_value";
            $query .= " FROM " . $table_posts . ", " . $table_postmeta;
            $query .= " WHERE post_id = ID AND post_type='shop_order'";
            if($id) {
                $query .= " AND " . $table_posts . ".ID=%d";
            }
            if($hrs !== null) {
                $now->sub(new DateInterval('PT' . $hrs . 'H'));
                $query .= " AND post_date_gmt >= '%s'";
            }
            $query .= " ORDER BY ID;";

            if ($id && $hrs !== null) {
                $query = $wpdb->prepare($query, $id, $now->format('Y-m-d H:i:s'));
            }
            else if($id) {
                $query = $wpdb->prepare($query, $id);
            }
            else if($hrs !== null) {
                $query = $wpdb->prepare($query, $now->format('Y-m-d H:i:s'));
            }
            
            $order_set = $wpdb->get_results($query);

            $current_order_id = null;
            $order_count = -1;
            foreach ($order_set as $order_row) {
                if($order_row->ID !== $current_order_id) {
                    $order_count++;
                    $current_order_id = $order_row->ID;
                }
                $this->process_order_post_meta_row($orders[$order_count], $order_row);
            }
            unset($order_row);

            return $orders;
        }

        function process_order_item_row(&$order_item, $row) {
            switch($row->meta_key) {
                case '_qty':
                    $order_item->quantity = $row->meta_value;
                    break;
                case '_tax_class':
                    $order_item->tax_class = $row->meta_value;
                    break;
                case '_product_id':
                    $order_item->product_id = $row->meta_value;
                    break;
                case '_variation_id':
                    $order_item->variation_id = $row->meta_value;
                    break;
                case '_line_subtotal':
                    $order_item->subtotal = $row->meta_value;
                    break;
                case '_line_total':
                    $order_item->total = $row->meta_value;
                    break;
                case '_line_subtotal_tax':
                    $order_item->subtotal = $row->meta_value;
                    break;
                case '_line_tax':
                    $order_item->tax = $row->meta_value;
                    break;
            }
        }

        function process_order_shipping_row(&$order_item, $row) {
            switch($row->meta_key) {
                case 'method_id':
                    $order_item->shipping_method = $row->meta_value;
                    break;
            }
        }

        function process_order_post_meta_row(&$order_item, $row) {
            switch($row->meta_key) {
                case '_order_currency':
                    $order_item->currency = $row->meta_value;
                    break;
                case '_customer_ip_address':
                    $order_item->customer_order_ip = $row->meta_value;
                    break;
                case '_customer_user_agent':
                    $order_item->customer_user_agent = $row->meta_value;
                    break;
                case '_customer_user':
                    if($row->meta_value == 0) {
                        $order_item->guest_order = true;
                        $order_item->customer_id = 0;
                    }
                    else {
                        $order_item->guest_order = false;
                        $order_item->customer_id = $row->meta_value;
                    }
                    break;
                case '_order_shipping':
                    $order_item->shippings[0]->shipping_cost = $row->meta_value;
                    break;
                case '_billing_country':
                    $order_item->billing_country = $row->meta_value;
                    break;
                case '_billing_first_name':
                    $order_item->billing_first_name = $row->meta_value;
                    break;
                case '_billing_last_name':
                    $order_item->billing_last_name = $row->meta_value;
                    break;
                case '_billing_company':
                    $order_item->billing_company = $row->meta_value;
                    break;
                case 'billing_address_1':
                case 'billing_address_2':
                    if(isset($order_item->billing_address) === false) {
                        $order_item->billing_address = $row->meta_value;
                    }
                    else {
                        $order_item->billing_address .= " " . $row->meta_value;
                    }
                    break;
                case '_billing_city':
                    $order_item->billing_city = $row->meta_value;
                    break;
                case '_billing_state':
                    $order_item->billing_state = $row->meta_value;
                    break;
                case '_billing_postcode':
                    $order_item->billing_postcode = $row->meta_value;
                    break;
                case '_billing_email':
                    $order_item->customer_email = $row->meta_value;
                    break;
                case '_billing_phone':
                    $order_item->customer_phone = $row->meta_value;
                    break;
                case '_shipping_country':
                    $order_item->shippings[0]->shipping_country = $row->meta_value;
                    break;
                case '_shipping_first_name':
                    $order_item->shippings[0]->shipping_first_name = $row->meta_value;
                    break;
                case '_shipping_last_name':
                    $order_item->shippings[0]->shipping_last_name = $row->meta_value;
                    break;
                case '_shipping_company':
                    $order_item->shippings[0]->shipping_company = $row->meta_value;
                    break;
                case 'shipping_address_1':
                case 'shipping_address_2':
                    if(isset($order_item->shippings[0]->shipping_address) === false) {
                        $order_item->shippings[0]->shipping_address = $row->meta_value;
                    }
                    else {
                        $order_item->shippings[0]->shipping_address .= " " . $row->meta_value;
                    }
                    break;
                case '_shipping_city':
                    $order_item->shippings[0]->shipping_city = $row->meta_value;
                    break;
                case '_shipping_state':
                    $order_item->shippings[0]->shipping_state = $row->meta_value;
                    break;
                case '_shipping_postcode':
                    $order_item->shippings[0]->shipping_postcode = $row->meta_value;
                    break;
                case '_payment_method':
                    $order_item->payment_method = $row->meta_value;
                    break;
                case '_cart_discount':
                    $order_item->cart_discount = $row->meta_value;
                    break;
                case '_cart_discount_tax':
                    $order_item->cart_discount_tax = $row->meta_value;
                    break;
                case '_order_tax':
                    $order_item->order_tax = $row->meta_value;
                    break;
                case 'order_shipping_tax':
                    $order_item->shippings[0]->shipping_tax = $row->meta_value;
                    break;
                case 'order_total':
                    $order_item->total = $row->value;
                    break;
            }
        }

        function get_customers($id) {
            global $wpdb;

            $customers = array();

            $table_users = $wpdb->prefix . 'users';
            $table_usermeta = $wpdb->prefix . 'usermeta';

            $query = "SELECT " . $table_users . ".ID, user_email, user_url, user_registered, meta_key, meta_value";
            $query .= " FROM " . $table_users . ", " . $table_usermeta;
            $query .= " WHERE " . $table_users . ".ID = " . $table_usermeta . ".user_id";
            if($id) {
                $query .= " AND " . $table_users . ".ID = %d";
            }
            $query .= " ORDER BY ID;";
            if($id) {
                $query = $wpdb->prepare($query, $id);
            }

            $user_set = $wpdb->get_results($query);

            $current_user_id = null;
            $customer_count = 0;
            foreach($user_set as $user_row) {
                if($user_row->ID !== $current_user_id) {
                    $customers[$customer_count] = new stdClass();
                    $customers[$customer_count]->id = $user_row->ID;
                    $customers[$customer_count]->email = $user_row->user_email;
                    $customers[$customer_count]->url = $user_row->user_url;
                    $customers[$customer_count]->registration_time = $user_row->user_registered;
                    $customer_count++;
                    $current_user_id = $user_row->ID;
                }
                switch($user_row->meta_key) {
                    case 'nickname':
                        $customers[$customer_count - 1]->nickname = $user_row->meta_value;
                        break;
                    case 'first_name':
                        $customers[$customer_count - 1]->first_name = $user_row->meta_value;
                        break;
                    case 'last_name':
                        $customers[$customer_count - 1]->last_name = $user_row->meta_value;
                        break;
                    case 'billing_country':
                        $customers[$customer_count - 1]->billing_country = $user_row->meta_value;
                        break;
                    case 'billing_first_name':
                        $customers[$customer_count - 1]->billing_first_name = $user_row->meta_value;
                        break;
                    case 'billing_last_name':
                        $customers[$customer_count - 1]->billing_last_name = $user_row->meta_value;
                        break;
                    case 'billing_company':
                        $customers[$customer_count - 1]->billing_company = $user_row->meta_value;
                        break;
                    case 'billing_address_1':
                    case 'billing_address_2':
                        if(isset($customers[$customer_count - 1]->billing_address) === false) {
                            $customers[$customer_count - 1]->billing_address = $user_row->meta_value;
                        }
                        else {
                            $customers[$customer_count - 1]->billing_address .= " " . $user_row->meta_value;
                        }
                        break;
                    case 'billing_city':
                        $customers[$customer_count - 1]->billing_city = $user_row->meta_value;
                        break;
                    case 'billing_state':
                        $customers[$customer_count - 1]->billing_state = $user_row->meta_value;
                        break;
                    case 'billing_postcode':
                        $customers[$customer_count - 1]->billing_postcode = $user_row->meta_value;
                        break;
                    case 'billing_email':
                        $customers[$customer_count - 1]->billing_email = $user_row->meta_value;
                        break;
                    case 'billing_phone':
                        $customers[$customer_count - 1]->billing_phone = $user_row->meta_value;
                        break;
                    case 'shipping_country':
                        $customers[$customer_count - 1]->shipping_country = $user_row->meta_value;
                        break;
                    case 'shipping_first_name':
                        $customers[$customer_count - 1]->shipping_first_name = $user_row->meta_value;
                        break;
                    case 'shipping_last_name':
                        $customers[$customer_count - 1]->shipping_last_name = $user_row->meta_value;
                        break;
                    case 'shipping_company':
                        $customers[$customer_count - 1]->shipping_company = $user_row->meta_value;
                        break;
                    case 'shipping_address_1':
                    case 'shipping_address_2':
                        if(isset($customers[$customer_count - 1]->shipping_address) === false) {
                            $customers[$customer_count - 1]->shipping_address = $user_row->meta_value;
                        }
                        else {
                            $customers[$customer_count - 1]->shipping_address .= " " . $user_row->meta_value;
                        }
                        break;
                    case 'shipping_city':
                        $customers[$customer_count - 1]->shipping_city = $user_row->meta_value;
                        break;
                    case 'shipping_state':
                        $customers[$customer_count - 1]->shipping_state = $user_row->meta_value;
                        break;
                    case 'shipping_postcode':
                        $customers[$customer_count - 1]->shipping_postcode = $user_row->meta_value;
                        break;
                }
            }
            unset($user_row);

            return $customers;
        }

        function get_products($id) {
            global $wpdb;

            $products = array();

            $table_posts = $wpdb->prefix . 'posts';
            $table_postmeta = $wpdb->prefix . 'postmeta';
            $query = "SELECT ID, post_date_gmt, post_content, post_title, post_excerpt, post_name, meta_key, meta_value";
            $query .= " FROM " . $table_posts . ", " . $table_postmeta;
            $query .= " WHERE post_type='product' AND " . $table_postmeta . ".post_id = " . $table_posts . ".ID";
            if($id) {
                $query .= " AND " . $table_posts . ".ID = %d";
            }
            $query .= " ORDER BY ID;";
            if($id) {
                $query = $wpdb->prepare($query, $id);
            }

            $product_set = $wpdb->get_results($query);

            $current_product_id = null;
            $current_product_sale_start = null;
            $product_count = 0;
            foreach($product_set as $product_row) {
                if($product_row->ID !== $current_product_id) {
                    $products[$product_count] = new stdClass();
                    $products[$product_count]->id = $product_row->ID;
                    $products[$product_count]->creation_date = $product_row->post_date_gmt;
                    $products[$product_count]->description = $product_row->post_content;
                    $products[$product_count]->title = $product_row->post_title;
                    $products[$product_count]->short_description = $product_row->post_excerpt;
                    $products[$product_count]->permalink = get_site_url() . "/index.php/product/" . $product_row->post_name;
                    $products[$product_count]->currency = get_option('woocommerce_currency');
                    $product_count++;
                    $current_product_id = $product_row->ID;
                }
                switch($product_row->meta_key) {
                    case '_visibility':
                        $products[$product_count - 1]->visible = $product_row->meta_value;
                        break;
                    case '_stock_status':
                        $products[$product_count - 1]->stock_status = $product_row->meta_value;
                        break;
                    case 'total_sales':
                        $products[$product_count - 1]->stock_status = $product_row->meta_value;
                        break;
                    case '_downloadable':
                        $products[$product_count - 1]->downloadable = $product_row->meta_value;
                        break;
                    case '_virtual':
                        $products[$product_count - 1]->virtual = $product_row->meta_value;
                        break;
                    case '_price':
                        $products[$product_count - 1]->price = $product_row->meta_value;
                        break;
                    case '_regular_price':
                        $products[$product_count - 1]->regular_price = $product_row->meta_value;
                        break;
                    case '_sale_price':
                        $products[$product_count - 1]->sale_price = $product_row->meta_value;
                        break;
                    case '_featured':
                        $products[$product_count - 1]->featured = $product_row->meta_value;
                        break;
                    case '_weight':
                        $products[$product_count - 1]->weight = $product_row->meta_value;
                        break;
                    case '_length':
                        $products[$product_count - 1]->length = $product_row->meta_value;
                        break;
                    case '_width':
                        $products[$product_count - 1]->width = $product_row->meta_value;
                        break;
                    case '_height':
                        $products[$product_count - 1]->height = $product_row->meta_value;
                        break;
                    case '_sku':
                        $products[$product_count - 1]->sku = $product_row->meta_value;
                        break;
                    case '_sale_price_dates_from':
                        $current_product_sale_start = $product_row->meta_value;
                        break;
                    case '_sale_price_dates_to':
                        $start_time = strtotime($current_product_sale_start);
                        $end_time = strtotime($product_row->meta_value);
                        $now = strtotime('now');
                        $products[$product_count - 1]->on_sale = ($now >= $start_time && $now <= $end_time);
                        break;
                    case '_thumbnail_id':
                        $products[$product_count - 1]->images = $product_row->meta_value;
                        break;
                }
            }
            unset($product_row);

            foreach($products as $product) {
                //Add categories
                $query = "select name from wp_terms, wp_term_taxonomy, wp_term_relationships where object_id=%d AND wp_term_relationships.term_taxonomy_id=wp_term_taxonomy.term_taxonomy_id AND wp_term_taxonomy.term_id = wp_terms.term_id;";
                $query = $wpdb->prepare($query, $product->id);
                $category_set = $wpdb->get_results($query);
                $product->categories = array();
                foreach($category_set as $category) {
                    $product->categories[] = $category->name;
                }
                unset($category);

                //Add images
                //$image_id = $product->images;
                $product->images = array();
                //$query = "select meta_value as file_name from wp_postmeta where post_id=%d and meta_key='_thumbnail_id';";
                $query = "select wp_posts.guid from wp_postmeta, wp_posts where wp_postmeta.post_id=%d and wp_postmeta.meta_key='_thumbnail_id' and wp_posts.ID=wp_postmeta.meta_value and wp_posts.post_type='attachment';";
                $query = $wpdb->prepare($query, $product->id);
                $images_set = $wpdb->get_results($query);
                foreach ($images_set as $image) {
                    //$product->images[] = get_site_url() . "/wp-content/uploads/" . $file_name->file_name;
                    $product->images[] = $image->guid;
                }
                unset($image);
            }
            unset($product);

            return $products;
        }
    }
}

global $woomiowoocommerce;
if (!$woomiowoocommerce) {
    $woomiowoocommerce = new Woomio_Woocommerce();
}
