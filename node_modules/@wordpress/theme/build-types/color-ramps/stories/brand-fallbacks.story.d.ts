import type { Meta, StoryObj } from '@storybook/react-vite';
type VerifierProps = {
    adminThemeColor: string;
};
declare const Verifier: React.FC<VerifierProps>;
/**
 * Compares actual brand token values (computed by ThemeProvider using the full
 * ramp algorithm) against their `color-mix()` fallback approximations driven
 * by `--wp-admin-theme-color`. Use the color picker to switch admin color
 * schemes and observe how closely the fallbacks track the real values.
 */
declare const meta: Meta<typeof Verifier>;
export default meta;
export declare const Default: StoryObj<typeof Verifier>;
//# sourceMappingURL=brand-fallbacks.story.d.ts.map