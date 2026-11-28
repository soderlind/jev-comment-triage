import { existsSync, readFileSync, rmSync } from 'node:fs';
import { STATE_FILE } from './state';
import { AUTH_DIR, MU_PLUGIN, php } from './wp';

/**
 * Remove the suite's users, test post, and harness. The test forum, topics, and replies are kept.
 */
export function cleanUp(): void {
	const previous = existsSync( STATE_FILE )
		? ( JSON.parse( readFileSync( STATE_FILE, 'utf8' ) ) as { previousModeration?: string } ).previousModeration
		: undefined;

	php( `
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		require_once ABSPATH . 'wp-admin/includes/user.php';

		foreach ( get_posts( [ 'post_type' => 'post', 'post_status' => 'any', 'meta_key' => '_jct_e2e_post', 'numberposts' => -1, 'fields' => 'ids' ] ) as $post ) {
			wp_delete_post( $post, true );
		}

		$admin = (int) ( get_user_by( 'id', 1 ) ? 1 : 0 );
		foreach ( get_users( [ 'blog_id' => 0, 'search' => 'jct_e2e_*', 'search_columns' => [ 'user_login' ], 'fields' => 'ID' ] ) as $id ) {
			// Reassign forum content first; deleting a network user would delete it.
			foreach ( get_blogs_of_user( (int) $id ) as $blog ) {
				remove_user_from_blog( (int) $id, (int) $blog->userblog_id, $admin );
			}
			is_multisite() ? wpmu_delete_user( (int) $id ) : wp_delete_user( (int) $id, $admin );
		}

		$previous = ${ JSON.stringify( previous ?? null ) };
		if ( null !== $previous ) {
			update_option( 'comment_moderation', $previous );
		}

		return true;
	` );

	rmSync( MU_PLUGIN, { force: true } );
	rmSync( AUTH_DIR, { recursive: true, force: true } );
}

export default async function globalTeardown(): Promise<void> {
	cleanUp();
}
