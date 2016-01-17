<?php if(!defined('ABSPATH')) { die; }

// Plugin Name: WooCommerce Pay on Pickup
// Plugin URI:  https://github.com/reitermarkus/woocommerce-pay-on-pickup
// Description: Provides a “Pay on Pickup” option for WooCommerce.
// Version:     1.0.0
// Author:      Markus Reiter
// Author URI:  http://reitermark.us
// License:     GPL
// License URI: http://www.gnu.org/licenses/gpl-3.0.html
// Text Domain: woocommerce-pay-on-pickup
// Domain Path: /languages


add_action('plugins_loaded', 'woocommerce_pay_on_pickup_init', 0);
function woocommerce_pay_on_pickup_init() {

  if(!class_exists('WC_Payment_Gateway')) {
    return;
  }

  // Gateway Class
  class WC_Payment_Gateway_Pay_On_Pickup extends WC_Payment_Gateway {

    public function __construct() {
  		// load translation
  		load_plugin_textdomain('woocommerce-pay-on-pickup', false, dirname(plugin_basename( __FILE__ )) . '/languages');

      $this->id                 = 'pay_on_pickup';
      $this->icon               = apply_filters('woocommerce_pay_on_pickup_icon', '');
      $this->method_title       = __('Pay on Pickup', 'woocommerce-pay-on-pickup');
      $this->method_description = __('Give your customers the option to pay in cash (or by other means) upon pickup in your store.', 'woocommerce-pay-on-pickup');
      $this->has_fields         = false;
      $this->supports           = array('products', 'pre-orders'); // process batch pre-order payments

      // Load Settings
      $this->init_form_fields();
      $this->init_settings();

      // Get Settings
      $this->title              = $this->get_option('title');
      $this->description        = $this->get_option('description');
      $this->instructions       = $this->get_option('instructions', $this->description);
      $this->enable_for_methods = $this->get_option('enable_for_methods', array());

      add_action('woocommerce_update_options_payment_gateways_'.$this->id, array($this, 'process_admin_options'));
      add_action('woocommerce_thankyou_pay_on_pickup', array($this, 'thankyou_page'));

      // Customer Emails
      add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
    }


    // Initialise Gateway Settings Form Fields
    public function init_form_fields() {
      global $woocommerce;

      $shipping_methods = array();

      if(is_admin()) {
        foreach(WC()->shipping->load_shipping_methods() as $method) {
          $shipping_methods[$method->id] = $method->get_title();
        }
      }

      $this->form_fields = array(
        'enabled' => array(
          'title'   => __('Enable/Disable', 'woocommerce'),
          'label'   => __('Enable Pay on Pickup', 'woocommerce-pay-on-pickup'),
          'type'  => 'checkbox',
          'description' => '',
          'default'   => 'no'
        ),
        'title' => array(
          'title'   => __('Title', 'woocommerce'),
          'type'  => 'text',
          'description' => __('Payment method description that the customer will see on your checkout.', 'woocommerce'),
          'default'   => __('Pay on Pickup', 'woocommerce-pay-on-pickup'),
          'desc_tip'  => true,
        ),
        'description' => array(
          'title'   => __('Description', 'woocommerce'),
          'type'  => 'textarea',
          'description' => __('Payment method description that the customer will see on your website.', 'woocommerce'),
          'default'   => __('Pay when you pick up your order.', 'woocommerce-pay-on-pickup' ),
          'desc_tip'  => true,
        ),
        'instructions' => array(
          'title'   => __('Instructions', 'woocommerce'),
          'type'  => 'textarea',
          'description' => __('Instructions that will be added to the thank you page.', 'woocommerce'),
          'default'   => __('Pay when you pick up your order.', 'woocommerce-pay-on-pickup'),
          'desc_tip'  => true,
        ),
        'enable_for_methods' => array(
          'title'   => __('Enable for shipping methods', 'woocommerce'),
          'type'  => 'multiselect',
          'class'   => 'chosen_select',
          'default'   => '',
          'description'   => __('If you want Pay on Pickup to only be available for certain shipping methods, set it up here. Leave blank to enable for all methods.', 'woocommerce-pay-on-pickup'),
          'options'   => $shipping_methods,
          'desc_tip'  => true,
          'custom_attributes' => array(
            'data-placeholder' => __('Select shipping methods', 'woocommerce')
          )
        )
      );
    }

    // Check If The Gateway Is Available For Use
    public function is_available() {
      $order = null;

      if(!WC()->cart->needs_shipping()) {
        return false;
      }

      if(is_page(wc_get_page_id('checkout')) && 0 < get_query_var('order-pay')) {
        $order_id = absint(get_query_var('order-pay'));
        $order  = new WC_Order($order_id);

        // Test if order needs shipping.
        $needs_shipping = false;

        if(0 < sizeof($order->get_items())) {
          foreach ($order->get_items() as $item) {
            $_product = $order->get_product_from_item($item);

            if($_product->needs_shipping()) {
              $needs_shipping = true;
              break;
            }
          }
        }

        $needs_shipping = apply_filters('woocommerce_cart_needs_shipping', $needs_shipping);

        if($needs_shipping) {
          return false;
        }
      }

      if(!empty($this->enable_for_methods)) {

        // Only apply if all packages are being shipped via local pickup
        $chosen_shipping_methods_session = WC()->session->get('chosen_shipping_methods');

        if(isset($chosen_shipping_methods_session)) {
          $chosen_shipping_methods = array_unique($chosen_shipping_methods_session);
        } else {
          $chosen_shipping_methods = array();
        }

        $check_method = false;

        if(is_object($order)) {
          if($order->shipping_method) {
            $check_method = $order->shipping_method;
          }

        } elseif(empty($chosen_shipping_methods) || sizeof($chosen_shipping_methods) > 1) {
          $check_method = false;
        } elseif(sizeof($chosen_shipping_methods) == 1) {
          $check_method = $chosen_shipping_methods[0];
        }

        if(!$check_method) {
          return false;
        }

        $found = false;

        foreach ($this->enable_for_methods as $method_id) {
          if(strpos($check_method, $method_id) === 0) {
            $found = true;
            break;
          }
        }

        if(!$found) {
          return false;
        }
      }

      return parent::is_available();
    }

    // Process the payment and return the result
    public function process_payment($order_id) {

      $order = new WC_Order($order_id);

      // Mark as processing (payment won't be taken until delivery)
      $order->update_status('processing', __('Payment to be made upon pick up from store.', 'woocommerce-pay-on-pickup'));

      // Reduce stock levels
      $order->reduce_order_stock();

      // Remove cart
      WC()->cart->empty_cart();

      // Return thankyou redirect
      return array(
        'result'   => 'success',
        'redirect' => $this->get_return_url($order)
      );
    }

    // Output for the “order received” page.
    public function thankyou_page() {
      if($this->instructions) {
        echo wpautop(wptexturize($this->instructions));
      }
    }

    // Add content to the WC emails.
    public function email_instructions($order, $sent_to_admin, $plain_text = false) {
      if($sent_to_admin || $order->payment_method !== 'pay_on_pickup')
        return;

      if($this->instructions)
      echo wpautop(wptexturize($this->instructions));
    }
  }

  function woocommerce_add_pay_on_pickup_gateway($methods) {
    $methods[] = 'WC_Payment_Gateway_Pay_On_Pickup';
    return $methods;
  }
  add_filter('woocommerce_payment_gateways', 'woocommerce_add_pay_on_pickup_gateway');
}
