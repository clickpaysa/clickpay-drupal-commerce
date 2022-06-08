(function ($, Drupal, drupalSettings) {
    'use strict';
    Drupal.behaviors.offsiteForm = {
        attach: function (context) {
            var ptLink = drupalSettings.clickpay_drupal_commerce;
            var return_link = drupalSettings.return_url;
            var iframe = document.createElement('iframe');
            iframe.width="100%";
            iframe.height="900px";
            iframe.id="ClickpayIframe";
            iframe.setAttribute("src", ptLink);
            document.getElementById("block-bartik-content").appendChild(iframe);

            $("#PaytabsIframe").load(function(){
                $(this).contents().on("click", function(){
                    window.parent.location = return_link;
                });
            });
        }

    };

}(jQuery, Drupal, drupalSettings));