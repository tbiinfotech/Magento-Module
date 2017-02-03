This is the Custom payment gateway module for Magento store using 4pay Api. It is the Merchant’s integration procedure that provides

- Full Api Gateway.
- Payment gateway supports--Verifies by VISA / Master Card Secure Code.


Feature Set
---------------

- Full functionality for sales and refunds through the website front end and back end.
- Used API Credentials for the merchant account.
- Manage and modify the merchant account configuration from the Admin backend. 
- Provides the Functionality same as the stock Authorize.net module that comes with Magento.
- Credit card payment on the website, and configuration.
- Setup payments from the backend. 
- Manage Refund from the admin backend.

Installation
--------------

To setup the extension in Magento follow instructions written below:

1. Copy content of "Mygateway" inside app/code/local/ folder.
2. Copy content of "Mygateway_Pay" inside app/etc/modules/ folder.
3. Copy content of "pay" inside app/design/frontend/[your_template_package]/[your_template_theme]/layout/ folder.
4. Copy content of "pay_adminhtml" inside app/design/adminhtml/default/default/template/ folder.
5. Copy content of "pay_frontend" inside app/design/frontend/[your_template_package]/[your_template_theme]/template/ folder.
6. Refresh Magento's cache from System -> Cache Management.