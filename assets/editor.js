/**
 * Agent changes, in the editor.
 *
 * When an agent changed the post, the Document sidebar gets a panel that says
 * who and when, shows what changed, and offers "Undo this change" (this post
 * only). When a request is held on the post, approvers see it with Approve and
 * Reject. The server decides what a person may see: the REST routes answer 403
 * for a post they cannot edit, and then nothing is drawn at all.
 *
 * JS hooks (wp.hooks):
 *
 * - filter agentGovernance.editorPanelTitle ( title ): the panel's heading.
 * - action agentGovernance.changeUndone ( postId, action ): a change was undone.
 * - action agentGovernance.requestDecided ( requestId, decision ): a request was approved or rejected.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data || ! wp.apiFetch ) {
		return;
	}

	const el = wp.element.createElement;
	const Fragment = wp.element.Fragment;
	const useState = wp.element.useState;
	const useEffect = wp.element.useEffect;
	const __ = wp.i18n.__;
	const sprintf = wp.i18n.sprintf;
	const components = wp.components;
	const Button = components.Button;
	const Spinner = components.Spinner;
	const Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	const base = ( window.agentGovernanceEditor && window.agentGovernanceEditor.base ) || '/agent-governance/v1';

	if ( ! Panel ) {
		return;
	}

	function speak( text ) {
		if ( wp.a11y && wp.a11y.speak ) {
			wp.a11y.speak( text, 'polite' );
		}
	}

	/** One field's before and after, each side labelled for people who cannot see the colours. */
	function Diff( props ) {
		return el(
			'div',
			{ className: 'agg-editor-diff' },
			el( 'strong', { className: 'agg-editor-diff__field' }, props.field ),
			el(
				'div',
				{ className: 'agg-editor-diff__side agg-editor-diff__side--before' },
				el( 'span', { className: 'agg-editor-diff__label' }, __( 'Before', 'agent-governance' ) ),
				el( 'div', { className: 'agg-editor-diff__text', dir: 'auto' }, props.before || __( '(empty)', 'agent-governance' ) )
			),
			el(
				'div',
				{ className: 'agg-editor-diff__side agg-editor-diff__side--after' },
				el( 'span', { className: 'agg-editor-diff__label' }, __( 'After', 'agent-governance' ) ),
				el( 'div', { className: 'agg-editor-diff__text', dir: 'auto' }, props.after || __( '(empty)', 'agent-governance' ) )
			)
		);
	}

	function Fields( props ) {
		return el(
			'div',
			{ className: 'agg-editor-fields' },
			props.fields.map( function ( f, i ) {
				return el( Diff, { key: i, field: f.field, before: f.before, after: f.after } );
			} )
		);
	}

	function errorText( error ) {
		return ( error && error.message ) || __( 'That did not work.', 'agent-governance' );
	}

	/** The latest change by an agent, with View changes and Undo. */
	function Change( props ) {
		const change = props.change;
		const dirty = props.dirty;
		const view = useState( false );
		const confirm = useState( false );
		const busy = useState( false );
		const error = useState( '' );
		const stale = useState( false );

		function undo( force ) {
			busy[ 1 ]( true );
			error[ 1 ]( '' );
			wp.apiFetch( {
				path: base + '/posts/' + props.postId + '/undo',
				method: 'POST',
				data: { log_id: change.log_id, force: !! force },
			} ).then(
				function ( result ) {
					busy[ 1 ]( false );
					confirm[ 1 ]( false );
					speak( __( 'The change was undone. Reload the editor to see the post as it is now.', 'agent-governance' ) );
					if ( wp.hooks ) {
						wp.hooks.doAction( 'agentGovernance.changeUndone', props.postId, result.action );
					}
					props.onDone( true );
				},
				function ( e ) {
					busy[ 1 ]( false );
					if ( e && 'agent_governance_edited_since' === e.code ) {
						stale[ 1 ]( true );
						return;
					}
					error[ 1 ]( errorText( e ) );
					speak( errorText( e ) );
				}
			);
		}

		const heading = 'created' === change.action
			/* translators: 1: agent name, 2: time such as "2 hours ago". */
			? sprintf( __( '%1$s created this post %2$s.', 'agent-governance' ), change.agent, change.ago )
			/* translators: 1: agent name, 2: time such as "2 hours ago". */
			: sprintf( __( '%1$s changed this %2$s.', 'agent-governance' ), change.agent, change.ago );

		if ( change.undone ) {
			return el(
				'div',
				{ className: 'agg-editor-change' },
				/* translators: 1: agent name, 2: time such as "2 hours ago". */
				el( 'p', null, sprintf( __( 'The change by %1$s was undone %2$s. Reload the editor if it still shows the old version.', 'agent-governance' ), change.agent, change.undone_ago ) )
			);
		}

		return el(
			'div',
			{ className: 'agg-editor-change' },
			el( 'p', { className: 'agg-editor-change__line' }, heading ),
			change.edited_since || stale[ 0 ]
				? el(
					'div',
					{ className: 'agg-editor-warning', role: 'note' },
					__( 'Somebody saved this post after the agent changed it. Undoing now would also remove their changes. What the post is now stays in its revisions.', 'agent-governance' )
				)
				: null,
			change.fields.length || 'created' === change.action
				? el(
					'div',
					null,
					el(
						Button,
						{
							variant: 'link',
							'aria-expanded': view[ 0 ],
							onClick: function () {
								view[ 1 ]( ! view[ 0 ] );
							},
						},
						view[ 0 ] ? __( 'Hide changes', 'agent-governance' ) : __( 'View changes', 'agent-governance' )
					),
					view[ 0 ]
						? 'created' === change.action
							? el( 'p', { className: 'agg-editor-note' }, __( 'The agent created this post. Undo moves it to the trash.', 'agent-governance' ) )
							: el( Fields, { fields: change.fields } )
						: null
				)
				: null,
			change.note ? el( 'p', { className: 'agg-editor-note' }, change.note ) : null,
			error[ 0 ] ? el( 'p', { className: 'agg-editor-error', role: 'alert' }, error[ 0 ] ) : null,
			change.can_undo
				? el(
					'div',
					{ className: 'agg-editor-actions' },
					dirty
						? el( 'p', { className: 'agg-editor-note' }, __( 'Save your changes first. Undoing while the editor holds unsaved edits would let the next save bring the agent’s version back.', 'agent-governance' ) )
						: null,
					confirm[ 0 ] || stale[ 0 ] || change.edited_since
						? el(
							'div',
							{ className: 'agg-editor-confirm', role: 'group', 'aria-label': __( 'Confirm undo', 'agent-governance' ) },
							el( 'p', null, 'created' === change.action
								? __( 'Move this post to the trash?', 'agent-governance' )
								: __( 'Put this post back as it was before the agent changed it?', 'agent-governance' ) ),
							el(
								Button,
								{
									variant: 'primary',
									isDestructive: true,
									isBusy: busy[ 0 ],
									disabled: busy[ 0 ] || dirty,
									onClick: function () {
										undo( change.edited_since || stale[ 0 ] );
									},
								},
								change.edited_since || stale[ 0 ] ? __( 'Undo anyway', 'agent-governance' ) : __( 'Undo this change', 'agent-governance' )
							),
							el(
								Button,
								{
									variant: 'tertiary',
									disabled: busy[ 0 ],
									onClick: function () {
										confirm[ 1 ]( false );
										stale[ 1 ]( false );
									},
								},
								__( 'Cancel', 'agent-governance' )
							)
						)
						: el(
							Button,
							{
								variant: 'secondary',
								disabled: dirty,
								onClick: function () {
									confirm[ 1 ]( true );
								},
							},
							__( 'Undo this change', 'agent-governance' )
						)
				)
				: null
		);
	}

	/** A request held on this post, with Approve and Reject. */
	function Held( props ) {
		const request = props.request;
		const dirty = props.dirty;
		const mode = useState( '' );
		const reason = useState( '' );
		const busy = useState( false );
		const error = useState( '' );

		function decide( decision ) {
			busy[ 1 ]( true );
			error[ 1 ]( '' );
			wp.apiFetch( {
				path: base + '/requests/' + request.id + '/decide',
				method: 'POST',
				data: { decision: decision, reason: reason[ 0 ] },
			} ).then(
				function ( result ) {
					busy[ 1 ]( false );
					mode[ 1 ]( '' );
					speak( 'approve' === decision ? __( 'Approved. The call ran. Reload the editor to see the change.', 'agent-governance' ) : __( 'Rejected. The agent will see your reason.', 'agent-governance' ) );
					if ( wp.hooks ) {
						wp.hooks.doAction( 'agentGovernance.requestDecided', request.id, decision );
					}
					props.onDone( 'approve' === decision, result );
				},
				function ( e ) {
					busy[ 1 ]( false );
					error[ 1 ]( errorText( e ) );
					speak( errorText( e ) );
				}
			);
		}

		return el(
			'div',
			{ className: 'agg-editor-held' },
			/* translators: 1: agent name, 2: time such as "2 hours ago". */
			el( 'p', { className: 'agg-editor-change__line' }, sprintf( __( '%1$s asked to change this post %2$s. It waits for approval.', 'agent-governance' ), request.agent, request.ago ) ),
			request.summary ? el( 'p', null, request.summary ) : null,
			request.fields.length ? el( Fields, { fields: request.fields } ) : null,
			el( 'p', null, el( 'a', { href: request.url }, __( 'Open the full request', 'agent-governance' ) ) ),
			error[ 0 ] ? el( 'p', { className: 'agg-editor-error', role: 'alert' }, error[ 0 ] ) : null,
			dirty
				? el( 'p', { className: 'agg-editor-note' }, __( 'Save your changes first. Approving changes this post, and the next save would overwrite it.', 'agent-governance' ) )
				: null,
			'approve' === mode[ 0 ]
				? el(
					'div',
					{ className: 'agg-editor-confirm', role: 'group', 'aria-label': __( 'Confirm approval', 'agent-governance' ) },
					el( 'p', null, __( 'Approving runs the call now, as the user the agent signed in as. It cannot be undone from here.', 'agent-governance' ) ),
					el( Button, { variant: 'primary', isBusy: busy[ 0 ], disabled: busy[ 0 ] || dirty, onClick: function () { decide( 'approve' ); } }, __( 'Approve and run', 'agent-governance' ) ),
					el( Button, { variant: 'tertiary', disabled: busy[ 0 ], onClick: function () { mode[ 1 ]( '' ); } }, __( 'Cancel', 'agent-governance' ) )
				)
				: 'reject' === mode[ 0 ]
					? el(
						'div',
						{ className: 'agg-editor-confirm', role: 'group', 'aria-label': __( 'Confirm rejection', 'agent-governance' ) },
						el( 'label', { htmlFor: 'agg-editor-reason-' + request.id }, __( 'Reason, shown to the agent', 'agent-governance' ) ),
						el( 'textarea', {
							id: 'agg-editor-reason-' + request.id,
							className: 'components-textarea-control__input',
							rows: 3,
							value: reason[ 0 ],
							onChange: function ( e ) {
								reason[ 1 ]( e.target.value );
							},
						} ),
						el( Button, { variant: 'primary', isDestructive: true, isBusy: busy[ 0 ], disabled: busy[ 0 ], onClick: function () { decide( 'reject' ); } }, __( 'Reject this call', 'agent-governance' ) ),
						el( Button, { variant: 'tertiary', disabled: busy[ 0 ], onClick: function () { mode[ 1 ]( '' ); } }, __( 'Cancel', 'agent-governance' ) )
					)
					: el(
						'div',
						{ className: 'agg-editor-actions' },
						el( Button, { variant: 'primary', disabled: dirty, onClick: function () { mode[ 1 ]( 'approve' ); } }, __( 'Approve', 'agent-governance' ) ),
						el( Button, { variant: 'secondary', isDestructive: true, onClick: function () { mode[ 1 ]( 'reject' ); } }, __( 'Reject', 'agent-governance' ) )
					)
		);
	}

	function AgentChangesPanel() {
		const postId = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		}, [] );
		const dirty = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).isEditedPostDirty();
		}, [] );
		const state = useState( null );
		const reload = useState( false );

		function load() {
			if ( ! postId ) {
				return;
			}
			wp.apiFetch( { path: base + '/posts/' + postId } ).then(
				function ( data ) {
					state[ 1 ]( data );
				},
				function () {
					// Not allowed, or no longer there: nothing is shown.
					state[ 1 ]( { change: null, requests: [] } );
				}
			);
		}

		useEffect( load, [ postId ] );

		// A panel starts closed, whatever initialOpen says: open it once when there is something to read.
		const hasNews = !! ( state[ 0 ] && ( state[ 0 ].change || state[ 0 ].requests.length ) );
		useEffect(
			function () {
				if ( ! hasNews ) {
					return;
				}
				const editor = wp.data.select( 'core/editor' );
				const name = 'agent-governance/agent-governance';
				if ( editor && editor.isEditorPanelOpened && ! editor.isEditorPanelOpened( name ) ) {
					wp.data.dispatch( 'core/editor' ).toggleEditorPanelOpened( name );
				}
			},
			[ hasNews ]
		);

		// Say it once, where people look first: a notice above the content.
		useEffect(
			function () {
				if ( ! state[ 0 ] || ! wp.data.dispatch( 'core/notices' ) ) {
					return;
				}
				const notices = wp.data.dispatch( 'core/notices' );
				const change = state[ 0 ].change;
				if ( change && ! change.undone ) {
					notices.createNotice(
						'info',
						'created' === change.action
							/* translators: 1: agent name, 2: time such as "2 hours ago". */
							? sprintf( __( '%1$s created this post %2$s.', 'agent-governance' ), change.agent, change.ago )
							/* translators: 1: agent name, 2: time such as "2 hours ago". */
							: sprintf( __( '%1$s changed this %2$s.', 'agent-governance' ), change.agent, change.ago ),
						{
							id: 'agent-governance-change',
							isDismissible: true,
							actions: [
								{
									label: __( 'View changes', 'agent-governance' ),
									onClick: function () {
										const post = wp.data.dispatch( 'core/edit-post' );
										if ( post && post.openGeneralSidebar ) {
											post.openGeneralSidebar( 'edit-post/document' );
										}
									},
								},
							],
						}
					);
				}
				if ( state[ 0 ].requests.length ) {
					notices.createNotice(
						'warning',
						__( 'A request to change this post is waiting for approval.', 'agent-governance' ),
						{
							id: 'agent-governance-held',
							isDismissible: true,
							actions: [
								{
									label: __( 'Review it', 'agent-governance' ),
									onClick: function () {
										const post = wp.data.dispatch( 'core/edit-post' );
										if ( post && post.openGeneralSidebar ) {
											post.openGeneralSidebar( 'edit-post/document' );
										}
									},
								},
							],
						}
					);
				}
			},
			[ state[ 0 ] && state[ 0 ].change && state[ 0 ].change.log_id, state[ 0 ] && state[ 0 ].requests.length ]
		);

		if ( ! state[ 0 ] || ( ! state[ 0 ].change && ! state[ 0 ].requests.length ) ) {
			return null;
		}

		const title = wp.hooks ? wp.hooks.applyFilters( 'agentGovernance.editorPanelTitle', __( 'Agent changes', 'agent-governance' ) ) : __( 'Agent changes', 'agent-governance' );

		return el(
			Panel,
			{ name: 'agent-governance', title: title, className: 'agg-editor-panel', initialOpen: true },
			state[ 0 ].requests.map( function ( request ) {
				return el( Held, {
					key: request.id,
					request: request,
					dirty: dirty,
					onDone: function ( approved ) {
						if ( approved ) {
							reload[ 1 ]( true );
						}
						load();
					},
				} );
			} ),
			state[ 0 ].change
				? el( Change, {
					postId: postId,
					change: state[ 0 ].change,
					dirty: dirty,
					onDone: function () {
						reload[ 1 ]( true );
						load();
					},
				} )
				: null,
			reload[ 0 ]
				? el(
					'p',
					{ className: 'agg-editor-reload' },
					el(
						Button,
						{
							variant: 'secondary',
							onClick: function () {
								window.location.reload();
							},
						},
						__( 'Reload the editor', 'agent-governance' )
					)
				)
				: null
		);
	}

	wp.plugins.registerPlugin( 'agent-governance', { render: AgentChangesPanel } );
}( window.wp ) );
