jQuery(function ($) {
    $(document).on('click', '.ai-log-row', function () {
        var id = $(this).data('id');
        var detail = $('.ai-detail-row[data-id="' + id + '"]');
        $('.ai-detail-row').not(detail).hide();
        detail.toggle();
    });
});
