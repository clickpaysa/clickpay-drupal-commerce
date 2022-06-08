Commerce ClickPay PT2

Description
-----------
This module provides integration with the ClickPay payment gateway.

CONTENTS OF THIS FILE
---------------------
* Introduction
* Requirements
* Installation
* Configuration

INTRODUCTION
------------
This project integrates ClickPay online payments into
the Drupal Commerce payment and checkout systems.

REQUIREMENTS
------------
This module requires no external dependencies.
But make sure to enable the 'Telephone' core module.

INSTALLATION
------------


* Install Via Github Repo Link:
  - https://github.com/paytabscom/paytabs-drupal-commerce/tree/clickpay
  - extract the downloaded file and Place it in the /modules/contrib directory with folder name clickpay_drupal_commerce

- Go to 'Extend' as an administrator, and Enable the module

CONFIGURATION
-------------
* Create new ClickPay payment gateway
  Administration > Commerce > Configuration > Payment gateways > Add payment gateway
  Provide the following settings:
  - Merchant Profile id.
  - Server key.
  - Merchant region.
  - Pay Page Mode
  - Order Complete status
  -  Paypage Mode
  - iframe
  
* Make sure to install telephone module and enable it
  - go to config / people / profile types/ manage fields / add new field
  - add phone number field with name phone.
  
* Make sure that your website currency is as same as your currency in ClickPay profile

SCREENSHOTS
-------------


![Configuration Page](/../clickpay/src/images/configuration%20page.jpg?raw=true "Configuration Page")

![Checkout Page](/../clickpay/src/images/checkout%20page.jpg?raw=true "Checkout Page")

![Payment Page](/../clickpay/src/images/payment%20page.jpg?raw=true "Payment Page")

![return Page](/../clickpay/src/images/return%20page.jpg?raw=true "return Page")

![order status Page](/../clickpay/src/images/order%20status%20dashboard.jpg?raw=true "order status Page")

![payment result Page](/../clickpay/src/images/payment%20result%20page%20dashboard.jpg?raw=true "payment result Page")
