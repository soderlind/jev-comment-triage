import { chromium, expect, type FullConfig } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { copyFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import path from 'node:path';
import { AUTH_DIR, BASE_URL, MU_PLUGIN, php, phpStr, WP_ROOT } from './wp';
import { cleanUp } from './global-teardown';
import { FORUM_TEXT, POST_TEXT, type Role, type SuiteState, STATE_FILE, storageFile } from './state';

const ROLES: Record<Exclude<Role, 'admin'>, [ string, string ]> = {
	subscriber: [ 'subscriber', 'bbp_participant' ],
	author: [ 'author', 'bbp_participant' ],
	moderator: [ 'subscriber', 'bbp_moderator' ],
};

export default async function globalSetup( _config: FullConfig ): Promise<void> {
	// Remove anything an aborted earlier run left behind.
	cleanUp();

	mkdirSync( AUTH_DIR, { recursive: true } );
	copyFileSync( path.join( __dirname, 'harness.php' ), MU_PLUGIN );

	const token = randomBytes( 4 ).toString( 'hex' );
	const passwords = Object.fromEntries(
		Object.keys( ROLES ).map( ( role ) => [ role, randomBytes( 18 ).toString( 'base64url' ) ] )
	) as Record<keyof typeof ROLES, string>;
	const env = Object.fromEntries(
		Object.entries( passwords ).map( ( [ role, pass ] ) => [ `JCT_E2E_PASS_${ role.toUpperCase() }`, pass ] )
	);

	const site = php<Omit<SuiteState, 'token'>>(
		`
		$previous = (string) get_option( 'comment_moderation' );
		update_option( 'comment_moderation', '0' );

		$users = [];
		foreach ( ${ phpArray( ROLES ) } as $key => [ $role, $forum_role ] ) {
			$login = 'jct_e2e_' . $key . '_${ token }';
			$id    = wp_insert_user(
				[
					'user_login'   => $login,
					'user_pass'    => (string) getenv( 'JCT_E2E_PASS_' . strtoupper( $key ) ),
					'user_email'   => $login . '@example.com',
					'display_name' => 'E2E ' . ucfirst( $key ),
					'role'         => $role,
				]
			);
			if ( is_wp_error( $id ) ) {
				throw new \\RuntimeException( $id->get_error_message() );
			}
			add_user_to_blog( get_current_blog_id(), $id, $role );
			bbp_set_user_role( $id, $forum_role );
			$users[ $key ] = [ 'id' => $id, 'login' => $login ];
		}

		$found = get_posts( [ 'post_type' => bbp_get_forum_post_type(), 'post_status' => 'any', 'meta_key' => '_jct_e2e_forum', 'numberposts' => 1, 'fields' => 'ids' ] );
		$forum = $found[0] ?? bbp_insert_forum(
			[
				'post_title'   => 'Jev acceptance tests: WordPress plugin development',
				'post_content' => ${ phpStr( FORUM_TEXT ) },
				'post_status'  => 'publish',
			]
		);
		update_post_meta( $forum, '_jct_e2e_forum', 1 );

		$post = wp_insert_post(
			[
				'post_title'     => 'Using action hooks and filters in WordPress plugins',
				'post_content'   => ${ phpStr( POST_TEXT ) },
				'post_status'    => 'publish',
				'post_author'    => $users['author']['id'],
				'comment_status' => 'open',
				'meta_input'     => [ '_jct_e2e_post' => 1 ],
			],
			true
		);
		if ( is_wp_error( $post ) ) {
			throw new \\RuntimeException( $post->get_error_message() );
		}

		return [ 'users' => $users, 'forumId' => (int) $forum, 'postId' => (int) $post, 'previousModeration' => $previous ];
	`,
		env
	);

	const state: SuiteState = { token, ...site };
	writeFileSync( STATE_FILE, JSON.stringify( state, null, 2 ) );

	const browser = await chromium.launch();
	try {
		for ( const role of Object.keys( ROLES ) as Array<keyof typeof ROLES> ) {
			const context = await browser.newContext();
			const page = await context.newPage();
			await page.goto( `${ BASE_URL }/wp-login.php` );
			await page.getByLabel( 'Username or Email Address' ).fill( state.users[ role ].login );
			await page.getByLabel( 'Password', { exact: true } ).fill( passwords[ role ] );
			await page.getByRole( 'button', { name: 'Log In' } ).click();
			// Some roles are redirected to the front end instead of wp-admin.
			await page.waitForURL( ( url ) => ! url.pathname.endsWith( '/wp-login.php' ) );
			expect( ( await context.cookies() ).some( ( c ) => c.name.startsWith( 'wordpress_logged_in_' ) ) ).toBe( true );
			await context.storageState( { path: storageFile( role ) } );
			await context.close();
		}

		const context = await browser.newContext();
		const page = await context.newPage();
		await page.goto( adminLoginUrl() );
		await page.waitForURL( /\/wp-admin\// );
		await page.goto( `${ BASE_URL }/wp-admin/edit-comments.php` );
		await expect( page.getByRole( 'heading', { name: 'Comments', level: 1 } ) ).toBeVisible();
		await context.storageState( { path: storageFile( 'admin' ) } );
		await context.close();
	} finally {
		await browser.close();
	}
}

/**
 * Local's one-click admin login URL. It is a credential, so it is never logged.
 */
function adminLoginUrl(): string {
	const script = path.join( homedir(), '.agents/skills/local-wordpress-login/scripts/resolve-login.mjs' );
	const out = execFileSync( 'node', [ script, '--path', path.resolve( WP_ROOT, '../..' ), '--json' ], { encoding: 'utf8' } );
	const { loginUrl } = JSON.parse( out ) as { loginUrl: string };
	return loginUrl;
}

function phpArray( roles: typeof ROLES ): string {
	return `[ ${ Object.entries( roles )
		.map( ( [ key, [ role, forum ] ] ) => `'${ key }' => [ '${ role }', '${ forum }' ]` )
		.join( ', ' ) } ]`;
}
