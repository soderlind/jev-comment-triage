import path from 'node:path';
import { AUTH_DIR } from './wp';

export type Role = 'admin' | 'subscriber' | 'author' | 'moderator';

export interface SuiteState {
	token: string;
	users: Record<Exclude<Role, 'admin'>, { id: number; login: string }>;
	forumId: number;
	postId: number;
	previousModeration: string;
}

export const STATE_FILE = path.join( AUTH_DIR, 'state.json' );

export const storageFile = ( role: Role ): string => path.join( AUTH_DIR, `${ role }.json` );

export const FORUM_TEXT =
	'A forum for WordPress plugin developers. Ask and answer questions about action hooks, filters, ' +
	'hook priorities, WP-Cron, the REST API, and debugging PHP in custom plugins.';

export const POST_TEXT =
	'WordPress plugins change behaviour through hooks. An action runs code at a specific point, for example ' +
	'add_action( "init", "my_setup" ). A filter receives a value, changes it, and must return it, for example ' +
	'add_filter( "the_title", "my_title" ). The optional priority argument decides the order callbacks run in: ' +
	'lower numbers run first, and the default is 10.';

export const ON_TOPIC_COMMENT =
	'Thanks, this cleared up my confusion between actions and filters. My filter callback forgot to return the ' +
	'value, so the post title disappeared. Returning it and setting priority 20 so it runs after the theme fixed it.';

export const ON_TOPIC_TOPIC_BODY =
	'I add a filter to the_content in my plugin, but another plugin runs later and overwrites my change. ' +
	'Should I raise the hook priority, or is there a better way to make sure my filter callback runs last?';

// bbPress rejects a second topic with the same author, forum, and body, so each test that
// creates a topic in the shared forum uses its own body.
export const SEEDED_TOPIC_BODY =
	'My plugin hooks into save_post, but the callback runs twice when a post is updated. ' +
	'Is that expected, and how do I make sure my code only runs once per save?';

export const PROVIDER_OFF_TOPIC_BODY =
	'If two plugins call add_action on init with the same priority, is the order they run in guaranteed, ' +
	'or does it depend on which plugin WordPress loads first?';

export const ON_TOPIC_REPLY =
	'Use a higher priority such as 99 in add_filter so your callback runs after the other plugin. ' +
	'You can check which callbacks are attached with the global $wp_filter while debugging.';

export const SPAM_EDIT_TEXT =
	'CHEAP REPLICA WATCHES and DESIGNER BAGS at 95% OFF!!! Free shipping worldwide, limited offer, ' +
	'order now at http://replica-watches-deal.example/shop and http://replica-watches-deal.example/sale';

export const SPAM_TEXT =
	'BUY CHEAP VIAGRA AND CIALIS ONLINE!!! 90% OFF, no prescription needed. Best online casino bonus and ' +
	'fast payday loans. Click here now: http://cheap-pills-casino.example/buy-now http://cheap-pills-casino.example/win';
