import { drainUntilJudged, itemState } from '../support/wp';
import { ON_TOPIC_REPLY, ON_TOPIC_TOPIC_BODY, SEEDED_TOPIC_BODY, SPAM_TEXT } from '../support/state';
import {
	editUrl,
	expect,
	findPost,
	forumContent,
	JEV_APPROVED,
	JEV_SPAM,
	listRow,
	permalink,
	ref,
	seedReply,
	seedTopic,
	tagged,
	test,
} from '../support/fixtures';

const topicsScreen = ( status: string, title: string ) =>
	`wp-admin/edit.php?post_type=topic&post_status=${ status }&s=${ encodeURIComponent( title ) }`;

// bbPress leaves reply titles empty, so replies are listed per forum and found by the topic in their row.
const repliesScreen = ( status: string, forumId: number ) =>
	`wp-admin/edit.php?post_type=reply&post_status=${ status }&bbp_forum_id=${ forumId }`;

test.describe( 'Forum submission', () => {
	test( 'AC-05 a new topic is pending, then published after triage', async ( { as, suite } ) => {
		const r = ref();
		const title = `Making my the_content filter run last (${ r })`;
		const page = await as( 'subscriber' );
		await page.goto( permalink( suite.forumId ) );
		await page.getByRole( 'textbox', { name: /Topic Title/ } ).fill( title );
		await forumContent( page, 'topic' ).fill( tagged( ON_TOPIC_TOPIC_BODY, r ) );
		await page.getByRole( 'button', { name: 'Submit' } ).click();

		await expect( page.getByText( 'Your topic is pending moderation.' ) ).toBeVisible();
		const id = findPost( 'topic', suite.users.subscriber.id, title );
		expect( itemState( 'topic', id ) ).toMatchObject( { status: 'pending', queued: true } );

		expect( drainUntilJudged( 'topic', id ).status ).toBe( 'publish' );

		const admin = await as( 'admin' );
		await admin.goto( topicsScreen( 'publish', title ) );
		await expect( listRow( admin, title ) ).toContainText( JEV_APPROVED );
	} );

	test( 'AC-06 an on-topic reply is published and a spam reply is marked spam', async ( { as, suite } ) => {
		const r = ref();
		const title = `Checking attached callbacks (${ r })`;
		const topic = seedTopic( suite.forumId, suite.users.subscriber.id, title, tagged( SEEDED_TOPIC_BODY, r ) );

		const page = await as( 'subscriber' );
		await page.goto( permalink( topic ) );
		await forumContent( page, 'reply' ).fill( tagged( ON_TOPIC_REPLY, `${ r }-good` ) );
		await page.getByRole( 'button', { name: 'Submit' } ).click();
		await expect( page.getByText( 'Your reply is pending moderation.' ) ).toBeVisible();
		const good = findPost( 'reply', suite.users.subscriber.id, `${ r }-good` );

		await page.goto( permalink( topic ) );
		await forumContent( page, 'reply' ).fill( tagged( SPAM_TEXT, `${ r }-spam` ) );
		await page.getByRole( 'button', { name: 'Submit' } ).click();
		await expect( page.getByText( 'Your reply is pending moderation.' ) ).toBeVisible();
		const spam = findPost( 'reply', suite.users.subscriber.id, `${ r }-spam` );

		expect( itemState( 'reply', good ) ).toMatchObject( { status: 'pending', queued: true } );
		expect( itemState( 'reply', spam ) ).toMatchObject( { status: 'pending', queued: true } );
		expect( drainUntilJudged( 'reply', good ).status ).toBe( 'publish' );
		expect( drainUntilJudged( 'reply', spam ).status ).toBe( 'spam' );

		const admin = await as( 'admin' );
		await admin.goto( repliesScreen( 'publish', suite.forumId ) );
		await expect( listRow( admin, title ) ).toContainText( JEV_APPROVED );
		await admin.goto( repliesScreen( 'spam', suite.forumId ) );
		await expect( listRow( admin, title ) ).toContainText( JEV_SPAM );
	} );
} );

test.describe( 'Forum edits', () => {
	test( 'AC-07 a reply edited into spam is marked spam, and Not Spam restores it to pending', async ( { as, suite } ) => {
		const r = ref();
		const title = `Debugging hook order (${ r })`;
		const topic = seedTopic( suite.forumId, suite.users.moderator.id, title, tagged( SEEDED_TOPIC_BODY, r ) );
		const reply = seedReply( suite.forumId, topic, suite.users.subscriber.id, `Reply To: ${ title }`, tagged( ON_TOPIC_REPLY, r ) );

		const page = await as( 'subscriber' );
		await page.goto( editUrl( 'reply', reply ) );
		await forumContent( page, 'reply' ).fill( tagged( SPAM_TEXT, r ) );
		await page.getByRole( 'button', { name: 'Submit' } ).click();
		await page.waitForURL( ( url ) => ! url.pathname.endsWith( '/edit/' ) );

		expect( itemState( 'reply', reply ) ).toMatchObject( { status: 'pending', queued: true } );
		expect( drainUntilJudged( 'reply', reply ).status ).toBe( 'spam' );

		const admin = await as( 'admin' );
		await admin.goto( repliesScreen( 'spam', suite.forumId ) );
		const row = listRow( admin, title );
		await expect( row ).toContainText( JEV_SPAM );
		await row.hover();
		await row.getByRole( 'link', { name: 'Not Spam' } ).click();

		await admin.goto( repliesScreen( 'pending', suite.forumId ) );
		await expect( listRow( admin, title ) ).toBeVisible();
		expect( itemState( 'reply', reply ).status ).toBe( 'pending' );
	} );

	test( 'AC-08 a forum moderator is trusted for new replies and edits', async ( { as, suite } ) => {
		const r = ref();
		const title = `Removing a filter added by a theme (${ r })`;
		const topic = seedTopic( suite.forumId, suite.users.subscriber.id, title, tagged( SEEDED_TOPIC_BODY, r ) );

		const page = await as( 'moderator' );
		await page.goto( permalink( topic ) );
		await forumContent( page, 'reply' ).fill( tagged( ON_TOPIC_REPLY, r ) );
		await page.getByRole( 'button', { name: 'Submit' } ).click();
		await expect( page ).toHaveURL( /#post-\d+$/ );
		await expect( page.getByText( 'Your reply is pending moderation.' ) ).toHaveCount( 0 );

		const reply = findPost( 'reply', suite.users.moderator.id, r );
		expect( itemState( 'reply', reply ) ).toEqual( { status: 'publish', queued: false, jev: null } );

		const admin = await as( 'admin' );
		await admin.goto( repliesScreen( 'publish', suite.forumId ) );
		await expect( listRow( admin, title ) ).toContainText( '—' );

		await page.goto( editUrl( 'topic', topic ) );
		await forumContent( page, 'topic' ).fill( `${ tagged( SEEDED_TOPIC_BODY, r ) } Moderator note: see the reply below.` );
		await page.getByRole( 'button', { name: 'Submit' } ).click();
		await page.waitForURL( ( url ) => ! url.pathname.endsWith( '/edit/' ) );

		expect( itemState( 'topic', topic ) ).toMatchObject( { status: 'publish', queued: false } );
	} );
} );
