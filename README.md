# WooCommerce Sage ERP Connector

Easily sync customers & orders from WooCommerce to Sage ERP 100 (or MAS 90/200) via the eBusiness Web Services API.

## Overview

This plugin pushes customers and orders from WooCommerce into Sage ERP 100 via the eBusiness Web Services API. After initial setup & configuration, you can select orders in bulk to export to Sage. Customers will be created if they do not exist and a sales order will be created for each order. The Sales Order number will be returned and used as the order number with WooCommerce. There are filters available to customize the customer and order data sent to Sage.

## Requirements

* WordPress 4.0 (tested up to 4.2.2)
* Sage ERP 100 or MAS 90/200 (including SQL)
* Sage eBusiness Web Services API module (ERP 100 2014 includes this, if you are using an older version you must purchase it from Sage)
* (optional) [Sage eBusiness Web Services Extended API](https://github.com/skyverge/sage-ebusiness-webservices-extended) if you're using a SQL version of Sage ERP and would like automatic postal code creation (see FAQ)

## Installation

* Download the [ZIP](https://github.com/skyverge/woocommerce-sage-erp-connector/archive/master.zip) and upload to your `/wp-content/plugins` directory
* Activate the plugin through the 'Plugins' menu in WordPress

## Setup

* Configure the Sage eBusiness Web Services API and expose a public endpoint. This is beyond the scope of this readme, but you can follow the excellent walk-through provided by Sage [here](http://infosource.sagesoftwareonline.com/sw_attach/sso/mas90/445WebServices.pdf). You'll need to also create a specific Sage user to use with the API as part of this configuration.

* In WooCommerce, browse to WooCommerce > Integrations > Sage ERP Connector and enter your API endpoint, along with the username/password for the user you created.

* Enter the company code of the company you want to create customers/orders in. You'll also need to enter a division number and Salesperson number that customers & orders will be created under. Price level is required.

* Place a test order and click on the Sage icon in the Orders list to test the import. You'll likely see an error message or two that will need to be resolved first.

## FAQ

* __Q: Is it possible to sync product stock levels from Sage?__
No, the Sage API does not provide any methods for retrieving item stock.

* __Q: How do I customize the data that's used for creating customers and sales orders?__
Use the [`wc_sage_erp_connector_customer`](https://github.com/skyverge/woocommerce-sage-erp-connector/blob/master/classes/class-wc-sage-erp-connector-exporter.php#L351-351), [`wc_sage_erp_connector_sales_order_line_item`](https://github.com/skyverge/woocommerce-sage-erp-connector/blob/master/classes/class-wc-sage-erp-connector-exporter.php#L290-290), and [`wc_sage_erp_connector_sales_order`](https://github.com/skyverge/woocommerce-sage-erp-connector/blob/master/classes/class-wc-sage-erp-connector-exporter.php#L297-297) filters. These are best used in a custom plugin -- checkout the [sample plugin](https://gist.github.com/maxrice/6a59f496cc8a2dfcff44) for more examples.

* __Q: How can I learn more about the Sage eBusiness Web Services API?__
You're in luck! Check out the out-of-print [API guide](http://cl.ly/2B3Z3n32320u)!

## Support

Support is only provided via GitHub, please add an issue if you're experiencing an issue or bug.

## License

Copyright (c) 2012-2015, SkyVerge, Inc.
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.

3. Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

## Contributing

Fork this repository and send a pull request :)

Please adhere to the [WordPress Coding Standards](http://codex.wordpress.org/WordPress_Coding_Standards) in your contributions.
