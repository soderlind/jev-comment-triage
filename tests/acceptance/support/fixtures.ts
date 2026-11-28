import { test as base, expect, type Browser, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { BASE_URL, php, phpStr } from './wp';
import { type Role, type SuiteState, STATE_FILE, storageFile } from './state';

interface Fixtures {
	suite: SuiteState;
	as: ( role: Role, options?: { providerOff?: boolean } ) => Promise<Page>;
}

export const test = base.extend<Fixtures>( {
	// eslint-disable-next-line no-empty-pattern
	suite: async ( {}, use ) => {
		await use( JSON.parse( readFileSync( STATE_FILE, 'utf8' ) ) as SuiteState );
	},
	as: async ( { browser }, use ) => {
		const contexts: Awaited<ReturnType<Browser[ 'newContext' ]>>[] = [];
		await use( async ( role, options = {} ) => {
			const context = await browser.newContext( { storageState: storageFile( role ) } );
			if ( options.providerOff ) {
				await context.addCookies( [ { name: 'jct_e2e_provider_off', value: '1', url: BASE_URL } ] );
			}
			contexts.push( context );
			return context.newPage();
		} );
		await Promise.all( contexts.map( ( c ) => c.close() ) );
	},
} );

export { expect };

/** A short unique reference that keeps kept forum content distinguishable between runs. */
export const ref = (): string => Math.random().toString( 36 ).slice( 2, 8 );

// WordPress and bbPress reject a repeated body from the same author, and rows persist across runs,
// so every submitted body carries a per-test ref that also serves as its lookup key.
export const tagged = ( text: string, r: string ): string => `${ text } (ref ${ r })`;

export function permalink( id: number ): string {
	return php<string>( `return get_permalink( ${ id } );` );
}

export function findPost( type: 'topic' | 'reply', authorId: number, needle: string ): number {
	return php<number>( `
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d AND ( post_title LIKE %s OR post_content LIKE %s ) ORDER BY ID DESC LIMIT 1",
			${ phpStr( type ) }, ${ authorId }, '%' . $wpdb->esc_like( ${ phpStr( needle ) } ) . '%', '%' . $wpdb->esc_like( ${ phpStr( needle ) } ) . '%'
		) );
	` );
}

/** Seed a published topic directly, bypassing intake, as the given author. */
export function seedTopic( forumId: number, authorId: number, title: string, body: string, judged = false ): number {
	return php<number>( `
		$id = bbp_insert_topic(
			[ 'post_parent' => ${ forumId }, 'post_author' => ${ authorId }, 'post_title' => ${ phpStr( title ) }, 'post_content' => ${ phpStr( body ) }, 'post_status' => bbp_get_public_status_id() ],
			[ 'forum_id' => ${ forumId } ]
		);
		${ judged ? `update_post_meta( $id, META_KEY, ${ assessment( '1' ) } );` : '' }
		return (int) $id;
	` );
}

/** Seed a published reply directly, bypassing intake, as the given author. */
export function seedReply( forumId: number, topicId: number, authorId: number, title: string, body: string, judged = false ): number {
	return php<number>( `
		$id = bbp_insert_reply(
			[ 'post_parent' => ${ topicId }, 'post_author' => ${ authorId }, 'post_title' => ${ phpStr( title ) }, 'post_content' => ${ phpStr( body ) }, 'post_status' => bbp_get_public_status_id() ],
			[ 'forum_id' => ${ forumId }, 'topic_id' => ${ topicId } ]
		);
		${ judged ? `update_post_meta( $id, META_KEY, ${ assessment( '1' ) } );` : '' }
		return (int) $id;
	` );
}

/** Seed an approved comment directly, bypassing intake. */
export function seedComment( postId: number, userId: number, body: string, judged = false ): number {
	return php<number>( `
		$user = get_userdata( ${ userId } );
		$id   = wp_insert_comment(
			[ 'comment_post_ID' => ${ postId }, 'user_id' => ${ userId }, 'comment_author' => $user->display_name, 'comment_author_email' => $user->user_email, 'comment_content' => ${ phpStr( body ) }, 'comment_approved' => 1 ]
		);
		${ judged ? `update_comment_meta( $id, META_KEY, ${ assessment( '1' ) } );` : '' }
		return (int) $id;
	` );
}

export function rawJevMeta( type: 'comment' | 'topic' | 'reply', id: number ): string {
	return php<string>( `return maybe_serialize( 'comment' === ${ phpStr( type ) } ? get_comment_meta( ${ id }, META_KEY, true ) : get_post_meta( ${ id }, META_KEY, true ) );` );
}

function assessment( decision: '1' | '0' | 'spam' ): string {
	return `[ 'version' => SCHEMA_VERSION, 'source' => 'jev', 'relevance' => [ 'choice' => 'on_topic', 'confidence' => 0.97 ], 'spam' => 0.01, 'abusive' => 0.0, 'link_count' => 0, 'decision' => '${ decision }' ]`;
}

export const JEV_APPROVED = /on-topic [\d.]+ · spam [\d.]+ · abuse [\d.]+ → approved/;
export const JEV_SPAM = /→ spam/;

/** A row on a wp-admin list screen, found by visible text. */
export function listRow( page: Page, text: string | RegExp ) {
	return page.getByRole( 'row' ).filter( { hasText: text } );
}

/**
 * bbPress's topic and reply content textareas have no accessible name, so they are
 * located by their form field name.
 */
export function forumContent( page: Page, kind: 'topic' | 'reply' ) {
	return page.locator( `textarea[name="bbp_${ kind }_content"]` );
}

export function editUrl( kind: 'topic' | 'reply', id: number ): string {
	return php<string>( `return bbp_get_${ kind }_edit_url( ${ id } );` );
}
