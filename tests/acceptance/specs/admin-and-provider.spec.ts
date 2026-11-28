import { itemState } from '../support/wp';
import { ON_TOPIC_REPLY, PROVIDER_OFF_TOPIC_BODY, SEEDED_TOPIC_BODY } from '../support/state';
import { expect, findPost, forumContent, listRow, permalink, ref, seedComment, seedReply, seedTopic, tagged, test } from '../support/fixtures';

const SEEDED = 'on-topic 0.97 · spam 0.01 · abuse 0.00 → approved';

test.describe( 'Admin visibility', () => {
	test( 'AC-09 the Jev column shows the decision on Comments, Topics, and Replies', async ( { as, suite } ) => {
		const r = ref();
		const title = `Column check for hook priorities (${ r })`;
		const comment = tagged( 'Column check: priority 5 runs before the default 10.', r );
		seedComment( suite.postId, suite.users.subscriber.id, comment, true );
		const topic = seedTopic( suite.forumId, suite.users.subscriber.id, title, tagged( SEEDED_TOPIC_BODY, r ), true );
		seedReply( suite.forumId, topic, suite.users.subscriber.id, `Reply To: ${ title }`, tagged( ON_TOPIC_REPLY, r ), true );

		const admin = await as( 'admin' );
		const screens: Array<[ string, string ]> = [
			[ `wp-admin/edit-comments.php?p=${ suite.postId }`, comment ],
			[ `wp-admin/edit.php?post_type=topic&s=${ encodeURIComponent( title ) }`, title ],
			// Reply titles can be empty, so replies are found by the topic shown in their row.
			[ `wp-admin/edit.php?post_type=reply&bbp_forum_id=${ suite.forumId }`, title ],
		];
		for ( const [ url, text ] of screens ) {
			await admin.goto( url );
			await expect( admin.getByRole( 'columnheader', { name: 'Jev' } ).first() ).toBeVisible();
			await expect( listRow( admin, text ) ).toContainText( SEEDED );
		}
	} );
} );

test.describe( 'Provider not configured', () => {
	test( 'AC-10 a topic is published immediately and never queued', async ( { as, suite } ) => {
		const r = ref();
		const title = `Is add_action order guaranteed? (${ r })`;
		const page = await as( 'subscriber', { providerOff: true } );
		await page.goto( permalink( suite.forumId ) );
		await page.getByRole( 'textbox', { name: /Topic Title/ } ).fill( title );
		await forumContent( page, 'topic' ).fill( tagged( PROVIDER_OFF_TOPIC_BODY, r ) );
		await page.getByRole( 'button', { name: 'Submit' } ).click();

		await expect( page.getByRole( 'heading', { name: title } ) ).toBeVisible();
		await expect( page.getByText( 'Your topic is pending moderation.' ) ).toHaveCount( 0 );
		const id = findPost( 'topic', suite.users.subscriber.id, title );
		expect( itemState( 'topic', id ) ).toEqual( { status: 'publish', queued: false, jev: null } );
	} );
} );
