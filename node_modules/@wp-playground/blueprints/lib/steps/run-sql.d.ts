import type { StepHandler } from '.';
/**
 * @inheritDoc runSql
 * @hasRunnableExample
 * @example
 *
 * <code>
 * {
 *		"step": "runSql",
 *		"sql": {
 *			"resource": "literal",
 *			"name": "schema.sql",
 *			"contents": "DELETE FROM wp_posts"
 *		}
 * }
 * </code>
 */
export interface RunSqlStep<ResourceType> {
    /**
     * The step identifier.
     */
    step: 'runSql';
    /**
     * The SQL to run. Each non-empty line must contain a valid SQL query.
     */
    sql: ResourceType;
}
/**
 * Run one or more SQL queries.
 *
 * This step uses WP_MySQL_Naive_Query_Stream to parse and execute SQL queries using
 * streaming semantics. It supports multiline queries, comments, and queries
 * separated by semicolons. Each query is executed using `$wpdb`. This step assumes
 * a presence of the `sqlite-database-integration` plugin that ships the required
 * query tokenizer classes.
 */
export declare const runSql: StepHandler<RunSqlStep<File>>;
