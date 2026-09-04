<?php
/**
 * Object-level capability guards for write abilities.
 *
 * The native tool registry records ONE broad primitive capability per tool
 * (e.g. `edit_posts`) and the server checks only that before invoking a
 * callback. A primitive check cannot answer "may this user act on THIS
 * object?" — a Contributor holds `edit_posts` but must not edit an
 * Administrator-owned post, a page, or publish content.
 *
 * Every write callback that accepts a caller-supplied object ID must call the
 * matching guard here and bail on a WP_Error. These mirror the meta-capability
 * checks WordPress core's own REST controllers perform.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Guard an edit against a specific post object.
 *
 * @param int               $id            Post ID supplied by the caller.
 * @param string|string[]|null $allowed_types Restrict to these post type(s); null allows any.
 * @return WP_Post|WP_Error The post on success, WP_Error if missing / wrong type / forbidden.
 */
function wsp_mcp_guard_edit_post( $id, $allowed_types = null ) {
	$id   = intval( $id );
	$post = $id ? get_post( $id ) : null;
	if ( ! $post ) {
		return new WP_Error( 'not_found', "Object {$id} was not found." );
	}
	if ( null !== $allowed_types && ! in_array( $post->post_type, (array) $allowed_types, true ) ) {
		return new WP_Error( 'invalid_post_type', "Object {$id} is a '{$post->post_type}', which this tool cannot modify." );
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return new WP_Error( 'forbidden', "You do not have permission to edit object {$id}." );
	}
	return $post;
}

/**
 * Guard a delete/trash against a specific post object.
 *
 * @param int               $id            Post ID supplied by the caller.
 * @param string|string[]|null $allowed_types Restrict to these post type(s); null allows any.
 * @return WP_Post|WP_Error
 */
function wsp_mcp_guard_delete_post( $id, $allowed_types = null ) {
	$id   = intval( $id );
	$post = $id ? get_post( $id ) : null;
	if ( ! $post ) {
		return new WP_Error( 'not_found', "Object {$id} was not found." );
	}
	if ( null !== $allowed_types && ! in_array( $post->post_type, (array) $allowed_types, true ) ) {
		return new WP_Error( 'invalid_post_type', "Object {$id} is a '{$post->post_type}', which this tool cannot delete." );
	}
	if ( ! current_user_can( 'delete_post', $id ) ) {
		return new WP_Error( 'forbidden', "You do not have permission to delete object {$id}." );
	}
	return $post;
}

/**
 * Guard a requested status transition. Publishing / scheduling / privatising
 * content requires the post type's publish capability, which a Contributor
 * lacks.
 *
 * @param WP_Post $post   The target post.
 * @param string  $status Requested post_status.
 * @return true|WP_Error
 */
function wsp_mcp_guard_post_status( $post, $status ) {
	$status = sanitize_key( $status );
	if ( ! in_array( $status, array( 'publish', 'future', 'private' ), true ) ) {
		return true;
	}
	$type = get_post_type_object( $post->post_type );
	if ( ! $type || ! current_user_can( $type->cap->publish_posts ) ) {
		return new WP_Error( 'forbidden', "You do not have permission to set status '{$status}' on object {$post->ID}." );
	}
	return true;
}
