/**
 * Category language sync and theme auto-selection for Content AI admin forms.
 *
 * 1. Language sync: Updates category option labels when language changes.
 *    Expects the language select to have data-category-select="#id" pointing at
 *    the category select, and each category <option> to carry data-title-en and
 *    data-title-es attributes.
 *
 * 2. Theme auto-selection: When category changes, reads data-theme from the
 *    selected option and updates the hidden contai_wordpress_theme input.
 *    Also updates the readonly display field if present (Settings form).
 *
 * @package ContentAI
 */
(function () {
    'use strict';

    // Language sync
    document.querySelectorAll('select[data-category-select]').forEach(function (languageSelect) {
        var categorySelector = languageSelect.getAttribute('data-category-select');
        var categorySelect   = document.querySelector(categorySelector);

        if (!categorySelect) {
            return;
        }

        languageSelect.addEventListener('change', function () {
            var selectedLanguage = this.value.toLowerCase();
            var langCode         = selectedLanguage === 'spanish' ? 'es' : 'en';
            var savedValue       = categorySelect.value;

            Array.from(categorySelect.options).forEach(function (option) {
                if (option.value === '') {
                    return;
                }

                var titleAttr = 'data-title-' + langCode;
                var newTitle  = option.getAttribute(titleAttr);

                if (newTitle) {
                    option.textContent = newTitle;
                }
            });

            categorySelect.value = savedValue;
        });
    });

    // Theme auto-selection based on category
    document.querySelectorAll('select[data-lang-select]').forEach(function (categorySelect) {
        var themeInput   = document.getElementById('contai_wordpress_theme');
        var themeDisplay = document.getElementById('contai_wordpress_theme_display');

        if (!themeInput) {
            return;
        }

        function updateTheme() {
            var selected = categorySelect.options[categorySelect.selectedIndex];
            var theme    = selected ? selected.getAttribute('data-theme') : null;

            if (theme) {
                themeInput.value = theme;
                if (themeDisplay) {
                    themeDisplay.value = theme.charAt(0).toUpperCase() + theme.slice(1);
                }
            }
        }

        categorySelect.addEventListener('change', updateTheme);

        // Set initial theme if a category is already selected
        if (categorySelect.value) {
            updateTheme();
        }
    });
})();
