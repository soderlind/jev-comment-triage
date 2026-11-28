import { drainUntilJudged, itemState } from '../support/wp';
import { ON_TOPIC_COMMENT, SPAM_EDIT_TEXT, SPAM_TEXT } from '../support/state';
import { expect, JEV_APPROVED, JEV_SPAM, listRow, permalink, rawJevMeta, ref, seedComment, tagged, test } from '../support/fixtures';

const commentsScreen = ( status: string, postId: number ) => `wp-admin/edit-comments.php?comment_status=${ status }&p=${ postId }`;

test.describe( 'Comment submission', () => {
	test( 'AC-01 an on-topic comment is held, then approved with a Jev result', async ( { as, suite } ) => {
		const r = ref();
		const page = await as( 'subscriber' );
		await page.goto( permalink( suite.postId ) );
		await page.getByRole( 'textbox', { name: 'Comment' } ).fill( tagged( ON_TOPIC_COMMENT, r ) );
		await page.getByRole( 'button', { name: 'Post Comment' } ).click();

		await expect( page.getByText( 'Your comment is awaiting moderation.' ) ).toBeVisible();
		const id = Number( new URL( page.url() ).hash.replace( '#comment-', '' ) );
		expect( itemState( 'comment', id ) ).toMatchObject( { status: 'unapproved', queued: true } );

		expect( drainUntilJudged( 'comment', id ).status ).toBe( 'approved' );

		const admin = await as( 'admin' );
		await admin.goto( commentsScreen( 'approved', suite.postId ) );
		await expect( admin.getByRole( 'columnheader', { name: 'Jev' } ).first() ).toBeVisible();
		await expect( listRow( admin, r ) ).toContainText( JEV_APPROVED );
	} );

	test( 'AC-02 an obvious spam comment ends up in Spam', async ( { as, suite } ) => {
		const r = ref();
		const page = await as( 'subscriber' );
		await page.goto( permalink( suite.postId ) );
		await page.getByRole( 'textbox', { name: 'Comment' } ).fill( tagged( SPAM_TEXT, r ) );
		await page.getByRole( 'button', { name: 'Post Comment' } ).click();

		await expect( page.getByText( 'Your comment is awaiting moderation.' ) ).toBeVisible();
		const id = Number( new URL( page.url() ).hash.replace( '#comment-', '' ) );

		expect( drainUntilJudged( 'comment', id ).status ).toBe( 'spam' );

		const admin = await as( 'admin' );
		await admin.goto( commentsScreen( 'spam', suite.postId ) );
		await expect( listRow( admin, r ) ).toContainText( JEV_SPAM );
	} );
} );

test.describe( 'Comment edits', () => {
	test( 'AC-03 an author editing a comment into spam holds it, then Jev marks it spam', async ( { as, suite } ) => {
		const r = ref();
		const id = seedComment( suite.postId, suite.users.subscriber.id, tagged( 'Good overview of hook priorities, thanks for writing it up.', r ) );

		const author = await as( 'author' );
		await author.goto( `wp-admin/comment.php?action=editcomment&c=${ id }` );
		await author.getByRole( 'textbox', { name: 'Comment', exact: true } ).fill( tagged( SPAM_EDIT_TEXT, r ) );
		await author.getByRole( 'button', { name: 'Update' } ).click();
		await author.waitForURL( /edit-comments\.php/ );

		const admin = await as( 'admin' );
		await admin.goto( commentsScreen( 'moderated', suite.postId ) );
		await expect( listRow( admin, r ) ).toBeVisible();
		expect( itemState( 'comment', id ) ).toMatchObject( { status: 'unapproved', queued: true } );

		expect( drainUntilJudged( 'comment', id ).status ).toBe( 'spam' );
		await admin.goto( commentsScreen( 'spam', suite.postId ) );
		await expect( listRow( admin, r ) ).toContainText( JEV_SPAM );
	} );

	test( 'AC-04 an admin edit is not re-triaged', async ( { as, suite } ) => {
		const r = ref();
		const id = seedComment( suite.postId, suite.users.subscriber.id, tagged( 'Priority 20 fixed my filter ordering problem.', r ), true );
		const before = rawJevMeta( 'comment', id );

		const admin = await as( 'admin' );
		await admin.goto( `wp-admin/comment.php?action=editcomment&c=${ id }` );
		await admin.getByRole( 'textbox', { name: 'Comment', exact: true } ).fill( `${ tagged( 'Priority 20 fixed my filter ordering problem.', r ) } Edited by an admin.` );
		await admin.getByRole( 'button', { name: 'Update' } ).click();
		await admin.waitForURL( /edit-comments\.php/ );

		expect( itemState( 'comment', id ) ).toMatchObject( { status: 'approved', queued: false } );
		expect( rawJevMeta( 'comment', id ) ).toBe( before );
		await admin.goto( commentsScreen( 'approved', suite.postId ) );
		await expect( listRow( admin, r ) ).toContainText( JEV_APPROVED );
	} );

	test( 'AC-04 saving a comment without changing the text is not re-triaged', async ( { as, suite } ) => {
		const id = seedComment( suite.postId, suite.users.subscriber.id, tagged( 'The default priority of 10 surprised me too.', ref() ), true );
		const before = rawJevMeta( 'comment', id );

		const author = await as( 'author' );
		await author.goto( `wp-admin/comment.php?action=editcomment&c=${ id }` );
		await author.getByRole( 'button', { name: 'Update' } ).click();
		await author.waitForURL( /edit-comments\.php/ );

		expect( itemState( 'comment', id ) ).toMatchObject( { status: 'approved', queued: false } );
		expect( rawJevMeta( 'comment', id ) ).toBe( before );
	} );
} );
