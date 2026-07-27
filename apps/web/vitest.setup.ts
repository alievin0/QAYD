import "@testing-library/jest-dom/vitest";
import { vi } from "vitest";

/*
 * jsdom polyfills for the browser APIs the shell's primitives touch. jsdom implements none of these, so
 * without them `@qayd/ui`'s theme provider (matchMedia) and Radix Select (ResizeObserver, pointer
 * capture, scrollIntoView) throw on render. Kept minimal and inert — they satisfy the API surface, not
 * behavior, which is all a render/interaction assertion needs.
 */

if (typeof window.matchMedia !== "function") {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    addListener: vi.fn(),
    removeListener: vi.fn(),
    dispatchEvent: vi.fn(),
  }));
}

if (typeof globalThis.ResizeObserver === "undefined") {
  globalThis.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  };
}

if (!Element.prototype.scrollIntoView) {
  Element.prototype.scrollIntoView = vi.fn();
}
if (!Element.prototype.hasPointerCapture) {
  Element.prototype.hasPointerCapture = vi.fn(() => false);
}
if (!Element.prototype.releasePointerCapture) {
  Element.prototype.releasePointerCapture = vi.fn();
}
