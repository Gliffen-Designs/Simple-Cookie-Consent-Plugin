/**
 * Gliffen Admin Scripts
 */

jQuery(function($) {
    'use strict';

    // Tab switching
    $('.gliffen-tab-btn').on('click', function(e) {
        e.preventDefault();
        const tab = $(this).data('tab');

        $('.gliffen-tab-btn').removeClass('active');
        $('.gliffen-tab-content').removeClass('active');

        $(this).addClass('active');
        $('#' + tab).addClass('active');
    });

    // Color picker initialization is done in the template via wpColorPicker()
});
