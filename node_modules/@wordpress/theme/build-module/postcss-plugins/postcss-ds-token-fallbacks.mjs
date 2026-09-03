// packages/theme/src/postcss-plugins/postcss-ds-token-fallbacks.mjs
import { addFallbackToVar } from "./ds-token-fallbacks.mjs";
var plugin = () => ({
  postcssPlugin: "postcss-ds-token-fallbacks",
  /** @param {import('postcss').Declaration} decl */
  Declaration(decl) {
    const updated = addFallbackToVar(decl.value);
    if (updated !== decl.value) {
      decl.value = updated;
    }
  }
});
plugin.postcss = true;
var postcss_ds_token_fallbacks_default = plugin;
export {
  postcss_ds_token_fallbacks_default as default
};
//# sourceMappingURL=postcss-ds-token-fallbacks.mjs.map
