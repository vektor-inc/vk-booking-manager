// packages/dom/src/focusable.js
function buildSelector(sequential) {
  return [
    sequential ? '[tabindex]:not([tabindex^="-"])' : "[tabindex]",
    "a[href]",
    "button:not([disabled])",
    'input:not([type="hidden"]):not([disabled])',
    "select:not([disabled])",
    "textarea:not([disabled])",
    'iframe:not([tabindex^="-"])',
    "object",
    "embed",
    "summary",
    "area[href]",
    "[contenteditable]:not([contenteditable=false])"
  ].join(",");
}
function isVisible(element) {
  return element.offsetWidth > 0 || element.offsetHeight > 0 || element.getClientRects().length > 0;
}
function isValidFocusableArea(element) {
  const map = element.closest("map[name]");
  if (!map) {
    return false;
  }
  const img = element.ownerDocument.querySelector(
    'img[usemap="#' + map.name + '"]'
  );
  return !!img && isVisible(img);
}
function find(context, { sequential = false } = {}) {
  const elements = context.querySelectorAll(buildSelector(sequential));
  return Array.from(elements).filter((element) => {
    if (!isVisible(element)) {
      return false;
    }
    if (element.closest("[inert]")) {
      return false;
    }
    const { nodeName } = element;
    if ("AREA" === nodeName) {
      return isValidFocusableArea(
        /** @type {HTMLAreaElement} */
        element
      );
    }
    return true;
  });
}
export {
  find
};
//# sourceMappingURL=focusable.mjs.map
