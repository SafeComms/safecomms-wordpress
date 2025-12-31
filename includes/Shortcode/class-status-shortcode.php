<?php
/**
 * SafeComms status shortcode renderer.
 *
 * @package SafeComms
 */

namespace SafeComms\Shortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders SafeComms moderation status for posts and comments.
 */
class Status_Shortcode {

	/**
	 * Register the SafeComms status shortcode.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'safecomms_status', array( $this, 'render' ) );
	}

	/**
	 * Render shortcode output.
	 *
	 * @param array $atts Shortcode attributes.
	 *
	 * @return string
	 */
	public function render( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'post_id'    => '',
				'comment_id' => '',
			),
			$atts,
			'safecomms_status'
		);

		// Resolve context (comment vs post).
		if ( ! empty( $atts['comment_id'] ) ) {
			return $this->render_comment_status( (int) $atts['comment_id'] );
		}

		$post_id = ! empty( $atts['post_id'] ) ? (int) $atts['post_id'] : get_the_ID();
		if ( ! $post_id ) {
			return '';
		}

		return $this->render_post_status( $post_id );
	}

	/**
	 * Determine whether the current user can view the status.
	 *
	 * @param string $capability Capability to check.
	 * @param int    $object_id  Object ID for capability context.
	 *
	 * @return bool
	 */
	private function can_view_status( string $capability, int $object_id ): bool {
		$options     = get_option( 'safecomms_options', array() );
		$admins_only = ! empty( $options['shortcode_admins_only'] );

		if ( $admins_only ) {
			return current_user_can( 'manage_options' );
		}

		return current_user_can( $capability, $object_id );
	}

	/**
	 * Render post moderation status.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string
	 */
	private function render_post_status( int $post_id ): string {
		// Security: Check visibility settings.
		if ( ! $this->can_view_status( 'edit_post', $post_id ) ) {
			return '';
		}

		$status = get_post_meta( $post_id, 'safecomms_status', true );
		$reason = get_post_meta( $post_id, 'safecomms_reason', true );
		$score  = get_post_meta( $post_id, 'safecomms_score', true );

		return $this->format_output( 'Post', $status, $score, $reason );
	}

	/**
	 * Render comment moderation status.
	 *
	 * @param int $comment_id Comment ID.
	 *
	 * @return string
	 */
	private function render_comment_status( int $comment_id ): string {
		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return '';
		}

		// Security: Check visibility settings.
		$options     = get_option( 'safecomms_options', array() );
		$admins_only = ! empty( $options['shortcode_admins_only'] );

		if ( $admins_only && ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		// Allow if user is moderator or comment author.
		$can_view = current_user_can( 'moderate_comments' );
		if ( ! $can_view && is_user_logged_in() ) {
			$can_view = ( get_current_user_id() === (int) $comment->user_id );
		}

		if ( ! $can_view ) {
			return '';
		}

		$status = get_comment_meta( $comment_id, 'safecomms_status', true );
		$reason = get_comment_meta( $comment_id, 'safecomms_reason', true );
		$score  = get_comment_meta( $comment_id, 'safecomms_score', true );

		return $this->format_output( 'Comment', $status, $score, $reason );
	}

	/**
	 * Format the status block for output.
	 *
	 * @param string     $type   Context type (Post or Comment).
	 * @param string|int $status Stored status.
	 * @param string|int $score  Stored score.
	 * @param string     $reason Stored reason text.
	 *
	 * @return string
	 */
	private function format_output( string $type, $status, $score, $reason ): string {
		$status_value = '' !== (string) $status ? (string) $status : __( 'unknown', 'safecomms' );
		$status_text  = esc_html( $status_value );

		$score_text = '' !== (string) $score
			? ' | ' . esc_html(
				sprintf(
					/* translators: %s: SafeComms moderation score. */
					__( 'Score: %s', 'safecomms' ),
					$score
				)
			)
			: '';
		$reason_text = '' !== (string) $reason ? ' | ' . esc_html( $reason ) : '';

		$color = '#2271b1'; // Default Blue.
		if ( 'block' === $status ) {
			$color = '#d63638'; // Red.
		}
		if ( 'allow' === $status ) {
			$color = '#00a32a'; // Green.
		}

		$style = "background: #f0f0f1; border-left: 4px solid {$color}; padding: 8px 12px; font-size: 13px; margin: 10px 0; color: #3c434a;";

		return sprintf(
			'<div class="safecomms-status" style="%s"><strong>SafeComms (%s):</strong> %s%s%s</div>',
			esc_attr( $style ),
			esc_html( $type ),
			$status_text,
			$score_text,
			$reason_text
		);
	}
}
