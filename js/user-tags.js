/**
 * User Tags JavaScript functionality
 */
jQuery(document).ready(function($) {
    // Initialize Select2 for user tags on profile page
    $('.user-tags-select').select2({
        placeholder: 'Select or search for tags',
        allowClear: true,
        ajax: {
            url: userTags.ajaxurl,
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    q: params.term,
                    page: params.page || 1,
                    action: 'search_user_tags',
                    nonce: userTags.nonce
                };
            },
            processResults: function(data, params) {
                params.page = params.page || 1;
                return {
                    results: data.results,
                    pagination: data.pagination
                };
            },
            cache: true
        },
        minimumInputLength: 0,
        tags: true, // Allow creating new tags
        createTag: function(params) {
            return {
                id: params.term,
                text: params.term + ' (Create new)',
                newTag: true
            };
        }
    });
    
    // Initialize Select2 for user tags filter on users list page
    $('.user-tags-filter').select2({
        minimumResultsForSearch: 5
    });
});
