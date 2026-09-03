var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __getOwnPropNames = Object.getOwnPropertyNames;
var __hasOwnProp = Object.prototype.hasOwnProperty;
var __export = (target, all) => {
  for (var name in all)
    __defProp(target, name, { get: all[name], enumerable: true });
};
var __copyProps = (to, from, except, desc) => {
  if (from && typeof from === "object" || typeof from === "function") {
    for (let key of __getOwnPropNames(from))
      if (!__hasOwnProp.call(to, key) && key !== except)
        __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
  }
  return to;
};
var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);

// packages/theme/src/postcss-plugins/postcss-ds-token-fallbacks.mjs
var postcss_ds_token_fallbacks_exports = {};
__export(postcss_ds_token_fallbacks_exports, {
  default: () => postcss_ds_token_fallbacks_default
});
module.exports = __toCommonJS(postcss_ds_token_fallbacks_exports);
var import_ds_token_fallbacks = require("./ds-token-fallbacks.mjs");
var plugin = () => ({
  postcssPlugin: "postcss-ds-token-fallbacks",
  /** @param {import('postcss').Declaration} decl */
  Declaration(decl) {
    const updated = (0, import_ds_token_fallbacks.addFallbackToVar)(decl.value);
    if (updated !== decl.value) {
      decl.value = updated;
    }
  }
});
plugin.postcss = true;
var postcss_ds_token_fallbacks_default = plugin;
//# sourceMappingURL=postcss-ds-token-fallbacks.cjs.map
