<?php
/**
 * WP_List_Table for the conversion report queue.
 *
 * Displays active and recently completed jobs with row actions for retrying
 * and deleting individual jobs.
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
			'reporter'        => __( 'Reporter', 'kntnt-ad-attr' ),
			'label'           => __( 'Description', 'kntnt-ad-attr' ),
			'created_at'      => __( 'Created', 'kntnt-ad-attr' ),
			'last_attempt_at' => __( 'Last Attempt', 'kntnt-ad-attr' ),
			'status'          => __( 'Status', 'kntnt-ad-attr' ),
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
	 * Hides "Run Now" for completed jobs since they don't need re-processing.
	 *
	 * @param object $item Queue job row.
	 *
	 * @return string Column HTML.
	 * @since 1.8.0
	 */
	protected function column_reporter( object $item ): string {
		$page   = Plugin::get_slug();
		$job_id = (int) $item->id;

		$delete_url = wp_nonce_url(
			admin_url( "tools.php?page={$page}&queue_action=delete_job&job_id={$job_id}" ),
			"queue_delete_{$job_id}",
		);

		$actions = [];

		// Only show "Run Now" for jobs that are not already done.
		if ( $item->status !== 'done' ) {
			$run_url = wp_nonce_url(
				admin_url( "tools.php?page={$page}&queue_action=run_now&job_id={$job_id}" ),
				"queue_run_{$job_id}",
			);
			$actions['run_now'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $run_url ),
				esc_html__( 'Run Now', 'kntnt-ad-attr' ),
			);
		}

		$actions['delete'] = sprintf(
			'<a href="%s" class="submitdelete">%s</a>',
			esc_url( $delete_url ),
			esc_html__( 'Delete', 'kntnt-ad-attr' ),
		);

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
	 * Renders the created_at column as a formatted date+time.
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

		return esc_html( wp_date( 'Y-m-d H:i', $timestamp ) );
	}

	/**
	 * Renders the last_attempt_at column as a formatted date+time.
	 *
	 * @param object $item Queue job row.
	 *
	 * @return string Column HTML.
	 * @since 1.9.0
	 */
	protected function column_last_attempt_at( object $item ): string {
		if ( empty( $item->last_attempt_at ) ) {
			return '—';
		}

		$timestamp = strtotime( $item->last_attempt_at . ' UTC' );

		if ( ! $timestamp ) {
			return esc_html( $item->last_attempt_at );
		}

		return esc_html( wp_date( 'Y-m-d H:i', $timestamp ) );
	}

	/**
	 * Renders the status column with human-readable messages.
	 *
	 * @param object $item Queue job row.
	 *
	 * @return string Column HTML.
	 * @since 1.9.0
	 */
	protected function column_status( object $item ): string {
		$attempts = (int) $item->attempts;

		return match ( $item->status ) {
			'done' => esc_html( sprintf(
				/* translators: %d: Number of attempts */
				__( 'Success after %d attempts', 'kntnt-ad-attr' ),
				$attempts,
			) ),

			'failed' => '<span style="color:#b32d2e">' . esc_html( sprintf(
				/* translators: %d: Number of attempts */
				__( 'Failed after %d attempts', 'kntnt-ad-attr' ),
				$attempts,
			) ) . '</span>',

			'processing' => esc_html( sprintf(
				/* translators: %d: Current attempt number */
				__( 'Attempt %d running', 'kntnt-ad-attr' ),
				$attempts + 1,
			) ),

			'pending' => $this->render_pending_status( $item, $attempts ),

			default => esc_html( $item->status ),
		};
	}

	/**
	 * Renders the status for pending jobs with retry/deferral context.
	 *
	 * @param object $item     Queue job row.
	 * @param int    $attempts Current attempt count.
	 *
	 * @return string Status HTML.
	 * @since 1.9.0
	 */
	private function render_pending_status( object $item, int $attempts ): string {

		// Brand new job — never attempted, no retry scheduled.
		if ( $attempts === 0 && empty( $item->retry_after ) ) {
			return esc_html__( 'Pending', 'kntnt-ad-attr' );
		}

		// Job with a future retry_after — show when the next attempt is scheduled.
		if ( ! empty( $item->retry_after ) ) {
			$retry_ts = strtotime( $item->retry_after . ' UTC' );

			if ( $retry_ts && $retry_ts > time() ) {
				return esc_html( sprintf(
					/* translators: 1: Attempt number, 2: Date and time */
					__( 'Attempt %1$d at %2$s', 'kntnt-ad-attr' ),
					$attempts + 1,
					wp_date( 'Y-m-d H:i', $retry_ts ),
				) );
			}
		}

		// Retry time has passed or is null — ready for processing.
		return esc_html( sprintf(
			/* translators: %d: Current attempt number */
			__( 'Attempt %d ready', 'kntnt-ad-attr' ),
			$attempts + 1,
		) );
	}

	/**
	 * Message displayed when no jobs are found.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function no_items(): void {
		esc_html_e( 'No jobs in the queue.', 'kntnt-ad-attr' );
	}

}
