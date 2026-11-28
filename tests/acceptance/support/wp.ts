import { execFileSync } from 'node:child_process';
import { homedir } from 'node:os';
import path from 'node:path';

export const PLUGIN_DIR = path.resolve( __dirname, '../../..' );
export const WP_ROOT = path.resolve( PLUGIN_DIR, '../../..' );
export const MU_PLUGIN = path.join( WP_ROOT, 'wp-content/mu-plugins/jct-e2e-harness.php' );
export const AUTH_DIR = path.join( PLUGIN_DIR, 'tests/acceptance/.auth' );
export const BASE_URL = process.env.JCT_E2E_BASE_URL ?? 'http://plugins.local/subsite30';

const WRAPPER = process.env.JCT_E2E_WP ?? path.join( homedir(), '.agents/skills/wp-cli-local/scripts/wp' );
const MARKER = '@@JCT@@';

/**
 * Run a WP-CLI command on the test site through the Local wrapper.
 */
export function wp( args: string[], env: Record<string, string> = {} ): string {
	try {
		return execFileSync( 'bash', [ WRAPPER, `--url=${ BASE_URL }`, ...args ], {
			cwd: WP_ROOT,
			env: { ...process.env, ...env },
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'pipe' ],
			timeout: 170_000,
		} );
	} catch ( error ) {
		const e = error as { stderr?: string; stdout?: string; message: string };
		throw new Error( `WP-CLI failed: wp ${ args[ 0 ] } ${ args[ 1 ] ?? '' }\n${ e.stderr ?? '' }${ e.stdout ?? '' }` );
	}
}

/**
 * Run a PHP function body on the site and return its JSON-decoded return value.
 */
export function php<T = unknown>( body: string, env: Record<string, string> = {} ): T {
	const code = `namespace Soderlind\\Plugin\\JevCommentTriage; echo "\\n${ MARKER }" . wp_json_encode( ( function () { ${ body } } )() );`;
	const out = wp( [ 'eval', code ], env );
	const line = out.split( '\n' ).find( ( l ) => l.startsWith( MARKER ) );
	if ( undefined === line ) {
		throw new Error( `No result from PHP:\n${ out }` );
	}
	return JSON.parse( line.slice( MARKER.length ) ) as T;
}

export type ItemType = 'comment' | 'topic' | 'reply';

export interface ItemState {
	status: string;
	queued: boolean;
	jev: string | null;
}

/**
 * Read an item's status, whether it waits in the triage queue, and its Jev column text.
 */
export function itemState( type: ItemType, id: number ): ItemState {
	return php<ItemState>( `
		$type = ${ phpStr( type ) };
		$id   = ${ id };
		if ( 'comment' === $type ) {
			clean_comment_cache( $id );
			$status = (string) wp_get_comment_status( $id );
			$data   = get_comment_meta( $id, META_KEY, true );
			$queued = '' !== (string) get_comment_meta( $id, PENDING_META, true );
		} else {
			clean_post_cache( $id );
			$status = (string) get_post_status( $id );
			$data   = get_post_meta( $id, META_KEY, true );
			$queued = '' !== (string) get_post_meta( $id, PENDING_META, true );
		}
		return [
			'status' => $status,
			'queued' => $queued && ! is_array( $data ),
			'jev'    => is_array( $data ) ? column_text( $data ) : null,
		];
	` );
}

/**
 * Run the background drain until the item has a Jev result, then return its state.
 */
export function drainUntilJudged( type: ItemType, id: number, attempts = 5 ): ItemState {
	let state = itemState( type, id );
	for ( let i = 0; i < attempts && ! state.jev; i++ ) {
		wp( [ 'cron', 'event', 'run', 'jct_drain' ] );
		state = itemState( type, id );
	}
	return state;
}

/**
 * Quote a value as a PHP single-quoted string literal, so `$` is never interpolated.
 */
export function phpStr( value: string ): string {
	return `'${ value.replace( /\\/g, '\\\\' ).replace( /'/g, "\\'" ) }'`;
}
