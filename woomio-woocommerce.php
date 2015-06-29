<?php
/*
	Plugin Name: Woomio Woocommerce
	Plugin URI: https://woomio.com
	Description: Woomio Integration into WooCommerce made easy
	Version: 1.1
	Author: Woomio.com
	Author URI: https://woomio.com
*/


if (!defined('ABSPATH') || !function_exists('is_admin'))
{
	header('Status: 403 Forbidden');
	header('HTTP/1.1 403 Forbidden');
	exit();
}


if (!class_exists("Woomio_Woocommerce"))
{
	class Woomio_Woocommerce
    {
        const WOOMIO_WOOCOMMERCE_VERSION = '1.1';
        const WOOMIO_WOOCOMMERCE_RELEASE = '1430431200';
        const WOOMIO_WOOCOMMERCE_URL = 'https://www.woomio.com';
        const WOOMIO_WOOCOMMERCE_LINK = 'Woomio.com';

        const WOOMIO_WOOCOMMERCE_ACCESS = 'ping.woomio.com';
        const WOOMIO_WOOCOMMERCE_API = 'https://www.woomio.com/';        
        var $WOOMIO_WOOCOMMERCE_RID = 0;

        var $settings, $options_page;

        public static function w_error_handler($errno, $errstr, $errfile, $errline, $errcontext) {
            error_log('An error occurred communication with woomio servers, and was bypassed. ' . $errno . ': ' . $errstr);
            return true;
        }

        function __construct()
        {
            if (is_admin()) {
                if (!class_exists("Woomio_Woocommerce_Settings")) {
                    require_once plugin_dir_path(__FILE__) . 'woomio-woocommerce-settings.php';
                }

                $this->settings = new Woomio_Woocommerce_Settings();
            } else {

            }
            add_action('init', array($this, 'init'));

            register_activation_hook(__FILE__, array($this, 'activate'));
            register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        }


        function activate($networkwide)
        {
            $this->network_propagate(array($this, '_activate'), $networkwide);
        }

        function deactivate($networkwide)
        {
            $this->network_propagate(array($this, '_deactivate'), $networkwide);
        }

        function network_propagate($pfunction, $networkwide)
        {
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


        function _activate()
        {
            $this->installDB();
            $Response = $this->registerSite();
            update_option('woomio_rid', $Response);
        }


        function _deactivate()
        {
        }


        function init()
        {
            load_plugin_textdomain('woomio_woocommerce', plugin_dir_path(__FILE__) . 'lang', basename(dirname(__FILE__)) . '/lang');

            if (isset($_GET['woomio']) && in_array($_GET['woomio'], array('orders', 'customers', 'products'))) {
                $this->getData($_GET['woomio']);
            }

            if (get_option('woomio_allow_js')=='on') {
                add_action('wp_print_scripts', array($this, 'add_woomio_script'));
            }

            add_action('woocommerce_thankyou', array($this, 'registerOrder'));
        }

        function add_woomio_script()
        {
            if(!isset($WOOMIO_WOOCOMMERCE_RID)) {
                $WOOMIO_WOOCOMMERCE_RID = 0;
            }
            if ($WOOMIO_WOOCOMMERCE_RID == 0) {
                $WOOMIO_WOOCOMMERCE_RID = get_option('woomio_rid');
            }
                    
            ?>
            <script type="text/javascript" src="https://woomio.com/assets/js/analytics/r.js" id="wa" data-r="<?=$WOOMIO_WOOCOMMERCE_RID?>"></script>
            <?php
        }


        function installDB()
        {
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


        function registerSite()
        {
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


        function registerOrder($order_id)
        {
            $order = new WC_Order($order_id);
            $wacsid = $_COOKIE['wacsid'];

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
            $ot = urlencode($order->get_total());
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


        function getData($type, $debug = 0)
        {
            $AllowedIP  = gethostbyname(self::WOOMIO_WOOCOMMERCE_ACCESS);
            $woodir     = plugin_dir_path(__FILE__) . '../woocommerce/includes/api/';
            $response   = array(
				'platform' => 'woocommerce',
                'status' => 'error',
                'status_message' => 'IP NOT allowed'
            );

            // Test for WooCommerce
            if (!file_exists($woodir . 'class-wc-api-server.php'))
            {
                $response['status'] = 'error';
                $response['status_message'] = 'WooCommerce is NOT installed';
            }
            else if ($_SERVER['REMOTE_ADDR'] == $AllowedIP || $debug)
            {
                require_once $woodir . 'interface-wc-api-handler.php';
                require_once $woodir . 'class-wc-api-exception.php';
                require_once $woodir . 'class-wc-api-server.php';
                require_once $woodir . 'class-wc-api-resource.php';
                require_once $woodir . 'class-wc-api-authentication.php';
                require_once $woodir . 'class-wc-api-json-handler.php';
                require_once $woodir . 'class-wc-api-orders.php';
                require_once $woodir . 'class-wc-api-customers.php';
                require_once $woodir . 'class-wc-api-products.php';

                global $wp, $wpdb;

                // Settings
                $woomioTable = $wpdb->prefix . 'woomio_woocommerce';
                $server = new WC_API_Server($wp->query_vars['wc-api-route']);

                // Authenticate WC api request as first known admin
                add_filter('woocommerce_api_check_authentication', function () {
                    $admins = get_users('role=administrator&number=1'); // find an admin, any admin
                    return $admins[0];
                });
                $server->check_authentication();

                // Set default response
                $response['status'] = 'success';
                $response['status_message'] = 'IP allowed';

                // Params
                $_hrs = (int)(isset($_GET['hrs']) && $_GET['hrs'] ? $_GET['hrs'] : 1);
                $_wacsid = (int)(isset($_GET['wacsid']) && $_GET['wacsid'] ? $_GET['wacsid'] : 0);
                $_id = (int)(isset($_GET['id']) && $_GET['id'] ? $_GET['id'] : 0);
                $_debug = (bool)(isset($_GET['debug']) && $_GET['debug'] ? $_GET['debug'] : $debug);

                switch ($type)
				{
                    case 'orders':
                        $response['orders'] = array();

                        $api = new WC_API_Orders($server);

                        if ($_id) {
                            $data = $api->get_order($_id);

                            if ($data instanceof WP_Error) {
                                // No need to handle errors as we will just send an empty list
                            } else if ($data['order']) {
                                $oid = $data['order']['id'];
                                $map = $wpdb->get_row('SELECT * FROM ' . $woomioTable . ' WHERE orderid = ' . $oid);

                                $response['orders'][$oid] = $data['order'];
                                $response['orders'][$oid]['wacsid'] = ($map && $map->wacsid ? $map->wacsid : 0);
                            }
                        } else {
                            $response['orders'] = array();

                            $filter = array(
                                'created_at_min' => date('Y-m-d H:i:s', strtotime('now -' . $_hrs . ' hours')),
                                'created_at_max' => date('Y-m-d H:i:s', strtotime('now'))
                            );
                            
                            // Get all orders:
                            // WooCommerce only allows paged order lookups, hence the do/while approach                            
                            $i = 1;
                            $data = null;
                            $orders = array();
                            do {
                                $data = $api->get_orders(null, $filter, null, $i);
                                $orders = array_merge($orders, $data['orders']);
                                $i++;
                            } while ($data['orders'] != null);

                            // Display all orders
                            if ($orders instanceof WP_Error) {
                                // No need to handle errors as we will just send an empty list
                            } else {
                                foreach ($orders as $order) {
                                    $oid = $order['id'];
                                    $map = $wpdb->get_row('SELECT * FROM ' . $woomioTable . ' WHERE orderid = ' . $oid);

                                    if ($_wacsid) {
                                        if ($map && $map->wacsid) {
                                            $response['orders'][$oid] = $order;
                                            $response['orders'][$oid]['wacsid'] = $map->wacsid;
                                        }
                                    } else {
                                        $response['orders'][$oid] = $order;
                                        $response['orders'][$oid]['wacsid'] = ($map && $map->wacsid ? $map->wacsid : 0);
                                    }
                                }
                            }
                        }
                        break;


                    case 'customers':
                        $response['orders'] = array();

                        $api = new WC_API_Customers($server);

                        if ($_id) {
                            $data = $api->get_customer($_id);

                            if ($data instanceof WP_Error) {
                                // No need to handle errors as we will just send an empty list
                            } else if ($data['customer']) {
                                $response['customers'][] = $data['customer'];
                            }
                        } else {
                            // Get all customers:
                            // WooCommerce only allows paged customer lookups, hence the do/while approach                            
                            $i = 1;
                            $data = null;
                            $customers = array();
                            do {
                                $data = $api->get_customers(null, array(), $i);
                                $customers = array_merge($customers, $data['customers']);
                                $i++;
                            } while ($data['customers'] != null);

                            // Display all orders
                            if ($customers instanceof WP_Error) {
                                // No need to handle errors as we will just send an empty list
                            } else if ($customers) {
                                foreach ($customers as $customer) {
                                    $response['customers'][] = $customer;
                                }
                            }
                        }
                        break;


                    case 'products':
                        $response['products'] = array();

                        $api = new WC_API_Products($server);

                        if ($_id) {
                            $data = $api->get_product($_id);

                            if ($data instanceof WP_Error) {
                                // No need to handle errors as we will just send an empty list
                            } else if ($data['product']) {
                                $response['products'][] = $data['product'];
                            }
                        } else {
                            $filter = array();

                            if ($_hrs != 'all') {
                                $filter['created_at_min'] = date('Y-m-d H:i:s', strtotime('now -' . $_hrs . ' hours'));
                                $filter['created_at_max'] = date('Y-m-d H:i:s', strtotime('now'));
                            }

                            // Get all products:
                            // WooCommerce only allows paged product lookups, hence the do/while approach                            
                            $i = 1;
                            $data = null;
                            $products = array();
                            do {
                                $data = $api->get_products(null, null, $filter, $i);
                                $products = array_merge($products, $data['products']);
                                $i++;
                            } while ($data['products'] != null);

                            // Display all products
                            if ($products instanceof WP_Error) {
                                // No need to handle errors as we will just send an empty list
                            } else if ($products) {
                                foreach ($products as $product) {
                                    $response['products'][] = $product;
                                }
                            }
                        }

                        break;
                }
            }

            if (!headers_sent()) {
                header("Content-type: text/plain; Charset=UTF-8");
            }

            if ($_debug) {
                setCookie('wacsid', 999);
                var_dump($response);
                die();
            } else {
                echo json_encode($response);
                die();
            }
        }
    }
}


global $woomiowoocommerce;
if (!$woomiowoocommerce) {
    $woomiowoocommerce = new Woomio_Woocommerce();
}