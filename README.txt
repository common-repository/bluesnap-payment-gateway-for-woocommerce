=== BlueSnap Payment Gateway for WooCommerce ===
Contributors: bluesnap, harmz
Donate link: https://saucal.com
Tags: WooCommerce, BlueSnap, payment, gateway, Apple Pay, ACH, 3D Secure 
Requires at least: 5.2.4
Tested up to: 6.6
Requires WooCommerce: 3.7.0
Requires PHP: 7.4
Stable tag: 3.1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html


Accept cards, ACH, Apple Pay and Google Pay, along with support for WooCommerce Subscriptions and Pre-orders on a global payments gateway.

== Description ==

#Why use the BlueSnap Payment Gateway for WooCommerce
 * Accept all major debit and credit cards as well as Apple Pay, Google Pay and ACH
 * Simplify your PCI compliance using our built-in Hosted Payment Fields. Your shoppers never leave your site while you maintain easy PCI compliance.
 * Supports Strong Customer Authentication (SCA) with 3D Secure
 * Identify and prevent fraud with built-in fraud protection from Kount
 * Sell in 100 currencies 
 * Support for WooCommerce pre-orders
 * Support recurring payments via WooCommerce Subscriptions

#A secure and frictionless checkout flow 
The BlueSnap Payment Gateway for WooCommerce uses our Hosted Payment Fields to provide you with a seamless, PCI-compliant checkout experience that works within any browser or device.  Our Hosted Payment Fields silently collect your shopper’s sensitive payment data on BlueSnap servers without interrupting the checkout flow.

#Sell and settle in multiple currencies
A simple way to help your shoppers complete their purchase is to offer your products in the currency of your shoppers. The BlueSnap Payment Gateway allows your shoppers to checkout in 100 different currencies and, as an added benefit, using the BlueSnap Payment Gateway gets you access to our connections to 30 global banks. When your shopper completes their purchase, BlueSnap paves the most efficient path to payment success by routing the transaction to the most appropriate local bank for your shopper, minimizing decline rates and maximizing revenue gains. 

Once the sale is complete and you need to get paid, BlueSnap works with you by offering the option to get money into your account in one of our 17 like-for-like payout currencies. 
#Fraud Protection and 3D Secure
The BlueSnap Payment Gateway offers Kount Fraud protection right from the plugin to best optimize the checkout flow. We also provide the option to select advanced fraud options if you want to customize your level of fraud screening. 
In addition, as you sell to shoppers around the world, you will likely run into a location where you are required to support a 3D Secure checkout experience.  The BlueSnap Payment Gateway has built-in support for 3DS so you aren't out of compliance in the regions where this is mandatory.
#Full support for Subscriptions and Pre-Orders
The BlueSnap Payment Gateway provides support for **WooCommerce Subscriptions**, offering support for all of subscription features, including payment date changes, subscription date changes, and more. The gateway also fully supports **WooCommerce Pre-Orders**, so you can take customer’s payment information upfront and then automatically charge their payment method once the pre-order is released.
== Installation ==

For the most recent version of these instructions, refer to https://support.bluesnap.com/docs/woocommerce


#Requirements 
##Recommended Versions 
We recommend that you use the following versions when using the BlueSnap plugin for WooCommerce. The plugin may work when using older versions of PHP and MySQL as well; however, the following versions have been tested to ensure compatibility. 
* PHP: 7.4 or later 
* MySQL: 5.6 or later 
* WordPress: 5.2.4 or later 
* WooCommerce: 3.7 or later 
* WooCommerce Pre-Orders: 1.5.16 or later 
* WooCommerce Subscriptions: 3.0 or later 


##Software 
This guide assumes that you have: 
* A working WordPress platform 
WooCommerce is a WordPress plugin that is installed on top of the WordPress platform. If you do not yet have a working WordPress installation, you may want to contact your website hosting provider, as many of them supply a quick-install process for WordPress. 

* WooCommerce software installed and uploaded to your server. 
If you need the plugin, go to: https://wordpress.org/plugins/woocommerce/ or to http://www.woothemes.com/woocommerce/ to download the WooCommerce plugin. 

* If you want to use the Pre-Orders or Subscription functionalities, make sure that the respective plugins are also installed in your WordPress website: 
  * Pre-Orders:  http://www.woothemes.com/products/woocommerce-pre-orders/ 
  *  Subscriptions:  http://www.woothemes.com/products/woocommerce-subscriptions/ 


##PCI compliance 
A PCI compliance of SAQ-A is required. 


#Setup Steps 
##Step 1: Configure your BlueSnap account settings 
Before you install the BlueSnap extension, complete these steps in your BlueSnap account: 
1. Set up your BlueSnap API Credentials(https://developers.bluesnap.com/v8976-Basics/docs/api-credentials). Make note of your API username and password; you need them in later steps. 

2. Define the authorized IP address for your server. 

3. Configure your payout settings (https://support.bluesnap.com/docs/payout-method). 


##Step 2: Install the plugin 
Install the BlueSnap Payment Gateway plugin, as follows: 
1. In WordPress, click **Plugins > Add New** in the left menu. 

2. Search for `BlueSnap` in the search box in the top-right side. 

3. Click the **BlueSnap Payment Gateway for WooCommerce** plugin and install it. 

4. Click **Plugins > Installed Plugins** in the left menu. 

5. In the installed plugin screen, **activate** the following plugins, in this order:
   * WooCommerce 
   * WooCommerce Subscriptions (optional) 
   * WooCommerce Pre-Orders (optional) 
   * BlueSnap Payment Gateway for WooCommerce 

**Important**
If these are not activated in the specified order, the installation will not complete properly. 


##Step 3: Set the Default Currency 
Configure the default currency settings for WooCommerce by completing the following steps: 
1. Go to **WooCommerce > Settings > General**. 

2. Scroll down to **Currency Options** and set the values as necessary.

3. Click Save Changes. 


##Step 4: Configure the plugin 
Configure the BlueSnap plugin using the following steps: 
1. Click the **Settings** link below the BlueSnap plugin. The BlueSnap page opens. 

2. Configure the following settings. 

>**Note**: You can find your BlueSnap information (API credentials, Merchant ID, and more) for the following settings in your BlueSnap Merchant Console in **Settings > API Settings**. 

  * **Enable/Disable** &mdash; Select Enable BlueSnap. This means that BlueSnap appears as a payment option during checkout. 
  * **Test mode** &mdash; Select Enable Test Mode to use your BlueSnap Sandbox account, select the Enable Test Mode option. Leave the option cleared to use your BlueSnap Production account. 
  * **IPN configuration** &mdash; Copy the URL from this section and use it for the IPN Setup section below. 
  * **Title** &mdash; By default, this is Credit/Debit Cards. This label is presented to the shopper when they choose a payment option during checkout. 
  * **Description** &mdash; By default, this is Pay using your Credit/Debit Card. This describes the payment method during checkout. 
  * **API Username and API Password** &mdash; Enter your API Username and Password for your BlueSnap account. Use your sandbox credentials if you chose Enable Test Mode above. Use your production credentials if you did not chose Enable Test Mode above. 
  * **Merchant ID** &mdash; Enter your Merchant ID number from your BlueSnap merchant account. <br /> **Note**: Use the Merchant ID from you sandbox or production environment, as applicable. They are different. 
  * **Soft Descriptor** &mdash; Enter a string, no more than 20 characters in length. This descriptor appears on the shopper's billing statement to help them identify the purchase. You should use the same soft descriptor set in your BlueSnap Console. 
  * **Capture** &mdash; Whether or not to immediately capture the charge. When unchecked, the charge issues an authorization and will need to be captured later. This setting has no effect on subscriptions. Charges on orders related to subscriptions are always captured. 
  * **3D Secure** &mdash; If you want to offer 3-D Secure, contact BlueSnap Merchant Support and ask for 3-D Secure to be enabled for your account. After that is done, you can select this option to activate 3-D Secure. For more information on 3-D Secure, refer to our 3-D Secure Guide (https://support.bluesnap.com/docs/3d-secure).  
  * **Saved Cards** &mdash; Select this if you want to give logged-in shoppers the option to store their credit card details for future purchases. They can manage their information from their My Account area. 
  * **BlueSnap currency converter** &mdash; BlueSnap works with many currencies (see a complete list at https://support.bluesnap.com/docs/currencies). The BlueSnap plugin for WooCommerce includes a built-in currency converter that you must configure in order to enable successful purchasing via BlueSnap. <br />Select this option to use the converter. 
  * **Select the currencies to display in your shop** &mdash; Select all the currencies your WooCommerce store supports. 
  * **Google Pay Wallet** &mdash; If you want to offer Google Pay as a payment method for your shoppers, contact BlueSnap [Merchant Support](https://bluesnap.zendesk.com/hc/en-us/requests/new?ticket_form_id=360000127087) and ask for Google Pay to be enabled for your account. After that is done, you can select this option to allow shoppers to pay with Google Pay. 
  * **Google Merchant ID** &mdash; Enter your Google Business Merchant ID. Request for Google Pay production access [here]. Google Pay won't be available in production mode until you enter the merchant ID. 
  * **Apple Pay Wallet** &mdash; If you want to offer Apple Pay as a payment method for your shoppers, contact BlueSnap [Merchant Support](https://bluesnap.zendesk.com/hc/en-us/requests/new?ticket_form_id=360000127087) and ask for Apple Pay to be enabled for your account. After that is done, you can select this option to allow shoppers to pay with Apple Pay. 
  * **Logging** &mdash; Select the Log debug messages option to have communications between WooCommerce and BlueSnap recorded in the process log files. We recommend using this option during the development of your site or if you are experiencing any problems. <br />To access process logs for the BlueSnap plugin, go to **WooCommerce > Status** and click the **Logs** tab. 

3. Click Save Changes. 
4. If you would like to accept ACH payments, enable this feature by going to **WooCommerce > Settings > Payments** and setting the **BlueSnap ACH** toggle to **enable.**
5. Click Save Changes.
**Important** 
If you plan to offer ACH as a payment method for subscriptions, please note:
You will need to contact Merchant Support to have this feature enabled.
ACH is not supported for subscriptions with daily billing, as banks take several days to authorize these charges.


##Step 5: Secure checkout 
Ensure that you are using secure checkout by completing the following steps. 
1. Go to **WordPress > Settings > General**. 

2. In the following URL fields, make sure that the URL begins with `https://`: 
  * **WordPress Address (URL)**
  * **Site Address (URL)** 


##Step 6. IPN Setup 
Instant Payment Notifications (IPNs) are webhooks that trigger an HTTP POST message to your WooCommerce account when an important event occurs. Follow the steps below to set up IPNs.

1. Log in to your BlueSnap account and go to **Settings > IPN Settings**.

2. Select the **Receive Instant Payment Notifications** check box.

3. Update the **IPN URL(s)** field. The format of the URL should follow this pattern:
  `https://www.yourdomain.com/?wc-api=bluesnap`

4. To enable specific IPNs, click **Select IPNs**. In the section that opens, toggle the button next to the IPN to select it. We recommend enabling the following IPN types:
    - AUTH_ONLY
    - CANCEL_ON_RENEWAL
    - CANCELLATION
    - CANCELLATION_REFUND
    - CHARGE
    - CHARGEBACK
    - CHARGEBACK_STATUS_CHANGED
    - CONTRACT_CHANGE
    - DECLINE
    - FAILED_PAYOUT_TRANSFER
    - RECURRING
    - REFUND
    - SUBSCRIPTION_REMINDER

5. If you plan to offer ACH as a payment method for subscriptions, make sure to select the **Send Subscription Charge Failure IPN**.

6. Click **Submit**.

For more information on IPNs, refer to our [IPN documentation](https://support.bluesnap.com/docs/about-ipns).


##Step 7: Crontab Setup 
We recommend that you add a line to your crontab. The crontab is an application that runs in the server operating the WordPress application, and is in charge of periodic actions. It ensures that subscriptions continue to charge on time even if your WooCommerce store has no traffic, stores automatic renewals, and handles pre-orders. 
The crontab file is available to you in most UNIX/Linux based machines, and often can be found in `/var/spool/cron`. If you are not sure where your crontab file is, reach out to your IT team or hosting provider for more details. 
You should add the following line to your crontab file: 
`*/15 * * * * {wget path} -q -O – {Web domain of your WooCommerce Store}/ wp-cron.php?doing_wp_cron` 
For example: `*/15 * * * * /usr/bin/wget -q -O - http://shoppingcarts.bluesnap.com/wordpress/wp-cron.php?doing_wp_cron `
If you have multiple WooCommerce Stores running on the same server, you should add this line for each one of them. 
**Note**:  `*/15` makes the crontab run every 15 minutes. You can use this to change the cron frequency. 
For additional help, contact BlueSnap Merchant Support (https://bluesnap.zendesk.com/hc/en-us/requests/new?ticket_form_id=360000127087). 
**Styling your payment form**
BlueSnap supports the ability to customize the card elements (such as text color or font size) of the payment form. To do this, edit the BlueSnap plugin's JavaScript by creating a bluesnapStyleOverrides object that contains your styling. See Supported selectors and Supported CSS properties for details https://developers.bluesnap.com/v8976-Tools/docs/hosted-payment-fields#section-supported-css-properties. Use the code below as a starting point.

`const bluesnapStyleOverrides = {
  '.invalid': {
    //style all invalid elements
    color: 'red'
  },
  '.valid': {
    //style all valid elements
    color: 'green'
  },
  ':focus': {
    //style all elements on the focus event
    color: 'orange'
  },
  '#ccn': {
    //style only the card number element
    color: 'blue'
  },
  '#cvv': {
    //style only the CVV element
    'font-size': '30px'
  }
};`

**Customizing error messages**
BlueSnap allows you to customize the messages of any errors that occur, giving you full control over the error descriptions that display in the UI or in error logs. To use this feature, you will need to add custom code to your WooCommerce account. We recommend using a plugin such as Code Snippets to accomplish this.
Use the code sample below as a starting point. You can change error descriptions and add/remove errors from the code below.

`add_filter(
  'wc_gateway_bluesnap_api_errors',
  function ( $errors ) {
      $errors = array(
          '10000|INVALID_API_VERSION'        => __( 'API version is not correct...', 'woocommerce-bluesnap-gateway' ),
          '10001|VALIDATION_GENERAL_FAILURE' => __( 'This is a val.gen. failure error.', 'woocommerce-bluesnap-gateway' ),
          '14002'                            => __( 'This is the new error message for 14002', 'woocommerce-bluesnap-gateway' ),
          '14002|SYSTEM_TECHNICAL_ERROR'     => __( 'This is the new error message for 14002|SYSTEM_TECHNICAL_ERROR', 'woocommerce-bluesnap-gateway' ),
          '14016'                            => __( 'This is the new error message for 14016', 'woocommerce-bluesnap-gateway' ),
      );
      return $errors;
  }
);
`

**Managing orders**
If card charges are not automatically captured, they will result in authorizations that need to be captured later. For example, if you sell physical goods, you will need to capture the authorization when the items are ready to be shipped out. Orders can be conveniently managed from your WooCommerce account by following these steps:
1. Go to **WooCommerce > Orders** and click the order that you want to manage. The order's status will be **On hold.**

**Note:** ACH charges will also have a status of **On hold.** You don't need to capture these charges.
2. In the **Status** dropdown, select one of the following:
If you want to capture the authorization, select **Processing** or **Completed.**
If you want to cancel the authorization, select **Cancelled.**

== Screenshots ==
1. Top half of the screen for configuring the BlueSnap plugin in WooCommerce.
2. Bottom half of the screen for configuring the BlueSnap plugin in WooCommerce.

== Changelog ==
= 3.1.0 =
* Added HPOS compatibility.
* Added plugin's review prompt.
* Updated tested up to WordPress v6.4 and WooCommerce 8.5.2.

= 3.0.1 = 
* Updated tested up to WordPress v6.3 and WooCommerce 8.0.1.

= 3.0.0 = 
* Added support for Google Pay
* Fix consistency with the submit button's attribute "data-bluesnap"
* Improve the support for Apple Pay
* General improvements and bug fixes

= 2.6.3 = 
* Updated tested up to WordPress v6.2 and WooCommerce 7.5.

= 2.6.2 = 
* Updated required WordPress to v5.2.4 and tested up to WordPress v6.1 and WooCommerce 7.1.

= 2.6.1 = 
* Updated tested up to WordPress v6.0 and WooCommerce 6.6.

= 2.6.0 = 
* Updated Hosted Payment Fields from v3 to v4.
* Added support for 3DS and returning shopper.
* Fix error with jQuery deprecated function.
* Fix Issue with Tax calculation on a currency different than the default one.
* Fix handling of cases where the HPF token is expired.
* Fix compatibility with WooCommerce Price Based on Country plugin.
* Fix Payment process, sometimes gets stuck after pressing ApplePay button.
* Fix CC logos appear blurry on payment form.

= 2.5.3 = 
* Fix - Issue related with expiring BlueSnap HPF tokens
* Fix - Issue related with not showing a notification that the token had expired.
* Fix - Issue related to Pay for Order flow for registered users.
* Updated - IPN section of the Readme file.

= 2.5.2 = 
* Fix - Issue related to Pay for Order flow for registered users.
* Fix - JavaScript error causing 3rd party code to not work in cart.
* Fix - Wrong notification when cancelling a PayPal subscription.
* Fix - Adjust order status when a split Auth/Capture order is not captured.
* Updated - Visa logo updated to new version.

= 2.5.1 =
* Fix - Issue reported by merchants using non-default decimal separators.
* Fix - Issues related to labeling of Apple Pay paid orders.
* Fix - Updated deprecated WooCommerce Subscription functions
* Fix – Improvements to avoid specific PHP notices

= 2.5.0 =
* Updated to add ACH, split auth and capture, hosted payment field styling, custom error messages.
