import util from 'node:util'

export let COLORS_ENABLED = false

/** @param {boolean} [enabled] */
export const enableColors = (enabled) => {
  if (enabled) {
    COLORS_ENABLED = true
  }
}

/**
 * @param {util.InspectColor | readonly util.InspectColor[]} format
 * @returns {(text: string) => string}
 */
const styleText = (format) => (text) =>
  COLORS_ENABLED ? util.styleText(format, text, { validateStream: false }) : text

export const green = styleText('green')

export const red = styleText('red')

export const yellow = styleText('yellow')

export const blue = styleText('blue')

export const dim = styleText('dim')

export const bold = styleText('bold')
