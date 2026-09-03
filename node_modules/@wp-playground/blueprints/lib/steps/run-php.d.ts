import type { PHPResponse } from '@php-wasm/universal';
import type { StepHandler } from '.';
/**
 * @inheritDoc runPHP
 * @hasRunnableExample
 * @example
 *
 * <code>
 * {
 * 		"step": "runPHP",
 * 		"code": "<?php require_once '/wordpress/wp-load.php'; wp_insert_post(array('post_title' => 'wp-load.php required for WP functionality', 'post_status' => 'publish')); ?>"
 * }
 * </code>
 */
export interface RunPHPStep {
    /** The step identifier. */
    step: 'runPHP';
    /** The PHP code to run. */
    code: string | {
        /**
         * This property is ignored during Blueprint v1 execution but exists
         * so the same runPHP step structure can be used for Blueprints v1 and v2.
         */
        filename: string;
        content: string;
    };
}
/**
 * Runs PHP code.
 * When running WordPress functions, the `code` key must first load [`wp-load.php`](https://github.com/WordPress/WordPress/blob/master/wp-load.php) and start with `"<?php require_once '/wordpress/wp-load.php'; "`.
 */
export declare const runPHP: StepHandler<RunPHPStep, Promise<PHPResponse>>;
