import type { StepDefinition } from '@wp-playground/blueprints';
export declare const PHPMYADMIN_VERSION = "5.2.3";
export declare const PHPMYADMIN_DOWNLOAD_URL = "https://files.phpmyadmin.net/phpMyAdmin/5.2.3/phpMyAdmin-5.2.3-english.zip";
export declare const PHPMYADMIN_INSTALL_PATH = "/tools/phpmyadmin";
export declare const PHPMYADMIN_ENTRY_PATH = "/index.php?route=/database/structure&db=wordpress";
/**
 * Returns the blueprint steps needed to install phpMyAdmin in Playground.
 *
 * This installs phpMyAdmin and applies the following modifications:
 *   1. Inject a "config.inc.php" file to configure phpMyAdmin for Playground.
 *   2. Inject a "DbiMysqli.php" file to implement the MySQL-on-SQLite driver.
 *
 * @returns Blueprint steps to install phpMyAdmin in Playground.
 */
export declare function getPhpMyAdminInstallSteps(): Promise<StepDefinition[]>;
