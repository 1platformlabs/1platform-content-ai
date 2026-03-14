(function() {
    'use strict';

    const initToggle = () => {
        const toggleButtons = document.querySelectorAll('.toc-toggle');

        toggleButtons.forEach(button => {
            button.addEventListener('click', function() {
                const container = this.closest('.toc-container');
                const content = container.querySelector('.toc-content');
                const isExpanded = this.getAttribute('aria-expanded') === 'true';

                content.classList.toggle('toc-hidden');
                this.setAttribute('aria-expanded', !isExpanded);

                localStorage.setItem('toc_state', isExpanded ? 'collapsed' : 'expanded');
            });
        });

        restoreTocState();
    };

    const restoreTocState = () => {
        const savedState = localStorage.getItem('toc_state');

        if (savedState === 'collapsed') {
            const contents = document.querySelectorAll('.toc-content');
            const toggles = document.querySelectorAll('.toc-toggle');

            contents.forEach(content => {
                if (!content.classList.contains('toc-hidden')) {
                    content.classList.add('toc-hidden');
                }
            });

            toggles.forEach(toggle => {
                toggle.setAttribute('aria-expanded', 'false');
            });
        }
    };

    const initSmoothScroll = () => {
        if (!window.contaiTocConfig || !contaiTocConfig.smoothScroll) {
            return;
        }

        const offset = contaiTocConfig.smoothScrollOffset || 30;
        const links = document.querySelectorAll('.toc-link');

        links.forEach(link => {
            link.addEventListener('click', function(e) {
                const href = this.getAttribute('href');

                if (!href || !href.startsWith('#')) {
                    return;
                }

                const targetId = href.substring(1);
                const target = document.getElementById(targetId);

                if (!target) {
                    return;
                }

                e.preventDefault();

                const targetPosition = target.getBoundingClientRect().top + window.pageYOffset;
                const offsetPosition = targetPosition - offset;

                window.scrollTo({
                    top: offsetPosition,
                    behavior: 'smooth'
                });

                if (history.pushState) {
                    history.pushState(null, null, href);
                } else {
                    window.location.hash = href;
                }

                target.focus();
            });
        });
    };

    const highlightCurrentSection = () => {
        const links = document.querySelectorAll('.toc-link');
        const anchors = Array.from(document.querySelectorAll('.toc-anchor'));

        if (anchors.length === 0) {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const id = entry.target.id;

                        links.forEach(link => {
                            link.classList.remove('toc-active');
                        });

                        const activeLink = document.querySelector(`.toc-link[href="#${id}"]`);
                        if (activeLink) {
                            activeLink.classList.add('toc-active');
                        }
                    }
                });
            },
            {
                rootMargin: '-80px 0px -80% 0px'
            }
        );

        anchors.forEach(anchor => {
            observer.observe(anchor);
        });
    };

    const init = () => {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => {
                initToggle();
                initSmoothScroll();
                highlightCurrentSection();
            });
        } else {
            initToggle();
            initSmoothScroll();
            highlightCurrentSection();
        }
    };

    init();
})();
