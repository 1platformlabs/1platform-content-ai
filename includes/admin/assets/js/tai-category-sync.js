/**
 * Category language sync for Content AI admin forms.
 *
 * Reads data-category-select / data-lang-select attributes from the language
 * <select> and updates category option labels when the language changes.
 *
 * Expects the language select to have data-category-select="#id" pointing at
 * the category select, and each category <option> to carry data-title-en and
 * data-title-es attributes.
 *
 * @package ContentAI
 */
(function () {
    'use strict';

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
})();
