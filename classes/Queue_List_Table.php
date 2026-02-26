<?php
/**
 * WP_List_Table for the conversion report queue.
 *
 * Displays pending and failed jobs with row actions for retrying and
 * deleting individual jobs.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.8.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Queue list table for the admin page.
 *
 * @since 1.8.0
 */
final class Queue_List_Table extends \WP_List_Table {

	/**
	 * Queue instance for fetching jobs.
	 *
	 * @var Queue
	 * @since 1.8.0
	 */
	private readonly Queue $queue;

	/**
	 * Initializes the list table with a Queue dependency.
	 *
	 * @param Queue $queue The job queue.
	 *
	 * @since 1.8.0
	 */
	public function __construct( Queue $queue ) {
		parent::__construct( [
			'singular' => 'queue-job',
			'plural'   => 'queue-jobs',
			'ajax'     => false,
		] );
		$this->queue = $queue;
	}

	/**
	 * Defines the table columns.
	 *
	 * @return array<string, string> Column slug => label.
	 * @since 1.8.0
	 */
	public function get_columns(): array {
		return [
			'reporter'      => __( 'Reporter', 'kntnt-ad-attr' ),
			'label'         => __( 'Description', 'kntnt-ad-attr' ),
			'created_at'    => __( 'Created', 'kntnt-ad-attr' ),
			'retry_after'   => __( 'Next Retry', 'kntnt-ad-attr' ),
			'attempts'      => __( 'Attempts', 'kntnt-ad-attr' ),
			'error_message' => __( 'Error', 'kntnt-ad-attr' ),
		];
	}

	/**
	 * Prepares items for display.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function prepare_items(): void {
		$this->_column_headers = [
			$this->get_columns(),
			[],
			[],
		];

		$this->items = $this->queue->get_active_jobs();
	}

	/**
	 * Renders the reporter column with row actions.
	 *
	 * @param object $item Queue job row.
	 *
	 * @return string Column HTML.
	 * @since 1.8.0
	 */
	protected function column_reporter( object $item ): string {
		$page   = Plugin::get_slug();
		$job_id = (int) $item->id;

		$run_url = wp_nonce_url(
			admin_url( "tools.php?page={$page}&queue_action=run_now&job_id={$job_id}" ),
			"queue_run_{$job_id}",
		);

		$delete_url = wp_nonce_url(
			admin_url( "tools.php?page={$page}&queue_action=delete_job&job_id={$job_id}" ),
			"queue_delete_{$job_id}",
		);

		$actions = [
			'run_now' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $run_url ),
				esc_html__( 'Run Now', 'kntnt-ad-attr' ),
			),
			'delete'  => sprintf(
				'<a href="%s" class="submitdelete">%s</a>',
				esc_url( $delete_url ),
				esc_html__( 'Delete', 'kntnt-ad-attr' ),
			),
		];

		return esc_html( $item->reporter ) . $this->row_actions( $actions );
	}

	/**
	 * Renders the label column.
	 *
	 * @param object $item Queue job row.
	 *
	 * @return string Column HTML.
	 * @since 1.8.0
	 */
	protected function column_label( object $item ): string {
		return esc_html( $item->label ?? '—' );
	}

	/**
	 * Renders the created_at column as a relative time.
	 *
	 * @param object $item Queue job row.
	 *
	 * @return string Column HTML.
	 * @since 1.8.0
	 */
	protected function column_created_at( object $item ): string {
		$timestamp = strtotime( $item->created_at . ' UTC' );

		if ( ! $timestamp ) {
			return esc_html( $item->created_at );
		}

		return sprintf(
			/* translators: %s: Human-readable time difference (e.g. "2 hours ago") */
			esc_html__( '%s ago', 'kntnt-ad-attr' ),
			human_time_diff( $timestamp ),
		);
	}

	/**
	 * Renders the retry_after column.
	 *
	 * @param object $item Queue job row.
	 *
	 * @return string Column HTML.
	 * @since 1.8.0
	 */
	protected function column_retry_after( object $item ): string {

		if ( $item->status === 'failed' ) {
			return '<span style="color:#b32d2e">' . esc_html__( 'Permanently failed', 'kntnt-ad-attr' ) . '</span>';
		}

		if ( empty( $item->retry_after ) ) {
			return '—';
		}

		$timestamp = strtotime( $item->retry_after . ' UTC' );

		if ( ! $timestamp ) {
			return esc_html( $item->retry_after );
		}

		if ( $timestamp <= time() ) {
			return esc_html__( 'Ready', 'kntnt-ad-attr' );
		}

		return sprintf(
			/* translators: %s: Human-readable time difference (e.g. "in 5 minutes") */
			esc_html__( 'in %s', 'kntnt-ad-attr' ),
			human_time_diff( $timestamp ),
		);
	}

	/**
	 * Renders the attempts column.
	 *
	 * @param object $item Queue job row.
	 *
	 * @return string Column HTML.
	 * @since 1.8.0
	 */
	protected function column_attempts( object $item ): string {
		return esc_html( (string) $item->attempts );
	}

	/**
	 * Renders the error_message column (truncated).
	 *
	 * @param object $item Queue job row.
	 *
	 * @return string Column HTML.
	 * @since 1.8.0
	 */
	protected function column_error_message( object $item ): string {
		if ( empty( $item->error_message ) ) {
			return '—';
		}

		$truncated = mb_strimwidth( $item->error_message, 0, 120, '…' );
		return '<span title="' . esc_attr( $item->error_message ) . '">' . esc_html( $truncated ) . '</span>';
	}

	/**
	 * Message displayed when no jobs are found.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function no_items(): void {
		esc_html_e( 'No pending or failed jobs in the queue.', 'kntnt-ad-attr' );
	}

}
