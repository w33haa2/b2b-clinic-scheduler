import type { MaybeElementRef } from '@vueuse/core';
import {
    useElementBounding,
    usePreferredReducedMotion,
    useWindowScroll,
    useWindowSize,
} from '@vueuse/core';
import { computed } from 'vue';
import type { ComputedRef, CSSProperties } from 'vue';

/**
 * Scroll-linked parallax. Returns a style that shifts an element by `speed`
 * times the scroll distance: 0.3 lags behind the page, negative values move
 * against it. Without `target` the distance is the page scroll (for the hero);
 * with `target` it is how far that section has scrolled past the centre of the
 * viewport, so sections further down pass through rest as you read them.
 * Static when the visitor prefers reduced motion.
 */
export function useParallax(
    speed: number,
    target?: MaybeElementRef,
    maxShift = 72,
): ComputedRef<CSSProperties> {
    const { y } = useWindowScroll();
    const { height: windowHeight } = useWindowSize();
    const bounding = target ? useElementBounding(target) : null;
    const reducedMotion = usePreferredReducedMotion();

    return computed(() => {
        if (reducedMotion.value === 'reduce') {
            return {};
        }

        const distance = bounding
            ? windowHeight.value * 0.5 -
              (bounding.top.value + bounding.height.value * 0.5)
            : y.value;

        const rawShift = distance * speed;
        const clampedShift = Math.max(-maxShift, Math.min(maxShift, rawShift));

        return {
            transform: `translate3d(0, ${clampedShift.toFixed(1)}px, 0)`,
            willChange: 'transform',
        };
    });
}

/**
 * Normalised 0..1 progress as `target` scrolls through the viewport.
 * Useful for scroll-driven storyboards and step indicators.
 */
export function useScrollProgress(
    target: MaybeElementRef,
): ComputedRef<number> {
    const { height: windowHeight } = useWindowSize();
    const bounding = useElementBounding(target);

    return computed(() => {
        const vh = windowHeight.value || 1;
        const totalTravel = Math.max(bounding.height.value - vh * 0.35, 1);
        const scrolled = vh * 0.55 - bounding.top.value;

        return Math.max(0, Math.min(1, scrolled / totalTravel));
    });
}
