import type { Directive } from 'vue';

type RevealElement = HTMLElement & { revealObserver?: IntersectionObserver };

/**
 * `v-reveal` plays an animate.css entrance (default `fadeInUp`) the first time
 * an element scrolls into view: `<section v-reveal="'fadeInLeft'">`. Add
 * `data-reveal-delay="150"` (ms) to stagger siblings. Skipped entirely when
 * the visitor prefers reduced motion.
 */
export const reveal: Directive<RevealElement, string | undefined> = {
    getSSRProps: () => ({}),
    mounted(el, binding) {
        const prefersReducedMotion = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;

        if (prefersReducedMotion || !('IntersectionObserver' in window)) {
            return;
        }

        const animation = binding.value ?? 'fadeInUp';
        el.style.opacity = '0';

        el.revealObserver = new IntersectionObserver(
            ([entry]) => {
                if (!entry?.isIntersecting) {
                    return;
                }

                el.style.opacity = '';
                el.style.animationDelay = `${el.dataset.revealDelay ?? 0}ms`;
                el.classList.add('animate__animated', `animate__${animation}`);
                el.revealObserver?.disconnect();
            },
            { threshold: 0.15 },
        );

        el.revealObserver.observe(el);
    },
    unmounted(el) {
        el.revealObserver?.disconnect();
    },
};
