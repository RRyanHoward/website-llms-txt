jQuery(document).on('click', '.llms-admin-notice .notice-dismiss', function () {
    jQuery.post(llmsNoticeAjax.ajax_url, {
        action: 'dismiss_llms_admin_notice',
        nonce: llmsNoticeAjax.nonce
    });
});