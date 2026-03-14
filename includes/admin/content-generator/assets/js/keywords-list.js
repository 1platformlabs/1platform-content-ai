(function($) {
    'use strict';

    class KeywordsListHandler {
        constructor() {
            this.searchInput = $('#contai-keyword-search');
            this.searchButton = $('.contai-search-button');
            this.statusFilter = $('#contai-status-filter');
            this.clearFiltersButton = $('.contai-clear-filters');
            this.searchDebounceTimer = null;

            this.init();
        }

        init() {
            this.attachEventListeners();
            this.enableEnterKeySearch();
        }

        attachEventListeners() {
            this.searchButton.on('click', () => this.performSearch());
            this.statusFilter.on('change', () => this.performFilter());
            this.clearFiltersButton.on('click', () => this.clearAllFilters());

            this.searchInput.on('input', () => {
                clearTimeout(this.searchDebounceTimer);
                this.searchDebounceTimer = setTimeout(() => {
                    if (this.searchInput.val().length >= 3 || this.searchInput.val().length === 0) {
                        this.performSearch();
                    }
                }, 500);
            });
        }

        enableEnterKeySearch() {
            this.searchInput.on('keypress', (e) => {
                if (e.which === 13) {
                    e.preventDefault();
                    this.performSearch();
                }
            });
        }

        performSearch() {
            const searchTerm = this.searchInput.val().trim();
            const currentStatus = this.statusFilter.val();

            this.navigateWithFilters({
                s: searchTerm || null,
                status: currentStatus !== 'all' ? currentStatus : null,
                paged: 1
            });
        }

        performFilter() {
            const searchTerm = this.searchInput.val().trim();
            const selectedStatus = this.statusFilter.val();

            this.navigateWithFilters({
                s: searchTerm || null,
                status: selectedStatus !== 'all' ? selectedStatus : null,
                paged: 1
            });
        }

        clearAllFilters() {
            this.searchInput.val('');
            this.statusFilter.val('all');

            this.navigateWithFilters({
                s: null,
                status: null,
                paged: 1
            });
        }

        navigateWithFilters(filters) {
            const url = new URL(window.location.href);

            Object.keys(filters).forEach(key => {
                if (filters[key] !== null && filters[key] !== '') {
                    url.searchParams.set(key, filters[key]);
                } else {
                    url.searchParams.delete(key);
                }
            });

            url.searchParams.delete('orderby');
            url.searchParams.delete('order');

            window.location.href = url.toString();
        }

        getCurrentUrlParam(param) {
            const urlParams = new URLSearchParams(window.location.search);
            return urlParams.get(param);
        }
    }

    $(document).ready(function() {
        if ($('.contai-panel-keywords-list').length) {
            new KeywordsListHandler();
        }
    });

})(jQuery);
