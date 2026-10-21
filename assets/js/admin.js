/* Media & Products Cleanup \u2014 admin UI (progress bars, AJAX scan/quarantine, survivor override). */
( function () {
	'use strict';
	function showActionError( error ) {
		var wrap = document.querySelector( '.lucidlab-mpc-wrap' );
		if ( ! wrap ) { return; }
		var box = document.getElementById( 'lucidlab-mpc-action-error' );
		if ( ! box ) {
			box = document.createElement( 'div' );
			box.id = 'lucidlab-mpc-action-error';
			box.className = 'notice notice-error inline lucidlab-mpc-notice';
			box.setAttribute( 'role', 'alert' );
			var title = wrap.querySelector( '.lucidlab-mpc-title' );
			if ( title ) { title.insertAdjacentElement( 'afterend', box ); } else { wrap.prepend( box ); }
		}
		var message = ( error && error.message ) || String( error );
		box.textContent = mpcText("Action error: ") + message;
		box.scrollIntoView( { block: 'center' } );
		if ( /Cannot restore while|Another cleanup job is pending|Current Running Jobs/i.test( message ) ) {
			var jobs = document.getElementById( 'lucidlab-mpc-running-jobs' );
			if ( jobs ) {
				jobs.hidden = false;
				if ( jobs.scrollIntoView ) { jobs.scrollIntoView( { behavior: 'smooth', block: 'start' } ); }
			}
		}
	}
	window.addEventListener( 'unhandledrejection', function ( event ) {
		var reason = event.reason;
		var stack = reason && reason.stack ? String( reason.stack ) : '';
		if ( stack.indexOf( 'admin.js' ) !== -1 ) { showActionError( reason ); }
	} );
	window.addEventListener( 'error', function ( event ) {
		if ( event.filename && event.filename.indexOf( 'admin.js' ) !== -1 ) { showActionError( event.error || event.message ); }
	} );

	function el( sel, ctx ) { return ( ctx || document ).querySelector( sel ); }
	function els( sel, ctx ) { return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( sel ) ); }
	/* Free and Pro: background continue is available; Free still enforces a single active job server-side. */
	function canBackground() { return true; }
	/*
	 * One plugin tab polls actively (the leader); other tabs only read the saved status now and then.
	 * The leader is the visible tab that claimed it first; a hidden leader stops renewing, so a
	 * visible tab takes over within a few seconds.
	 */
	var mpcHttpPause={until:0,hits:0};
	var mpcTabId=Math.random().toString(36).slice(2)+Date.now().toString(36);
	var mpcLeaderKey='lucidlab_mpc_poll_leader';
	function mpcIsLeader(){
		try{
			var raw=window.localStorage.getItem(mpcLeaderKey),cur=raw?JSON.parse(raw):null,now=Date.now();
			var fresh=cur&&cur.id&&now-Number(cur.t||0)<=6000;
			if(!document.hidden&&(!fresh||cur.id===mpcTabId)){window.localStorage.setItem(mpcLeaderKey,JSON.stringify({id:mpcTabId,t:now}));return true;}
			return !!(fresh&&cur.id===mpcTabId);
		}catch(e){return true;}
	}
	setInterval(mpcIsLeader,2000);
	window.addEventListener('pagehide',function(){try{var raw=window.localStorage.getItem(mpcLeaderKey),cur=raw?JSON.parse(raw):null;if(cur&&cur.id===mpcTabId)window.localStorage.removeItem(mpcLeaderKey);}catch(e){}});
	/** An open page drives a background job once saved progress has gone quiet. */
	function cronPollAction(job){
		if(!job || job.status!=='running') return 'step';
		var now=Date.now()/1000;
		// The job waits (for example after HTTP 429 from the site): only read its status until then.
		if(Number(job.retry_at||0)>now) return 'status';
		if(job.driver!=='cron'){
			// Another tab drives this job. Step only when nothing has moved for a while (that tab was closed).
			if(!mpcIsLeader()) return (Number(job.updated||0)>0 && now-Number(job.updated)>20) ? 'step' : 'status';
			return 'step';
		}
		if(Number(job.updated||0)>0 && now-Number(job.updated)>15) return 'step';
		return 'status';
	}
	function mpcText(text) { var pack = (window.LucidLabMPC && LucidLabMPC.messages) || {}; return typeof pack[text] === 'string' && pack[text] ? pack[text] : text; }
	function mpcT( key, fallback ) {
		var pack = ( window.LucidLabMPC && LucidLabMPC.i18n ) || {};
		var value = pack[ key ];
		return ( typeof value === 'string' && value ) ? value : mpcText( fallback || key );
	}
	/** Red snapshot warning must never carry a Completed job-summary key or Completed text. */
	function repairMissingResultsNotice( node ) {
		if ( ! node || ! node.classList || ! node.classList.contains( 'lucidlab-mpc-missing-results-notice' ) ) { return; }
		if ( node.dataset ) { delete node.dataset.mpcJobSummary; }
		var text = String( node.textContent || '' ).replace( /^\s+|\s+$/g, '' );
		if ( /^(Completed|Cancelled|Failed) on:/i.test( text ) ) {
			node.textContent = mpcT( 'noSavedResults', 'No saved result list is available. Run a new scan to refresh the findings; the counters may show an older snapshot.' );
		}
	}
	/** A visible missing-list notice is not a current result, so hide that scan's old counters. */
	function syncSnapshotCounters( panel, notice ) {
		if ( ! panel || ! notice ) { return; }
		var hideSnapshot = ! notice.hidden;
		panel.querySelectorAll( '.lucidlab-mpc-stats-snapshot' ).forEach( function ( stats ) {
			setHidden( stats, hideSnapshot );
		} );
	}
	function placeStatusAtPanelTop( panel, node ) {
		if ( ! panel || ! node ) { return; }
		var preferred = null;
		if ( node && node.id === 'lucidlab-mpc-webp-stat' ) {
			var webpStats = panel.querySelector( '#lucidlab-mpc-webpstats' );
			preferred = webpStats ? ( webpStats.closest( '.lucidlab-mpc-card' ) || webpStats.parentElement ) : null;
		} else if ( node && node.id === 'lucidlab-mpc-regen-stat' ) {
			var regenBox = panel.querySelector( '#lucidlab-mpc-regen' );
			preferred = regenBox ? ( regenBox.closest( '.lucidlab-mpc-card' ) || regenBox.parentElement ) : null;
		}
		// Uploads: keep Completed/status in the overview card with the snapshot counters.
		var host = preferred || panel.querySelector( '[data-mpc-card-overview], [data-mpc-db-overview]' ) || panel;
		// Database size stays above the missing-list notice. Old finding squares stay below it and are hidden.
		var placedAfterLive = false;
		if ( node.classList && node.classList.contains( 'lucidlab-mpc-missing-results-notice' ) ) {
			var liveStats = host.querySelector( ':scope > .lucidlab-mpc-stats-live' );
			if ( liveStats ) {
				if ( node !== liveStats.nextElementSibling ) {
					liveStats.after( node );
				}
				placedAfterLive = true;
			}
		}
		// Prefer the earliest overview block so Completed sits above intro copy (Missing Images).
		var found = host.querySelector( '.lucidlab-mpc-stats-snapshot, .lucidlab-mpc-stats-uploads, #lucidlab-mpc-uploadsstats, .lucidlab-mpc-stats' )
			|| host.querySelector( '.lucidlab-mpc-scan-description, .lucidlab-mpc-card__lede' )
			|| host.querySelector( ':scope > p.lucidlab-mpc-hint, :scope > .lucidlab-mpc-hint' )
			|| host.querySelector( '#lucidlab-mpc-regen, #lucidlab-mpc-webpstats' )
			|| host.querySelector( 'form.lucidlab-mpc-controls, .lucidlab-mpc-controls, .lucidlab-mpc-btn-row, form' );
		var anchor = found;
		while ( anchor && anchor.parentNode && anchor.parentNode !== host ) {
			anchor = anchor.parentNode;
		}
		if ( ! anchor || anchor.parentNode !== host || anchor === node ) {
			anchor = null;
		}
		if ( ! placedAfterLive && anchor ) {
			if ( node.parentNode !== host || node.nextElementSibling !== anchor ) {
				host.insertBefore( node, anchor );
			}
		} else if ( ! placedAfterLive && ( node.parentNode !== host || host.firstElementChild !== node ) ) {
			host.prepend( node );
		}
		var summary = null;
		var missing = null;
		Array.prototype.forEach.call( host.children, function ( child ) {
			if ( ! summary && child.classList && child.classList.contains( 'lucidlab-mpc-job-summary' ) && child.dataset.mpcJobSummary && ! child.classList.contains( 'lucidlab-mpc-missing-results-notice' ) ) {
				summary = child;
			}
			if ( ! missing && child.classList && child.classList.contains( 'lucidlab-mpc-missing-results-notice' ) ) {
				missing = child;
			}
		} );
		if ( summary && missing && ( summary.compareDocumentPosition( missing ) & Node.DOCUMENT_POSITION_PRECEDING ) ) {
			host.insertBefore( summary, missing );
		}
		var sizesNotice = host.querySelector( '[data-mpc-job-summary="sizes"]' );
		var fsNotice = host.querySelector( '[data-mpc-job-summary="fs-sizes"]' );
		if ( sizesNotice && fsNotice && sizesNotice.nextElementSibling !== fsNotice ) {
			sizesNotice.after( fsNotice );
		}
	}
	/** Match PHP MPC_Helpers::job_datetime — "27 September 2026 at 22:53". Prefer server label (site TZ). */
	function formatJobDate( ts, label ) {
		if ( label ) { return String( label ); }
		var stamp = Number( ts );
		if ( ! stamp || stamp < 1 ) { return ''; }
		var d = new Date( stamp * 1000 );
		if ( isNaN( d.getTime() ) ) { return ''; }
		var parts = new Intl.DateTimeFormat( (window.LucidLabMPC && LucidLabMPC.locale) || undefined, { day: 'numeric', month: 'long', year: 'numeric' } ).formatToParts( d );
		var day = ( parts.find( function ( part ) { return part.type === 'day'; } ) || {} ).value || '';
		var month = ( parts.find( function ( part ) { return part.type === 'month'; } ) || {} ).value || '';
		var year = ( parts.find( function ( part ) { return part.type === 'year'; } ) || {} ).value || '';
		var date = [ day, month, year ].filter( Boolean ).join( ' ' );
		var time = d.toLocaleTimeString( (window.LucidLabMPC && LucidLabMPC.locale) || undefined, { hour: '2-digit', minute: '2-digit', hour12: false } );
		return date + ' ' + mpcT( 'at', 'at' ) + ' ' + time;
	}

	function buttonBusy( button, busy ) {
		if ( ! button ) { return; }
		button.disabled = !! busy;
		var host = button.parentElement;
		var spin = host && host.querySelector( '.lucidlab-mpc-inline-spinner[data-for="' + ( button.id || 'lucidlab-mpc-busy' ) + '"]' );
		if ( busy && ! spin ) {
			spin = document.createElement( 'span' );
			spin.className = 'spinner is-active lucidlab-mpc-inline-spinner';
			spin.dataset.for = button.id || 'lucidlab-mpc-busy';
			spin.setAttribute( 'role', 'status' );
			spin.setAttribute( 'aria-label', mpcText("Working") );
			button.insertAdjacentElement( 'afterend', spin );
		} else if ( ! busy && spin ) {
			spin.remove();
		}
	}
	/** Inventory / quarantine restore style: spinner replaces the pressed control until the job settles. */
	function crawlButtonBusy( button, busy ) {
		if ( ! button ) { return; }
		buttonBusy( button, busy );
		button.hidden = !! busy;
	}
	/** Disable a button/link and show a spinner before its label (replacing any leading dashicon). */
	function buttonBusyInside( button, busy ) {
		if ( ! button ) { return; }
		button.disabled = !! busy;
		button.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		if ( button.tagName === 'A' ) {
			button.setAttribute( 'aria-disabled', busy ? 'true' : 'false' );
			button.classList.toggle( 'disabled', !! busy );
		}
		var icons = button.querySelectorAll( '.dashicons:not(.lucidlab-mpc-btn-spinner)' );
		var spin = button.querySelector( '.lucidlab-mpc-btn-spinner' );
		if ( busy ) {
			icons.forEach( function ( icon ) {
				icon.classList.add( 'lucidlab-mpc-btn-icon-hidden' );
				icon.setAttribute( 'aria-hidden', 'true' );
			} );
			if ( ! spin ) {
				spin = document.createElement( 'span' );
				spin.className = 'spinner is-active lucidlab-mpc-btn-spinner';
				spin.setAttribute( 'aria-hidden', 'true' );
				button.insertBefore( spin, button.firstChild );
			}
		} else {
			if ( spin ) { spin.remove(); }
			icons.forEach( function ( icon ) {
				icon.classList.remove( 'lucidlab-mpc-btn-icon-hidden' );
			} );
		}
		syncScanButtonLabel( button, busy );
	}
	/** Scan actions keep their idle wording until work starts, then say Scanning…. */
	function syncScanButtonLabel( button, busy ) {
		if ( ! button ) { return; }
		var isScan = button.hasAttribute( 'data-mpc-scan' ) || button.id === 'lucidlab-mpc-fssizes-scan' || button.id === 'lucidlab-mpc-webpstats-scan' || button.id === 'lucidlab-mpc-webpclean-scan';
		if ( ! isScan ) { return; }
		var label = button.querySelector( '.lucidlab-mpc-btn-label' );
		if ( ! label ) {
			var idle = '';
			Array.prototype.forEach.call( button.childNodes, function ( node ) {
				if ( node.nodeType === 3 ) { idle += node.textContent; }
			} );
			idle = idle.replace( /\s+/g, ' ' ).trim();
			if ( ! button.dataset.mpcIdleLabel && idle ) { button.dataset.mpcIdleLabel = idle; }
			Array.prototype.forEach.call( button.childNodes, function ( node ) {
				if ( node.nodeType === 3 ) { node.textContent = ''; }
			} );
			label = document.createElement( 'span' );
			label.className = 'lucidlab-mpc-btn-label';
			label.textContent = button.dataset.mpcIdleLabel || idle;
			button.appendChild( label );
		}
		label.textContent = busy ? mpcT( 'scanning', 'Scanning…' ) : ( button.dataset.mpcIdleLabel || label.textContent );
	}
	function labelChoiceControls( root ) {
		( root || document ).querySelectorAll( 'input.lucidlab-mpc-row-check, input.lucidlab-mpc-selectall' ).forEach( function ( box ) {
			if ( box.getAttribute( 'aria-label' ) ) { return; }
			if ( box.classList.contains( 'lucidlab-mpc-selectall' ) ) {
				box.setAttribute( 'aria-label', mpcText( 'Select all rows' ) );
				return;
			}
			var name = box.getAttribute( 'data-label' ) || '';
			var row = box.closest( 'tr' );
			if ( ! name && row ) {
				var cell = row.querySelector( 'strong' ) || row.cells && row.cells[ 2 ];
				name = cell ? String( cell.textContent || '' ).replace( /\s+/g, ' ' ).trim() : '';
			}
			if ( name ) { box.setAttribute( 'aria-label', name ); }
		} );
	}
	/**
	 * Disabled submitters are omitted from POST. Copy name/value into a hidden field
	 * before buttonBusyInside() so ops like rebuild_index still reach the server.
	 */
	function preserveSubmitter( form, button ) {
		if ( ! form || ! button || ! button.name ) { return; }
		var existing = form.querySelector( 'input[type="hidden"][data-mpc-submitter="' + button.name + '"]' );
		if ( ! existing ) {
			existing = document.createElement( 'input' );
			existing.type = 'hidden';
			existing.name = button.name;
			existing.dataset.mpcSubmitter = button.name;
			form.appendChild( existing );
		}
		existing.value = button.value;
	}
	function applyWebpStats( stats ) {
		if ( ! stats ) { return; }
		Object.keys( stats ).forEach( function ( k ) {
			var value = stats[ k ];
			var text = ( /_count$/.test( k ) && value !== '' && value !== null && ! isNaN( Number( value ) ) ) ? Number( value ).toLocaleString() : value;
			document.querySelectorAll( '[data-mpc-stat="' + k + '"], [data-mpc-stat-key="' + k + '"] .lucidlab-mpc-num' ).forEach( function ( span ) {
				span.textContent = text;
			} );
		} );
		var overview = el( '#lucidlab-mpc-webpstats' );
		if ( overview ) { overview.dataset.mpcStatsUpdated = String( Math.floor( Date.now() / 1000 ) ); }
	}
	function applyWebpClean( payload ) {
		payload = payload && payload.webp ? payload : ( payload && payload.data && payload.data.webp ? payload.data : payload || {} );
		if ( payload.stats ) { applyWebpStats( payload.stats ); }
		els( '[data-clean-format]' ).forEach( function ( section ) {
			var fmt = section.getAttribute( 'data-clean-format' );
			if ( ! Object.prototype.hasOwnProperty.call( payload, fmt ) ) { return; }
			var d = payload[ fmt ] || {};
			var count = Number( d.count ) || 0;
			var bytes = d.bytes || formatPreviewBytes( 0 );
			var samples = Array.isArray( d.samples ) ? d.samples : [];
			var summary = el( '.lucidlab-mpc-clean-summary', section );
			var run = el( '.lucidlab-mpc-clean-run', section );
			var status = el( '.lucidlab-mpc-clean-status', section );
			var countEl = el( '.lucidlab-mpc-clean-count', section );
			var bytesEl = el( '.lucidlab-mpc-clean-bytes', section );
			var files = el( '.lucidlab-mpc-clean-files', section );
			var list = files && files.querySelector( 'ul' );
			var scanned = d.scanned === true || d.scanned === 1 || d.scanned === '1' || Object.prototype.hasOwnProperty.call( d, 'count' );
			if ( countEl ) { countEl.textContent = count.toLocaleString(); }
			if ( bytesEl ) { bytesEl.textContent = bytes; }
			if ( summary ) { summary.textContent = count ? ( count.toLocaleString() + ' copies (' + bytes + ')' ) : ( scanned ? '' : mpcText("Scan to find generated copies.") ); }
			if ( run ) { run.disabled = ! count; }
			if ( status ) {
				status.textContent = ( ! count && scanned ) ? mpcText("No generated copies found. Run a new scan after generating copies.") : '';
				status.classList.toggle( 'lucidlab-mpc-clean-empty', ! count && scanned );
			}
			if ( files && list ) {
				list.replaceChildren();
				// A saved job can replace the server-rendered list; discard its old total too.
				var sampleNote = files.querySelector('.lucidlab-mpc-hint');
				if (sampleNote) { sampleNote.remove(); }
				samples.forEach( function ( rel ) {
					var item = document.createElement( 'li' );
					var code = document.createElement( 'code' );
					code.textContent = rel;
					item.appendChild( code );
					list.appendChild( item );
				} );
				files.hidden = ! samples.length;
				if ( samples.length ) { files.open = true; }
			}
		} );
	}
	function hideFsSizesResults() {
		var results = el( '#lucidlab-mpc-fssizes-results' );
		var hint = el( '#lucidlab-mpc-fssizes-hint' );
		var summary = el( '#lucidlab-mpc-fssizes-summary' );
		var rows = el( '#lucidlab-mpc-fssizes-rows' );
		if ( results ) { results.hidden = true; }
		if ( hint ) { hint.hidden = true; hint.textContent = ''; }
		if ( summary ) { summary.textContent = ''; }
		if ( rows ) { rows.innerHTML = ''; }
	}
	function markFsSizesReady() {
		var viewBtn = el( '#lucidlab-mpc-fssizes-view' );
		if ( viewBtn ) { setHidden( viewBtn, false ); }
		hideFsSizesResults();
	}
	function applyFsSizesResult( d ) {
		d = d || {};
		var dims = d.dimensions || [];
		var rows = el( '#lucidlab-mpc-fssizes-rows' );
		var results = el( '#lucidlab-mpc-fssizes-results' );
		var hint = el( '#lucidlab-mpc-fssizes-hint' );
		var viewBtn = el( '#lucidlab-mpc-fssizes-view' );
		if ( ! rows ) { return; }
		rows.innerHTML = dims.length ? dims.map( function ( row ) {
			var protectedSize = !! row.protected;
			var reason = protectedSize ? ' <span class="lucidlab-mpc-hint">' + escapeHtml( row.protection_label || '' ) + '</span>' : '';
			return '<tr><td><input type="checkbox" class="lucidlab-mpc-row-check" value="' + escapeHtml( row.dim ) + '" data-bytes="' + Number( row.bytes || 0 ) + '" data-count="' + Number( row.count || 0 ) + '" aria-label="' + escapeHtml( row.dim + ( protectedSize ? ' — ' + ( row.protection_label || '' ) : '' ) ) + '"' + ( protectedSize ? ' disabled' : '' ) + ' /></td>' +
				'<td><code>' + escapeHtml( row.dim ) + '</code>' + reason + '</td><td>' + Number( row.count ).toLocaleString() + '</td><td>' + escapeHtml( row.human ) + '</td></tr>';
		} ).join( '' ) : '<tr><td colspan="4">' + ( ( window.LucidLabMPC && LucidLabMPC.i18n && LucidLabMPC.i18n.nothing ) || mpcText("Nothing found") ) + '</td></tr>';
		if ( results ) { results.hidden = false; }
		if ( hint ) {
			hint.hidden = false;
			hint.textContent = ( Number( d.files ) || 0 ).toLocaleString() + mpcText(" resized file(s) found (") + ( Number( d.skipped ) || 0 ).toLocaleString() + mpcText(" originals skipped).");
		}
		if ( viewBtn ) { setHidden( viewBtn, false ); }
		var summary = el( '#lucidlab-mpc-fssizes-summary' );
		if ( summary ) { summary.textContent = ''; }
	}
	function setHidden( node, hidden ) {
		if ( ! node ) { return; }
		node.hidden = !! hidden;
		node.classList.toggle( 'lucidlab-mpc-is-hidden', !! hidden );
		if ( hidden ) { node.setAttribute( 'aria-hidden', 'true' ); } else { node.removeAttribute( 'aria-hidden' ); }
	}
	function panelHasSavedResults( panel ) {
		if ( ! panel ) { return false; }
			if ( panel.querySelector( '[data-mpc-results-loaded], .lucidlab-mpc-group, .lucidlab-mpc-empty, .lucidlab-mpc-dup-form, .lucidlab-mpc-missing-table, .lucidlab-mpc-shared-list, .lucidlab-mpc-visual-candidates, .lucidlab-mpc-quar-zone .lucidlab-mpc-table tbody tr, [data-mpc-db-results]' ) ) { return true; }
		return panel.querySelectorAll( '.lucidlab-mpc-stats:not(.lucidlab-mpc-stats-snapshot):not(.lucidlab-mpc-stats-live)' ).length > 0;
	}
	function ensureViewCurrentResultsLink( scan, name ) {
		if ( ! scan ) { return null; }
		var panel = scan.closest( '[data-mpc-panel]' ) || scan.parentElement;
		var href = ( LucidLabMPC.tabUrls[ name ] || new URL( 'admin.php?page=lucidlab-mpc-cleanup&tab=' + name, location.href ).href ) + '&lucidlab_mpc_load=1&lucidlab_mpc_scanned=1';
		var links = panel ? Array.prototype.slice.call( panel.querySelectorAll( '.lucidlab-mpc-current-results-link, [data-run-all-results="' + name + '"]' ) ).filter( function ( link ) { return link.id !== 'lucidlab-mpc-fssizes-view' && link.tagName !== 'BUTTON'; } ) : [];
		var keep = links.find( function ( link ) { return ! link.hidden && link.getAttribute( 'aria-hidden' ) !== 'true'; } ) || links[0] || document.createElement( 'a' );
		keep.className = 'button lucidlab-mpc-current-results-link';
		keep.dataset.runAllResults = name;
		keep.textContent = mpcT( 'viewCurrentResults', 'View current results' );
		keep.href = href;
		keep.hidden = false;
		keep.removeAttribute( 'aria-hidden' );
		keep.classList.remove( 'lucidlab-mpc-is-hidden' );
		if ( ! keep.parentNode || keep.previousElementSibling !== scan ) {
			scan.after( keep );
		}
		if ( ! keep.previousSibling || keep.previousSibling.nodeType !== 3 ) {
			keep.before( document.createTextNode( ' ' ) );
		}
		links.forEach( function ( link ) {
			if ( link !== keep ) { link.remove(); }
		} );
		return keep;
	}
	function placeCurrentResultLinks(){
		els('[data-mpc-scan]').forEach(function(scan){
			var name=scan.getAttribute('data-mpc-scan');
			if(!name)return;
			var panel=scan.closest('[data-mpc-panel]');
			if(!panel)return;
			var links=panel.querySelectorAll('.lucidlab-mpc-current-results-link, [data-run-all-results="'+name+'"]');
			if(links.length>1){ensureViewCurrentResultsLink(scan,name);}
		});
	}
	function scanButtonLabel( panel, button ) {
		var tab = panel.getAttribute( 'data-mpc-panel' );
		if ( button.getAttribute( 'data-mpc-scan' ) === 'crawl' ) {
			return mpcT( 'checkLivePages', 'Check live pages' );
		}
		if ( tab === 'visual' || button.getAttribute( 'data-mpc-scan' ) === 'visual' ) {
			return mpcT( 'findVisual', 'Find visually identical images' );
		}
		var rescan = !! panel.querySelector( '.lucidlab-mpc-last-scan' );
		var labels = {
			orphans: mpcT( 'scanOrphans', 'Scan orphan images' ),
			sizes: mpcT( 'analyzeSizes', 'Analyze sizes' ),
			duplicates: mpcT( 'scanDuplicates', 'Scan duplicate products' ),
			uploads: mpcT( 'scanUploads', 'Scan uploads' ),
			dbcleaner: mpcT( 'scanDatabase', 'Scan database' ),
			missing: mpcT( 'scanMissing', 'Scan missing images' ),
			shared: mpcT( 'findShared', 'Find shared images' ),
			dupcontent: mpcT( 'findByteIdentical', 'Find byte-identical images' ),
			product_copy: mpcT( 'scanProducts', 'Scan products' ),
		};
		if ( tab === 'orphans' || tab === 'sizes' ) { return labels[ tab ]; }
		if ( labels[ tab ] ) {
			return rescan ? labels[ tab ].replace( /^(Scan|Find)\s+/i, 'Rescan: ' ) : labels[ tab ];
		}
		var text = button.textContent.replace( /\s*\(dry[- ]run\)/ig, '' ).replace( /^(Scan|Rescan|Find)\s*:?\s*/i, '' ).trim();
		return ( rescan ? 'Rescan: ' : 'Scan: ' ) + text;
	}

	/* Layout-only navigation: retain each panel's fields and results in the DOM. */
	function paintScanButton( button, label ) {
		var icon = button.querySelector( '.dashicons' );
		var wantIcon = !! icon || button.classList.contains( 'button-primary' );
		if ( wantIcon && ! icon ) {
			icon = document.createElement( 'span' );
			icon.className = 'dashicons dashicons-search';
			icon.setAttribute( 'aria-hidden', 'true' );
		}
		button.textContent = '';
		if ( icon ) {
			button.appendChild( icon );
			button.appendChild( document.createTextNode( ' ' + label ) );
		} else {
			button.textContent = label;
		}
	}
	function tabsInit() {
		els('[data-mpc-panel]').forEach(function(panel){els('[data-mpc-scan]',panel).forEach(function(button){paintScanButton(button,scanButtonLabel(panel,button));});});
		var otherSizesButton = document.getElementById('lucidlab-mpc-fssizes-scan');
		if ( otherSizesButton ) { paintScanButton( otherSizesButton, mpcT( 'scanOtherSizes', 'Scan other sizes' ) ); }
		var sharedProgressPanels=['duplicates','orphans','uploads','sizes','fs-sizes','missing','shared','dupcontent','visual','webpstats'];
		els('[data-mpc-panel]').forEach(function(panel){if(sharedProgressPanels.includes(panel.getAttribute('data-mpc-panel'))){els('.lucidlab-mpc-job-card',panel).forEach(function(card){card.remove();});}});
		var links = els( '.lucidlab-mpc-tabs a' ), panels = els( '[data-mpc-panel]' );
		if ( ! links.length || ! panels.length ) { return; }
		var activeTab = ( window.LucidLabMPC && LucidLabMPC.tab ) || new URL( window.location.href ).searchParams.get( 'tab' );
		els( '.lucidlab-mpc-job-summary' ).forEach( function ( notice ) {
			if ( notice.closest( '[data-mpc-panel]' ) ) { return; }
			var panel = el( '[data-mpc-panel="' + activeTab + '"]' ) || panels.filter( function ( item ) { return ! item.hidden; } )[0];
			if ( panel ) { panel.prepend( notice ); }
		} );
		els( '[data-mpc-panel]' ).forEach( function ( panel ) {
			els( '.lucidlab-mpc-missing-results-notice', panel ).forEach( repairMissingResultsNotice );
			var missing = panel.querySelector( '.lucidlab-mpc-missing-results-notice' );
			if ( missing && ! missing.hidden && missing.getAttribute( 'aria-hidden' ) !== 'true' ) {
				els( '.lucidlab-mpc-current-results-link', panel ).forEach( function ( link ) { setHidden( link, true ); } );
			}
		} );
		function show( tab ) {
			if ( ! panels.some( function ( panel ) { return panel.getAttribute( 'data-mpc-panel' ) === tab; } ) ) { return; }
			panels.forEach( function ( panel ) { panel.hidden = panel.getAttribute( 'data-mpc-panel' ) !== tab; } );
			links.forEach( function ( link ) {
				var active = new URL( link.href ).searchParams.get( 'tab' ) === tab;
				link.classList.toggle( 'nav-tab-active', active );
				if ( active ) { link.setAttribute( 'aria-current', 'page' ); } else { link.removeAttribute( 'aria-current' ); }
			} );
			els( '#toplevel_page_lucidlab-mpc-cleanup .wp-submenu a' ).forEach( function ( link ) {
                var active = new URL( link.href ).searchParams.get( 'tab' ) === tab;
                link.parentElement.classList.toggle( 'current', active );
                link.classList.toggle( 'current', active );
                if ( active ) { link.setAttribute( 'aria-current', 'page' ); } else { link.removeAttribute( 'aria-current' ); }
            } );
            LucidLabMPC.tab = tab;
			document.dispatchEvent( new CustomEvent( 'mpc:tabshown', { detail: tab } ) );
		}
		panels.forEach( function ( panel ) {
			var link = links.filter( function ( item ) { return new URL( item.href ).searchParams.get( 'tab' ) === panel.getAttribute( 'data-mpc-panel' ); } )[0];
			// POST actions must return to their own tab even after client-side navigation.
			els( 'form', panel ).forEach( function ( form ) { if ( ! form.hasAttribute( 'action' ) ) { form.action = link.href; } } );
		} );
		els( '.lucidlab-mpc-welcome-actions a, .lucidlab-mpc-media-index-link' ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				if ( event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ) { return; }
				var tab = new URL( link.href ).searchParams.get( 'tab' );
				if ( ! tab || ! panels.some( function ( panel ) { return panel.getAttribute( 'data-mpc-panel' ) === tab; } ) ) { return; }
				event.preventDefault();
				window.history.pushState( null, '', link.href );
				show( tab );
			} );
		} );
		window.addEventListener( 'popstate', function () { show( new URL( window.location.href ).searchParams.get( 'tab' ) || 'duplicates' ); } );
		show( LucidLabMPC.tab );
	}
	if ( document.readyState !== 'loading' ) { setTimeout( tabsInit, 0 ); } else { document.addEventListener( "DOMContentLoaded", tabsInit ); }
	function moveExternalJobNotices() {
		var tab = ( window.LucidLabMPC && LucidLabMPC.tab ) || new URL( window.location.href ).searchParams.get( 'tab' );
		var panel = document.querySelector( '[data-mpc-panel="' + tab + '"]' ) || document.querySelector( '[data-mpc-panel]:not([hidden])' );
		if ( ! panel ) { return; }
		document.querySelectorAll( '.lucidlab-mpc-wrap > .lucidlab-mpc-job-summary' ).forEach( function ( notice ) { panel.prepend( notice ); } );
	}
	if ( document.readyState !== 'loading' ) { setTimeout( moveExternalJobNotices, 0 ); } else { document.addEventListener( "DOMContentLoaded", moveExternalJobNotices ); }
	var noticeWrap = document.querySelector( '.lucidlab-mpc-wrap' );
	if ( noticeWrap && window.MutationObserver ) { new MutationObserver( moveExternalJobNotices ).observe( noticeWrap, { childList: true, subtree: true } ); }
	[ 50, 250, 1000 ].forEach( function ( delay ) { setTimeout( moveExternalJobNotices, delay ); } );

		/* One visible confirmation at a time, without browser dialogs. */
		// Handle action clicks before other admin scripts can consume their bubbling events.
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-mpc-rename-all], [data-mpc-quarantine]' );
		if ( ! button || button.disabled || button.getAttribute( 'aria-busy' ) === 'true' ) { return; }
		event.preventDefault();
		event.stopImmediatePropagation();
		if ( button.hasAttribute( 'data-mpc-rename-all' ) ) {
			runRenameAll( button ).catch( showActionError );
		} else {
			var tab = button.getAttribute( 'data-mpc-quarantine' );
			var zone = button.closest( '.lucidlab-mpc-quar-zone' );
			if ( ! zone ) { showActionError( new Error( mpcText("Quarantine controls were not found.") ) ); return; }
			( tab === 'sizes' || tab === 'fs-sizes' ? runDimQuar( tab, zone ) : runQuarantine( tab, zone, button ) ).catch( showActionError );
		}
	}, true );

var pendingConfirmation = null;
	function formatPreviewBytes( bytes ) {
		bytes = Math.max( 0, Number( bytes ) || 0 );
		function oneDec( n ) {
			var s = n.toFixed( 1 );
			var point = ( window.LucidLabMPC && LucidLabMPC.decimalPoint ) || '.';
			return point === '.' ? s : s.replace( '.', point );
		}
		if ( bytes >= 1073741824 ) { return oneDec( bytes / 1073741824 ) + mpcText(" GB"); }
		if ( bytes >= 1048576 ) { return oneDec( bytes / 1048576 ) + mpcText(" MB"); }
		if ( bytes >= 1024 ) { return oneDec( bytes / 1024 ) + mpcText(" KB"); }
		return oneDec( bytes ) + mpcText(" B");
	}
	function previewSummary( preview ) {
		var parts = [];
		var count = Number( preview && preview.count ) || 0;
		var files = Number( preview && preview.files ) || 0;
		parts.push( count + mpcText(" selected") );
		if ( files && files !== count ) { parts.push( files + mpcText(" files") ); }
		if ( preview && preview.bytes ) { parts.push( preview.bytes_label || formatPreviewBytes( preview.bytes ) ); }
		if ( preview && preview.keeps && preview.keeps.length ) { parts.push( 'keeping ' + preview.keeps.length ); }
		return parts.join( ' \u00B7 ' );
	}
	function confirmFileCount( preview ) {
		if ( preview && preview.files_label ) { return preview.files_label; }
		var n = Number( preview && ( preview.files || preview.count ) ) || 0;
		var unit = preview && preview.item_label === 'rows'
			? ( n === 1 ? mpcText(" row") : mpcText(" rows") )
			: ( n === 1 ? mpcText(" file") : mpcText(" files") );
		return n.toLocaleString() + unit;
	}
	async function confirmImpact( message, host, payload ) {
		var res = await post( Object.assign( { op: 'preview', direct: true }, payload || {} ) );
		if ( ! res || ! res.success ) {
			throw new Error( ( res && res.data && res.data.message ) || ( LucidLabMPC.i18n && LucidLabMPC.i18n.error ) || mpcText("Preview failed") );
		}
		return inlineConfirm( message, host, res.data );
	}
	function inlineConfirm( message, host, preview ) {
		if ( pendingConfirmation ) { pendingConfirmation( false ); }
		return new Promise( function ( resolve ) {
			var box = document.createElement( 'div' );
			box.className = 'notice notice-warning inline lucidlab-mpc-notice lucidlab-mpc-inline-confirm';
			box.setAttribute( 'role', 'alert' );
			box.style.position = 'relative';
			box.style.zIndex = '9999';
			var text = document.createElement( 'p' );
			text.textContent = message;
			box.appendChild( text );
			var compact = !!( preview && preview.compact );
            text.style.marginBottom = compact ? '8px' : '24px';
			if ( compact ) {
				var countLine = document.createElement( 'p' );
				countLine.className = 'lucidlab-mpc-confirm-count';
				countLine.textContent = confirmFileCount( preview );
				box.appendChild( countLine );
			} else if ( preview && typeof preview === 'object' ) {
				if ( ! preview.skip_note ) {
					var note = document.createElement( 'p' );
					note.className = 'lucidlab-mpc-dry-run-note';
					note.textContent = preview.note || ( window.LucidLabMPC && LucidLabMPC.i18n && LucidLabMPC.i18n.previewNote ) || mpcText("This is a preview. Nothing has been moved yet.");
					box.appendChild( note );
				}
				if ( ! preview.skip_meta ) {
					var meta = document.createElement( 'p' );
					meta.className = 'lucidlab-mpc-dry-run-meta';
					meta.textContent = previewSummary( preview );
					box.appendChild( meta );
				}
				var samples = preview.samples || [];
				if ( samples.length ) {
					var list = document.createElement( 'ul' );
					list.className = 'lucidlab-mpc-dry-run-samples';
					samples.forEach( function ( sample ) {
						var item = document.createElement( 'li' );
						item.textContent = ( sample.label || ( sample.id ? '#' + sample.id : 'item' ) ) + ( sample.bytes ? ' \u00B7 ' + formatPreviewBytes( sample.bytes ) : '' );
						list.appendChild( item );
					} );
					if ( preview.more ) {
						var extra = document.createElement( 'li' );
						var moreTpl = ( window.LucidLabMPC && LucidLabMPC.i18n && LucidLabMPC.i18n.previewMore ) || 'and %d more';
						extra.textContent = moreTpl.replace( '%d', String( preview.more ) );
						list.appendChild( extra );
					}
					box.appendChild( list );
				}
			}
			if ( preview && preview.require_text ) {
				var typeHint = document.createElement( 'p' );
				typeHint.className = 'lucidlab-mpc-dry-run-note';
				typeHint.textContent = mpcText("Type ") + preview.require_text + mpcText(" to confirm.");
				box.appendChild( typeHint );
				var typed = document.createElement( 'input' );
				typed.type = 'text';
				typed.className = 'regular-text';
				typed.autocomplete = 'off';
				typed.setAttribute( 'aria-label', mpcText("Type ") + preview.require_text + mpcText(" to confirm") );
				box.appendChild( typed );
			}
			function finish( answer ) {
				box.remove();
				pendingConfirmation = null;
				resolve( answer );
			}
			pendingConfirmation = finish;
			var actions = document.createElement( 'div' );
			actions.className = 'lucidlab-mpc-inline-confirm-actions';
			[ [ mpcText("Confirm"), true ], [ mpcText("Cancel"), false ] ].forEach( function ( choice ) {
				var button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'button ' + ( choice[1] ? 'button-primary' : '' );
				button.textContent = choice[0];
				button.addEventListener( 'click', function () {
					if ( choice[1] && preview && preview.require_text ) {
						var typedField = box.querySelector( 'input[type="text"]' );
						if ( ! typedField || typedField.value.trim() !== preview.require_text ) {
							if ( typedField ) { typedField.focus(); }
							return;
						}
					}
					finish( choice[1] );
				} );
				if ( choice[1] && preview && preview.require_text ) { button.disabled = true; }
				actions.appendChild( button );
			} );
			box.appendChild( actions );
			if ( preview && preview.require_text ) {
				var typedInput = box.querySelector( 'input[type="text"]' );
				var confirmBtn = el( '.button-primary', box );
				if ( typedInput && confirmBtn ) {
					typedInput.addEventListener( 'input', function () {
						confirmBtn.disabled = typedInput.value.trim() !== preview.require_text;
					} );
				}
			}
			var topNotices = el( '#lucidlab-mpc-top-notices' ) || el( '.lucidlab-mpc-admin-content' );
			var target = host || topNotices;
			// Never inject into the left sidebar nav (.lucidlab-mpc-tabs); keep confirms above the hero.
			if ( target && ( target.classList.contains( 'lucidlab-mpc-tabs' ) || target.closest( '.lucidlab-mpc-tabs, .lucidlab-mpc-nav-card' ) ) ) {
				target = topNotices;
			}
			var atTop = false;
			if ( target && ( target.id === 'lucidlab-mpc-top-notices' || target.classList.contains( 'lucidlab-mpc-top-notices' ) || target.classList.contains( 'lucidlab-mpc-admin-content' ) ) ) {
				target.insertAdjacentElement( 'afterbegin', box );
				atTop = true;
			} else if ( target ) {
				// Prefer below the button row (Backup / Run All) so Confirm stays near the control.
				var row = target.classList && ( target.classList.contains( 'lucidlab-mpc-btn-row' ) || target.classList.contains( 'lucidlab-mpc-welcome-actions' ) || target.id === 'lucidlab-mpc-run-all-picker' )
					? target
					: ( target.closest ? target.closest( '.lucidlab-mpc-btn-row, .lucidlab-mpc-welcome-actions, #lucidlab-mpc-run-all-picker' ) : null );
				if ( row ) {
					row.insertAdjacentElement( 'afterend', box );
				} else {
					target.insertAdjacentElement( 'afterend', box );
				}
			} else {
				document.body.appendChild( box );
			}
			// nearest often does nothing when the host button is already on screen (Emergency restore).
			requestAnimationFrame( function () {
				if ( box.scrollIntoView ) {
					box.scrollIntoView( { behavior: 'smooth', block: atTop ? 'start' : 'center' } );
				}
				var firstBtn = el( 'button', box );
				if ( firstBtn && firstBtn.focus ) { firstBtn.focus( { preventScroll: true } ); }
			} );
		} );
	}
	var confirmedForms = new WeakSet();
	document.addEventListener( 'submit', async function ( event ) {
		var form = event.target;
		if ( ! form.hasAttribute( 'data-mpc-confirm-message' ) ) { return; }
		if ( confirmedForms.has( form ) ) { confirmedForms.delete( form );
 if (form.querySelector('[name="lucidlab_mpc_op"][value="undo_rename"]')) {
  form.setAttribute('aria-busy','true');
  var busy=document.createElement('span');busy.setAttribute('role','status');busy.innerHTML='<span class="spinner is-active" style="float:none;margin:0 6px;vertical-align:middle"></span>Undoing rename\u2026';form.appendChild(busy);
  form.querySelectorAll('button').forEach(function(button){button.disabled=true;});
 }
 if ( form.querySelector( '[name="lucidlab_mpc_op"][value="reset_plugin_data"]' ) ) {
  form.setAttribute( 'aria-busy', 'true' );
  var resetBtn = event.submitter || form.querySelector( 'button[type="submit"], .button' );
  // Disabled checkboxes are omitted from POST — copy quarantine opt-in into a hidden field first.
  var quarBox = form.querySelector( 'input[name="lucidlab_mpc_reset_quarantine"]' );
  if ( quarBox && quarBox.checked ) {
   var quarHidden = form.querySelector( 'input[type="hidden"][name="lucidlab_mpc_reset_quarantine"]' );
   if ( ! quarHidden ) {
    quarHidden = document.createElement( 'input' );
    quarHidden.type = 'hidden';
    quarHidden.name = 'lucidlab_mpc_reset_quarantine';
    form.appendChild( quarHidden );
   }
   quarHidden.value = quarBox.value || '1';
  }
  preserveSubmitter( form, resetBtn );
  buttonBusyInside( resetBtn, true );
  form.querySelectorAll( 'button' ).forEach( function ( control ) {
   if ( control !== resetBtn ) { control.disabled = true; }
  } );
  if ( quarBox ) { quarBox.disabled = true; }
  try { sessionStorage.setItem( 'lucidlab-mpc-options-scroll-top', '1' ); } catch ( e ) {}
 }
 return; }
		event.preventDefault();
		var submitter = event.submitter;
		var requireText = form.getAttribute( 'data-mpc-confirm-text' ) || '';
		var preview = requireText ? { skip_note: true, skip_meta: true, require_text: requireText } : undefined;
		if ( await inlineConfirm( form.getAttribute( 'data-mpc-confirm-message' ), form, preview ) ) {
			if ( requireText ) {
				var confirmField = form.querySelector( '[name="lucidlab_mpc_reset_confirm"]' );
				if ( confirmField ) { confirmField.value = requireText; }
			}
			confirmedForms.add( form );
			if ( submitter ) { form.requestSubmit( submitter ); } else { form.requestSubmit(); }
		}
	}, true );


	document.addEventListener( 'click', function ( event ) {
		var retryBtn = event.target.closest( '[data-mpc-retry-job]' );
		if ( ! retryBtn || retryBtn.disabled ) { return; }
		event.preventDefault();
		retryBtn.disabled = true;
		var key = retryBtn.getAttribute( 'data-key' ) || '';
		var special = retryBtn.getAttribute( 'data-special' ) || '';
		var work;
		if ( special === 'regen' ) { work = post( { op: 'regen', sub: 'retry' } ); }
		else if ( special === 'webp' ) { work = post( { op: 'convert', tab: 'webp', sub: 'retry', settings: '{}' } ); }
		else { work = taskRequest( 'retry', { key: key } ); }
		work.then( function () { window.location.reload(); } ).catch( function ( error ) {
			retryBtn.disabled = false;
			showActionError( error );
		} );
	} );

    /* Persistent batches use the same server runner in this page and in WP-Cron. */
    var durableTasks = {};
	var aiPump = { timer: null, pumping: false, command: null, state: {}, startIds: [], focusId: 0 };
    async function taskRequest(action, input) {
        var response = await post({op:'taskjob',sub:action,request:JSON.stringify(input||{})});
        if (!response || !response.success) throw Error(response && response.data && response.data.message || mpcText("Job request failed"));
        return response.data;
    }

	var jobSourceLabels={duplicates:mpcText("Duplicate Products"),orphans:mpcText("Orphan Images"),uploads:mpcText("Uploads Scanner"),sizes:mpcText("Image Sizes"),'fs-sizes':mpcText("Other Sizes on Disk"),missing:mpcText("Missing Images"),crawl:mpcText("Missing Images"),product_copy:mpcText("Product AI"),shared:mpcText("Shared Images"),dupcontent:mpcText("Byte Identical Images"),visual:mpcText("Visually Identical Images"),regenerate:mpcText("Regenerate Thumbnails"),webp:mpcText("WebP"),webpstats:mpcText("WebP"),webpscan:mpcText("WebP"),webpclean:mpcText("WebP"),dbcleaner:mpcText("Database Cleaner"),quarantine:mpcText("Quarantine"),rename:mpcText("Rename Images"),rename_ai:mpcText("Rename Images AI"),review:mpcText("Review Queue"),welcome:mpcText("Getting Started"),backup:mpcText("Backup")};
	function jobSource(job){var key=job.scan_tab||job.panel||'';return String(job.key||'').indexOf('run_all')===0?mpcText("Run All"):jobSourceLabels[key]||key.replace(/[-_]/g,' ').replace(/\b\w/g,function(c){return c.toUpperCase();})||mpcText("General");}
	function isOtherSizesJob(job){return (job.scan_tab||job.progress_id||'')==='fs-sizes' || String(job.key||'').indexOf('scan_fs-sizes')===0;}
	function isRefreshStatsJob(job){return (job.scan_tab||'')==='webpstats' || String(job.key||'').indexOf('scan_webpstats')===0;}
	function isFindCopiesJob(job){return (job.progress_id||'')==='webpscan' || String(job.key||'').indexOf('webpscan')===0;}
	function isWebpCleanJob(job){return (job.progress_id||'')==='webpclean' || String(job.key||'').indexOf('webpclean')===0;}
	function webpCleanFormat(job){var fromJob=String(job.format||'').toLowerCase();if(fromJob==='webp'||fromJob==='avif')return fromJob;var match=String(job.key||'').match(/(?:^|_)(webp|avif)$/i);return match?match[1].toLowerCase():'';}
	function isRestoreJob(job){var key=String(job.key||'');var sub=String(job.sub_action||job.scan_tab||'');return key.indexOf('restore_quarantine')!==-1||key.indexOf('restore_one')!==-1||sub==='restore_quarantine'||sub==='restore_one';}
	function isWebpConvertJob(job){return job.special==='webp' || job.key==='lucidlab_mpc_webp_job';}
	function webpConvertFallback(job){
		var formats=job.formats||[];
		var idx=Number(job.format_index||0);
		var current=formats[idx]||'';
		var name=current==='avif'?mpcText("AVIF"):current==='webp'?mpcText("WebP"):'';
		var webp=Number(job.made_webp||0),avif=Number(job.made_avif||0);
		var made=Math.max(Number(job.made||0),webp+avif);
		var skipped=Number(job.skipped||0);
		var total=Number(job.total||0);
		var phases=Math.max(1,formats.length||1);
		var unitsDone=Math.min(phases*Math.max(0,total),(idx*Math.max(0,total))+Math.max(0,Number(job.offset||0)));
		var unitsTotal=Math.max(1,phases*Math.max(0,total));
		var counts=[];
		function larger(n){n=Number(n||0);return n?' ('+n+mpcText(" not smaller, removed)"):'';}
		if(formats.indexOf('webp')>=0||webp)counts.push(mpcText("WebP ")+webp+mpcText(" created")+larger(job.larger_webp));
		if(formats.indexOf('avif')>=0||avif)counts.push(mpcText("AVIF ")+avif+mpcText(" created")+larger(job.larger_avif));
		if(!counts.length&&made)counts.push(made+mpcText(" copies created"));
		if(skipped&&['paused','cancelled','done'].indexOf(job.status)>=0)counts.push(skipped+mpcText(" skipped"));
		var originals=Number(job.originals||0);if(originals)counts.push(originals+mpcText(" originals quarantined"));
		var extra=counts.join(' \u00B7 ');
		if(job.status==='paused')return extra?mpcText("Stopped — ")+extra:mpcText("Stopped");
		if(job.status==='cancelled')return extra?mpcText("Cancelled — ")+extra:mpcText("Cancelled");
		if(job.status==='done')return extra?mpcText("Finished — ")+extra:mpcText("Finished");
		// Match the progress bar: image×format units across every selected format.
		if(name)return mpcText("Converting to ")+name+' \u2014 '+unitsDone+'/'+unitsTotal+mpcText(" images");
		return mpcText("Converting — ")+unitsDone+'/'+unitsTotal+mpcText(" images");
	}
	var quarantineSourceLabels={orphans:mpcText("Orphan Images"),uploads:mpcText("Uploads Scanner"),webp:mpcText("Generated WebP"),avif:mpcText("Generated AVIF"),originals:mpcText("Original Images"),'fs-sizes':mpcText("Other Sizes on Disk"),sizes:mpcText("Image Sizes"),dupcontent:mpcText("Byte Identical Images"),visual:mpcText("Visually Identical Images"),missing:mpcText("Missing Images"),shared:mpcText("Shared Images"),dbcleaner:mpcText("Database Cleaner")};
	function quarantineSetSource(job){return String(job.source||job.lucidlab_mpc_source||'').toLowerCase();}
	function quarantineSetName(job){return quarantineSourceLabels[quarantineSetSource(job)]||'';}
	function isPurgeJob(job){var key=String(job.key||'');var sub=String(job.sub_action||'');return key.indexOf('purge_quarantine')!==-1||key.indexOf('purge_source')!==-1||sub==='purge_quarantine'||sub==='purge_source';}
	function countsAsScanJob(job){
		var op=jobOp(job);
		return op==='scan'||op==='webpscan'||(!op&&(isOtherSizesJob(job)||isRefreshStatsJob(job)));
	}
	function finishedRowSource(job){
		if(!job)return '';
		if(job.special==='regen'||job.key==='lucidlab_mpc_regen_job')return 'regenerate';
		if(isWebpConvertJob(job))return 'webp';
		if(!countsAsScanJob(job))return '';
		var source=job.scan_tab||job.panel;
		return ({webpstats:'webp'})[source]||source||'';
	}
	function jobOp(job){
		if(job.special==='backup')return 'backup';
		if(job.special==='ai')return 'ai';
		if(job.special==='regen')return 'regen';
		if(job.special==='rename')return 'rename';
		if(job.special==='webp')return 'convert';
		if(job.op)return String(job.op);
		var key=String(job.key||'');
		if(key.indexOf('run_all')===0)return 'run_all';
		if(key.indexOf('webpscan')===0)return 'webpscan';
		if(key.indexOf('webpclean')===0)return 'webpclean';
		if(key.indexOf('scan_')===0)return 'scan';
		if(key.indexOf('quarantine_')===0)return 'quarantine';
		if(key.indexOf('trash_dup')===0)return 'trash_dup';
		if(key.indexOf('merge')===0)return 'merge';
		if(key.indexOf('form_')===0)return 'form';
		return '';
	}
	function jobTab(job){return String(job.scan_tab||job.progress_id||job.panel||'');}
	function isRunAllJob(job){return String(job.key||'').indexOf('run_all')===0||jobOp(job)==='run_all';}
	function hasActiveRunAll(){return knownRunningJobs.some(function(job){return isRunAllJob(job)&&['running','paused'].includes(job.status);});}
	function isRunAllOwnedJob(job){
		if(isRunAllJob(job))return false;
		if(String(job.driver||'')==='run_all')return true;
		return hasActiveRunAll()&&(isWebpConvertJob(job)||job.special==='regen'||job.key==='lucidlab_mpc_regen_job');
	}
	function runningJobTitle(job){
		var op=jobOp(job),tab=jobTab(job),setName=quarantineSetName(job),sub=String(job.sub_action||'');
		if(op==='run_all'||String(job.key||'').indexOf('run_all')===0)return mpcText("Getting Started — Run All Scans");
		if(op==='backup'){
			var kind=String(job.kind||'');
			if(kind==='db')return mpcText("Backup — Backup Database");
			if(kind==='uploads')return mpcText("Backup — Backup uploads");
			if(kind==='site')return mpcText("Backup — Backup full site");
			if(kind==='restore_db')return mpcText("Backup — Restore database");
			if(kind==='restore_uploads')return mpcText("Backup — Restore uploads");
			return mpcText("Backup — Backup");
		}
		if(op==='ai')return job.scope==='selected'||(Array.isArray(job.ids)&&job.ids.length)?mpcText("Rename Images AI — Analyze selected images"):mpcText("Rename Images AI — Analyze entire library");
		if(op==='regen')return mpcText("Regenerate Thumbnails — Generate thumbnails");
		if(op==='rename')return job.pattern?mpcText("Rename Images — Rename All Images"):mpcText("Rename Images — Rename Images on this page");
		if(op==='convert')return mpcText("WebP / AVIF — Generate copies");
		if(op==='webpscan'||isFindCopiesJob(job))return mpcText("WebP / AVIF — Find generated copies");
		if(op==='webpclean'||isWebpCleanJob(job)){
			var fmt=webpCleanFormat(job);
			return fmt==='avif'?mpcText("WebP / AVIF — Quarantine AVIF Images"):fmt==='webp'?mpcText("WebP / AVIF — Quarantine WebP Images"):mpcText("WebP / AVIF — Quarantine");
		}
		if(isRestoreJob(job))return mpcText("Quarantine — Restoring ")+(setName||'items');
		if(isPurgeJob(job))return mpcText("Quarantine — Deleting ")+(setName||(String(job.lucidlab_mpc_set||'')==='ALL'?mpcText("all sets"):'items'));
		if(sub==='undo_merge'||String(job.key||'').indexOf('undo_merge')!==-1)return mpcText("Quarantine — Undo merge");
		if(sub==='clean_empty'||String(job.key||'').indexOf('clean_empty')!==-1)return mpcText("Quarantine — Clean empty folders");
		if(op==='quarantine'){
			if(job.panel==='review'||tab==='review')return mpcText("Review Queue — Quarantine selected");
			if(tab==='fs-sizes'||tab==='fs-sizes-quar')return mpcText("Other Sizes on Disk — Quarantine selected dimensions");
			if(tab==='sizes')return mpcText("Image Sizes — Quarantine unused sizes");
			if(tab==='orphans')return mpcText("Orphan Images — Move selected to quarantine");
			if(tab==='uploads')return mpcText("Uploads Scanner — Move selected to quarantine");
			if(tab==='dbcleaner'){var dbMode=String(job.db_mode||(job.request&&job.request.db_mode)||'');return dbMode==='rows'?mpcText("Database Cleaner — Quarantine selected records"):mpcText("Database Cleaner — Quarantine selected tables");}
			if(tab==='shared')return mpcText("Shared Images — Quarantine image");
			return (jobSourceLabels[tab]||mpcText("Quarantine"))+mpcText(" — Quarantine");
		}
		if(op==='merge')return (job.panel==='visual'||job.scan_tab==='visual'||job.source==='visual')?mpcText("Visually Identical Images — Merge group into its Keep image"):mpcText("Byte Identical Images — Merge all groups into their Keep image");
		if(op==='trash_dup')return mpcText("Duplicate Products — Trash selected + cascade variations");
		if(op==='scan'||(!op&&(isOtherSizesJob(job)||isRefreshStatsJob(job)))){
			if(tab==='fs-sizes'||isOtherSizesJob(job))return mpcText("Other Sizes on Disk — Scan other sizes");
			if(tab==='sizes')return mpcText("Image Sizes — Analyze sizes");
			if(tab==='webpstats'||isRefreshStatsJob(job))return mpcText("WebP / AVIF — Refresh stats");
			if(tab==='orphans')return mpcText("Orphan Images — Scan orphan images");
			if(tab==='uploads')return mpcText("Uploads Scanner — Scan uploads");
			if(tab==='dbcleaner')return mpcText("Database Cleaner — Scan database");
			if(tab==='missing')return mpcText("Missing Images — Scan missing images");
			if(tab==='crawl')return mpcText("Missing Images — Check live pages");
			if(tab==='product_copy')return mpcText("Product AI — Scan products");
			if(tab==='shared')return mpcText("Shared Images — Find shared images");
			if(tab==='duplicates')return mpcText("Duplicate Products — Scan duplicate products");
			if(tab==='dupcontent')return mpcText("Byte Identical Images — Find byte-identical images");
			if(tab==='visual')return mpcText("Visually Identical Images — Find visually identical images");
			if(op==='scan')return jobSource(job)+' \u2014 Scan';
		}
		return jobSource(job)+mpcText(" — Background job");
	}
	function runningJobActivity(job){
		if(job.pending){
			if(isRestoreJob(job))return mpcText("Starting restore of ")+(quarantineSetName(job)||mpcText("quarantine items"))+'\u2026';
			if(isPurgeJob(job))return mpcText("Starting delete of ")+(quarantineSetName(job)||mpcText("quarantine items"))+'\u2026';
			return mpcText("Starting…");
		}
		if(runningJobMessages[job.key])return runningJobMessages[job.key];
		if(job.status==='queued')return mpcText("Queued. Starts automatically when a job slot is free.");
		if(job.status==='paused')return mpcT('pausedResume','Paused; Resume to continue');
		if(job.status==='running' && job.driver==='cron' && Number(job.updated)>0 && Date.now()/1000-Number(job.updated)>600){
			return mpcT('jobStale','No saved progress for over 10 minutes. Check WP-Cron and server errors in Logs / Diagnostics.')+' '+mpcT('lastUpdate','Last update:')+' '+formatJobDate(job.updated,job.updated_label);
		}
		var label=String(job.label||job.message||'').trim();
		var text='';
		if(isRestoreJob(job)||isPurgeJob(job)){
			var setName=quarantineSetName(job);
			if(label){
				text=label;
				if(setName && label.indexOf(setName)===-1){
					text=(isRestoreJob(job)?mpcText("Restoring "):mpcText("Deleting "))+setName+' \u2014 '+label.replace(/^(Restoring|Deleting)\s+/i,'');
				}
			}else{
				text=(isRestoreJob(job)?mpcText("Restoring "):mpcText("Deleting "))+(setName||'items')+'\u2026';
			}
		}else if(label){
			if(job.special==='backup' && /restore finished/i.test(label) && ['running','paused'].includes(job.status)){
				label='';
			}
			if(job.special==='rename' && label.indexOf('Renaming:')===0){
				text=label;
			}else if(!label){
				text=job.special==='backup'?mpcText("Working on backup…"):'';
			}else{
				// The card title already names the tool. Repeating it here produced
				// "Missing Images — Scan missing images" above "Missing Images — Indexing…".
				text=label;
			}
		}else if(job.special==='ai')text=mpcText("Analyzing library images…");
		else if(job.special==='backup')text=mpcText("Working on backup…");
		else if(isWebpConvertJob(job)){
			text=String(job.label||job.message||'').trim()||webpConvertFallback(job);
		}else if(job.status==='paused')text=mpcText("Paused; Resume to continue");
		else text=mpcText("Processing batches");
		// The active Run All stage already has its own caption above its progress bar.
		if(isRunAllJob(job)&&job.status==='running'&&(job.stages||[]).some(function(stage){return stage.status==='running';}))text='';
		if(job.driver==='cron'&&job.status==='running'&&job.explicit_background){
			var bg=mpcText("Running in the background. You can leave this page and come back later to see how far the scan has got.");
			// Only after Continue in background. A cron driver alone is not that choice.
			text=text.replace(/\s*\u2014\s*Running in the background\. You can leave this page and come back later to see how far the scan has got\.?/g,'').split(bg).join('').trim();
			text=text?text.replace(/\u2026$/,'')+'\n'+bg:bg;
		}
		return text;
	}
	function specialJobRequest(job,action){
		var request={tab:job.panel||job.scan_tab||'rename'};
		if(job.special==='ai')return post({op:'ai_job',tab:'rename_ai',sub:action==='background'?'background':action});
		if(job.special==='backup')return post({op:'backup',tab:'backup',sub:action==='background'?'bg':action==='stop'?'pause':action});
		if(job.special==='regen')return post({op:'regen',sub:action==='background'?'bg':action==='stop'?'pause':action});
		if(job.special==='rename')return post({op:'rename_job',sub:action});
		if(job.special==='webp')return post({op:'convert',tab:'webp',sub:action==='background'?'background':action,settings:'{}'});
		return taskRequest(action,{key:job.key});
	}
	var runningCommands={};
	var mpcPaintRegen=function(){};
	var mpcPaintWebp=function(){};
	var pendingRunningJobs={};
	var knownRunningJobs=[];
	var runningJobMessages={};
	var backupSent=0;
    var runningJobOrder={},nextRunningJobOrder=0;
    var runningOrderStorage='lucidlab-mpc-running-order:'+LucidLabMPC.ajaxUrl;
    try { runningJobOrder=JSON.parse(sessionStorage.getItem(runningOrderStorage)||'{}')||{}; } catch(e) {}
    Object.keys(runningJobOrder).forEach(function(key){nextRunningJobOrder=Math.max(nextRunningJobOrder,Number(runningJobOrder[key])+1);});
    function ensureRunningJobOrder(job,order){
        if(!job||!job.key)return;
        if(order!==undefined || runningJobOrder[job.key]===undefined){
            runningJobOrder[job.key]=order===undefined?nextRunningJobOrder++:order;
            try { sessionStorage.setItem(runningOrderStorage,JSON.stringify(runningJobOrder)); } catch(e) {}
        }
    }
	function runningJobProgress(job){
		var exact=job&&(job.progress_exact===0||job.progress_exact)?Number(job.progress_exact):NaN;
		var pct=Number.isFinite(exact)?exact:(Number(job&&job.progress)||0);
		if(!Number.isFinite(pct))pct=0;
		return Math.max(0,Math.min(100,pct));
	}
	// Measured from the job start, not from each batch request, so it does not restart every batch.
	var runningStartedAt={};
	var runningStartedMarker={};
	// Server clock minus browser clock. The smallest gap seen is the real one; a reply that waited in a queue only looks older.
	var mpcClockSkew=null;
	function jobStartedAt(job){
		if(!job||!job.key)return 0;
		var marker=Number(job.started)||0;
		if(marker&&runningStartedMarker[job.key]!==marker){
			delete runningStartedAt[job.key];
			runningStartedMarker[job.key]=marker;
		}
		var now=Number(job.now)||0;
		if(now>1e9){var skew=Date.now()-now*1000;if(mpcClockSkew===null||skew<mpcClockSkew)mpcClockSkew=skew;}
		// The stored start time is the only source: a reload or a slow poll can no longer restart the timer.
		if(marker>1e9){
			runningStartedAt[job.key]=marker*1000+(mpcClockSkew||0);
			return runningStartedAt[job.key];
		}
		if(Number.isFinite(Number(job.elapsed))&&job.elapsed!==''&&job.elapsed!==null){
			var fromServer=Date.now()-Number(job.elapsed)*1000;
			if(!runningStartedAt[job.key]||Math.abs(runningStartedAt[job.key]-fromServer)>5000)runningStartedAt[job.key]=fromServer;
		}
		if(!runningStartedAt[job.key]&&(job.pending||job.status==='running')){
			runningStartedAt[job.key]=Date.now();
		}
		return runningStartedAt[job.key]||0;
	}
	function withElapsed(text,startedAt){
		if(!startedAt)return text;
		var s=Math.max(0,Math.floor((Date.now()-startedAt)/1000)),m=Math.floor(s/60);
		var span=m?m.toLocaleString()+'m '+(s%60)+'s':s+'s';
		var lines=String(text||'').split('\n');
		lines[0]=(lines[0]?lines[0].replace(/\u2026$/,'')+' \u2014 ':'')+mpcText("running for ")+span;
		return lines.join('\n');
	}
	function fillRunningProgress(bar,job){
		if(!bar)return;
		var pct=runningJobProgress(job);
		var firstStage=(isRunAllJob(job) && Array.isArray(job.stages) && job.stages.length) ? job.stages[0] : null;
		var unknown=job.pending||(['running','paused'].includes(job.status)&&(job.indeterminate||(job.special==='backup'&&pct<1)||(pct===0&&firstStage&&firstStage.status==='running'&&firstStage.indeterminate)));
		var wrap=bar.parentElement;
		if(wrap)wrap.classList.toggle('lucidlab-mpc-running-job-bar--indeterminate',!!unknown);
		if(wrap)wrap.classList.toggle('lucidlab-mpc-running-job-bar--working',job.status==='running'&&!unknown);
		if(unknown){
			// Assigning .value would make the bar determinate again (including a stuck 54%).
			bar.removeAttribute('value');
			bar.dataset.indeterminate='1';
			bar.setAttribute('aria-busy','true');
			bar.removeAttribute('aria-valuenow');
			bar.removeAttribute('aria-valuetext');
		}else{
			bar.value=pct;
			delete bar.dataset.indeterminate;
			bar.removeAttribute('aria-busy');
			bar.setAttribute('aria-valuenow',String(Math.round(pct)));
			if(job.progress_estimate){bar.setAttribute('aria-valuetext','≈ '+Math.round(pct)+'%');}
			else{bar.removeAttribute('aria-valuetext');}
		}
		var label=wrap&&wrap.querySelector('.lucidlab-mpc-running-job-pct');
		if(label){
			label.hidden=!!unknown;
			label.textContent=unknown?'':(job.progress_estimate?'≈ ':'')+(pct>0&&pct<0.01?'<0.01%':pct.toFixed(2)+'%');
		}
	}
	function addRunAllStageProgress(item, job, overallBar) {
		item.querySelectorAll('.lucidlab-mpc-current-stage-caption, .lucidlab-mpc-current-stage-bar, .lucidlab-mpc-overall-progress-caption').forEach(function(node){ node.remove(); });
		if (!isRunAllJob(job)) return;
		var stage = (job.stages || []).find(function(entry){ return entry.status === 'running'; });
		if (stage) {
			var caption = document.createElement('div');
			caption.className = 'lucidlab-mpc-current-stage-caption';
			caption.textContent = stage.label + (stage.message ? ' — ' + stage.message : '');
			item.insertBefore(caption, overallBar);
			{
				var wrap = document.createElement('div');
				wrap.className = 'lucidlab-mpc-running-job-bar lucidlab-mpc-current-stage-bar';
				var bar = document.createElement('progress');
				bar.max = 100;
				bar.setAttribute('aria-label', stage.label + mpcText(" progress"));
				var label = document.createElement('div');
				label.className = 'lucidlab-mpc-running-job-pct';
				label.setAttribute('aria-hidden', 'true');
				wrap.append(bar, label);
				var value = Number(stage.progress);
				// Use the scanner's measured percentage even when individual batches vary in cost.
				fillRunningProgress(bar, {key:'current-stage', progress:value, status:job.status, indeterminate:(stage.indeterminate && !Number.isFinite(value)) || stage.progress == null || !Number.isFinite(value)});
				item.insertBefore(wrap, overallBar);
			}
		}
		var overallCaption = document.createElement('div');
		overallCaption.className = 'lucidlab-mpc-overall-progress-caption';
		overallCaption.textContent = mpcText("Run All — overall progress");
		item.insertBefore(overallCaption, overallBar);
	}
	function runningActivitySelector(key){return '[data-running-activity="'+(window.CSS&&CSS.escape?CSS.escape(key):String(key).replace(/"/g,'\\"'))+'"]';}
	function upsertRunningJob(job){
		if(!job||!job.key)return;
		var list=knownRunningJobs.slice();
		var found=false;
		list=list.map(function(item){if(item.key!==job.key)return item;found=true;return Object.assign({},item,job);});
		if(!found)list.push(job);
		knownRunningJobs=list;
		job=list.find(function(item){ return item.key===job.key; });
		// Always rebuild so stage list, Finished Jobs and progress stay in sync (partial DOM updates left stages on Waiting).
		renderRunningJobs(list);
	}
	function setRunningJobMessage(key,message){
		if(message){runningJobMessages[key]=message;}else{delete runningJobMessages[key];}
		var node=el(runningActivitySelector(key));
		if(node){
			var job=knownRunningJobs.filter(function(item){return item.key===key;})[0]||{key:key,label:node.dataset.label||'',status:node.dataset.status,driver:node.dataset.driver};
			node.textContent=runningJobActivity(job);
			return;
		}
		if(el('#lucidlab-mpc-running-jobs'))renderRunningJobs(knownRunningJobs);
	}
    async function controlRunningJob(job,action){
		if(action==='cancel'){
			var cancelHost=el('#lucidlab-mpc-running-jobs')||el('[data-running-activity="'+job.key+'"]')||null;
			if(!await inlineConfirm(mpcText("Cancel this job after the current batch finishes? Work already done stays in place."),cancelHost)){return;}
		}
        runningCommands[job.key]=action;
		if(action==='cancel'||action==='stop')setRunningJobMessage(job.key,action==='cancel'?mpcText("Canceling after the current batch…"):mpcText("Stopping after the current batch…"));
		if(action==='skip')setRunningJobMessage(job.key,mpcText("Skipping this stage after the current batch…"));
		if(action==='background')setRunningJobMessage(job.key,mpcText("Running in the background. You can leave this page and come back later to see how far the scan has got."));
		if(job.special==='backup' && (action==='cancel'||action==='stop')){clearTimeout(backupTimer);backupControl=true;}
		if(job.special==='ai' && (action==='cancel'||action==='stop')){aiPump.command=action==='stop'?'stop':'cancel';}
        try {
            var response, waits=0;
            do {
                response=await specialJobRequest(job,action);
                if(response && response.success===false)throw Error(response.data&&response.data.message||mpcText("Job command failed"));
                response=response&&response.success===true?response.data:response;
                if(action==='cancel' && response && response.status==='cancelled')break;
                if(action==='stop' && response && response.status==='paused')break;
                if(action==='skip' && response && !response.busy && ((response.stages||[]).some(function(stage){return stage.status==='skipped';})||response.status==='done'))break;
                if(response&&response.busy && waits<20){waits++;await new Promise(function(resolve){setTimeout(resolve,500);});}
				else break;
            }while(response&&response.busy);
			if(action==='skip'){var skipped=!response.busy&&((response.stages||[]).some(function(stage){return stage.status==='skipped';})||response.status==='done');setRunningJobMessage(job.key,skipped?'':mpcText("Skipping this stage after the current batch…"));}
			else if(action!=='background')setRunningJobMessage(job.key,'');
            if(!job.special){var task=durableTasks[job.key]||connectTask(response);if(task){task.render(response);task.schedule();}else if(action==='resume'){await taskRequest('resume',{key:job.key});}}
            else if(action==='resume'){var result=await specialJobRequest(job,'resume');if(result&&result.success===false)throw Error(result.data&&result.data.message||mpcText("Could not continue job"));}
			if(job.special==='regen'&&response)mpcPaintRegen(response);
			if(job.special==='webp'&&response)mpcPaintWebp(response);
        } finally {
			delete runningCommands[job.key];
			if(job.special==='backup')backupControl=false;
			refreshRunningJobs();
		}
    }
	function finishedJobTone(status){
		if(status==='running'||status==='pending')return 'running';
		if(status==='paused')return 'paused';
		if(status==='done')return 'done';
		if(status==='cancelled')return 'cancelled';
		if(status==='failed')return 'failed';
		return 'never';
	}
	function finishedActions(dd){
		var wrap=dd.querySelector('.lucidlab-mpc-finished-actions');
		if(!wrap){
			wrap=document.createElement('span');
			wrap.className='lucidlab-mpc-finished-actions';
			dd.appendChild(wrap);
		}
		return wrap;
	}
	function syncFinishedRerun(row, tone){
		var dd=row.querySelector('dd');
		if(!dd)return;
		var wrap=dd.querySelector('.lucidlab-mpc-finished-actions');
		var link=dd.querySelector('.lucidlab-mpc-finished-rerun');
		if(tone==='running'||tone==='paused'){
			if(wrap)wrap.hidden=true;
			else if(link)link.hidden=true;
			return;
		}
		if(tone==='never'){
			if(link)link.hidden=true;
			return;
		}
		wrap=finishedActions(dd);
		wrap.hidden=false;
		if(!link){
			link=document.createElement('a');
			link.className='lucidlab-mpc-finished-rerun';
			link.href='#';
			link.textContent=mpcT('runAgain','Run again');
			wrap.appendChild(link);
		}
		link.hidden=false;
	}
	function finishedReplay(row){
		try{return JSON.parse(row.getAttribute('data-finished-replay')||'{}')||{};}catch(e){return {};}
	}
	async function startSavedWebp(){
		var settings=(window.LucidLabMPC&&LucidLabMPC.webpSettings)||{};
		pendingRunningJobs['webp-starting']={key:'webp-starting',pending:true,status:'running',panel:'webp',scan_tab:'webp',special:'webp',label:mpcText("Starting WebP / AVIF conversion")};
		ensureRunningJobOrder(pendingRunningJobs['webp-starting']);
		renderRunningJobs(knownRunningJobs);
		scrollToRunningJobs();
		var response=await post({op:'convert',tab:'webp',sub:'start',settings:JSON.stringify(settings)});
		if(!response||!response.success)throw Error(response&&response.data&&response.data.message||mpcText("Conversion request failed"));
		var data=response.data||{};
		while(data.busy){await new Promise(function(resolve){setTimeout(resolve,500);});response=await post({op:'convert',tab:'webp',sub:'start',settings:JSON.stringify(settings)});if(!response||!response.success)throw Error(response&&response.data&&response.data.message||mpcText("Conversion request failed"));data=response.data||{};}
		var pendingOrder=runningJobOrder['webp-starting'];
		delete pendingRunningJobs['webp-starting'];
		var job=Object.assign({},data,{key:'lucidlab_mpc_webp_job',panel:'webp',scan_tab:'webp',special:'webp'});
		ensureRunningJobOrder(job,pendingOrder);
		renderRunningJobs(knownRunningJobs.filter(function(item){return item.key!=='lucidlab_mpc_webp_job';}).concat([job]));
		(function schedule(current){
			if(!current||current.status!=='running')return;
			setTimeout(async function(){
				try{
					var nextResponse=await post({op:'convert',tab:'webp',sub:current.driver==='cron'?'status':'step',settings:'{}'});
					if(!nextResponse||!nextResponse.success)throw Error(nextResponse&&nextResponse.data&&nextResponse.data.message||mpcText("Conversion request failed"));
					var next=nextResponse.data||{};
					var nextJob=Object.assign({},next,{key:'lucidlab_mpc_webp_job',panel:'webp',scan_tab:'webp',special:'webp'});
					renderRunningJobs(knownRunningJobs.filter(function(item){return item.key!=='lucidlab_mpc_webp_job';}).concat([nextJob]));
					schedule(next);
				}catch(error){showActionError(error);}
			},current.driver==='cron'||current.busy?2500:150);
		})(data);
	}
	async function rerunFinishedJob(row){
		var source=row.dataset.finishedSource||'';
		var replay=finishedReplay(row);
		if(replay.special==='regen'||source==='regenerate'&&replay.special!=='webp'){
			await regenCommand('start',{mode:replay.mode||'skip',sizes:JSON.stringify(replay.sizes||[]),quality:replay.quality||85});
			return;
		}
		if(replay.special==='webp'||(source==='webp'&&(!replay.op||replay.op==='convert'))){
			var webpStart=el('#lucidlab-mpc-webp-start');
			if(webpStart){webpStart.click();return;}
			await startSavedWebp();
			return;
		}
		if(replay.special==='rename'||source==='rename'){
			if(!replay.pattern){throw Error(mpcText("This rename has no saved pattern yet. Open Rename Images, set the pattern, and run it once."));}
			await renameJobCommand('start',replay);
			return;
		}
		if(replay.special==='ai'||source==='rename_ai'){
			aiPump.command='start';
			aiPump.startIds=[];
			pendingRunningJobs['ai-starting']={key:'ai-starting',pending:true,status:'running',panel:'rename_ai',scan_tab:'rename_ai',special:'ai',scope:'library',label:mpcText("Starting AI library analysis")};
			ensureRunningJobOrder(pendingRunningJobs['ai-starting']);
			renderRunningJobs(knownRunningJobs);
			scrollToRunningJobs();
			ensureAiPump();
			return;
		}
		if(replay.op==='webpscan'){
			await persistentTask({op:'webpscan',progress_id:replay.progress_id||'webpscan',reset:1});
			return;
		}
		var extra={};
		if(replay.lucidlab_mpc_match_mode)extra.lucidlab_mpc_match_mode=replay.lucidlab_mpc_match_mode;
		if(replay.include_empty_gallery)extra.include_empty_gallery=1;
		runScan(replay.tab||source,extra);
	}
	function paintFinishedJob(row,status,text){
		if(!row)return;
		var tone=finishedJobTone(status);
		row.classList.remove('lucidlab-mpc-finished-job--running','lucidlab-mpc-finished-job--paused','lucidlab-mpc-finished-job--done','lucidlab-mpc-finished-job--cancelled','lucidlab-mpc-finished-job--failed','lucidlab-mpc-finished-job--never');
		row.classList.add('lucidlab-mpc-finished-job','lucidlab-mpc-finished-job--'+tone);
		row.dataset.finishedStatus=tone;
		var dd=row.querySelector('dd');
		if(!dd||text==null)return;
		var statusEl=dd.querySelector('.lucidlab-mpc-finished-status');
		if(!statusEl){
			statusEl=document.createElement('span');
			statusEl.className='lucidlab-mpc-finished-status';
			dd.insertBefore(statusEl, dd.firstChild);
		}
		statusEl.textContent=text;
		syncFinishedRerun(row, tone);
		var open=dd.querySelector('.lucidlab-mpc-finished-open');
		if(tone==='never'){
			var wrap=finishedActions(dd);
			var rerun=wrap.querySelector('.lucidlab-mpc-finished-rerun');
			if(rerun)rerun.hidden=true;
			if(!open){
				open=document.createElement('a');
				open.className='lucidlab-mpc-finished-open';
				open.textContent=mpcT('openTab','Open');
				var tabLink=row.querySelector('dt a');
				open.href=(LucidLabMPC.tabUrls&&LucidLabMPC.tabUrls[row.dataset.finishedSource])||(tabLink&&tabLink.href)||'#';
				wrap.insertBefore(open, rerun||null);
			}
		}else if(open){
			open.remove();
		}
	}
	function renderRunningJobs(jobs){
		var box=el('#lucidlab-mpc-running-jobs'),list=box&&el('[data-mpc-running-jobs-list]',box);if(!box||!list)return;
		knownRunningJobs=jobs||[];
		var runAllJobs=knownRunningJobs.filter(function(job){return String(job.key||'').startsWith('run_all');});
		var runAll=runAllJobs.filter(function(job){return ['running','paused'].includes(job.status);})[0]||runAllJobs[0];
		if(runAllJobs.some(function(job){return (job.stages||[]).some(function(stage){return stage.choice==='index'&&stage.status==='done';});})){
			els('.lucidlab-mpc-readiness-card').forEach(function(card){var title=card.querySelector('strong');if(!title||title.textContent.trim()!==mpcText('Media index'))return;card.classList.remove('is-check');card.classList.add('is-ready');var mark=card.querySelector('.lucidlab-mpc-readiness-mark .dashicons');if(mark){mark.classList.remove('dashicons-warning');mark.classList.add('dashicons-yes');}var state=card.querySelector('.lucidlab-mpc-readiness-state');if(state)state.textContent=mpcText('Ready');});
		}
		if(runAll)renderRunAllStages(runAll);else clearRunAllStages();
		knownRunningJobs.filter(function(job){return String(job.key||'').startsWith('run_all');}).forEach(function(job){(job.stages||[]).forEach(function(stage){if(stage.status!=='done'||stage.kind==='cleanup')return;var source=stage.tab||stage.panel;source=({webpstats:'webp'})[source]||source;var row=document.querySelector('[data-finished-source="'+source+'"]');if(!row)return;var completed=Number(stage.completed_at||0);if(!completed)completed=Number(row.dataset.finishedUpdated||job.updated||0);var wasLive=row.dataset.finishedStatus==='running'||row.dataset.finishedStatus==='paused';if(!wasLive&&completed<Number(row.dataset.finishedUpdated||0))return;row.dataset.finishedUpdated=completed;paintFinishedJob(row,'done',mpcT('completedOnPrefix','Completed on')+' '+formatJobDate(completed,completed===Number(job.updated)?job.updated_label:''));});});
		els('[data-finished-source]').forEach(function(row){
			var stageJob=runAll&&['running','paused'].includes(runAll.status)&&(runAll.stages||[]).find(function(stage){var source=stage.tab||stage.panel;source=({webpstats:'webp'})[source]||source;return stage.status==='running'&&source===row.dataset.finishedSource;});
			if(stageJob){row.dataset.finishedUpdated=runAll.updated||0;paintFinishedJob(row,runAll.status,runAll.status==='paused'?mpcT('paused','Paused'):mpcT('currentlyRunning','Currently running'));return;}
			var matches=knownRunningJobs.concat(Object.keys(pendingRunningJobs).map(function(key){return pendingRunningJobs[key];})).filter(function(j){var source=finishedRowSource(j);return source&&source===row.dataset.finishedSource&&!String(j.key).startsWith('run_all');});
			matches.sort(function(a,b){return Number(b.updated||0)-Number(a.updated||0);});
			var active=matches.filter(function(j){return j.pending||['running','paused','queued'].includes(j.status);});
			if(!active.length && (row.dataset.finishedStatus==='running'||row.dataset.finishedStatus==='paused')){
				var ownedStage=runAll&&['running','paused'].includes(runAll.status)&&(runAll.stages||[]).find(function(stage){var source=stage.tab||stage.panel;source=({webpstats:'webp'})[source]||source;return source===row.dataset.finishedSource&&['queued','running','failed','done'].includes(stage.status);});
				if(ownedStage){if(ownedStage.status==='done'){var ownedAt=Number(ownedStage.completed_at||0);if(ownedAt){row.dataset.finishedUpdated=ownedAt;paintFinishedJob(row,'done',mpcT('completedOnPrefix','Completed on')+' '+formatJobDate(ownedAt,''));}}return;}
				var terminal=matches.find(function(j){return j.status==='cancelled';})||matches.find(function(j){return j.status==='failed';})||matches.find(function(j){return j.status==='done';});
				var stamp=Number((terminal&&terminal.updated)||row.dataset.finishedUpdated||0);
				var date=stamp>0?formatJobDate(stamp,terminal&&terminal.updated_label):'';
				var termStatus=(terminal&&terminal.status)||'cancelled';
				if(terminal)row.dataset.finishedUpdated=terminal.updated||row.dataset.finishedUpdated;
				var termText=termStatus==='cancelled'?(date?mpcT('cancelledOnPrefix','Cancelled on')+' '+date:mpcT('cancelledOnPrefix','Cancelled')):termStatus==='failed'?(date?mpcT('failedOnPrefix','Failed on')+' '+date:mpcT('failedOnPrefix','Failed')):(date?mpcT('completedOnPrefix','Completed on')+' '+date:mpcT('completedOnPrefix','Completed'));
				paintFinishedJob(row,termStatus,termText);
				return;
			}
			var j=active.find(function(item){return item.pending;})||active[0]||matches[0];
			if(!j)return;
			// Live running/paused always wins over a stale Completed stamp.
			if(!['running','paused'].includes(j.status)&&!j.pending&&Number(j.updated||0)<Number(row.dataset.finishedUpdated||0))return;
			var priorStamp=Number(row.dataset.finishedUpdated||0),jobStamp=Number(j.updated||0);
			// Keep the server-rendered scan summary time when the job clock is seconds later.
			if(j.status==='done'&&row.dataset.finishedStatus==='done'&&priorStamp&&jobStamp&&Math.abs(priorStamp-jobStamp)<=90)return;
			row.dataset.finishedUpdated=j.updated||0;
			var date=Number(j.updated)>0?formatJobDate(j.updated,j.updated_label):mpcText("date unavailable");
			var status=j.pending?'running':j.status;
			var prefix=({done:mpcT('completedOnPrefix','Completed on')+' ',cancelled:mpcT('cancelledOnPrefix','Cancelled on')+' ',failed:mpcT('failedOnPrefix','Failed on')+' '})[status];
			// Queued (or any status without a date prefix) used to print "undefined" before the date.
			var text=status==='running'?mpcT('currentlyRunning','Currently running'):status==='paused'?mpcT('paused','Paused'):status==='queued'?mpcText("Queued"):(prefix?prefix+date:date);
			paintFinishedJob(row,status,text);
        });
		knownRunningJobs.forEach(function(job){if(job&&['done','failed','cancelled'].includes(job.status))delete runningJobMessages[job.key];});
		knownRunningJobs.forEach(function(job){ensureRunningJobOrder(job);});
		var jobMap={};knownRunningJobs.forEach(function(job){if(job&&job.key)jobMap[job.key]=job;});Object.keys(pendingRunningJobs).forEach(function(key){ensureRunningJobOrder(pendingRunningJobs[key]);jobMap[key]=pendingRunningJobs[key];});
		var allJobs=Object.keys(jobMap).map(function(key){return jobMap[key];});
		allJobs.sort(function(a,b){return runningJobOrder[a.key]-runningJobOrder[b.key];});
		var runAllActive=allJobs.some(function(job){return isRunAllJob(job)&&['running','paused'].includes(job.status);});
		var active=allJobs.filter(function(job){return ['running','paused','queued'].includes(job.status)&&!(runAllActive&&isRunAllOwnedJob(job));});
		if(Object.keys(runningCommands).length)return;box.hidden=!active.length;list.replaceChildren();
		active.forEach(function(job){var item=document.createElement('div');item.className='lucidlab-mpc-running-job';var title=document.createElement('strong');title.textContent=runningJobTitle(job);var source=document.createElement('span');source.className='lucidlab-mpc-hint';source.textContent=jobSource(job)+' \u00B7 '+(job.pending?mpcText("Starting"):job.status==='queued'?mpcText("Queued"):job.status==='paused'?mpcText("Paused"):job.driver==='cron'?mpcText("Running in background"):mpcText("Running"));var actions=document.createElement('div');actions.className='lucidlab-mpc-running-job-actions';[['stop',mpcT('stop','Stop')],['resume',mpcT('resume','Resume')],['skip',mpcT('skip','Skip')],['cancel',mpcT('cancel','Cancel')],['background',mpcT('continueBackground','Continue in background')]].forEach(function(pair){if(job.pending||pair[0]==='resume'&&job.status!=='paused'||pair[0]==='stop'&&job.status!=='running'||pair[0]==='skip'&&(!isRunAllJob(job)||!['running','paused'].includes(job.status))||pair[0]==='cancel'&&!['running','paused','queued'].includes(job.status)||pair[0]==='background'&&!canBackground()||pair[0]==='background'&&!['running','paused'].includes(job.status))return;var button=document.createElement('button');button.type='button';button.className='button';button.textContent=pair[1];button.disabled=pair[0]==='background'&&job.status==='running'&&job.driver==='cron';button.addEventListener('click',async function(){button.disabled=true;activity.textContent=({stop:mpcText("Stopping after the current batch…"),resume:mpcText("Resuming"),skip:mpcText("Skipping this stage after the current batch…"),cancel:mpcText("Canceling after the current batch…"),background:mpcText("Running in the background. You can leave this page and come back later to see how far the scan has got.")})[pair[0]];if(pair[0]==='stop')button.textContent=mpcText("Resume");try{await controlRunningJob(job,pair[0]);var refreshed=await taskRequest('list',{});renderRunningJobs(refreshed.jobs||[]);}catch(error){showActionError(error);}finally{button.disabled=false;}});actions.appendChild(button);});var progressWrap=document.createElement('div');progressWrap.className='lucidlab-mpc-running-job-bar lucidlab-mpc-overall-job-bar';var progress=document.createElement('progress');progress.max=100;progress.setAttribute('aria-label',jobSource(job)+mpcText(" progress"));var pctLabel=document.createElement('div');pctLabel.className='lucidlab-mpc-running-job-pct';pctLabel.setAttribute('aria-hidden','true');progressWrap.append(progress,pctLabel);fillRunningProgress(progress,job);var activity=document.createElement('div');activity.className='lucidlab-mpc-job-activity';activity.dataset.runningActivity=job.key;activity.dataset.status=job.status;activity.dataset.pending=job.pending?'1':'0';activity.dataset.driver=job.driver||'';activity.dataset.label=job.label||'';activity.setAttribute('role','status');activity.textContent=(job.status==='running'||job.pending)?withElapsed(runningJobActivity(job),jobStartedAt(job)):runningJobActivity(job);item.append(title,activity,progressWrap,actions);addRunAllStageProgress(item,job,progressWrap);
			if(String(job.key||'').startsWith('run_all')){var stages=document.createElement('ol');stages.className='lucidlab-mpc-running-stages';(job.stages||[]).forEach(function(stage){var row=document.createElement('li');var state=stage.status==='queued'?mpcText("Waiting"):stage.status==='running'?(job.status==='paused'?mpcText("Paused"):mpcText("Running")):stage.status==='skipped'?mpcText("Skipped"):stage.status;var prefix=(stage.status==='done'?'\u2713 ':stage.status==='skipped'?'- ':'')+stage.label+' \u2014 '+state;var msg=String(stage.message||'');if(stage.status==='done'&&(stage.tab==='sizes'||stage.panel==='sizes')&&/\b0 moved\s*$/.test(msg)){msg=mpcText("Nothing to be moved");}var reviewAt=msg.indexOf('Review this tab.');if(reviewAt!==-1&&stage.panel&&LucidLabMPC.tabUrls&&LucidLabMPC.tabUrls[stage.panel]){row.appendChild(document.createTextNode(prefix+' \u00B7 '+msg.slice(0,reviewAt)));var link=document.createElement('a');link.href=LucidLabMPC.tabUrls[stage.panel]+(String(LucidLabMPC.tabUrls[stage.panel]).indexOf('?')>=0?'&':'?')+'lucidlab_mpc_load=1';link.textContent=mpcT('reviewThisTab','Review this tab');row.appendChild(link);if(msg.slice(reviewAt+16))row.appendChild(document.createTextNode(msg.slice(reviewAt+16)));}else{row.textContent=prefix+(msg?' \u00B7 '+msg:'');}if(stage.status==='running'&&job.status==='running'){row.classList.add('lucidlab-mpc-run-all-active');row.setAttribute('aria-current','step');}if(stage.status==='done')row.className='lucidlab-mpc-run-all-finished';if(stage.status==='skipped')row.className='lucidlab-mpc-run-all-skipped';stages.appendChild(row);});item.appendChild(stages);}list.appendChild(item);});
	}
	function addRunAllBackgroundButton(){if(!canBackground())return;var job=knownRunningJobs.find(function(item){return String(item.key||'').startsWith('run_all')&&['running','paused'].includes(item.status);});var box=el('#lucidlab-mpc-running-jobs');if(!box||!job)return;var item=Array.from(box.querySelectorAll('.lucidlab-mpc-running-job')).find(function(row){var title=row.querySelector('strong');return title&&title.textContent.indexOf('Run All Scans')!==-1;});var actions=item&&item.querySelector('.lucidlab-mpc-running-job-actions');if(!actions||actions.querySelector('[data-run-all-background]')||Array.from(actions.querySelectorAll('button')).some(function(btn){return /background/i.test(btn.textContent);}))return;var button=document.createElement('button');button.type='button';button.className='button';button.dataset.runAllBackground='1';button.textContent=mpcT('continueBackground','Continue in background');button.addEventListener('click',function(){button.disabled=true;controlRunningJob(job,'background');});actions.appendChild(button);}
	var runningJobsList=el('[data-mpc-running-jobs-list]');if(runningJobsList&&window.MutationObserver)new MutationObserver(addRunAllBackgroundButton).observe(runningJobsList,{childList:true,subtree:true});
	function updateRunningActivity(){els('[data-running-activity]').forEach(function(node){var key=node.dataset.runningActivity,task=durableTasks[key],command=runningCommands[key],job=knownRunningJobs.filter(function(item){return item.key===key;})[0]||{key:key,status:node.dataset.status,driver:node.dataset.driver,pending:node.dataset.pending==='1',label:node.dataset.label||''};if(command){node.textContent=({stop:mpcText("Stopping after the current batch…"),resume:mpcText("Resuming"),skip:mpcText("Skipping this stage after the current batch…"),cancel:mpcText("Canceling after the current batch…"),background:mpcText("Running in the background. You can leave this page and come back later to see how far the scan has got.")})[command];return;}var text=runningJobActivity(job);var started=(job.status==='running'||job.pending)?(jobStartedAt(job)||(task&&task.job&&jobStartedAt(task.job))):0;if(started){text=withElapsed(text,started);}else{var sent=key==='lucidlab_mpc_backup_job'?backupSent:0;if(sent){var wait=Math.floor((Date.now()-sent)/1000);if(wait>=1)text=text.replace(/\u2026$/,'')+' \u2014 working '+wait+'s';}}node.textContent=text;});}
    setInterval(updateRunningActivity,1000);
    var initialJobs=window.lucidlabMpcInitialJobs||[];
	renderRunningJobs(initialJobs);
	function refreshRunningJobs(){taskRequest('list',{}).then(function(data){renderRunningJobs(data.jobs||[]);}).catch(function(){});}
	if(el('#lucidlab-mpc-running-jobs')){(function loopRunningJobs(){setTimeout(function(){if(!document.hidden)refreshRunningJobs();loopRunningJobs();},document.hidden?60000:(mpcIsLeader()?8000:20000));})();}
	function scrollToRunningJobs(){var jobs=el('#lucidlab-mpc-running-jobs');if(jobs&&jobs.scrollIntoView)jobs.scrollIntoView({behavior:'smooth',block:'start'});}

	function clearRunAllStages(){
		var host=el('#lucidlab-mpc-run-all-progress');
		if(host){host.hidden=true;host.replaceChildren();}
		els('.lucidlab-mpc-run-all-stage').forEach(function(card){card.remove();});
	}
	function renderRunAllSummary(job){
		var panel=el('[data-mpc-panel="welcome"]');if(!panel)return;
		var notice=el('#lucidlab-mpc-run-all-summary',panel);
		if(job.status!=='done'){if(notice)notice.remove();return;}
		if(!notice){notice=document.createElement('div');notice.id='lucidlab-mpc-run-all-summary';notice.className='notice notice-info inline lucidlab-mpc-job-summary';var actions=el('.lucidlab-mpc-welcome-actions',panel);(actions||panel).insertAdjacentElement(actions?'afterend':'afterbegin',notice);}
		var stages=job.stages||[];
		var complete=stages.filter(function(stage){return stage.status==='done';}).length;
		var skipped=stages.filter(function(stage){return stage.status==='skipped';}).length;
		var failed=stages.filter(function(stage){return stage.status==='failed';}).length;
		notice.textContent=mpcText('Run All completed on')+' '+(job.updated_label||formatJobDate(job.updated))+' — '+complete+' '+mpcText('stages completed')+(skipped?', '+skipped+' '+mpcText('skipped'):'')+(failed?', '+failed+' '+mpcText('failed'):'')+'.';
	}
	function ensureRunAllStageCompleteUi( name, updated ) {
		var panelName = ( { 'fs-sizes': 'sizes', webpstats: 'webp', crawl: 'missing' } )[ name ] || name;
		var panel=el('[data-mpc-panel="'+panelName+'"]');
		if(!panel||name==='sizes'||name==='options'||name==='regenerate')return;
		var scan=panel.querySelector('[data-mpc-scan="'+name+'"]')||panel.querySelector('#lucidlab-mpc-fssizes-scan')||panel.querySelector('#lucidlab-mpc-webpstats-scan');
		if(scan&&name!=='webp'){ensureViewCurrentResultsLink(scan,name);}
		var stamp=Number(updated||0);
		var date=stamp>0?formatJobDate(stamp):'';
		var summaryTarget=name;
		// Never paint Completed into the red snapshot warning (.lucidlab-mpc-missing-results-notice).
		var existing=panel.querySelector('.lucidlab-mpc-job-summary[data-mpc-job-summary="'+summaryTarget+'"]:not(.lucidlab-mpc-missing-results-notice)');
		if(!existing){
			existing=document.createElement('div');
			existing.className='notice notice-info inline lucidlab-mpc-job-summary';
			existing.dataset.mpcJobSummary=summaryTarget;
		}
		placeStatusAtPanelTop(panel,existing);
		var existingStamp=Number(existing.dataset.mpcCompleted||0);
		if(date&&stamp>=existingStamp){if(stamp)existing.dataset.mpcCompleted=String(stamp);existing.textContent=(name==='fs-sizes'?mpcText('Other Sizes on Disk')+' \u2014 ':'')+mpcT('completedOn','Completed on:')+' '+date;}
		setHidden(existing,false);
		var row=document.querySelector('[data-finished-source="'+name+'"]');
		if(row&&stamp>=Number(row.dataset.finishedUpdated||0)){
			row.dataset.finishedUpdated=stamp||row.dataset.finishedUpdated;
			paintFinishedJob(row,'done',date?mpcText("Completed on ")+date:mpcText("Completed"));
		}
	}
    function renderRunAllStages(job){
        var active=['running','paused'].includes(job.status),button=el('[data-mpc-run-all]');if(button)buttonBusyInside(button,active);
		renderRunAllSummary(job);
		if(!active){
			// Keep View current results + Completed on for finished stages after Run All ends.
			(job.stages||[]).forEach(function(stage){
				if(stage.status==='done'&&(stage.tab||stage.panel)){ensureRunAllStageCompleteUi(stage.tab||stage.panel,stage.completed_at||job.updated);}
			});
			clearRunAllStages();
			return;
		}
		clearRunAllStages();
        var grouped={};(job.stages||[]).forEach(function(stage){(grouped[stage.panel]||(grouped[stage.panel]=[])).push(stage);});
			Object.keys(grouped).forEach(function(name) {
            var panel=el('[data-mpc-panel="'+name+'"]');if(!panel)return;
			els('.lucidlab-mpc-progress:not(.lucidlab-mpc-job-card)',panel).forEach(function(progress){progress.hidden=true;});
            grouped[name].forEach(function(stage){
				if(stage.status==='done'&&name!=='sizes'){
					ensureRunAllStageCompleteUi(name,stage.completed_at||job.updated);
				}
            });
            els('[data-mpc-scan]',panel).forEach(function(b){buttonBusyInside(b,active);});
			if(name==='sizes'){var fs=el('#lucidlab-mpc-fssizes-scan',panel);if(fs)buttonBusyInside(fs,active);}
			if(name==='regenerate'){var regen=el('#lucidlab-mpc-regen-start',panel);if(regen)buttonBusyInside(regen,active);}
			if(name==='webp'){['#lucidlab-mpc-webpstats-scan','#lucidlab-mpc-webp-start','#lucidlab-mpc-webpclean-scan'].forEach(function(selector){var button=el(selector,panel);if(button)buttonBusyInside(button,active);});}
        });
    }

    function settleTaskWaiters(task) {
        var job = task.job;
        if (!['done', 'failed', 'cancelled'].includes(job.status)) return;
        task.waiters.splice(0).forEach(function(waiter) {
            if (job.status === 'done') waiter.resolve({success:true, data:job.result});
            else waiter.reject(Error(job.status === 'cancelled' ? mpcText("Cancelled; completed changes remain.") : job.label));
        });
    }

    function connectTask(job) {
		if(job.special)return null;
		if(String(job.key||'').startsWith('run_all')){
			// Run All owns the welcome progress UI only; never steal View results / Completed on from scan tabs.
			if(job.status==='done')return null;
			var existingRun=durableTasks[job.key]; if(existingRun)return existingRun;
			var welcomePanel=el('[data-mpc-panel="welcome"]')||document.createElement('div');
			var runBox=document.createElement('div');runBox.className='lucidlab-mpc-progress lucidlab-mpc-job-card';runBox.hidden=true;
			var runLabel=document.createElement('div');runLabel.className='lucidlab-mpc-progress-label';
			var runActivity=document.createElement('div');runActivity.className='lucidlab-mpc-job-activity';
			var runTrack=document.createElement('div');runTrack.className='lucidlab-mpc-progress-track';var runBar=document.createElement('div');runBar.className='lucidlab-mpc-progress-bar';runTrack.appendChild(runBar);
			var runControls=document.createElement('div');runControls.className='lucidlab-mpc-scan-controls';
			runBox.append(runLabel,runActivity,runTrack,runControls);
			var runT={job:job,queue:Promise.resolve(),timer:null,commanding:false,waiters:[],box:runBox,sent:0,quiet:true,firstStep:true};durableTasks[job.key]=runT;
			runT.render=function(d){runT.job=Object.assign({},runT.job,d);if(['running','paused'].includes(runT.job.status))upsertRunningJob(runT.job);renderRunAllStages(runT.job);settleTaskWaiters(runT);};
			runT.request=function(action){var promise=runT.queue.catch(function(){}).then(async function(){runT.sent=Date.now();try{return await taskRequest(action,{key:runT.job.key});}finally{runT.sent=0;}});runT.queue=promise;return promise;};
			runT.schedule=function(){clearTimeout(runT.timer);if(runT.commanding||runT.job.status!=='running')return;runT.timer=setTimeout(async function(){try{var want=cronPollAction(runT.job);var d=await runT.request(want);if(d&&d.busy&&want==='step')d=await runT.request('status');if(d&&!d.busy){runT.render(d);}runT.schedule();}catch(e){runT.schedule();}},runT.job.driver==='cron'?2500:150);};
			runT.command=async function(action){if(runT.commanding)return;runT.commanding=true;clearTimeout(runT.timer);try{var d=await runT.request(action);runT.render(d);}finally{runT.commanding=false;runT.schedule();}};
			runT.render(job);return runT;
		}
        var existing=durableTasks[job.key]; if(existing)return existing;
        var isCrawlJob=job.scan_tab==='crawl' || String(job.key||'').indexOf('scan_crawl')===0;
		var isQuarantineJob=jobOp(job)==='quarantine';
        var panel=el('[data-mpc-panel="'+(isCrawlJob?'missing':job.panel)+'"]')||el('[data-mpc-panel="'+job.panel+'"]');
        // A saved foreground scan must keep its worker when another tab is rendered.
        // Use detached UI nodes rather than attaching its controls to the wrong page.
        if (!panel) {
            if (!['running', 'paused'].includes(job.status)) return null;
            panel = document.createElement('div');
        }
        if(!isQuarantineJob)els('.lucidlab-mpc-progress:not(.lucidlab-mpc-job-card)',panel).forEach(function(old){old.hidden=true;});
        var box=document.createElement('div');box.className='lucidlab-mpc-progress lucidlab-mpc-job-card';
        var label=document.createElement('div');label.className='lucidlab-mpc-progress-label';label.setAttribute('role','status');
        var activity=document.createElement('div');activity.className='lucidlab-mpc-job-activity';activity.setAttribute('role','status');
        var track=document.createElement('div');track.className='lucidlab-mpc-progress-track';var bar=document.createElement('div');bar.className='lucidlab-mpc-progress-bar';track.appendChild(bar);
        var controls=document.createElement('div');controls.className='lucidlab-mpc-scan-controls';
		box.append(label,activity,track,controls);
		var scanButton=isQuarantineJob?null:(panel.querySelector('[data-mpc-scan="'+(job.scan_tab||job.panel)+'"]')||panel.querySelector(job.scan_tab==='fs-sizes'?'#lucidlab-mpc-fssizes-scan':job.scan_tab==='webpstats'?'#lucidlab-mpc-webpstats-scan':job.key.startsWith('webpscan')?'#lucidlab-mpc-webpclean-scan':'[data-mpc-scan]'));
		if(!scanButton && isCrawlJob) scanButton=document.querySelector('[data-mpc-scan="crawl"]');
		var hideCard=true;
		var summaryBox=null;
		var summaryLink=null;
		var summaryMissing=null;
		if(hideCard){els('.lucidlab-mpc-job-card',panel).forEach(function(old){old.remove();});}
		if(hideCard&&scanButton){
			var summaryTarget=job.scan_tab||job.panel;
			// The Other Sizes scan keeps a separate completion notice on this page.
			// Crawl UI lives on Missing Images — reuse that Completed notice, do not create a second one.
			// Check live pages shows its own status right under its button, not in the Missing Images notice at the top.
			if(isCrawlJob||summaryTarget==='crawl')summaryTarget='crawl';
			summaryBox=panel.querySelector('.lucidlab-mpc-job-summary[data-mpc-job-summary="'+summaryTarget+'"]:not(.lucidlab-mpc-missing-results-notice):not(#lucidlab-mpc-webp-stat)');
			if(!summaryBox){
				summaryBox=document.createElement('div');
				summaryBox.className='notice notice-info inline lucidlab-mpc-job-summary';
				summaryBox.dataset.mpcJobSummary=summaryTarget;
			}
			if(isCrawlJob){var crawlAnchor=scanButton.closest('p,div')||scanButton;if(summaryBox.previousElementSibling!==crawlAnchor)crawlAnchor.after(summaryBox);}
			else placeStatusAtPanelTop(panel,summaryBox);
			// Crawl reuses the Missing Completed notice — never hide an existing one while crawl polls.
			// Only force-show on terminal; leave a PHP/prior Completed visible during running/paused.
			if(isCrawlJob){
				if(['done','failed','cancelled'].includes(job.status)){setHidden(summaryBox,false);}
				else if(!(summaryBox.textContent||'').replace(/\s+/g,'')){setHidden(summaryBox,true);}
			}else{
				setHidden(summaryBox,!['done','failed','cancelled'].includes(job.status)&&!(summaryBox.textContent||'').trim());
			}
			// Crawl must not own the red snapshot warning (and must never put a job-summary key on it).
			summaryMissing=isCrawlJob?null:panel.querySelector('.lucidlab-mpc-missing-results-notice');
			if(!isCrawlJob&&summaryTarget!=='sizes'&&summaryTarget!=='webp'&&!summaryMissing){
				summaryMissing=document.createElement('div');
				summaryMissing.className='notice notice-error inline lucidlab-mpc-job-summary lucidlab-mpc-missing-results-notice';
				summaryMissing.textContent=mpcT('noSavedResults','No saved result list is available. Run a new scan to refresh the findings; the counters may show an older snapshot.');
			}
			if(summaryMissing){
				repairMissingResultsNotice(summaryMissing);
				placeStatusAtPanelTop(panel,summaryMissing);
				if(summaryBox){summaryBox.after(summaryMissing);}
				setHidden(summaryMissing,true);
				syncSnapshotCounters(panel,summaryMissing);
			}else if(isCrawlJob){
				panel.querySelectorAll('.lucidlab-mpc-missing-results-notice').forEach(repairMissingResultsNotice);
			}
			if(isCrawlJob){
				summaryLink=panel.querySelector('.lucidlab-mpc-crawl-results-link');
				if(summaryLink){setHidden(summaryLink,['running','paused'].includes(job.status));}
			}else if(summaryTarget!=='sizes'&&summaryTarget!=='fs-sizes'&&summaryTarget!=='webp'&&!isFindCopiesJob(job)){
				summaryLink=panel.querySelector('.lucidlab-mpc-current-results-link')||ensureViewCurrentResultsLink(scanButton,summaryTarget);
				if(summaryLink){summaryLink.dataset.mpcJobSummary=summaryTarget;setHidden(summaryLink,!(job.result_available===true||summaryLink.dataset.mpcSavedResults==='1'||panelHasSavedResults(panel)));}
			}
		}
		if(!hideCard){if(scanButton){var anchor=scanButton.closest('form')||scanButton;anchor.after(box);}else panel.prepend(box);}
		var t={job:job,queue:Promise.resolve(),timer:null,commanding:false,waiters:[],box:box,sent:0,quiet:hideCard,firstStep:true,isCrawl:isCrawlJob,panel:panel,scanButton:scanButton,summaryBox:summaryBox,summaryLink:summaryLink,summaryMissing:summaryMissing};durableTasks[job.key]=t;
		t.refreshAfterResultsLoad=function(loadedPanel,tab){
			var jobTab=t.job.scan_tab||t.job.panel;
			var panelTab=isCrawlJob?'missing':(t.job.panel||jobTab);
			if(tab!==panelTab&&tab!==jobTab&&!(jobTab==='fs-sizes'&&tab==='sizes')&&!(jobTab==='webpstats'&&tab==='webp')&&!(jobTab==='crawl'&&tab==='missing'))return;
			t.panel=loadedPanel||t.panel;
			panel=t.panel;
			t.scanButton=isQuarantineJob?null:(panel.querySelector('[data-mpc-scan="'+(jobTab)+'"]')||panel.querySelector(jobTab==='fs-sizes'?'#lucidlab-mpc-fssizes-scan':jobTab==='webpstats'?'#lucidlab-mpc-webpstats-scan':job.key.startsWith('webpscan')?'#lucidlab-mpc-webpclean-scan':'[data-mpc-scan]'));
			if(!t.scanButton&&isCrawlJob)t.scanButton=document.querySelector('[data-mpc-scan="crawl"]');
			scanButton=t.scanButton;
			t.summaryBox=isQuarantineJob?null:panel.querySelector('.lucidlab-mpc-job-summary[data-mpc-job-summary="'+(jobTab==='crawl'?'missing':jobTab)+'"]:not(.lucidlab-mpc-missing-results-notice):not(#lucidlab-mpc-webp-stat)');
			t.summaryLink=isQuarantineJob?null:panel.querySelector('.lucidlab-mpc-current-results-link');
			t.summaryMissing=isQuarantineJob||isCrawlJob?null:panel.querySelector('.lucidlab-mpc-missing-results-notice');
			summaryBox=t.summaryBox;summaryLink=t.summaryLink;summaryMissing=t.summaryMissing;
			if(scanButton)buttonBusyInside(scanButton,['running','paused'].includes(t.job.status));
			if(summaryLink){
			var hasSaved=t.job.result_available===true||summaryLink.dataset.mpcSavedResults==='1'||panelHasSavedResults(panel);
				setHidden(summaryLink,t.isCrawl?(['running','paused'].includes(t.job.status)||(t.job.status==='done'&&t.job.result_available!==true)):!hasSaved);
			}
		};
        var buttons={};
        [['stop',mpcT('stop','Stop')],['resume',mpcT('resume','Resume')],['skip',mpcT('skip','Skip')],['cancel',mpcT('cancel','Cancel')],['background',mpcT('continueBackground','Continue in background')]].forEach(function(pair){if(pair[0]==='background'&&!canBackground())return;if(pair[0]==='skip'&&!String(job.key||'').startsWith('run_all'))return;var button=document.createElement('button');button.type='button';button.className='button';button.textContent=pair[1];button.onclick=function(){t.command(pair[0]);};buttons[pair[0]]=button;controls.append(button,' ');});
        t.render=function(d){
            clearTimeout(t.hideTimer);
            syncCrawlLive(d);
            box.classList.toggle('lucidlab-mpc-job-summary', d.status==='done' && (d.scan_tab==='webpstats' || d.key==='scan_webpstats'));
            if(d.status==='running'){var oldLink=box.querySelector('.lucidlab-mpc-job-results');if(oldLink)oldLink.remove();}
			box.hidden=!!t.quiet;
			[activity,track,controls].forEach(function(node){node.hidden=false;});
			t.job=Object.assign({},t.job,d);if(['running','paused'].includes(t.job.status))upsertRunningJob(t.job);if(t.job.key.startsWith('run_all'))renderRunAllStages(t.job);scanButton=t.scanButton;if(scanButton){buttonBusyInside(scanButton,['running','paused'].includes(t.job.status));}activity.textContent=t.job.status==='running'?withElapsed(runningJobActivity(t.job),jobStartedAt(t.job)):runningJobActivity(t.job);var cardUnknown=['running','paused'].includes(t.job.status)&&!!t.job.indeterminate;track.classList.toggle('lucidlab-mpc-progress-track--indeterminate',cardUnknown);track.classList.toggle('lucidlab-mpc-progress-track--working',t.job.status==='running'&&!cardUnknown);bar.style.width=runningJobProgress(t.job)+'%';
            label.textContent=(d.status==='running'?(d.driver==='cron'?mpcText("Running in background — "):mpcText("Running — ")):d.status==='paused'?mpcText("Stopped — "):d.status==='cancelled'?mpcText("Cancelled — "):'')+(d.label||'')+(['running','paused'].includes(d.status)&&!cardUnknown?' \u00B7 '+(t.job.progress_estimate?'≈ ':'')+Math.floor(runningJobProgress(t.job))+'%':'');
            box.setAttribute('aria-busy',d.status==='running'?'true':'false');
			buttons.stop.hidden=d.status==='paused';buttons.stop.disabled=d.status!=='running';buttons.resume.hidden=d.status!=='paused';buttons.resume.disabled=d.status!=='paused';if(buttons.skip){buttons.skip.hidden=!['running','paused'].includes(d.status);buttons.skip.disabled=!['running','paused'].includes(d.status);}buttons.cancel.disabled=!['running','paused'].includes(d.status);if(buttons.background)buttons.background.disabled=!['running','paused'].includes(d.status)||d.status==='running'&&d.driver==='cron';
			if(summaryBox&&summaryBox.dataset.mpcCompleted&&!t.isCrawl){
				if(['running','paused'].includes(d.status)){
					if(!summaryBox.dataset.mpcPreviousText)summaryBox.dataset.mpcPreviousText=summaryBox.textContent;
					summaryBox.textContent=(d.status==='paused'?mpcT('paused','Paused'):mpcText('Scanning…'))+' · '+mpcText('Previous')+': '+summaryBox.dataset.mpcPreviousText;
				}else if(summaryBox.dataset.mpcPreviousText){
					summaryBox.textContent=summaryBox.dataset.mpcPreviousText;
					delete summaryBox.dataset.mpcPreviousText;
				}
			}
			if(summaryBox){var hasSavedResults=d.result_available===true||(!t.isCrawl&&((summaryLink&&summaryLink.dataset.mpcSavedResults==='1')||panelHasSavedResults(panel)));var summaryTerminal=['done','failed','cancelled'].includes(d.status);if(t.isCrawl){if(summaryTerminal)setHidden(summaryBox,false);}else{setHidden(summaryBox,['running','paused'].includes(d.status)&&!(summaryBox.textContent||'').trim());}if(!summaryBox.hidden&&summaryTerminal){var summaryStamp=Number(d.updated||0);var summaryDate=d.updated_label||((formatJobDate(d.updated)||mpcText("date unavailable")));if(summaryStamp>=Number(summaryBox.dataset.mpcCompleted||0)){if(!summaryBox.classList.contains('lucidlab-mpc-missing-results-notice')){if(summaryStamp)summaryBox.dataset.mpcCompleted=String(summaryStamp);var sizeSource=d.scan_tab==='fs-sizes'?mpcText('Other Sizes on Disk')+' \u2014 ':d.scan_tab==='sizes'?mpcText('Analyze sizes')+' \u2014 ':'';summaryBox.textContent=sizeSource+(d.status==='done'?mpcT('completedOn','Completed on:')+' '+summaryDate:d.status==='cancelled'?mpcT('cancelledOnPrefix','Cancelled on')+': '+summaryDate:mpcT('failedOnPrefix','Failed on')+': '+summaryDate);}}}if(summaryLink){if(t.isCrawl){setHidden(summaryLink,['running','paused'].includes(d.status)||(d.status==='done'&&d.result_available!==true));}else{setHidden(summaryLink,!hasSavedResults);if(!summaryLink.hidden){var resultPanel=d.panel||d.scan_tab;if(resultPanel&&LucidLabMPC.tabUrls[resultPanel])summaryLink.href=LucidLabMPC.tabUrls[resultPanel]+'&lucidlab_mpc_load=1&lucidlab_mpc_scanned=1';}}}if(summaryMissing){var freshResults=/[?&]lucidlab_mpc_scanned=1/.test(location.search)||panelHasSavedResults(panel)||!!panel.querySelector('[data-mpc-results-loaded], [data-mpc-db-results], .lucidlab-mpc-format-stats');setHidden(summaryMissing,d.status!=='done'||hasSavedResults||freshResults||d.scan_tab==='webpstats');syncSnapshotCounters(panel,summaryMissing);}}
            if(['done','failed','cancelled'].includes(d.status)){
                settleTaskWaiters(t);
                if(d.result_available===true&&!isFindCopiesJob(d) && !isWebpCleanJob(d) && d.scan_tab!=='webpstats' && d.key!=='scan_webpstats' && d.scan_tab!=='fs-sizes' && String(d.key||'').indexOf('scan_fs-sizes')!==0 && !box.querySelector('.lucidlab-mpc-job-results')){var resultPanel=d.key.startsWith('run_all')?'quarantine':d.panel;var link=document.createElement('a');link.className='lucidlab-mpc-job-results';link.textContent=mpcT('viewCurrentResults','View current results');link.href=LucidLabMPC.tabUrls[resultPanel] ? LucidLabMPC.tabUrls[resultPanel]+'&lucidlab_mpc_load=1' : window.location.href;box.appendChild(link);}
            }
            if(d.status==='done'){
				// A quarantine action changes saved findings, but it is not a new scan.
				if ( ! isQuarantineJob ) showScanCompleteNotice(t.panel||panel,d);
				if ( d.result ) {
					if ( d.scan_tab === 'webpstats' || d.key === 'scan_webpstats' || String( d.key || '' ).indexOf( 'webpscan' ) === 0 ) { applyWebpClean( d.result ); }
					if ( d.scan_tab === 'fs-sizes' || String( d.key || '' ).indexOf( 'scan_fs-sizes' ) === 0 ) { markFsSizesReady(); }
				}
				if ( isQuarantineJob || d.panel === 'sizes' || d.scan_tab === 'sizes' || d.scan_tab === 'fs-sizes' ) { box.remove(); return; }
				var collapse=function(){
					if(d.key.startsWith('run_all')){box.hidden=true;clearRunAllStages();return;}
					box.innerHTML='';
					box.className='';
					var stat=document.createElement('p');
					stat.className='notice notice-info inline lucidlab-mpc-job-summary';
					stat.style.margin='12px 0';
					stat.style.fontFamily='inherit';
					stat.textContent=mpcText("Completed on: ")+(d.updated_label||formatJobDate(d.updated));
					box.appendChild(stat);
					if(d.result_available===true&&!isFindCopiesJob(d) && !isWebpCleanJob(d) && d.scan_tab!=='webpstats' && d.key!=='scan_webpstats'){
					var resultPanel=d.key.startsWith('run_all')?'quarantine':d.panel;
					var link=document.createElement('a');
					link.className='button lucidlab-mpc-current-results-link';
					link.textContent=mpcT('viewCurrentResults','View current results');
					link.href=LucidLabMPC.tabUrls[resultPanel] ? LucidLabMPC.tabUrls[resultPanel]+'&lucidlab_mpc_load=1&lucidlab_mpc_scanned=1' : window.location.href;
					box.appendChild(link);
					}
					if(d.scan_tab==='webpstats' || d.key==='scan_webpstats')box.appendChild(document.createElement('br'));
				};
				collapse();
            }
        };
        t.request=function(action){var promise=t.queue.catch(function(){}).then(async function(){t.sent=Date.now();try{return await taskRequest(action,{key:t.job.key});}finally{t.sent=0;}});t.queue=promise;return promise;};
		t.schedule=function(){clearTimeout(t.timer);if(t.commanding||!['running','queued'].includes(t.job.status))return;var delay=t.firstStep?0:mpcPollDelay(t.job);t.firstStep=false;t.timer=setTimeout(async function(){try{var want=(t.reconnecting||t.job.status==='queued')?'status':cronPollAction(t.job);var d=await t.request(want);if(d&&d.busy&&want==='step')d=await t.request('status');if(d&&!d.busy){t.reconnecting=false;t.errors=0;if(t.job.driver!=='cron')setRunningJobMessage(t.job.key,'');t.render(d);}t.schedule();}catch(e){
            t.reconnecting=true;
			if(e&&e.throttled){
				// Rate limited: wait as long as the server asks, without counting it as a failure.
				label.textContent=e.message;setRunningJobMessage(t.job.key,e.message);
				t.timer=setTimeout(t.schedule,Math.max(1000,Number(e.retryAt||0)-Date.now()+500));
				return;
			}
            t.errors=(t.errors||0)+1;
			var gateway=/502|504|Bad Gateway|Gateway Time/i.test(e.message||'');
			var connectionMessage=gateway
				? (t.errors<8?mpcText("The host closed this batch early. Continuing from saved progress…"):mpcText("Reload to reconnect to the saved job."))
				: (mpcText("Connection interrupted: ")+e.message+(t.errors<4?mpcText(" Checking saved progress…"):mpcText(" Reload to reconnect to the saved job.")));
			label.textContent=connectionMessage;setRunningJobMessage(t.job.key,connectionMessage);
            if(t.errors<(gateway?10:4))t.timer=setTimeout(t.schedule,mpcBackoff(t.errors,gateway));
            else{t.waiters.splice(0).forEach(w=>w.reject(e));t.timer=setTimeout(t.schedule,60000);}
        }},delay);};
		t.command=async function(action){if(t.commanding)return;t.commanding=true;t.action=action;clearTimeout(t.timer);activity.textContent=({stop:mpcText("Stopping after the current batch…"),resume:mpcText("Resuming"),skip:mpcText("Skipping this stage after the current batch…"),cancel:mpcText("Canceling after the current batch…"),background:mpcText("Running in the background. You can leave this page and come back later to see how far the scan has got.")})[action]||mpcText("Applying command");if(action==='stop'){buttons.stop.hidden=true;buttons.resume.hidden=false;buttons.stop.disabled=true;}try{var d,waits=0;do{d=await t.request(action);if(action==='cancel'&&d&&d.status==='cancelled')break;if(action==='stop'&&d&&d.status==='paused')break;if(action==='skip'&&d&&!d.busy&&((d.stages||[]).some(function(stage){return stage.status==='skipped';})||d.status==='done'))break;if(d&&d.busy&&waits<20){waits++;await new Promise(resolve=>setTimeout(resolve,500));}else break;}while(d&&d.busy);t.render(d);}catch(e){showActionError(e);}finally{t.commanding=false;t.action='';t.schedule();}};
		setInterval(function(){if(t.commanding){if(t.sent)activity.textContent=(({stop:mpcText("Stopping"),resume:mpcText("Resuming"),skip:mpcText("Skipping"),cancel:mpcText("Canceling"),background:mpcText("Continuing in background")})[t.action]||mpcText("Applying command"))+' \u2014 '+Math.floor((Date.now()-t.sent)/1000)+'s.';return;}if(t.job.status==='running')activity.textContent=withElapsed(runningJobActivity(t.job),jobStartedAt(t.job));},1000);
        t.render(job);return t;
    }
    async function persistentTask(params) {
        var crawlStart=params.op==='scan' && params.tab==='crawl';
        if(crawlStart) prepareCrawlLive();
        var input=Object.assign({},params);
        input.panel=params.panel || ({'fs-sizes':'sizes',webpstats:'webp',crawl:'missing'})[params.tab] || params.tab || ({merge:'dupcontent',trash_dup:'duplicates',webpscan:'webp',webpclean:'webp'})[params.op] || LucidLabMPC.tab;
        if(params.op==='webpscan')input.progress_id=params.progress_id||'webpscan';
        if(params.op==='webpclean')input.progress_id=params.progress_id||'webpclean';
        var startButton=params.op==='scan'?el('[data-mpc-scan="'+params.tab+'"]')||el(params.tab==='fs-sizes'?'#lucidlab-mpc-fssizes-scan':params.tab==='webpstats'?'#lucidlab-mpc-webpstats-scan':'#lucidlab-mpc-unused'):params.op==='webpscan'?el('#lucidlab-mpc-webpclean-scan'):null;
        if(startButton){buttonBusyInside(startButton,true);}
		var pendingKey='pending-'+Date.now()+'-'+Math.random().toString(36).slice(2);pendingRunningJobs[pendingKey]={key:pendingKey,pending:true,status:'running',op:params.op||'',panel:input.panel,scan_tab:params.tab,progress_id:input.progress_id,format:params.format||'',sub_action:params.sub_action||'',source:params.lucidlab_mpc_source||params.source||'',lucidlab_mpc_source:params.lucidlab_mpc_source||params.source||'',lucidlab_mpc_set:params.lucidlab_mpc_set||'',label:(function(){var pendingJob={panel:input.panel,scan_tab:params.tab||params.op,progress_id:input.progress_id,format:params.format,sub_action:params.sub_action,source:params.lucidlab_mpc_source||params.source||'',lucidlab_mpc_source:params.lucidlab_mpc_source||params.source||'',key:(params.op||'')+'_'+(params.format||params.sub_action||'')};if(isRestoreJob(pendingJob))return mpcText("Starting restore of ")+(quarantineSetName(pendingJob)||mpcText("quarantine items"))+'\u2026';if(isPurgeJob(pendingJob))return mpcText("Starting delete of ")+(quarantineSetName(pendingJob)||mpcText("quarantine items"))+'\u2026';return mpcText("Starting ")+jobSource(pendingJob);})()};renderRunningJobs(knownRunningJobs);scrollToRunningJobs();
		var job;try{do {job=await taskRequest('start',input);if(job.busy)await new Promise(resolve=>setTimeout(resolve,500));}while(job.busy);}catch(error){delete pendingRunningJobs[pendingKey];renderRunningJobs(knownRunningJobs);if(startButton){buttonBusyInside(startButton,false);}if(crawlStart) restoreCrawlSaved();throw error;}
		var pendingOrder=runningJobOrder[pendingKey];delete pendingRunningJobs[pendingKey];ensureRunningJobOrder(job,pendingOrder);renderRunningJobs(knownRunningJobs.concat([job]));
        // A completed card can be reused for a new run without leaving stale callbacks.
        var t=connectTask(job);t.render(job);
        if(params.quiet){t.quiet=true;t.box.hidden=true;}
        var result=new Promise(function(resolve,reject){t.waiters.push({resolve:resolve,reject:reject});});
		if(!params.quiet&&!t.quiet)t.box.scrollIntoView({block:'center'});t.schedule();return result;
    }
	/* Poll gently: browser-driven batches pause briefly between steps, background and queued jobs poll less often, hidden tabs poll slower. */
	function mpcPollDelay(job){
		var hidden=document.hidden,base;
		if(job&&job.status==='queued')base=hidden?15000:5000;
		else if(job&&job.driver==='cron')base=hidden?12000:4000;
		else base=hidden?3000:400;
		// Tabs that do not drive the job only look at its status now and then.
		if(!mpcIsLeader())base=Math.max(base,hidden?60000:15000);
		var now=Date.now()/1000,retry=Number(job&&job.retry_at||0);
		// A job that waits (HTTP 429 from the site) is looked at again when it resumes, and at least every minute.
		if(retry>now)base=Math.max(base,Math.min(60000,(retry-now)*1000+1000));
		return base;
	}
	/* Exponential backoff after a gateway or network error, capped at 30 seconds. */
	function mpcBackoff(errors,gateway){var base=gateway?2000:3000;return Math.min(30000,base*Math.pow(2,Math.max(0,errors-1)));}
	function jobLooksAbandoned(job){if(!job||job.driver==='cron'||job.status!=='running')return false;var updated=Number(job.updated||0);return updated>0&&(Date.now()/1000)-updated>=90;}
	function restoreTaskCards(jobs){var latest={};(jobs||[]).filter(j=>!j.special&&!((j.scan_tab==='webpstats'||j.key==='scan_webpstats')&&j.status==='done')&&['running','paused','queued','failed','done'].includes(j.status)&&!(j.status==='done'&&/_(purge_quarantine|purge_source)$/.test(j.key))).forEach(function(job){var target=job.scan_tab||job.panel||job.key;var current=latest[target];var active=['running','paused','queued'].includes(job.status);var currentActive=current&&['running','paused'].includes(current.status);if(!current||active&&!currentActive||Number(job.updated||0)>Number(current.updated||0))latest[target]=job;});Object.keys(latest).forEach(function(target){var t=connectTask(latest[target]);if(t&&!jobLooksAbandoned(latest[target]))t.schedule();});}
	restoreTaskCards(initialJobs);

	var pageSettingsTimers = {};
	function savePageSettings( page, settings ) {
		return post( { op: 'save_page_settings', page: page, settings: JSON.stringify( settings || {} ), direct: 1 } ).catch( function () {} );
	}
	function queuePageSettings( page, settings ) {
		clearTimeout( pageSettingsTimers[ page ] );
		pageSettingsTimers[ page ] = setTimeout( function () { savePageSettings( page, settings ); }, 400 );
	}
	function regenPageSettings() {
		var box = el( '#lucidlab-mpc-regen' );
		if ( ! box ) { return {}; }
		return {
			mode: ( el( 'input[name="lucidlab-mpc-regen-mode"]:checked', box ) || {} ).value || 'skip',
			sizes: els( '.lucidlab-mpc-regen-size:checked', box ).map( function ( c ) { return c.value; } ),
			quality: ( el( '#lucidlab-mpc-regen-quality' ) || {} ).value || 85
		};
	}
	function keepJobsInBackground() {
		if ( ! canBackground() ) { return; }
		knownRunningJobs.filter( function ( job ) {
			return [ 'running', 'paused' ].includes( job.status ) && job.driver !== 'cron' && job.driver !== 'run_all';
		} ).forEach( function ( job ) {
			try {
				var body = new URLSearchParams();
				body.set( 'action', 'lucidlab_mpc_op' );
				body.set( 'nonce', LucidLabMPC.nonce );
				body.set( 'op', 'taskjob' );
				body.set( 'sub', 'background' );
				body.set( 'request', JSON.stringify( { key: job.key } ) );
				if ( job.special === 'webp' ) {
					body.set( 'op', 'convert' );
					body.set( 'tab', 'webp' );
					body.set( 'sub', 'background' );
					body.set( 'settings', '{}' );
					body.delete( 'request' );
				} else if ( job.special === 'regen' ) {
					body.set( 'op', 'regen' );
					body.set( 'sub', 'bg' );
					body.delete( 'request' );
				} else if ( job.special ) {
					return;
				}
				if ( navigator.sendBeacon ) {
					navigator.sendBeacon( LucidLabMPC.ajaxUrl, body );
				} else {
					fetch( LucidLabMPC.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body, keepalive: true } );
				}
			} catch ( e ) {}
		} );
	}
	window.addEventListener( 'pagehide', keepJobsInBackground );
	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'hidden' ) { keepJobsInBackground(); }
		if ( document.visibilityState === 'visible' ) {
			refreshRunningJobs();
			Object.keys( durableTasks ).forEach( function ( key ) {
				var task = durableTasks[ key ];
				if ( ! task || task.commanding || ! [ 'running', 'paused' ].includes( task.job.status ) ) { return; }
				task.request( 'status' ).then( function ( fresh ) {
					if ( ! fresh || fresh.busy ) { return; }
					task.render( fresh );
					task.schedule();
				} ).catch( showActionError );
			} );
		}
	} );

	/* After HTTP 429 or 503 from admin-ajax every request of this tab waits until the server's Retry-After (or a backoff) has passed. */
	function mpcRetryAfter(r){var v=r&&r.headers?r.headers.get('Retry-After'):'';if(!v)return 0;var n=Number(v);if(Number.isFinite(n))return Math.max(0,Math.min(3600,n));var t=Date.parse(v);return Number.isFinite(t)?Math.max(0,Math.min(3600,(t-Date.now())/1000)):0;}
	function mpcClock(ms){try{return new Date(ms).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});}catch(e){return '';}}
async function post( params, extra ) {
        extra = extra || {};
        if (!params.direct && ["scan","quarantine","merge","trash_dup","webpscan","webpclean","run_all"].includes(params.op)) return persistentTask(params);
        if ( mpcHttpPause.until > Date.now() ) { await new Promise( function ( resolve ) { setTimeout( resolve, mpcHttpPause.until - Date.now() ); } ); }
        try {
		var body;
		if ( extra.form ) {
			body = extra.form;
			body.set( 'action', 'lucidlab_mpc_op' );
			body.set( 'nonce', LucidLabMPC.nonce );
			Object.keys( params ).forEach( function ( k ) { if ( k !== 'direct' && params[ k ] !== undefined && params[ k ] !== null ) { body.set( k, params[ k ] ); } } );
		} else {
			body = new URLSearchParams();
			body.set( 'action', 'lucidlab_mpc_op' );
			body.set( 'nonce', LucidLabMPC.nonce );
			Object.keys( params ).forEach( function ( k ) { if ( k !== 'direct' && params[ k ] !== undefined && params[ k ] !== null ) { body.set( k, params[ k ] ); } } );
		}
		var response = await fetch( LucidLabMPC.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body, signal: extra.signal } )
			.then( async function ( r ) {
                if ( r.status === 429 || r.status === 503 ) {
                    mpcHttpPause.hits++;
                    var wait = Math.max( mpcRetryAfter( r ), Math.min( 300, 15 * Math.pow( 2, mpcHttpPause.hits - 1 ) ) );
                    mpcHttpPause.until = Math.max( mpcHttpPause.until, Date.now() + wait * 1000 );
                    var slow = new Error( mpcText("The server asked us to slow down (HTTP ") + r.status + mpcText("). Trying again at ") + mpcClock( mpcHttpPause.until ) + '\u2026' );
                    slow.status = r.status; slow.throttled = true; slow.retryAt = mpcHttpPause.until;
                    throw slow;
                }
                mpcHttpPause.hits = 0;
                var raw=await r.text(), data;
                try { data=JSON.parse(raw); } catch(e) { throw new Error(mpcText("HTTP ")+r.status+': '+(raw==='-1'?mpcText("Session expired. Reload the page."):raw.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().slice(0,400)||r.statusText||mpcText("Empty server response"))); }
                if(!r.ok || data===-1 || data===0) { throw new Error(data&&data.data&&data.data.message||mpcText("HTTP ")+r.status+': '+raw.slice(0,400)); }
                return data;
            } );
        return response;
        } catch(error) { throw error; }
	}

	function progress( id ) {
		var box = el( '#lucidlab-mpc-progress-' + id );
		return {
			show: function () { if ( box ) { box.hidden = true; } },
			set: function ( pct, label ) {
				if ( ! box ) { return; }
				var bar = el( '.lucidlab-mpc-progress-bar', box ), lbl = el( '.lucidlab-mpc-progress-label', box );
				if ( bar ) { bar.style.width = ( pct || 0 ) + '%'; }
				if ( lbl && label ) { lbl.textContent = label; }
                if(pct>=100){els('[data-scan-stop]',box).forEach(function(button){button.disabled=true;});}
			}
		};
	}

	function scrollToProgress( id ) {
		var box = el( '#lucidlab-mpc-progress-' + id );
		if ( box && box.scrollIntoView ) {
			box.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}
	}

	function productCopySyncApply( card ) {
		if ( ! card ) { return; }
		var apply = card.querySelector( '[data-mpc-copy-apply-fields]' );
		if ( ! apply ) { return; }
		var any = els( '[data-mpc-copy-apply]', card ).some( function ( box ) { return ! box.disabled && box.checked; } );
		apply.disabled = ! any;
	}
	function productCopyFill( card, data ) {
		[ 'title', 'short', 'long' ].forEach( function ( field ) {
			var key = field === 'title' ? 'title' : ( field === 'short' ? 'short_description' : 'description' );
			var area = card.querySelector( '[data-mpc-copy-suggest="' + field + '"]' );
			if ( area && data[ key ] != null ) { area.value = data[ key ]; }
			var current = card.querySelector( '[data-mpc-copy-current="' + field + '"]' );
			if ( current && data[ 'current_' + field ] != null ) { current.textContent = data[ 'current_' + field ]; }
		} );
		var note = card.querySelector( '.lucidlab-mpc-copy-note' );
		if ( note && data.message ) { note.textContent = data.message; }
		if ( data.title || data.short_description || data.description ) {
			var locked = card.getAttribute( 'data-copy-locked' ) === '1';
			card.querySelectorAll( '[data-mpc-copy-suggest]' ).forEach( function ( area ) {
				if ( locked && area.getAttribute( 'data-mpc-copy-suggest' ) === 'long' ) { return; }
				area.readOnly = false;
			} );
			card.querySelectorAll( '[data-mpc-copy-apply]' ).forEach( function ( box ) { box.disabled = false; } );
		}
		productCopySyncApply( card );
	}
	function productCopySpinner( button, busy ) {
		if ( ! button ) { return; }
		var spin = button.querySelector( '.lucidlab-mpc-inline-spinner' );
		if ( busy && ! spin ) {
			spin = document.createElement( 'span' );
			spin.className = 'spinner is-active lucidlab-mpc-inline-spinner';
			spin.setAttribute( 'role', 'status' );
			spin.setAttribute( 'aria-label', mpcText("Working") );
			button.appendChild( spin );
		} else if ( ! busy && spin ) {
			spin.remove();
		}
	}
	var productCopyAboutTimer = null;
	function queueProductCopyAbout() {
		clearTimeout( productCopyAboutTimer );
		productCopyAboutTimer = setTimeout( function () {
			var field = el( '#lucidlab-mpc-copy-about' );
			if ( ! field ) { return; }
			post( { op: 'product_copy_about', site_about: field.value, direct: 1 } );
		}, 500 );
	}
	document.addEventListener( 'input', function ( event ) {
		if ( event.target && event.target.id === 'lucidlab-mpc-copy-about' ) { queueProductCopyAbout(); }
	} );
	document.addEventListener( 'change', function ( event ) {
		var box = event.target && event.target.closest ? event.target.closest( '[data-mpc-copy-apply]' ) : null;
		if ( box ) { productCopySyncApply( box.closest( '.lucidlab-mpc-copy-card' ) ); }
	} );
	async function productCopyCall( card, params ) {
		var note = card.querySelector( '.lucidlab-mpc-copy-note' );
		var spinButton = params.op === 'product_copy_suggest' ? card.querySelector( 'button[data-mpc-copy-suggest]' ) : null;
		if ( params.op === 'product_copy_suggest' ) {
			var aboutField = el( '#lucidlab-mpc-copy-about' );
			if ( aboutField ) { params = Object.assign( {}, params, { site_about: aboutField.value } ); }
		}
		card.querySelectorAll( 'button' ).forEach( function ( button ) { button.disabled = true; } );
		productCopySpinner( spinButton, true );
		try {
			var response = await post( Object.assign( { direct: 1, product_id: card.getAttribute( 'data-product-id' ) }, params ) );
			if ( ! response || ! response.success ) {
				throw new Error( response && response.data && response.data.message || mpcText("Request failed") );
			}
			productCopyFill( card, response.data || {} );
			if ( card.classList.contains( 'lucidlab-mpc-copy-recent-row' ) ) {
				var twin = document.querySelector( '.lucidlab-mpc-copy-card[data-product-id="' + card.getAttribute( 'data-product-id' ) + '"]' );
				if ( twin ) { productCopyFill( twin, response.data || {} ); }
			}
		} catch ( error ) {
			if ( note ) { note.textContent = error.message || mpcText("Request failed"); }
		} finally {
			productCopySpinner( spinButton, false );
			card.querySelectorAll( 'button' ).forEach( function ( button ) { button.disabled = false; } );
			productCopySyncApply( card );
		}
	}

	/* ---------- Stepped scan (Tab 2 & 3) ---------- */
	var crawlShown = 0;
	var crawlKeys = {};
	var crawlGen = 0;
	var crawlPulling = false;
	var crawlTimer = null;

	function prepareCrawlLive() {
		crawlGen++;
		crawlShown = 0;
		crawlKeys = {};
		if ( crawlTimer ) { clearInterval( crawlTimer ); crawlTimer = null; }
		var live = document.getElementById( 'lucidlab-mpc-crawl-live' );
		if ( live ) {
			var body = live.querySelector( 'tbody' );
			if ( body ) { body.textContent = ''; }
			live.hidden = true;
		}
		document.querySelectorAll( '.lucidlab-mpc-crawl-saved' ).forEach( function ( node ) { node.hidden = true; } );
		// Hide once for the whole crawl — progress polls must not re-show this control.
		document.querySelectorAll( '.lucidlab-mpc-crawl-results-link' ).forEach( function ( node ) { setHidden( node, true ); } );
		var crawlScan = document.querySelector( '[data-mpc-scan="crawl"]' );
		if ( crawlScan ) {
			var stray = crawlScan.nextElementSibling;
			while ( stray && ( stray.classList.contains( 'lucidlab-mpc-inline-spinner' ) || stray.classList.contains( 'lucidlab-mpc-current-results-link' ) ) ) {
				var next = stray.nextElementSibling;
				if ( stray.classList.contains( 'lucidlab-mpc-current-results-link' ) ) { stray.remove(); }
				stray = next;
			}
		}
	}

	function restoreCrawlSaved() {
		document.querySelectorAll( '.lucidlab-mpc-crawl-saved' ).forEach( function ( node ) { node.hidden = false; } );
		document.querySelectorAll( '.lucidlab-mpc-crawl-results-link' ).forEach( function ( node ) { setHidden( node, false ); } );
	}

	function crawlActionLink( href, label ) {
		var link = document.createElement( 'a' );
		link.className = 'button button-small';
		link.target = '_blank';
		link.rel = mpcText("noopener noreferrer");
		link.href = href;
		link.textContent = label;
		return link;
	}

	function appendCrawlRows( rows ) {
		var live = document.getElementById( 'lucidlab-mpc-crawl-live' );
		if ( ! live || ! rows || ! rows.length ) { return; }
		var body = live.querySelector( 'tbody' );
		if ( ! body ) { return; }
		rows.forEach( function ( row ) {
			var address = String( row.url || row.image || '' );
			var key = String( row.page || '' ) + '\n' + address + '\n' + String( row.status || '' ) + '\n' + String( row.error || '' );
			if ( crawlKeys[ key ] ) { return; }
			crawlKeys[ key ] = 1;
			var tr = document.createElement( 'tr' );
			tr.className = 'lucidlab-mpc-missing-row';
			var pageCell = document.createElement( 'td' );
			var title = document.createElement( row.page ? 'a' : 'span' );
			if ( row.page ) { title.href = row.page; title.target = '_blank'; title.rel = 'noopener'; }
			title.textContent = row.title || row.page || '';
			pageCell.appendChild( title );
			var pageActions = document.createElement( 'div' );
			pageActions.className = 'lucidlab-mpc-missing-usage-actions';
			if ( row.page ) { pageActions.appendChild( crawlActionLink( row.page, mpcT( 'open', 'Open' ) ) ); }
			if ( row.edit ) { pageActions.appendChild( crawlActionLink( row.edit, mpcT( 'edit', 'Edit' ) ) ); }
			if ( pageActions.childNodes.length ) { pageCell.appendChild( pageActions ); }
			var imageCell = document.createElement( 'td' );
			var code = document.createElement( 'code' );
			code.textContent = address;
			imageCell.appendChild( code );
			if ( row.via ) {
				var via = document.createElement( 'div' );
				via.className = 'lucidlab-mpc-hint';
				via.textContent = mpcT( 'crawlFoundIn', 'Found in stylesheet:' ) + ' ';
				var viaCode = document.createElement( 'code' );
				viaCode.textContent = String( row.via );
				via.appendChild( viaCode );
				imageCell.appendChild( via );
			}
			var imageActions = document.createElement( 'div' );
			imageActions.className = 'lucidlab-mpc-missing-usage-actions';
			if ( address ) { imageActions.appendChild( crawlActionLink( address, mpcT( 'open', 'Open' ) ) ); }
			if ( row.image_edit ) { imageActions.appendChild( crawlActionLink( row.image_edit, mpcT( 'editMedia', 'Edit media' ) ) ); }
			if ( imageActions.childNodes.length ) { imageCell.appendChild( imageActions ); }
			var kindCell = document.createElement( 'td' );
			kindCell.textContent = String( row.kind_label || '' );
			if ( row.external ) {
				var other = document.createElement( 'div' );
				other.className = 'lucidlab-mpc-hint';
				other.textContent = mpcT( 'crawlOtherSite', 'Other site' );
				kindCell.appendChild( other );
			}
			var errorCell = document.createElement( 'td' );
			errorCell.textContent = String( row.problem_label || row.status || '' );
			tr.append( pageCell, imageCell, kindCell, errorCell );
			body.appendChild( tr );
		} );
		live.hidden = false;
	}

	function pullCrawlRows() {
		if ( crawlPulling || ! document.getElementById( 'lucidlab-mpc-crawl-live' ) ) { return; }
		var gen = crawlGen;
		crawlPulling = true;
		post( { op: 'crawl_rows', offset: crawlShown, direct: 1 } ).then( function ( res ) {
			crawlPulling = false;
			if ( gen !== crawlGen ) { return; }
			var data = res && res.data ? res.data : {};
			var rows = data.rows || [];
			appendCrawlRows( rows );
			crawlShown = Number( data.next != null ? data.next : crawlShown + rows.length );
			if ( rows.length >= 40 ) { pullCrawlRows(); }
		} ).catch( function () { crawlPulling = false; } );
	}

	function watchCrawlRows( active ) {
		pullCrawlRows();
		if ( crawlTimer ) { clearInterval( crawlTimer ); crawlTimer = null; }
		if ( active ) { crawlTimer = setInterval( function () { if ( ! document.hidden && mpcHttpPause.until <= Date.now() ) { pullCrawlRows(); } }, 4000 ); }
	}

	function syncCrawlLive( d ) {
		if ( ! d || ( d.scan_tab !== 'crawl' && String( d.key || '' ).indexOf( 'scan_crawl' ) !== 0 ) ) { return; }
		var active = [ 'running', 'paused' ].includes( d.status );
		if ( active ) {
			document.querySelectorAll( '.lucidlab-mpc-crawl-saved' ).forEach( function ( node ) { node.hidden = true; } );
			document.querySelectorAll( '.lucidlab-mpc-crawl-results-link' ).forEach( function ( node ) { setHidden( node, true ); } );
		}
		watchCrawlRows( active );
	}

	function showScanCompleteNotice( panel, d ) {
		if ( ! panel || ! d || d.status !== 'done' ) { return; }
		var tab = d.scan_tab || d.panel || '';
		if ( tab === 'webpstats' || tab === 'crawl' || tab === 'webpscan' ) { return; }
		var box = panel.querySelector( '.lucidlab-mpc-job-summary[data-mpc-job-summary="' + tab + '"]:not(.lucidlab-mpc-missing-results-notice):not(#lucidlab-mpc-webp-stat)' );
		if ( ! box ) {
			box = document.createElement( 'div' );
			box.className = 'notice notice-info inline lucidlab-mpc-job-summary';
			box.dataset.mpcJobSummary = tab;
		}
		var stamp = Number( ( d.result && d.result.completed_at ) || d.completed_at || d.updated || 0 ) || Math.floor( Date.now() / 1000 );
		var date = ( stamp === Number( d.updated || 0 ) && d.updated_label ) ? d.updated_label : ( formatJobDate( stamp ) || d.updated_label || '' );
		var text = ( tab === 'fs-sizes' ? mpcText( 'Other Sizes on Disk' ) + ' \u2014 ' : tab === 'sizes' ? mpcText( 'Analyze sizes' ) + ' \u2014 ' : '' ) + mpcT( 'completedOn', 'Completed on:' ) + ' ' + date;
		box.dataset.mpcCompleted = String( stamp );
		box.textContent = text;
		setHidden( box, false );
		placeStatusAtPanelTop( panel, box );
	}

	function runScan( tab, extra ) {
		extra = extra || {};
		if ( [ 'orphans', 'uploads', 'duplicates', 'shared', 'visual', 'dupcontent', 'missing', 'crawl', 'product_copy', 'sizes', 'fs-sizes', 'webpstats' ].includes( tab ) ) {
			var noticePanel=el('[data-mpc-panel="'+(({ 'fs-sizes':'sizes', webpstats:'webp', crawl:'missing' })[tab]||tab)+'"]');
			var previousNotice=noticePanel&&noticePanel.querySelector('.lucidlab-mpc-job-summary[data-mpc-job-summary="'+tab+'"]:not(.lucidlab-mpc-missing-results-notice)');
			if(previousNotice&&previousNotice.dataset.mpcCompleted){
				previousNotice.dataset.mpcPreviousText=previousNotice.textContent;
				previousNotice.textContent=mpcText('Scanning…')+' · '+mpcText('Previous')+': '+previousNotice.dataset.mpcPreviousText;
			}
			persistentTask( Object.assign( { op: 'scan', tab: tab }, extra ) ).then( function () {
				// Visual candidates stay unloaded until View current results (the list is expensive).
				var pageTab = ( { 'fs-sizes': 'sizes', webpstats: 'webp', crawl: 'missing' } )[ tab ] || tab;
				var baseUrl = LucidLabMPC.tabUrls[ pageTab ] || window.location.pathname + '?page=lucidlab-mpc-cleanup&tab=' + encodeURIComponent( pageTab );
				window.location = baseUrl + ( tab === 'visual' ? '' : '&lucidlab_mpc_scanned=1' ) + ( tab === 'fs-sizes' ? '&lucidlab_mpc_fs_sizes=1' : '' );
			} ).catch( function ( error ) {
				if(previousNotice&&previousNotice.dataset.mpcPreviousText){previousNotice.textContent=previousNotice.dataset.mpcPreviousText;delete previousNotice.dataset.mpcPreviousText;}
				var scanButton=el('[data-mpc-scan="'+tab+'"]')||el(tab==='fs-sizes'?'#lucidlab-mpc-fssizes-scan':tab==='webpstats'?'#lucidlab-mpc-webpstats-scan':null);
				if(scanButton){buttonBusyInside(scanButton,false);}
				if(error&&error.message==='Cancelled; completed changes remain.')return;
				showActionError(error);
			} );
			return;
		}
		var pb = progress( tab );
		pb.show();
		scrollToProgress( tab );
		pb.set( 2, LucidLabMPC.i18n.scanning );

		function step( first ) {
			var params = { op: 'scan', tab: tab };
			Object.keys( extra ).forEach( function ( k ) { params[ k ] = extra[ k ]; } );
			if ( first ) { params.reset = 1; }
			post( params ).then( function ( res ) {
				if ( ! res || ! res.success ) { pb.set( 100, LucidLabMPC.i18n.error ); return; }
				var d = res.data;
				pb.set( d.progress, d.label || '' );
				if ( d.done ) {
					pb.set( 100, LucidLabMPC.i18n.done );
					window.location = LucidLabMPC.tabUrls[ tab ] + '&lucidlab_mpc_scanned=1';
					return;
				}
				step( false );
			} ).catch( function () { pb.set( 100, LucidLabMPC.i18n.error ); } );
		}
		step( true );
	}

	/* ---------- Batched quarantine (Tab 2 & 3) ---------- */
	function collectItems( zone ) {
		var items = [];
		els( '.lucidlab-mpc-row-check:checked', zone ).forEach( function ( c ) {
			if ( c.getAttribute( 'data-multi' ) ) {
				try { JSON.parse( c.value ).forEach( function ( r ) { items.push( r ); } ); } catch ( e ) {}
			} else {
				items.push( c.value );
			}
		} );
		return items;
	}

	function escapeHtml( value ) {
		return String( value == null ? '' : value ).replace( /[&<>"']/g, function ( ch ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ ch ];
		} );
	}

	function mergeQuarantineErrors( target, groups ) {
		( groups || [] ).forEach( function ( group ) {
			var code = group.code || group.label || 'unknown_filesystem_error';
			if ( ! target[ code ] ) {
				target[ code ] = { code: code, label: group.label || mpcText("Unexpected filesystem error"), files: [] };
			}
			( group.files || [] ).forEach( function ( file ) { target[ code ].files.push( file ); } );
		} );
	}

	function renderQuarantineErrors( progressId, grouped, ok, fail ) {
		var box = el( '#lucidlab-mpc-progress-' + progressId );
		if ( ! box ) { return; }
		var old = el( '.lucidlab-mpc-quarantine-errors', box.parentNode );
		if ( old ) { old.remove(); }

		var groups = Object.keys( grouped ).map( function ( key ) { return grouped[ key ]; } );
		if ( ! groups.length ) { return; }

		var wrap = document.createElement( 'div' );
		wrap.className = 'lucidlab-mpc-quarantine-errors notice notice-error';
		var html = ("<p><strong>" + escapeHtml(mpcText("Quarantine completed with errors.")) + "</strong> Moved: ") + ok + mpcText(" &nbsp; Failed items: ") + fail + '</p>';
		html += '<div class="lucidlab-mpc-error-groups">';
		groups.forEach( function ( group ) {
			var files = group.files || [];
			html += '<details class="lucidlab-mpc-error-group"><summary>' + escapeHtml( group.label ) + ' <span class="lucidlab-mpc-error-count">(' + files.length + ')</span></summary><ul>';
			files.forEach( function ( item ) {
				var id = item.id ? '<span class="lucidlab-mpc-error-id">#' + escapeHtml( item.id ) + '</span> ' : '';
				var detail = item.detail ? '<div class="lucidlab-mpc-error-detail">' + escapeHtml( item.detail ) + '</div>' : '';
				html += '<li>' + id + '<code>' + escapeHtml( item.file || mpcText("(unknown file)") ) + '</code>' + detail + '</li>';
			} );
			html += '</ul></details>';
		} );
		html += ("</div><p><button type=\"button\" class=\"button lucidlab-mpc-quarantine-refresh\">" + escapeHtml(mpcText("Refresh results")) + "</button></p>");
		wrap.innerHTML = html;
		box.insertAdjacentElement( 'afterend', wrap );
		var refresh = el( '.lucidlab-mpc-quarantine-refresh', wrap );
		if ( refresh ) { refresh.addEventListener( 'click', function () { window.location.reload(); } ); }
	}

	async function runQuarantine( tab, zone, triggerBtn ) {
		var allResults = tab === 'uploads' && zone && zone.dataset.mpcAllResults === '1';
		var items = allResults ? [] : collectItems( zone );
		if ( ! allResults && ! items.length ) { alert( LucidLabMPC.i18n.nothing ); return; }
		var dbMode = zone && zone.dataset ? String( zone.dataset.mpcDbMode || '' ) : '';
		var progressId = ( zone && zone.dataset && zone.dataset.mpcProgress ) ? String( zone.dataset.mpcProgress ) : ( tab + '-quar' );
		var quarBtn = triggerBtn || ( zone && zone.querySelector( '[data-mpc-quarantine="' + tab + '"]' ) ) || el( '[data-mpc-quarantine="' + tab + '"]' );
		var previewPayload = { preview: 'quarantine', tab: tab };
		if ( dbMode ) { previewPayload.db_mode = dbMode; }
		if ( allResults ) { previewPayload.all = 1; }
		else { previewPayload.items = JSON.stringify( items ); }
		if ( quarBtn ) { buttonBusyInside( quarBtn, true ); }
		var previewRes;
		try {
			previewRes = await post( Object.assign( { op: 'preview', direct: true }, previewPayload ) );
			if ( ! previewRes || ! previewRes.success ) {
				throw new Error( ( previewRes && previewRes.data && previewRes.data.message ) || ( LucidLabMPC.i18n && LucidLabMPC.i18n.error ) || mpcText("Preview failed") );
			}
		} catch ( error ) {
			if ( quarBtn ) { buttonBusyInside( quarBtn, false ); }
			showActionError( error );
			return;
		}
		if ( tab === 'uploads' ) { previewRes.data.compact = true; }
		if ( tab === 'dbcleaner' && dbMode === 'rows' ) {
			previewRes.data.compact = true;
			previewRes.data.item_label = 'rows';
			previewRes.data.files_label = ( Number( previewRes.data.files || previewRes.data.count ) || 0 ).toLocaleString() + mpcText(" rows");
		}
		var fileTotal = Number( previewRes.data.files || previewRes.data.count ) || 0;
		if ( ! fileTotal ) {
			if ( quarBtn ) { buttonBusyInside( quarBtn, false ); }
			alert( LucidLabMPC.i18n.nothing );
			return;
		}
		var confirmMsg = LucidLabMPC.i18n.confirmQuar;
		if ( tab === 'dbcleaner' ) {
			if ( dbMode === 'rows' && zone && zone.getAttribute( 'data-mpc-db-advanced' ) === '1' && LucidLabMPC.i18n.confirmDbAdvancedQuar ) {
				confirmMsg = LucidLabMPC.i18n.confirmDbAdvancedQuar;
			} else if ( dbMode === 'rows' && LucidLabMPC.i18n.confirmDbRowsQuar ) {
				confirmMsg = LucidLabMPC.i18n.confirmDbRowsQuar;
			} else if ( LucidLabMPC.i18n.confirmDbQuar ) {
				confirmMsg = LucidLabMPC.i18n.confirmDbQuar;
			}
		}
		if ( ! await inlineConfirm( confirmMsg, quarBtn || zone, previewRes.data ) ) {
			if ( quarBtn ) { buttonBusyInside( quarBtn, false ); }
			return;
		}
		if ( quarBtn ) { buttonBusyInside( quarBtn, true ); }

		var pb = progress( progressId );
		pb.show();
		scrollToProgress( progressId );
		pb.set( 1, LucidLabMPC.i18n.quarantining );

		var batch = items.length, total = items.length, done = 0, stamp = '';
		var totalOk = 0, totalFail = 0, groupedErrors = {};
		var optimizeNotes = [];

		function quarantineDoneUrl() {
			var url = LucidLabMPC.tabUrls[ tab ] + '&lucidlab_mpc_quarantined=1&lucidlab_mpc_load=1';
			if ( stamp ) { url += '&lucidlab_mpc_set=' + encodeURIComponent( stamp ); }
			if ( totalOk ) { url += '&lucidlab_mpc_ok=' + totalOk; }
			if ( totalFail ) { url += '&lucidlab_mpc_fail=' + totalFail; }
			return url;
		}
		function finishQuarantine() {
			var optSuffix = '';
			if ( optimizeNotes.length ) {
				optSuffix = mpcText(" Optimized: ") + optimizeNotes.join( '; ' ) + '.';
			}
			if ( totalFail > 0 ) {
				pb.set( 100, mpcText("Quarantine finished: ") + totalOk + mpcText(" moved, ") + totalFail + mpcText(" failed.") + optSuffix );
				renderQuarantineErrors( progressId, groupedErrors, totalOk, totalFail );
				if ( quarBtn ) { buttonBusyInside( quarBtn, false ); }
			} else if ( optSuffix ) {
				pb.set( 100, ( LucidLabMPC.i18n.done || mpcText("Done") ) + optSuffix );
				window.setTimeout( function () {
					window.location = quarantineDoneUrl();
				}, 900 );
			} else {
				pb.set( 100, LucidLabMPC.i18n.done );
				window.location = quarantineDoneUrl();
			}
		}
		function collectOptimize( list ) {
			( list || [] ).forEach( function ( row ) {
				if ( ! row || ! row.table ) { return; }
				var st = String( row.status || '' );
				if ( st === 'optimized' || st === 'dropped' ) {
					optimizeNotes.push( row.table + ( st === 'dropped' ? ' (dropped)' : '' ) );
				} else if ( st === 'lock' || st === 'error' || st === 'budget' ) {
					optimizeNotes.push( row.table + ' (' + st + ')' );
				}
			} );
		}
		function quarFail( err ) {
			if ( quarBtn ) { buttonBusyInside( quarBtn, false ); }
			pb.set( 100, LucidLabMPC.i18n.error + ( err && err.message ? ' (' + err.message + ')' : '' ) );
		}
		if ( allResults ) {
			post( { op: 'quarantine', tab: tab, all: 1, file_total: fileTotal } ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					var msg = ( res && res.data && res.data.message ) ? res.data.message : LucidLabMPC.i18n.error;
					if ( quarBtn ) { buttonBusyInside( quarBtn, false ); }
					pb.set( 100, msg );
					return;
				}
				stamp = res.data.stamp || stamp;
				totalOk = parseInt( res.data.ok || 0, 10 );
				totalFail = parseInt( res.data.fail || 0, 10 );
				mergeQuarantineErrors( groupedErrors, res.data.errors || [] );
				collectOptimize( res.data.optimize || [] );
				finishQuarantine();
			} ).catch( quarFail );
			return;
		}

		function next() {
			if ( done >= total ) {
				finishQuarantine();
				return;
			}
			var chunk = items.slice( done, done + batch );
			var payload = { op: 'quarantine', tab: tab, stamp: stamp, items: JSON.stringify( chunk ) };
			if ( dbMode ) { payload.db_mode = dbMode; }
			post( payload ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					var msg = ( res && res.data && res.data.message ) ? res.data.message : LucidLabMPC.i18n.error;
					if ( quarBtn ) { buttonBusyInside( quarBtn, false ); }
					pb.set( 100, msg );
					return;
				}
				stamp = res.data.stamp || stamp;
				totalOk += parseInt( res.data.ok || 0, 10 );
				totalFail += parseInt( res.data.fail || 0, 10 );
				mergeQuarantineErrors( groupedErrors, res.data.errors || [] );
				collectOptimize( res.data.optimize || [] );
				done += chunk.length;
				var lbl = mpcText("Quarantining ") + done + '/' + total + '\u2026';
				if ( totalFail ) { lbl += ' (' + totalFail + ' failed)'; }
				pb.set( Math.min( 99, Math.round( done / total * 100 ) ), lbl );
				next();
			} ).catch( quarFail );
		}
		next();
	}

	/* ---------- Batched duplicate trash (Tab 1) ---------- */
	async function runTrashDup( form ) {
		var ids = els( '.lucidlab-mpc-dup-check', form )
			.filter( function ( c ) { return c.checked && ! c.disabled; } )
			.map( function ( c ) { return c.value; } );
		if ( ! ids.length ) { alert( LucidLabMPC.i18n.nothing ); return; }
		if ( ! await inlineConfirm( LucidLabMPC.i18n.confirmDup ) ) { return; }

		var pb = progress( 'duplicates-trash' );
		pb.show();
		scrollToProgress( 'duplicates-trash' );
		pb.set( 1, LucidLabMPC.i18n.trashing );
		var batch = ids.length, total = ids.length, done = 0, started = false;

		function next() {
			if ( done >= total ) {
				pb.set( 100, LucidLabMPC.i18n.done );
				window.location = ( form.getAttribute( 'data-tab-url' ) || window.location.href.split( '&lucidlab_mpc_' )[0] ) + '&lucidlab_mpc_trashed=1';
				return;
			}
			var chunk = ids.slice( done, done + batch );
			var params = { op: 'trash_dup', items: JSON.stringify( chunk ) };
			if ( started ) { params.stamp = '1'; }
			started = true;
			post( params ).then( function ( res ) {
				if ( ! res || ! res.success ) { pb.set( 100, LucidLabMPC.i18n.error ); return; }
				done += chunk.length;
				pb.set( Math.min( 99, Math.round( done / total * 100 ) ), mpcText("Trashing ") + done + '/' + total + '\u2026' );
				next();
			} ).catch( function () { pb.set( 100, LucidLabMPC.i18n.error ); } );
		}
		next();
	}

	/* ---------- Single-image quarantine (Tab 4) ---------- */
	async function runQuarantineImage( id, button ) {
		if ( button ) { buttonBusyInside( button, true ); }
		try {
			if ( ! await confirmImpact( LucidLabMPC.i18n.confirmImg, button, { preview: 'quarantine', tab: 'shared', items: JSON.stringify( [ id ] ) } ) ) { return; }
			var pb = progress( 'shared-quar' );
			pb.show();
			scrollToProgress( 'shared-quar' );
			pb.set( 20, LucidLabMPC.i18n.quarantining );
			var res = await post( { op: 'quarantine', tab: 'shared', stamp: '', items: JSON.stringify( [ id ] ) } );
			if ( ! res || ! res.success ) {
				throw new Error( ( res && res.data && res.data.message ) || LucidLabMPC.i18n.error );
			}
			pb.set( 100, LucidLabMPC.i18n.done );
			window.location = LucidLabMPC.tabUrls.shared + '&lucidlab_mpc_quarantined=1';
		} catch ( error ) {
			showActionError( error );
		} finally {
			if ( button && document.body.contains( button ) ) { buttonBusyInside( button, false ); }
		}
	}

	/* ---------- Duplicate content merge (Tab 5) ---------- */
		/* Run each scan immediately before its cleanup so earlier moves are accounted for. */
	var runAllBusy = false;
	function placeRunAllPicker( show ) {
		var picker = el( '#lucidlab-mpc-run-all-picker' );
		if ( ! picker ) { return null; }
		var home = el( '.lucidlab-mpc-welcome-actions' );
		/* Keep the checklist inline under Run all scans / Open options (not #lucidlab-mpc-top-notices). */
		if ( home && picker.parentElement !== home ) { home.appendChild( picker ); }
		picker.hidden = ! show;
		if ( show && picker.scrollIntoView ) { picker.scrollIntoView( { block: 'nearest' } ); }
		return picker;
	}
	document.addEventListener( 'click', async function ( event ) {
		var openBtn = event.target.closest( '[data-mpc-run-all]' );
		var picker = el( '#lucidlab-mpc-run-all-picker' );
		if ( openBtn && picker ) {
			event.preventDefault();
			placeRunAllPicker( true );
			openBtn.setAttribute( 'aria-expanded', 'true' );
			return;
		}
		if ( event.target.closest( '[data-mpc-run-all-cancel]' ) && picker ) {
			placeRunAllPicker( false );
			var toggle = el( '[data-mpc-run-all]' );
			if ( toggle ) toggle.setAttribute( 'aria-expanded', 'false' );
			return;
		}
		var selectAll = event.target.closest( '[data-mpc-run-all-select-all]' );
		if ( selectAll && picker ) {
			els( '[data-mpc-run-all-stage]', picker ).forEach( function ( box ) { box.checked = selectAll.checked; } );
			return;
		}
		var autoSelect = event.target.closest( '[data-mpc-automation-select-all]' );
		if ( autoSelect ) {
			var fieldset = autoSelect.closest( 'fieldset' );
			if ( fieldset ) {
				els( 'input[type="checkbox"][name^="lucidlab_mpc_autorun_"]', fieldset ).forEach( function ( box ) { box.checked = autoSelect.checked; } );
			}
			return;
		}
		var startBtn = event.target.closest( '[data-mpc-run-all-start]' );
		if ( ! startBtn || runAllBusy ) { return; }
		var stages = els( '[data-mpc-run-all-stage]:checked', picker || document ).map( function ( box ) { return box.value; } );
		if ( ! stages.length ) { alert( mpcText("Select at least one scan or job to run.") ); return; }
		var emailReport = !!( picker && el( '[data-mpc-run-all-email]', picker ) && el( '[data-mpc-run-all-email]', picker ).checked );
		if ( emailReport && ! LucidLabMPC.hasReportEmail ) {
			alert( mpcText("Add at least one email under Options → Email report before requesting an email report.") );
			return;
		}
		var confirmMsg = mpcText("Run the selected stages (rebuild index, scans, quarantine, thumbnails and/or WebP / AVIF as chosen) with saved settings? Merges, renames and permanent purges remain manual.");
		if ( emailReport ) { confirmMsg += mpcText(" An email report will be sent to the Options recipients when Run All finishes."); }
		var confirmHost = startBtn.closest( '.lucidlab-mpc-welcome-actions, #lucidlab-mpc-run-all-picker, .lucidlab-mpc-btn-row' ) || el( '#lucidlab-mpc-run-all-picker' ) || startBtn;
		if ( ! await inlineConfirm( confirmMsg, confirmHost ) ) { return; }
		runAllBusy = true;
		buttonBusyInside( startBtn, true );
		var runAllButton = el( '[data-mpc-run-all]' );
		if ( runAllButton ) buttonBusyInside( runAllButton, true );
		try {
			await persistentTask({op:'run_all',panel:LucidLabMPC.tab,progress_id:'run-all',stages:stages,email_report:emailReport?1:0});
			placeRunAllPicker( false );
			if ( runAllButton ) runAllButton.setAttribute( 'aria-expanded', 'false' );
		} catch ( error ) {
			showActionError(error);
		} finally {
			runAllBusy = false;
			buttonBusyInside( startBtn, false );
			if ( runAllButton ) buttonBusyInside( runAllButton, false );
		}
	} );

	document.addEventListener( 'change', function ( event ) {
		var stage = event.target.closest( '[data-mpc-run-all-stage]' );
		var picker = el( '#lucidlab-mpc-run-all-picker' );
		if ( stage && picker ) {
			var boxes = els( '[data-mpc-run-all-stage]', picker );
			var master = el( '[data-mpc-run-all-select-all]', picker );
			if ( master ) master.checked = boxes.length > 0 && boxes.every( function ( box ) { return box.checked; } );
		}
		var matchMode = event.target.closest( 'input[name="lucidlab_mpc_match_mode"]' );
		if ( matchMode && matchMode.checked ) {
			post( { op: 'save_match_mode', lucidlab_mpc_match_mode: matchMode.value } ).catch( function () {} );
		}
		var autoBox = event.target.closest( 'input[type="checkbox"][name^="lucidlab_mpc_autorun_"]' );
		if ( autoBox ) {
			var fieldset = autoBox.closest( 'fieldset' );
			var masterAuto = fieldset && el( '[data-mpc-automation-select-all]', fieldset );
			if ( masterAuto && fieldset ) {
				var boxes = els( 'input[type="checkbox"][name^="lucidlab_mpc_autorun_"]', fieldset );
				masterAuto.checked = boxes.length > 0 && boxes.every( function ( box ) { return box.checked; } );
			}
		}
	} );

		function showVisualMergeBar( pct, text ) {
			var panel = el( '[data-mpc-panel="visual"]' ) || document;
			els( '.lucidlab-mpc-progress', panel ).forEach( function ( node ) {
				if ( node.id !== 'lucidlab-mpc-visual-merge-bar' ) { node.hidden = true; }
			} );
			var bar = el( '#lucidlab-mpc-visual-merge-bar' );
			if ( ! bar ) {
				bar = document.createElement( 'div' );
				bar.id = 'lucidlab-mpc-visual-merge-bar';
				bar.className = 'lucidlab-mpc-progress';
				bar.innerHTML = '<div class="lucidlab-mpc-progress-label" role="status"></div><div class="lucidlab-mpc-progress-track"><div class="lucidlab-mpc-progress-bar"></div></div>';
				var anchor = el( '[data-mpc-merge-all]', panel ) || el( '[data-mpc-merge-group]', panel );
				if ( anchor && anchor.parentNode ) { anchor.parentNode.insertBefore( bar, anchor ); }
				else { panel.appendChild( bar ); }
			}
			bar.hidden = false;
			var label = el( '.lucidlab-mpc-progress-label', bar );
			var fill = el( '.lucidlab-mpc-progress-bar', bar );
			if ( label ) { label.textContent = text || ''; }
			if ( fill ) { fill.style.width = Math.max( 0, Math.min( 100, pct || 0 ) ) + '%'; }
		}
		async function mergeVisualGroup( group, options ) {
		options = options || {};
		var button = el( '[data-mpc-merge-group]', group );
		if ( ! group || ! button || button.disabled ) { return { merged: 0, references: 0, skipped: true }; }
		var status = el( '.lucidlab-mpc-group-merge-status', group );
		if ( ! status ) {
			status = document.createElement( 'p' );
			status.className = 'lucidlab-mpc-group-merge-status notice notice-info inline';
			status.setAttribute( 'role', 'status' );
			button.parentNode.appendChild( status );
		}
		var pairs = collectMergePairs( group );
		if ( ! pairs.length ) { status.textContent = mpcText("Choose a Keep image in this group before merging."); return { merged: 0, references: 0, skipped: true }; }
		if ( ! options.skipConfirm && button.getAttribute( 'data-confirm-pairs' ) !== JSON.stringify( pairs ) ) {
			var preview = null;
			try {
				var previewRes = await post( { op: 'preview', preview: 'merge', tab: 'dupcontent', pairs: JSON.stringify( pairs ), direct: true } );
				if ( previewRes && previewRes.success ) { preview = previewRes.data; }
			} catch ( error ) { showActionError( error ); throw error; }
			button.setAttribute( 'data-confirm-pairs', JSON.stringify( pairs ) );
			button.textContent = mpcText("Confirm merge of this group");
			status.textContent = ( preview ? previewSummary( preview ) + '. ' : '' ) + mpcText("Ready to merge ") + pairs.length + mpcText(" duplicate image(s) into image #") + pairs[0].keep + '. This is a preview \u2014 nothing has been moved yet. Click Confirm merge to start.';
			var cancel = el( '.lucidlab-mpc-cancel-group-merge', group );
			if ( ! cancel ) {
				cancel = document.createElement( 'button' );
				cancel.type = 'button';
				cancel.className = 'button lucidlab-mpc-cancel-group-merge';
				cancel.textContent = mpcText("Cancel");
				button.parentNode.appendChild( cancel );
				cancel.addEventListener( 'click', function () {
					button.removeAttribute( 'data-confirm-pairs' );
					button.textContent = mpcText("Merge this group into its Keep image");
					status.remove();
					cancel.remove();
				} );
			}
			return { merged: 0, references: 0, preview: true };
		}
		button.removeAttribute( 'data-confirm-pairs' );
		var cancel = el( '.lucidlab-mpc-cancel-group-merge', group );
		if ( cancel ) { cancel.remove(); }
		button.textContent = mpcText("Merging…");
		button.disabled = true;
		showVisualMergeBar( 8, mpcText("Merging…") );
		var stamp = options.stamp || '', merged = 0, references = 0;
		status.className = 'lucidlab-mpc-group-merge-status notice notice-info inline';
		status.textContent = mpcText("Merging…");
		var mergeSource = ( group && group.closest( '[data-mpc-panel="visual"]' ) ) || ( LucidLabMPC.tab === 'visual' ) ? 'visual' : 'dupcontent';
		var response = await post( { op: 'merge', stamp: stamp, pairs: JSON.stringify( pairs ), source: mergeSource, tab: mergeSource, panel: mergeSource, direct: true } );
		if ( ! response || ! response.success ) { throw new Error( response && response.data && response.data.message || mpcText("The server rejected the merge. Refresh the page and scan again.") ); }
		var data = response.data || {};
		stamp = data.stamp || stamp;
		if ( data.fail || ! data.ok ) { throw new Error( ( data.fails || [] ).join( '; ' ) || mpcText("The duplicate could not be quarantined. Check the plugin log.") ); }
		merged += data.ok;
		references += data.repointed || 0;
		pairs.forEach(function(pair){var row=el('.lucidlab-mpc-dupc-row[data-id="'+pair.dup+'"]',group);if(row)row.remove();});
		status.className = 'lucidlab-mpc-group-merge-status notice notice-success inline';
		status.textContent = mpcText("Merged ") + merged + mpcText(" duplicate image(s); updated ") + references + mpcText(" reference(s). Duplicates are in Quarantine.");
		button.textContent = mpcText("Group merged");
		els( '.lucidlab-mpc-dupc-keep', group ).forEach( function ( radio ) { radio.disabled = true; } );
		var preview = el( '.lucidlab-mpc-dupc-preview', group );
		if ( preview ) { preview.textContent = ''; }
		showVisualMergeBar( 100, mpcText("Merged ") + merged + mpcText(" duplicate image(s)") );
		return { merged: merged, references: references, stamp: stamp };
	}
		document.addEventListener( 'click', async function ( event ) {
		var allButton = event.target.closest( '[data-mpc-merge-all]' );
		if ( allButton ) {
			event.preventDefault();
			if ( allButton.disabled || mergeBusy ) { return; }
			var groups = els( '.lucidlab-mpc-group' );
			if ( ! groups.length ) { return; }
			if ( ! await inlineConfirm( mpcText("Merge all ") + groups.length + mpcText(" groups on this page using each selected Keep image? Duplicate files move to quarantine."), allButton ) ) { return; }
			mergeBusy = true;
			allButton.disabled = true;
			var allStatus = el( '.lucidlab-mpc-merge-all-status' );
			if ( allStatus ) { allStatus.className = 'lucidlab-mpc-merge-all-status notice notice-info inline'; allStatus.textContent = mpcText("Merging groups…"); }
			var merged = 0, references = 0, stamp = '', failed = 0;
			showVisualMergeBar( 1, mpcText("Merging groups…") );
			try {
				for ( var i = 0; i < groups.length; i++ ) {
					showVisualMergeBar( Math.round( ( i / groups.length ) * 100 ), mpcText("Merging group ") + ( i + 1 ) + mpcText(" of ") + groups.length + '\u2026' );
					if ( allStatus ) { allStatus.textContent = mpcText("Merging group ") + ( i + 1 ) + mpcText(" of ") + groups.length + '\u2026'; }
					try {
						var result = await mergeVisualGroup( groups[ i ], { skipConfirm: true, stamp: stamp } );
						if ( result && ! result.skipped ) {
							merged += result.merged || 0;
							references += result.references || 0;
							stamp = result.stamp || stamp;
						}
					} catch ( error ) {
						failed++;
						var groupStatus = el( '.lucidlab-mpc-group-merge-status', groups[ i ] );
						if ( groupStatus ) { groupStatus.className = 'lucidlab-mpc-group-merge-status notice notice-error inline'; groupStatus.textContent = error.message; }
					}
				}
				showVisualMergeBar( 100, failed ? mpcText("Merge stopped") : mpcText("Done") );
				if ( allStatus ) {
					allStatus.className = 'lucidlab-mpc-merge-all-status notice ' + ( failed ? 'notice-warning' : 'notice-success' ) + " inline";
					allStatus.textContent = mpcText("Merged ") + merged + mpcText(" duplicate image(s); updated ") + references + ' reference(s).' + ( failed ? ' ' + failed + ' group(s) failed.' : '' );
				}
				allButton.textContent = failed ? mpcText("Merge remaining groups") : mpcText("All groups merged");
			} finally {
				mergeBusy = false;
				if ( failed ) { allButton.disabled = false; }
			}
			return;
		}
		var button = event.target.closest( '[data-mpc-merge-group]' );
		if ( ! button ) { return; }
		event.preventDefault();
		var group = button.closest( '.lucidlab-mpc-group' );
		if ( ! group || button.disabled ) { return; }
		if ( mergeBusy ) {
			var status = el( '.lucidlab-mpc-group-merge-status', group );
			if ( status ) { status.textContent = mpcText("Another merge is running. Please wait for it to finish."); }
			return;
		}
		mergeBusy = true;
		try {
			await mergeVisualGroup( group, {} );
		} catch ( error ) {
			var status = el( '.lucidlab-mpc-group-merge-status', group );
			if ( status ) { status.className = 'lucidlab-mpc-group-merge-status notice notice-error inline'; status.textContent = error.message; }
			button.disabled = false;
		} finally {
			mergeBusy = false;
		}
	} );

function collectMergePairs( form ) {
		var groups = {}, pairs = [];
		els( '.lucidlab-mpc-dupc-keep:checked', form ).forEach( function ( r ) {
			groups[ r.getAttribute( 'data-group' ) ] = r.getAttribute( 'data-id' );
		} );
		els( '.lucidlab-mpc-dupc-row', form ).forEach( function ( row ) {
			var g = row.getAttribute( 'data-group' ), id = row.getAttribute( 'data-id' );
			if ( groups[ g ] && id !== groups[ g ] ) {
				pairs.push( { dup: parseInt( id, 10 ), keep: parseInt( groups[ g ], 10 ), bytes: parseInt( row.getAttribute( 'data-bytes' ) || '0', 10 ) } );
			}
		} );
		return pairs;
	}

	var mergeBusy = false;
async function runMergeDup( form ) {
        if ( mergeBusy ) { return; }
		var pairs = collectMergePairs( form );
		if ( ! pairs.length ) { alert( LucidLabMPC.i18n.nothing ); return; }
		try {
			if ( ! await confirmImpact( LucidLabMPC.i18n.confirmMerge, form, { preview: 'merge', tab: 'dupcontent', pairs: JSON.stringify( pairs ) } ) ) { return; }
		} catch ( error ) { showActionError( error ); return; }
        mergeBusy = true;

		var pb = progress( 'dupcontent-merge' );
		pb.show();
		scrollToProgress( 'dupcontent-merge' );
		pb.set( 1, LucidLabMPC.i18n.merging );

		var batch = pairs.length, total = pairs.length, done = 0, stamp = '';

		function next() {
			if ( done >= total ) {
				pb.set( 100, LucidLabMPC.i18n.done );
				window.location = LucidLabMPC.tabUrls.dupcontent + '&lucidlab_mpc_quarantined=1';
				return;
			}
			var chunk = pairs.slice( done, done + batch );
			post( { op: 'merge', stamp: stamp, pairs: JSON.stringify( chunk ), source: 'dupcontent', tab: 'dupcontent', panel: 'dupcontent' } ).then( function ( res ) {
				if ( ! res || ! res.success || res.data.fail ) { mergeBusy = false; pb.set( 100, mpcText("Merge stopped — some items could not be merged. Review Quarantine before retrying.") ); return; }
				stamp = res.data.stamp || stamp;
				done += chunk.length;
				pb.set( Math.min( 99, Math.round( done / total * 100 ) ), mpcText("Merging ") + done + '/' + total + '\u2026' );
				next();
			} ).catch( function () { mergeBusy = false; pb.set( 100, LucidLabMPC.i18n.error ); } );
		}
		next();
	}

	function updateDupcPreview() {
		els( '.lucidlab-mpc-dupc-preview' ).forEach( function ( p ) {
			var g = p.getAttribute( 'data-group' );
			var keep = el( '.lucidlab-mpc-dupc-keep[data-group="' + g + '"]:checked' );
			if ( ! keep ) { p.textContent = ''; return; }
			var keepId = keep.getAttribute( 'data-id' );
			var copies = 0, refs = 0, keepTitle = '';
			els( '.lucidlab-mpc-dupc-row[data-group="' + g + '"]' ).forEach( function ( row ) {
				var id = row.getAttribute( 'data-id' );
				if ( id === keepId ) { keepTitle = row.getAttribute( 'data-title' ) || ''; return; }
				copies++;
				refs += parseInt( row.getAttribute( 'data-refs' ) || '0', 10 );
			} );
			p.textContent = refs
				? ( mpcText("Merging repoints ") + refs + mpcText(" reference") + ( refs === 1 ? '' : 's' ) + mpcText(" from ") + copies + mpcText(" copy") + ( copies === 1 ? '' : 'ies' ) + ' onto \u201C' + keepTitle + mpcText("”, then quarantines ") + copies + mpcText(" file") + ( copies === 1 ? '' : 's' ) + '.' )
				: ( copies + mpcText(" unused copy") + ( copies === 1 ? '' : 'ies' ) + mpcText(" will be quarantined; “") + keepTitle + mpcText("” is kept.") );
		} );
	}

	function resetDupcKeeps( form ) {
		var groups = {};
		els( '.lucidlab-mpc-dupc-row', form ).forEach( function ( row ) {
			var g = row.getAttribute( 'data-group' );
			var refs = parseInt( row.getAttribute( 'data-refs' ) || '0', 10 );
			var id = parseInt( row.getAttribute( 'data-id' ), 10 );
			if ( ! groups[ g ] || refs > groups[ g ].refs || ( refs === groups[ g ].refs && id < groups[ g ].id ) ) {
				groups[ g ] = { refs: refs, id: id };
			}
		} );
		Object.keys( groups ).forEach( function ( g ) {
			var radio = el( '.lucidlab-mpc-dupc-keep[data-group="' + g + '"][data-id="' + groups[ g ].id + '"]' );
			if ( radio ) { radio.checked = true; setDupSurvivor( radio ); }
		} );
		updateDupcPreview();
	}

	function setDupSurvivor( radio ) {
		var g = radio.getAttribute( 'data-group' ), keep = radio.getAttribute( 'data-id' );
		els( '.lucidlab-mpc-dupc-row[data-group="' + g + '"]' ).forEach( function ( row ) {
			var isKeep = ( row.getAttribute( 'data-id' ) === keep );
			row.classList.toggle( 'lucidlab-mpc-keep', isKeep );
			row.classList.toggle( 'lucidlab-mpc-dupe', ! isKeep );
			var rk = el( '.lucidlab-mpc-role-keep', row ), rd = el( '.lucidlab-mpc-role-dupe', row );
			if ( rk ) { rk.hidden = ! isKeep; }
			if ( rd ) { rd.hidden = isKeep; }
		} );
		updateDupcPreview();
	}

	/* ---------- Lightbox (all image tabs): prev/next + zoom ---------- */
	function lightbox( startEl ) {
		var scope = startEl.closest( '.lucidlab-mpc-webp-compare, .lucidlab-mpc-pro-teaser' );
		var gallery = scope ? els( '.lucidlab-mpc-lb', scope ) : els( '.lucidlab-mpc-lb' );
		var idx = gallery.indexOf( startEl );
		if ( idx < 0 ) { idx = 0; }
		var zoom = 1;

		var ov = document.createElement( 'div' );
		ov.className = 'lucidlab-mpc-lightbox';
		ov.innerHTML =
			("<button class=\"lucidlab-mpc-lb-nav lucidlab-mpc-lb-prev\" aria-label=\"" + escapeHtml(mpcText("Previous")) + "\">&#10094;</button>")
			+ (("<div class=\"lucidlab-mpc-lightbox-inner\"><button class=\"lucidlab-mpc-lightbox-close\" aria-label=\"" + escapeHtml(mpcText("Close")) + "\">") + "&times;" + "</button>")
			+ '<img class="lucidlab-mpc-lb-img" src="" alt="" /><div class="lucidlab-mpc-lightbox-cap"></div>'
			+ (("<div class=\"lucidlab-mpc-lb-tools\"><button class=\"lucidlab-mpc-lb-zoomout\" aria-label=\"" + escapeHtml(mpcText("Zoom out")) + "\">") + "&minus;" + "</button>")
			+ '<span class="lucidlab-mpc-lb-zlabel">100%</span>'
			+ ("<button class=\"lucidlab-mpc-lb-zoomin\" aria-label=\"" + escapeHtml(mpcText("Zoom in")) + "\">+</button>")
			+ ("<button class=\"lucidlab-mpc-lb-reset\">" + escapeHtml(mpcText("Reset")) + "</button></div></div>")
			+ ("<button class=\"lucidlab-mpc-lb-nav lucidlab-mpc-lb-next\" aria-label=\"" + escapeHtml(mpcText("Next")) + "\">&#10095;</button>");

		var img = ov.querySelector( '.lucidlab-mpc-lb-img' );
		var cap = ov.querySelector( '.lucidlab-mpc-lightbox-cap' );
		var zl  = ov.querySelector( '.lucidlab-mpc-lb-zlabel' );

		function applyZoom() { img.style.transform = 'scale(' + zoom + ')'; zl.textContent = Math.round( zoom * 100 ) + '%'; }
		function render() {
			var g = gallery[ idx ];
			if ( ! g ) { return; }
			zoom = 1;
			img.src = g.getAttribute( 'data-full' ) || g.getAttribute( 'src' );
			cap.textContent = g.getAttribute( 'data-caption' ) || '';
			applyZoom();
			var multi = gallery.length > 1;
			ov.querySelector( '.lucidlab-mpc-lb-prev' ).style.display = multi ? '' : 'none';
			ov.querySelector( '.lucidlab-mpc-lb-next' ).style.display = multi ? '' : 'none';
		}
		function move( d ) { idx = ( idx + d + gallery.length ) % gallery.length; render(); }
		function zoomBy( d ) { zoom = Math.min( 5, Math.max( 1, Math.round( ( zoom + d ) * 10 ) / 10 ) ); applyZoom(); }
		function close() { ov.remove(); document.removeEventListener( 'keydown', onKey ); }
		function onKey( e ) {
			if ( e.key === 'Escape' ) { close(); }
			else if ( e.key === 'ArrowRight' ) { move( 1 ); }
			else if ( e.key === 'ArrowLeft' ) { move( -1 ); }
			else if ( e.key === '+' || e.key === '=' ) { zoomBy( 0.25 ); }
			else if ( e.key === '-' ) { zoomBy( -0.25 ); }
		}
		ov.addEventListener( 'click', function ( e ) {
			var t = e.target;
			if ( t === ov || t.classList.contains( 'lucidlab-mpc-lightbox-close' ) ) { close(); }
			else if ( t.classList.contains( 'lucidlab-mpc-lb-next' ) ) { move( 1 ); }
			else if ( t.classList.contains( 'lucidlab-mpc-lb-prev' ) ) { move( -1 ); }
			else if ( t.classList.contains( 'lucidlab-mpc-lb-zoomin' ) ) { zoomBy( 0.25 ); }
			else if ( t.classList.contains( 'lucidlab-mpc-lb-zoomout' ) ) { zoomBy( -0.25 ); }
			else if ( t.classList.contains( 'lucidlab-mpc-lb-reset' ) ) { zoom = 1; applyZoom(); }
			else if ( t.classList.contains( 'lucidlab-mpc-lb-img' ) ) { zoomBy( 0.5 ); }
		} );
		img.addEventListener( 'wheel', function ( e ) { e.preventDefault(); zoomBy( e.deltaY < 0 ? 0.2 : -0.2 ); }, { passive: false } );
		document.addEventListener( 'keydown', onKey );
		document.body.appendChild( ov );
		render();
	}

	/* ---------- Survivor override (Tab 1) ---------- */
	function setSurvivor( radio ) {
		var g = radio.getAttribute( 'data-group' ), keep = radio.getAttribute( 'data-id' );
		els( 'tr[data-group="' + g + '"]' ).forEach( function ( row ) {
			var chk = el( '.lucidlab-mpc-dup-check', row );
			if ( ! chk ) { return; }
			var isKeep = ( chk.getAttribute( 'data-id' ) === keep );
			chk.disabled = isKeep;
			chk.checked = ! isKeep;
			row.classList.toggle( 'lucidlab-mpc-keep', isKeep );
			row.classList.toggle( 'lucidlab-mpc-dupe', ! isKeep );
			var rk = el( '.lucidlab-mpc-role-keep', row ), rd = el( '.lucidlab-mpc-role-dupe', row ), cb = el( '.lucidlab-mpc-cascade-badge', row );
			if ( rk ) { rk.hidden = ! isKeep; }
			if ( rd ) { rd.hidden = isKeep; }
			if ( cb ) { cb.hidden = isKeep || cb.getAttribute( 'data-novar' ) === '1'; }
		} );
	}

	/* ---------- Wiring ---------- */
	document.addEventListener( 'click', function ( e ) {
		var currentResults = e.target.closest( '.lucidlab-mpc-current-results-link' );
		if ( currentResults ) {
			if ( currentResults.id === 'lucidlab-mpc-fssizes-view' || currentResults.tagName === 'BUTTON' ) { return; }
			var destination = new URL( currentResults.href, window.location.href );
			if ( [ 'visual', 'duplicates', 'missing' ].includes( destination.searchParams.get( 'tab' ) ) ) {
				// These result pages may need a full reload to initialize their
				// controls. Navigation also avoids an indefinite AJAX spinner.
				return;
			}
			e.preventDefault();
			if ( currentResults.dataset.loading === '1' || currentResults.getAttribute( 'aria-disabled' ) === 'true' ) { return; }
			var panel = currentResults.closest( '[data-mpc-panel]' );
			var resultsUrl = new URL( currentResults.href, window.location.href );
			var resultsTab = resultsUrl.searchParams.get( 'tab' );
			currentResults.dataset.loading = '1';
			buttonBusyInside( currentResults, true );
			fetch( resultsUrl.href, { credentials: 'same-origin' } ).then( function ( response ) {
				if ( ! response.ok ) { throw new Error( mpcText("Could not load results (") + response.status + ').' ); }
				return response.text();
			} ).then( function ( html ) {
				var parsed = new DOMParser().parseFromString( html, 'text/html' );
				var loadedPanel = parsed.querySelector( '[data-mpc-panel="' + resultsTab + '"]' );
				if ( ! panel || ! loadedPanel ) { throw new Error( mpcText("The saved results could not be found.") ); }
				panel.replaceChildren.apply( panel, Array.prototype.slice.call( loadedPanel.childNodes ) );
				// Hide a leftover red snapshot notice when View loaded a clean empty state or the detail list.
				els( '.lucidlab-mpc-missing-results-notice', panel ).forEach( repairMissingResultsNotice );
				var stale = panel.querySelector( '.lucidlab-mpc-missing-results-notice' );
				if ( stale && ( panel.querySelector( '.lucidlab-mpc-empty.lucidlab-mpc-good' ) || panel.querySelector( '[data-mpc-results-loaded], .lucidlab-mpc-group, .lucidlab-mpc-dup-form, .lucidlab-mpc-missing-table, .lucidlab-mpc-shared-list, .lucidlab-mpc-visual-candidates, [data-mpc-db-results]' ) ) ) {
					stale.hidden = true;
				}
				// Keep Completed/status at the overview top (with snapshot counters) after AJAX panel swap.
				els( '.lucidlab-mpc-job-summary:not(.lucidlab-mpc-missing-results-notice)', panel ).forEach( function ( notice ) {
					placeStatusAtPanelTop( panel, notice );
				} );
				// Rebind an in-flight scan on this panel so the new Scan button stays busy and View still works.
				Object.keys( durableTasks ).forEach( function ( key ) {
					var task = durableTasks[ key ];
					if ( ! task || ! task.refreshAfterResultsLoad ) { return; }
					task.refreshAfterResultsLoad( panel, resultsTab );
				} );
				window.history.pushState( null, '', resultsUrl.href );
				document.dispatchEvent( new CustomEvent( 'mpc:tabshown', { detail: resultsTab } ) );
			} ).catch( function ( error ) {
				currentResults.dataset.loading = '0';
				buttonBusyInside( currentResults, false );
				showActionError( error );
			} );
			return;
		}
		var loadImages = e.target.closest( '[data-mpc-load-list]' );
		if ( loadImages ) {
			// Follow the real URL. This also works before admin.js is ready, and keeps
			// a slow result render visible as normal browser navigation.
			loadImages.setAttribute( 'aria-busy', 'true' );
			return;
		}
		var lb = e.target.closest( '.lucidlab-mpc-lb' );
		if ( lb ) {
			e.preventDefault();
			lightbox( lb );
			return;
		}

		var imgBtn = e.target.closest( '[data-mpc-quar-image]' );
		if ( imgBtn ) { e.preventDefault(); runQuarantineImage( imgBtn.getAttribute( 'data-id' ), imgBtn ); return; }

		var rerunLink = e.target.closest( '.lucidlab-mpc-finished-rerun' );
		if ( rerunLink ) {
			e.preventDefault();
			if ( rerunLink.hidden || rerunLink.getAttribute( 'aria-disabled' ) === 'true' ) { return; }
			var finishedRow = rerunLink.closest( '[data-finished-source]' );
			if ( ! finishedRow ) { return; }
			rerunLink.setAttribute( 'aria-disabled', 'true' );
			Promise.resolve( rerunFinishedJob( finishedRow ) ).catch( function ( error ) { showActionError( error ); } ).finally( function () { rerunLink.removeAttribute( 'aria-disabled' ); } );
			return;
		}

		var scanBtn = e.target.closest( '[data-mpc-scan]' );
		if ( scanBtn ) {
			e.preventDefault();
			var stab = scanBtn.getAttribute( 'data-mpc-scan' );
			var extra = {};
			if ( 'duplicates' === stab ) {
				var md = el( 'input[name="lucidlab_mpc_match_mode"]:checked' );
				if ( md ) { extra.lucidlab_mpc_match_mode = md.value; }
			}
			if ( 'product_copy' === stab ) {
				var emptyGallery = el( '#lucidlab-mpc-copy-empty-gallery' );
				if ( emptyGallery && emptyGallery.checked ) { extra.include_empty_gallery = 1; }
			}
			if ( 'crawl' === stab ) {
				var crawlLinks = el( '#lucidlab-mpc-crawl-links' );
				var crawlExternal = el( '#lucidlab-mpc-crawl-external' );
				extra.crawl_opts = 1;
				extra.crawl_links = crawlLinks && crawlLinks.checked ? 1 : 0;
				extra.crawl_external = crawlExternal && crawlExternal.checked && ! crawlExternal.disabled ? 1 : 0;
			}
			runScan( stab, extra );
			return;
		}

		var copySuggest = e.target.closest( '[data-mpc-copy-suggest]' );
		if ( copySuggest && copySuggest.tagName === 'BUTTON' ) {
			e.preventDefault();
			var suggestCard = copySuggest.closest( '.lucidlab-mpc-copy-card' );
			if ( suggestCard ) { productCopyCall( suggestCard, { op: 'product_copy_suggest' } ); }
			return;
		}
		var copyApply = e.target.closest( '[data-mpc-copy-apply-fields]' );
		if ( copyApply ) {
			e.preventDefault();
			var applyCard = copyApply.closest( '.lucidlab-mpc-copy-card' );
			if ( applyCard ) {
				var payload = { op: 'product_copy_apply' };
				var picked = 0;
				[ 'title', 'short', 'long' ].forEach( function ( field ) {
					var box = applyCard.querySelector( '[data-mpc-copy-apply="' + field + '"]' );
					var area = applyCard.querySelector( '[data-mpc-copy-suggest="' + field + '"]' );
					if ( box && box.checked ) {
						picked++;
						payload[ field === 'title' ? 'apply_title' : ( field === 'short' ? 'apply_short' : 'apply_long' ) ] = 1;
						payload[ field === 'title' ? 'title' : ( field === 'short' ? 'short_description' : 'description' ) ] = area ? area.value : '';
					}
				} );
				if ( ! picked ) {
					var note = applyCard.querySelector( '.lucidlab-mpc-copy-note' );
					if ( note ) { note.textContent = mpcText("Tick at least one field before applying."); }
					productCopySyncApply( applyCard );
					return;
				}
				productCopyCall( applyCard, payload );
			}
			return;
		}
		var copyUndo = e.target.closest( '[data-mpc-copy-undo]' );
		if ( copyUndo ) {
			e.preventDefault();
			var undoCard = copyUndo.closest( '.lucidlab-mpc-copy-card, .lucidlab-mpc-copy-recent-row' );
			if ( undoCard ) { productCopyCall( undoCard, { op: 'product_copy_undo' } ); }
			return;
		}
		var copySelected = e.target.closest( '[data-mpc-copy-suggest-selected]' );
		var copyPage = e.target.closest( '[data-mpc-copy-suggest-page]' );
		if ( copySelected || copyPage ) {
			e.preventDefault();
			var batchButton = copySelected || copyPage;
			var chosen = els( '.lucidlab-mpc-copy-card' ).filter( function ( card ) {
				var suggest = card.querySelector( 'button[data-mpc-copy-suggest]' );
				if ( ! suggest || suggest.disabled ) { return false; }
				if ( copyPage ) { return true; }
				var pick = card.querySelector( '.lucidlab-mpc-copy-pick' );
				return pick && pick.checked && ! pick.disabled;
			} );
			if ( ! chosen.length ) { alert( copyPage ? mpcText("No products with a usable image on this page.") : LucidLabMPC.i18n.nothing ); return; }
			( async function () {
				var bulk = els( '[data-mpc-copy-suggest-selected], [data-mpc-copy-suggest-page]' );
				var wasDisabled = bulk.map( function ( button ) { return button.disabled; } );
				bulk.forEach( function ( button ) { button.disabled = true; } );
				productCopySpinner( batchButton, true );
				for ( var i = 0; i < chosen.length; i++ ) {
					await productCopyCall( chosen[ i ], { op: 'product_copy_suggest' } );
				}
				productCopySpinner( batchButton, false );
				bulk.forEach( function ( button, index ) { button.disabled = wasDisabled[ index ]; } );
			} )();
			return;
		}

		var toggleBtn = e.target.closest( '[data-mpc-toggle]' );
		if ( toggleBtn ) {
			e.preventDefault();
			sharedToggle( toggleBtn );
			return;
		}
		var expandAll = e.target.closest( '[data-mpc-expand-all]' );
		if ( expandAll ) {
			e.preventDefault();
			var expandRoot = expandAll.closest( '[data-mpc-panel], .lucidlab-mpc-wrap' ) || document;
			var sharedPanels = [];
			els( '.lucidlab-mpc-shared-toggle', expandRoot ).forEach( function ( b ) {
				sharedToggle( b, true, true );
				var row = b.closest( '.lucidlab-mpc-shared-row' );
				var panel = row ? el( '.lucidlab-mpc-shared-products', row ) : null;
				if ( panel && panel.getAttribute( 'data-mpc-lazy-shared' ) && panel.getAttribute( 'data-loaded' ) !== '1' ) {
					sharedPanels.push( panel );
				}
			} );
			loadSharedPanels( sharedPanels );
			return;
		}
		var collapseAll = e.target.closest( '[data-mpc-collapse-all]' );
		if ( collapseAll ) {
			e.preventDefault();
			var collapseRoot = collapseAll.closest( '[data-mpc-panel], .lucidlab-mpc-wrap' ) || document;
			els( '.lucidlab-mpc-shared-toggle', collapseRoot ).forEach( function ( b ) { sharedToggle( b, false ); } );
			return;
		}

		var quarBtn = e.target.closest( '[data-mpc-quarantine]' );
		if ( quarBtn ) {
			e.preventDefault();
			if ( quarBtn.disabled || quarBtn.getAttribute( 'aria-busy' ) === 'true' ) { return; }
			var qtab = quarBtn.getAttribute( 'data-mpc-quarantine' );
			var zone = quarBtn.closest( '.lucidlab-mpc-quar-zone' );
			if ( 'sizes' === qtab || 'fs-sizes' === qtab ) { runDimQuar( qtab, zone ); } else { runQuarantine( qtab, zone, quarBtn ); }
			return;
		}

		var mergeBtn = e.target.closest( '[data-mpc-merge]' );
		if ( mergeBtn ) {
			e.preventDefault();
			var mform = mergeBtn.closest( '.lucidlab-mpc-dupc-form' );
			var mtoggle = mform ? el( '.lucidlab-mpc-enable', mform ) : null;
			if ( ! mtoggle || ! mtoggle.checked ) { alert( LucidLabMPC.i18n.enableFirst ); return; }
			runMergeDup( mform );
			return;
		}

		var resetBtn = e.target.closest( '[data-mpc-reset-keeps]' );
		if ( resetBtn ) {
			e.preventDefault();
			resetDupcKeeps( resetBtn.closest( '.lucidlab-mpc-dupc-form' ) );
			return;
		}

		var renameBtn = e.target.closest( '[data-mpc-rename]' );
		if ( renameBtn ) {
			e.preventDefault();
			runRename( renameBtn );
			return;
		}

		var bulkRenameBtn = e.target.closest( '[data-mpc-rename-all]' );
		if ( bulkRenameBtn ) {
			e.preventDefault();
			runRenameAll( bulkRenameBtn );
			return;
		}
	} );

	/* ---------- Shared / Missing / Visual collapsible panels ---------- */
	function sharedToggle( btn, force, skipLoad ) {
		var row = btn.closest( '.lucidlab-mpc-shared-row, .lucidlab-mpc-missing-row, .lucidlab-mpc-visual-group, .lucidlab-mpc-missing-urls-section, .lucidlab-mpc-missing-attachments-section' );
		if ( ! row ) { return; }
		// Section toggles use a dedicated panel so nested Used-by .lucidlab-mpc-shared-products stay separate.
		var panel = row.classList.contains( 'lucidlab-mpc-missing-urls-section' )
			? el( '.lucidlab-mpc-missing-urls-panel', row )
			: ( row.classList.contains( 'lucidlab-mpc-missing-attachments-section' )
				? el( '.lucidlab-mpc-missing-attachments-panel', row )
				: el( '.lucidlab-mpc-shared-products', row ) );
		if ( ! panel ) { return; }
		var open = ( typeof force === 'boolean' ) ? force : panel.hidden;
		panel.hidden = ! open;
		btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		var icon = el( '.dashicons', btn );
		if ( icon ) { icon.className = 'dashicons ' + ( open ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2' ); }
		var count = ( panel.getAttribute( 'data-count' ) ) || ( els( 'tbody tr', panel ).length ) || ( els( '.lucidlab-mpc-missing-usage', panel ).length ) || ( els( '.lucidlab-mpc-dupc-row', panel ).length );
		btn.lastChild.textContent = ' ' + ( open ? mpcText("Hide items") : ( mpcText("Show ") + count + mpcText(" items") ) );
		if ( open && ! skipLoad ) { loadLazyPanel( panel, 'shared', panel.getAttribute( 'data-mpc-lazy-shared' ), 'products', 'usage' ); }
	}

	function renderRefTable( items, badgeKey ) {
		if ( ! items || ! items.length ) {
			var empty = document.createElement( 'p' );
			empty.className = 'lucidlab-mpc-empty';
			empty.textContent = mpcText("Not referenced anywhere in scope.");
			return empty;
		}
		var table = document.createElement( 'table' );
		table.className = 'widefat lucidlab-mpc-table';
		var body = document.createElement( 'tbody' );
		items.forEach( function ( item ) {
			var tr = document.createElement( 'tr' );
			var td1 = document.createElement( 'td' );
			var strong = document.createElement( 'strong' );
			strong.textContent = item.label || item.title || '';
			td1.appendChild( strong );
			var badge = document.createElement( 'span' );
			badge.className = 'lucidlab-mpc-badge lucidlab-mpc-badge-warn';
			badge.textContent = item[ badgeKey ] || item.type || '';
			td1.appendChild( document.createTextNode( ' ' ) );
			td1.appendChild( badge );
			var td2 = document.createElement( 'td' );
			td2.className = 'lucidlab-mpc-btn-row lucidlab-mpc-right';
			[ 'view', 'edit' ].forEach( function ( kind ) {
				if ( ! item[ kind ] ) { return; }
				var a = document.createElement( 'a' );
				a.className = 'button button-small';
				a.href = item[ kind ];
				a.target = '_blank';
				a.rel = mpcText("noopener noreferrer");
				a.textContent = kind === 'view' ? mpcText("View") : mpcText("Edit");
				td2.appendChild( a );
			} );
			tr.appendChild( td1 );
			tr.appendChild( td2 );
			body.appendChild( tr );
		} );
		table.appendChild( body );
		return table;
	}

	function loadSharedPanels( panels ) {
		var need = ( panels || [] ).filter( function ( panel ) {
			return panel && panel.getAttribute( 'data-loaded' ) !== '1' && panel.getAttribute( 'data-mpc-lazy-shared' );
		} );
		if ( ! need.length ) { return; }
		need.forEach( function ( panel ) {
			panel.setAttribute( 'data-loaded', '1' );
			panel.textContent = '';
			var hint = document.createElement( 'p' );
			hint.className = 'lucidlab-mpc-hint';
			hint.textContent = mpcText( 'Loading references…' );
			panel.appendChild( hint );
		} );
		post( {
			op: 'lazy_refs',
			tab: 'shared',
			ids: need.map( function ( panel ) { return panel.getAttribute( 'data-mpc-lazy-shared' ); } ).join( ',' ),
			direct: true
		} ).then( function ( r ) {
			if ( ! r || ! r.success || ! r.data ) { throw new Error( mpcText( 'Could not load references.' ) ); }
			var lists = r.data.lists || {};
			need.forEach( function ( panel ) {
				var id = panel.getAttribute( 'data-mpc-lazy-shared' );
				panel.replaceChildren( renderRefTable( lists[ id ] || [], 'usage' ) );
			} );
		} ).catch( function () {
			need.forEach( function ( panel ) {
				panel.setAttribute( 'data-loaded', '0' );
				var hint = el( '.lucidlab-mpc-hint', panel );
				if ( hint ) { hint.textContent = mpcText( 'Could not load references.' ); }
			} );
		} );
	}

	function loadLazyPanel( panel, tab, id, listKey, badgeKey ) {
		if ( ! panel || ! id || panel.getAttribute( 'data-loaded' ) === '1' ) { return; }
		panel.setAttribute( 'data-loaded', '1' );
		var host = el( '.lucidlab-mpc-lazy-refs-body', panel ) || panel;
		host.textContent = '';
		var hint = document.createElement( 'p' );
		hint.className = 'lucidlab-mpc-hint';
		hint.textContent = mpcText("Loading references…");
		host.appendChild( hint );
		post( { op: 'lazy_refs', tab: tab, id: id, direct: true } ).then( function ( r ) {
			if(!r || !r.success || !r.data)throw new Error(mpcText("Could not load references."));
			var items = r && r.success && r.data ? ( r.data[ listKey ] || [] ) : [];
			host.replaceChildren( renderRefTable( items, badgeKey ) );
		} ).catch( function () {
			panel.setAttribute( 'data-loaded', '0' );
			hint.textContent = mpcText("Could not load references.");
		} );
	}

	document.addEventListener( 'toggle', function ( event ) {
		var details = event.target;
		if ( ! details || details.tagName !== 'DETAILS' || ! details.open ) { return; }
		var id = details.getAttribute( 'data-mpc-lazy-refs' );
		if ( ! id ) { return; }
		var tab = details.getAttribute( 'data-mpc-lazy-tab' ) || 'dupcontent';
		loadLazyPanel( details, tab, id, tab === 'shared' ? 'products' : 'refs', tab === 'shared' ? 'usage' : 'type' );
	}, true );

		var patternRenameBusy = false;
	document.addEventListener( 'click', async function ( event ) {
		var preview = event.target.closest( '#lucidlab-mpc-rename-preview' );
		var button = event.target.closest( '#lucidlab-mpc-rename-pattern-all' );
		if ( ! preview && ! button ) { return; }
		var pattern = el( '#lucidlab-mpc-rename-pattern' ).value.trim();
		var status = el( '#lucidlab-mpc-rename-pattern-status' );
		var allowed = [ '{title}', '{domain}', '{date}', '{id}', '{filename}', '{sku}', '{brand}', '{brands}', '{category}', '{categories}' ];
		if ( ! pattern || ( pattern.match( /\{[^}]+\}/g ) || [] ).some( function ( tag ) { return allowed.indexOf( tag ) < 0; } ) ) {
			status.textContent = mpcText("Enter a pattern using the supported tags."); return;
		}
		function applyPreview() {
			els( '.lucidlab-mpc-rename-row' ).forEach( function ( row ) {
				var tags = JSON.parse( row.getAttribute( 'data-tags' ) );
				var name = pattern.replace( /\{[^}]+\}/g, function ( tag ) { return tags[ tag ] || ''; } );
				el( '.lucidlab-mpc-rename-name', row ).value = name.normalize( "NFD" ).replace( /[\u0300-\u036f]/g, '' ).toLowerCase().replace( /[^a-z0-9_-]+/g, '-' ).replace( /^-+|-+$/g, '' );
			} );
		}
		if ( preview ) {
			if ( preview.disabled || preview.getAttribute( 'aria-busy' ) === 'true' ) { return; }
			buttonBusyInside( preview, true );
			requestAnimationFrame( function () {
				applyPreview();
				status.textContent = mpcText("Names below updated. No files renamed.");
				buttonBusyInside( preview, false );
			} );
			return;
		}
		if ( patternRenameBusy ) { return; }
		var search = el( '#lucidlab-mpc-rename-search' ).value;
		var signature = JSON.stringify( [ pattern, search ] );
		if ( button.getAttribute( 'data-confirm' ) !== signature ) {
			button.setAttribute( 'data-confirm', signature );
			button.textContent = mpcText("Confirm Rename All Images");
			status.textContent = mpcText("This will rename every matching image across all pages using \"") + pattern + mpcText("\". Click Confirm to start, or change the pattern/search to revise the selection.");
			return;
		}
		button.removeAttribute( 'data-confirm' );
		button.textContent = mpcText("Rename All Images");
		await renameJobCommand('start', {pattern:pattern, search:search});
	} );

/* ---------- Bulk rename (Tab 6) ---------- */

 async function runRenameAll(btn) {
  if(patternRenameBusy)return;
  var rows=els('.lucidlab-mpc-rename-row');if(!rows.length)return;
  var items=rows.map(function(row){return {id:Number(row.getAttribute('data-id')),name:el('.lucidlab-mpc-rename-name',row).value.trim()};});
  if(!await inlineConfirm(mpcText("Rename all ")+rows.length+mpcText(" images on this page using the names shown?"),btn))return;
  await renameJobCommand('start',{items:JSON.stringify(items)});
 }
 var renameJobTimer, renameJobPending=false, renameJobQueue=Promise.resolve();
 function renameJobRequest(action,input){
  var request=renameJobQueue.catch(function(){}).then(async function(){
   var response=await post(Object.assign({op:'rename_job',sub:action},input||{}));
   if(!response||!response.success)throw new Error(response&&response.data&&response.data.message||mpcText("Rename request failed. Reload to reconnect to the saved job."));
   return response.data;
  });renameJobQueue=request;return request;
 }
 function renameProgressLine(job){
  return 'Renaming: '+(job.offset||0)+'/'+(job.total||0)+mpcText(" processed. ")+(job.renamed||0)+mpcText(" renamed, ")+(job.skipped||0)+mpcText(" skipped, ")+(job.failed||0)+mpcText(" failed");
 }
 function renderRenameJob(job){
  patternRenameBusy=job.status==='running';
	var terminal=['done','failed','cancelled'].includes(job.status);els('#lucidlab-mpc-rename-pattern-all, [data-mpc-rename-all], [data-mpc-rename]').forEach(function(button){button.disabled=patternRenameBusy;});
	els('[data-rename-job]').forEach(function(button){var action=button.getAttribute('data-rename-job');button.disabled=action==='stop'?job.status!=='running':action==='cancel'?!['running','paused'].includes(job.status):action==='resume'?!['paused','cancelled'].includes(job.status):!['running','paused','cancelled'].includes(job.status)||job.driver==='cron'&&job.status==='running';});
	var line=renameProgressLine(job);
	var pct=job.total?Math.min(100,100*job.offset/job.total):(terminal?100:0);
	var renameProgress=el('#lucidlab-mpc-progress-rename');
	if(renameProgress){
		renameProgress.hidden=job.status==='idle';
		var bar=renameProgress.querySelector('.lucidlab-mpc-progress-bar');
		var label=renameProgress.querySelector('.lucidlab-mpc-progress-label');
		if(bar)bar.style.width=pct+'%';
		if(label)label.textContent=job.status==='idle'?'':line;
	}
  var status=el('#lucidlab-mpc-rename-pattern-status');
  if(status&&job.status==='idle')status.textContent='';
	if(status&&job.status!=='idle')status.textContent=(job.status==='running'||job.status==='paused')?line:((job.status==='cancelled'?mpcText("Cancelled. Completed renames were kept. "):job.status==='done'?'Completed. ':mpcText("Stopped with errors. "))+line);
	if(job.status&&job.status!=='idle'){
		var renameJob=Object.assign({},job,{key:'lucidlab_mpc_rename_job',panel:'rename',scan_tab:'rename',special:'rename',label:line,progress:pct});
		if(job.status==='running'||job.status==='paused')runningJobMessages[renameJob.key]=line;
		else delete runningJobMessages[renameJob.key];
		upsertRunningJob(renameJob);
	}
  (job.results&&job.results.length?job.results:(job.last?[job.last]:[])).forEach(function(result){var row=el('.lucidlab-mpc-rename-row[data-id="'+result.id+'"]');if(row){el('.lucidlab-mpc-rename-status',row).textContent=result.message;if(result.ok&&result.new)el('.lucidlab-mpc-rename-cur',row).textContent=result.new;}});
 }
 function scheduleRenameJob(job){clearTimeout(renameJobTimer);if(renameJobPending||job.status!=='running')return;
  renameJobTimer=setTimeout(async function(){try{var next=await renameJobRequest(job.driver==='cron'?'status':'step');renderRenameJob(next);scheduleRenameJob(next);}catch(error){showActionError(error);}},job.driver==='cron'||job.busy?2500:100);
 }
 function renameApplyFlags(){return {filename:!!el('#lucidlab-mpc-rename-filename')?.checked,title:!!el('#lucidlab-mpc-rename-title')?.checked,alt:!!el('#lucidlab-mpc-rename-alt')?.checked};}
 async function renameJobCommand(action,input){if(action==='start'){var flags=(input&&('update_filename' in input||'update_title' in input||'update_alt' in input))?{filename:!!Number(input.update_filename),title:!!Number(input.update_title),alt:!!Number(input.update_alt)}:renameApplyFlags();if(!flags.filename&&!flags.title&&!flags.alt){if(!el('#lucidlab-mpc-rename-filename')){flags={filename:true,title:false,alt:false};}else{var busy=el('#lucidlab-mpc-rename-pattern-status');if(busy)busy.textContent=mpcText("Choose filename, title and/or alt text before renaming.");return;}}input=Object.assign({},input,{update_filename:flags.filename?1:0,update_title:flags.title?1:0,update_alt:flags.alt?1:0});}if(renameJobPending)return;renameJobPending=true;clearTimeout(renameJobTimer);var busy=el('#lucidlab-mpc-rename-pattern-status');if(busy&&action!=='status')busy.innerHTML='<span class="spinner is-active" style="float:none;margin:0 6px"></span>Processing\u2026';
  if(action==='start'){pendingRunningJobs['rename-starting']={key:'rename-starting',pending:true,status:'running',panel:'rename',scan_tab:'rename',special:'rename',pattern:input&&input.pattern||'',label:mpcText("Starting rename")};ensureRunningJobOrder(pendingRunningJobs['rename-starting']);renderRunningJobs(knownRunningJobs);scrollToRunningJobs();}
  try{var job;do{job=await renameJobRequest(action,input);if(job.busy)await new Promise(function(resolve){setTimeout(resolve,500);});}while(job.busy);if(action==='start'){var pendingOrder=runningJobOrder['rename-starting'];delete pendingRunningJobs['rename-starting'];ensureRunningJobOrder({key:'lucidlab_mpc_rename_job'},pendingOrder);}renderRenameJob(job);renameJobPending=false;scheduleRenameJob(job);}catch(error){if(action==='start'){delete pendingRunningJobs['rename-starting'];renderRunningJobs(knownRunningJobs);}renameJobPending=false;showActionError(error);}
 }
 document.addEventListener('click',function(event){var button=event.target.closest('[data-rename-job]');if(!button||button.disabled)return;renameJobCommand(button.getAttribute('data-rename-job'));});
 function renameJobInit(){if(el('#lucidlab-mpc-rename-job-controls')){el('#lucidlab-mpc-rename-job-controls').classList.add('lucidlab-mpc-scan-controls');}if(LucidLabMPC.tab==='rename'||LucidLabMPC.tab==='rename_ai'){renameJobCommand('status');}}
 if(document.readyState!=='loading'){setTimeout(renameJobInit,0);}else{document.addEventListener("DOMContentLoaded",renameJobInit);}

	/* ---------- Rename (Tab 6) ---------- */
	function runRename( btn ) {
		var row = btn.closest( '.lucidlab-mpc-rename-row' );
		var input = el( '.lucidlab-mpc-rename-name', row );
		var status = el( '.lucidlab-mpc-rename-status', row );
		var name = input ? input.value.trim() : '';
		if ( ! name ) { if ( status ) { status.textContent = LucidLabMPC.i18n.nothing; } return; }
		btn.disabled = true;
		if ( status ) { status.style.color = ''; status.innerHTML = '<span class="spinner is-active" style="float:none;margin:0 6px"></span>Renaming\u2026'; }
		var flags=renameApplyFlags();
		if(!flags.filename&&!flags.title&&!flags.alt){if(status)status.textContent=mpcText("Choose filename, title and/or alt text.");btn.disabled=false;return;}
		post( { op: 'rename', update_filename:flags.filename?1:0, update_title:flags.title?1:0, update_alt:flags.alt?1:0, id: btn.getAttribute( 'data-id' ), name: name } ).then( function ( res ) {
			btn.disabled = false;
			if ( ! res || ! res.success ) { if ( status ) { status.textContent = LucidLabMPC.i18n.error; } return; }
			var d = res.data;
			if ( status ) {
				status.textContent = d.message;
				status.style.color = d.ok ? '#1a7f37' : '#a02020';
			}
			if ( d.ok && d.new ) {
				var cur = el( '.lucidlab-mpc-rename-cur', row );
				if ( cur ) { cur.textContent = d.new; }
			}
		} ).catch( function () { btn.disabled = false; if ( status ) { status.textContent = LucidLabMPC.i18n.error; } } );
	}

	/* ---------- WebP / AVIF stats (Tab 8) ---------- */
	function webpStatsInit() {
		var btn = el( '#lucidlab-mpc-webpstats-scan' );
		if ( ! btn ) { return; }
		btn.addEventListener( 'click', function () {
			// Durable scan task drives progress and applies stats when status is done — do not zero the tiles on reset.
			buttonBusy( btn, true );
			persistentTask( { op: 'scan', tab: 'webpstats', panel: 'webp', progress_id: 'webpstats', reset: 1 } ).catch( showActionError ).finally( function () { buttonBusy( btn, false ); } );
		} );
	}
	if ( document.readyState !== 'loading' ) { setTimeout( webpStatsInit, 0 ); } else { document.addEventListener( "DOMContentLoaded", webpStatsInit ); }

	function webpFormatSettings( background ) {
		var box = el( '#lucidlab-mpc-webp-settings' ) || el( '#lucidlab-mpc-webp' );
		var data = {
			background: !! background,
			replace_originals: !! ( el( '#lucidlab-mpc-webp-replace' ) && el( '#lucidlab-mpc-webp-replace' ).checked ),
			skip_existing: ! el( '#lucidlab-mpc-webp-skip-existing' ) || !! el( '#lucidlab-mpc-webp-skip-existing' ).checked
		};
		if ( ! box ) { return data; }
		els( '[data-format]', box ).forEach( function ( field ) {
			var fmt = field.getAttribute( 'data-format' );
			var check = field.querySelector( '.lucidlab-mpc-webp-fmt' );
			var mode = field.querySelector( 'select.lucidlab-mpc-format-mode' );
			var images = field.querySelector( '.lucidlab-mpc-quality-images' );
			var thumbs = field.querySelector( '.lucidlab-mpc-quality-thumbnails' );
			var imageFallback = qualityNumber( images && { value: images.defaultValue }, fmt === 'avif' ? 60 : 80 );
			var thumbFallback = qualityNumber( thumbs && { value: thumbs.defaultValue }, fmt === 'avif' ? 50 : 70 );
			data[ fmt ] = {
				enabled: ! field.disabled && check && check.checked,
				mode: mode ? mode.value : 'all',
				images: qualityNumber( images, imageFallback ),
				thumbnails: qualityNumber( thumbs, thumbFallback )
			};
		} );
		return data;
	}
	function qualityNumber( input, fallback ) {
		var n = parseInt( input && input.value, 10 );
		return n >= 1 && n <= 100 ? n : fallback;
	}
	function previewProgressText( payload ) {
		var total = payload && payload.total ? payload.total : 10;
		var processed = Math.min( total, Number( payload && payload.index ) || 0 );
		var counts = ( payload && payload.counts ) || {};
		var formats = Array.isArray( payload && payload.formats ) && payload.formats.length ? payload.formats : [ 'webp', 'avif' ];
		return processed + ' / ' + total + mpcText(' images processed') + ' · ' + mpcText("Converted ") + formats.map( function ( fmt ) {
			return fmt.toUpperCase() + ': ' + ( counts[ fmt ] || 0 ) + ' / ' + total;
		} ).join( '   ' );
	}
	function setPreviewProgress( text ) {
		var progress = el( '#lucidlab-mpc-webp-preview-progress' );
		if ( ! progress ) { return; }
		progress.hidden = ! text;
		progress.textContent = text || '';
	}
	function decodePreviewTitle( text ) {
		var value = String( text || '' );
		if ( value.indexOf( '&' ) === -1 ) { return value; }
		var node = document.createElement( 'textarea' );
		node.innerHTML = value;
		return node.value;
	}
	function renderWebpPreview( payload ) {
		payload = payload && payload.rows ? payload : ( payload && payload.data && payload.data.rows ? payload.data : payload || {} );
		var rows = Array.isArray( payload.rows ) ? payload.rows : [];
		var wrap = el( '.lucidlab-mpc-webp-compare-wrap' );
		var table = wrap && wrap.querySelector( 'tbody' );
		var status = el( '#lucidlab-mpc-webp-preview-status' );
		if ( ! table ) { return; }
		table.replaceChildren();
		rows.forEach( function ( row ) {
			var tr = document.createElement( 'tr' );
			function cell( item, fallback ) {
				var td = document.createElement( 'td' );
				item = item || {};
				if ( ! item.url ) {
					td.className = '';
					td.innerHTML = '';
					var hint = document.createElement( 'span' );
					hint.className = 'lucidlab-mpc-hint';
					hint.textContent = item.error || fallback;
					td.appendChild( hint );
					return td;
				}
				td.className = 'lucidlab-mpc-webp-compare-cell';
				var zoom = document.createElement( 'div' );
				zoom.className = 'lucidlab-mpc-webp-zoom';
				var img = document.createElement( 'img' );
				img.className = 'lucidlab-mpc-lb';
				img.src = item.thumb || item.url;
				img.width = 140;
				img.height = 140;
				img.setAttribute( 'data-full', item.url );
				img.setAttribute( 'data-caption', item.caption || fallback );
				img.alt = '';
				zoom.appendChild( img );
				var meta = document.createElement( 'div' );
				meta.className = 'lucidlab-mpc-webp-compare-meta';
				var strong = document.createElement( 'strong' );
				strong.textContent = item.label || '';
				meta.appendChild( strong );
				if ( typeof item.percent === 'number' && item.percent !== 0 ) {
					var saved = document.createElement( 'span' );
					saved.className = 'lucidlab-mpc-hint';
					saved.textContent = item.percent > 0 ? item.percent + '% smaller' : Math.abs( item.percent ) + '% larger';
					meta.appendChild( saved );
				}
				td.append( zoom, meta );
				return td;
			}
			var name = document.createElement( 'td' );
			name.textContent = decodePreviewTitle( row.title || ( '#' + ( row.id || '' ) ) );
			tr.append( name, cell( row.original, mpcText("Original") ), cell( row.webp, mpcText("Not generated") ), cell( row.avif, mpcText("Not generated") ) );
			table.appendChild( tr );
		} );
		if ( wrap ) { wrap.hidden = ! rows.length; }
		if ( status && ( payload.done || typeof payload.counts === 'undefined' ) ) {
			status.textContent = rows.length ? rows.length + mpcText(" images compared. Thumbnails are a 1:1 crop so compression is visible. Click a picture, then use the arrows to switch Original / WebP / AVIF.") : '';
		}
	}
	function webpPreviewInit() {
		var box = el( '#lucidlab-mpc-webp-preview-box' );
		if ( ! box || box.hasAttribute( 'data-initialized' ) ) { return; }
		box.setAttribute( 'data-initialized', '1' );
		var btn = el( '#lucidlab-mpc-webp-preview', box );
		var clear = el( '#lucidlab-mpc-webp-preview-clear', box );
		if ( btn ) {
			btn.addEventListener( 'click', function () {
				buttonBusy( btn, true );
				if ( clear ) { clear.disabled = true; }
				var status = el( '#lucidlab-mpc-webp-preview-status' );
				if ( status ) { status.textContent = ''; }
				renderWebpPreview( { rows: [] } );
				setPreviewProgress( mpcText("Converted WebP: 0 / 10   AVIF: 0 / 10") );
				var previewSettings = webpFormatSettings( false );
				// Free previews AVIF at defaults, without enabling library AVIF conversion.
				if ( ! LucidLabMPC.pro ) { previewSettings.avif = { enabled: true, mode: 'all', images: 0, thumbnails: 0 }; }
				var settings = JSON.stringify( previewSettings );
				post( { op: 'webp_preview', sub: 'start', settings: settings } ).then( function ( res ) {
					var data = ( res && res.data ) || {};
					setPreviewProgress( previewProgressText( data ) );
					function next() {
						return post( { op: 'webp_preview', sub: 'step' } ).then( function ( stepRes ) {
							var step = ( stepRes && stepRes.data ) || {};
							setPreviewProgress( previewProgressText( step ) );
							renderWebpPreview( step );
							if ( step.done ) { return post( { op: 'webp_preview', sub: 'status' } ).then( function ( savedRes ) { var saved = ( savedRes && savedRes.data ) || {}; renderWebpPreview( saved.done ? saved : step ); setPreviewProgress( previewProgressText( saved.done ? saved : step ) ); } ); }
							return next();
						} );
					}
					return data.done ? post( { op: 'webp_preview', sub: 'status' } ).then( function ( savedRes ) { renderWebpPreview( ( savedRes && savedRes.data ) || data ); } ) : next();
				} ).catch( function ( error ) {
					if ( status ) { status.textContent = error.message; }
					setPreviewProgress( '' );
					showActionError( error );
				} ).finally( function () {
					buttonBusy( btn, false );
					if ( clear ) { clear.disabled = false; }
				} );
			} );
		}
		if ( clear ) {
			clear.addEventListener( 'click', function () {
				buttonBusy( clear, true );
				if ( btn ) { btn.disabled = true; }
				var status = el( '#lucidlab-mpc-webp-preview-status' );
				if ( status ) { status.textContent = mpcText("Removing test images…"); }
				post( { op: 'webp_preview_clear' } ).then( function () {
					renderWebpPreview( { rows: [] } );
					setPreviewProgress( '' );
					if ( status ) { status.textContent = mpcText("Test images removed. Change the quality settings above and generate again to compare."); }
				} ).catch( function ( error ) {
					if ( status ) { status.textContent = error.message; }
					showActionError( error );
				} ).finally( function () {
					buttonBusy( clear, false );
					if ( btn ) { btn.disabled = false; }
				} );
			} );
		}
	}

	/* ---------- WebP / AVIF (Tab 8) ---------- */

 function webpInit() {
  webpPreviewInit();
  var box=el('#lucidlab-mpc-webp');if(!box||!el('#lucidlab-mpc-webp-start'))return;
  function removeWebpJobControls() {
   els('button',box).forEach(function(button) {
    var label=button.textContent.trim().toLowerCase();
    if (button.id==='lucidlab-mpc-webp-background' || button.id==='lucidlab-mpc-webp-cancel' || button.id==='lucidlab-mpc-webp-stop' || label==='run in background (cronjob)' || label==='continue in background' || label==='stop' || label==='cancel') {
     button.remove();
    }
   });
  }
  removeWebpJobControls();
  if (window.MutationObserver) { new MutationObserver(removeWebpJobControls).observe(box,{childList:true,subtree:true}); }
	var timer,control=false,queue=Promise.resolve(),lastStatsRefreshJob=0;
	var start=el('#lucidlab-mpc-webp-start'),stat=el('#lucidlab-mpc-webp-stat');
  var webpProgress=el('#lucidlab-mpc-progress-webp');if(webpProgress)webpProgress.hidden=true;
  var retry=document.createElement('button');retry.type='button';retry.className='button';retry.textContent=mpcText("Retry failed");retry.hidden=true;
  if(start&&start.parentElement)start.parentElement.append(' ',retry);
  function convertMessage(d){
    if(d.label)return d.label;
    return webpConvertFallback(d);
  }
  function renderWebpRunningJob(d){
    if(!['running','paused'].includes(d.status)){
      delete runningJobMessages['lucidlab_mpc_webp_job'];
      if(d.driver!=='run_all'&&!hasActiveRunAll())renderRunningJobs(knownRunningJobs.filter(function(known){return known.key!=='lucidlab_mpc_webp_job';}).concat([Object.assign({},d,{key:'lucidlab_mpc_webp_job',panel:'webp',scan_tab:'webp',special:'webp'})]));
      return;
    }
    var item=Object.assign({},d,{key:'lucidlab_mpc_webp_job',panel:'webp',scan_tab:'webp',special:'webp',progress:d.progress===0||d.progress?Number(d.progress):(d.total?Math.min(100,100*(d.offset||0)/d.total):0)});
    runningJobMessages[item.key]=convertMessage(d);
    if(item.driver==='run_all'||hasActiveRunAll())return;
		renderRunningJobs(knownRunningJobs.filter(function(known){return known.key!==item.key;}).concat([item]));
  }
	function refreshWebpStatsAfterRun(d){
		if(!['done','failed','cancelled'].includes(d.status))return;
		var changed=Math.max(Number(d.made||0),Number(d.made_webp||0)+Number(d.made_avif||0))+Number(d.originals||0);
		var finished=Number(d.updated||0),overview=el('#lucidlab-mpc-webpstats'),button=el('#lucidlab-mpc-webpstats-scan');
		if(!changed||!finished||!overview||!button||button.disabled||finished<=Number(overview.dataset.mpcStatsUpdated||0)||finished===lastStatsRefreshJob)return;
		lastStatsRefreshJob=finished;
		setTimeout(function(){button.click();},0);
	}
  function request(sub,settings){var r=queue.catch(function(){}).then(async function(){var response=await post({op:'convert',tab:'webp',sub:sub,settings:JSON.stringify(settings||{})});if(!response||!response.success)throw Error(response&&response.data&&response.data.message||mpcText("Conversion request failed"));return response.data;});queue=r;return r;}
	 function render(d){var running=d.status==='running',terminal=['done','failed','cancelled'].includes(d.status);if(start&&terminal)buttonBusyInside(start,false);if(start)start.disabled=running||d.driver==='run_all'&&!terminal;retry.hidden=!(d.status==='done'&&Number(d.failed)>0);retry.disabled=running;if(d.driver==='run_all'&&!terminal)[start,retry].forEach(function(b){if(b)b.disabled=true;});
   els('fieldset',box).forEach(function(field){if(field.querySelector('.lucidlab-mpc-webp-fmt').disabled)return;field.disabled=running||field.getAttribute('data-unavailable')==='1';});
   var replaceOriginals=el('#lucidlab-mpc-webp-replace');if(replaceOriginals)replaceOriginals.disabled=running||d.driver==='run_all';
   var skipExisting=el('#lucidlab-mpc-webp-skip-existing');if(skipExisting)skipExisting.disabled=running||d.driver==='run_all';
   if(webpProgress)webpProgress.hidden=true;
	   renderWebpRunningJob(d);
	   refreshWebpStatsAfterRun(d);
   if(!stat)return;
   if(d.status==='idle'){stat.hidden=true;stat.textContent='';return;}
	var terminalDate=d.updated_label||((formatJobDate(d.updated)||mpcText("date unavailable (older job)")));
    stat.classList.toggle('lucidlab-mpc-job-summary',terminal);if(terminal && !(d.status==='done'&&Number(d.failed)>0))retry.hidden=true;
	if(d.status==='done'&&Number(d.failed)>0){retry.hidden=false;retry.disabled=false;}
	stat.style.margin='';
	stat.style.fontFamily='';
	if(terminal){
		stat.hidden=false;
		stat.dataset.mpcJobSummary='webp-convert';
		var madeCopies=Math.max(Number(d.made||0),Number(d.made_webp||0)+Number(d.made_avif||0));
		var detail=(d.label&&Number(d.total||0)<1&&madeCopies<1)?d.label:(madeCopies+mpcText(" copies generated, ")+(d.skipped||0)+mpcText(" skipped, ")+(d.failed||0)+mpcText(" failed."));
		var summaryText=(d.status==='cancelled'?mpcText("Cancelled on: "):d.status==='done'?mpcText("Completed on: "):mpcText("Failed on: "))+terminalDate+' \u2014 '+detail;
		if(stat.textContent.trim()!==summaryText || (d.errors||[]).length){
			stat.textContent=summaryText;
			(d.errors||[]).forEach(function(error){var line=document.createElement('div');if(error.thumb){var img=document.createElement('img');img.src=error.thumb;img.className='lucidlab-mpc-thumb';line.appendChild(img);}var link=document.createElement('a');link.textContent='#'+error.id+' '+error.file;if(error.edit)link.href=error.edit;line.appendChild(link);line.appendChild(document.createTextNode(' ('+error.format+'): '+error.message));stat.appendChild(line);});
		}
		placeStatusAtPanelTop(stat.closest('[data-mpc-panel]')||el('[data-mpc-panel="webp"]'),stat);
	}else{stat.hidden=true;stat.textContent='';delete stat.dataset.mpcJobSummary;}
  }
	function schedule(d){clearTimeout(timer);if(control||d.status!=='running')return;var owned=d.driver==='run_all'||hasActiveRunAll();timer=setTimeout(async function(){try{var next=await request(owned||d.driver!=='ajax'?'status':'step');setRunningJobMessage('lucidlab_mpc_webp_job','');render(next);schedule(next);}catch(e){var message=mpcText("Connection interrupted: ")+e.message+mpcText(" Checking saved progress…");setRunningJobMessage('lucidlab_mpc_webp_job',message);if(stat){stat.hidden=false;stat.textContent=e.message;}timer=setTimeout(function(){schedule(d);},3000);}},owned||d.driver!=='ajax'||d.busy?2500:100);}
  function settings(background){return webpFormatSettings(background);}
  var queuedStart=null;
	async function command(action,input){
		if(control){
			if(action==='start'){
				queuedStart=input||{};
				if(start)buttonBusyInside(start,true);
				pendingRunningJobs['webp-starting']={key:'webp-starting',pending:true,status:'running',panel:'webp',scan_tab:'webp',special:'webp',label:mpcText("Starting WebP / AVIF conversion")};
				ensureRunningJobOrder(pendingRunningJobs['webp-starting']);
				renderRunningJobs(knownRunningJobs.filter(function(job){return job.key!=='lucidlab_mpc_webp_job';}));
				if(stat){stat.hidden=true;stat.textContent='';}
			}
			return;
		}
		control=true;
		clearTimeout(timer);
		if(action==='start'){
			if(start)buttonBusyInside(start,true);
			pendingRunningJobs['webp-starting']={key:'webp-starting',pending:true,status:'running',panel:'webp',scan_tab:'webp',special:'webp',label:mpcText("Starting WebP / AVIF conversion")};
			ensureRunningJobOrder(pendingRunningJobs['webp-starting']);
			renderRunningJobs(knownRunningJobs.filter(function(job){return job.key!=='lucidlab_mpc_webp_job';}));
			scrollToRunningJobs();
		}
		if(stat&&action!=='status'){stat.hidden=true;stat.textContent='';}
		try{
			var d;
			do{d=await request(action,input);if(d.busy)await new Promise(function(resolve){setTimeout(resolve,500);});}while(d.busy);
			if(action==='status'&&queuedStart){
				var queued=queuedStart;queuedStart=null;control=false;command('start',queued);return;
			}
			if(action==='start'){
				var pendingOrder=runningJobOrder['webp-starting'];
				delete pendingRunningJobs['webp-starting'];
				var webpJob=Object.assign({},d,{key:'lucidlab_mpc_webp_job',panel:'webp',scan_tab:'webp',special:'webp'});
				ensureRunningJobOrder(webpJob,pendingOrder);
				setRunningJobMessage('lucidlab_mpc_webp_job','');
				renderRunningJobs(knownRunningJobs.filter(function(job){return job.key!=='lucidlab_mpc_webp_job';}).concat([webpJob]));
			}
			render(d);
			control=false;
			schedule(d);
			if(start)buttonBusyInside(start,d.status==='running');
		}catch(e){
			if(action==='start'){delete pendingRunningJobs['webp-starting'];renderRunningJobs(knownRunningJobs);}
			control=false;
			if(start)buttonBusyInside(start,false);
			if(stat){stat.hidden=false;stat.textContent=e.message;}
			setRunningJobMessage('lucidlab_mpc_webp_job',mpcText("Connection interrupted: ")+e.message+mpcText(" Checking saved progress…"));
			showActionError(e);
		}
	}
  els('fieldset',box).forEach(function(field){if(field.disabled)field.setAttribute('data-unavailable','1');});
  if(start)start.addEventListener('click',function(){command('start',settings(false));});
  retry.addEventListener('click',function(){command('retry',settings(false));});
  function persistWebpSettings(){queuePageSettings('webp',webpFormatSettings(false));}
  box.addEventListener('change',persistWebpSettings);
  mpcPaintWebp=render;
  command('status');
 }
 if(document.readyState!=='loading'){setTimeout(webpInit,0);}else{document.addEventListener("DOMContentLoaded",webpInit);}


 function webpCleanInit(){
  var scan=el('#lucidlab-mpc-webpclean-scan');if(!scan||scan.hasAttribute('data-initialized'))return;scan.setAttribute('data-initialized','1');
  var sections=els('[data-clean-format]');
	initialJobs.forEach(function(job){if(isFindCopiesJob(job)&&job.status==='done'&&job.result)applyWebpClean(job.result);});
	scan.addEventListener('click',function(){buttonBusyInside(scan,true);persistentTask({op:'webpscan',progress_id:'webpscan',reset:1}).then(function(r){applyWebpClean((r&&r.data)||{});}).catch(function(e){sections.forEach(function(section){var status=el('.lucidlab-mpc-clean-status',section);if(status)status.textContent=e.message;});showActionError(e);}).finally(function(){buttonBusyInside(scan,false);});});
  sections.forEach(function(section){
	var button=el('.lucidlab-mpc-clean-run',section),status=el('.lucidlab-mpc-clean-status',section),format=section.getAttribute('data-clean-format');
	if(!button)return;
	var cleanBar=el('#lucidlab-mpc-progress-clean-'+format);if(cleanBar)cleanBar.hidden=true;
	button.addEventListener('click',async function(){
		if(!await inlineConfirm(mpcText("Move the scanned ")+format.toUpperCase()+mpcText(" copies into restorable quarantine? A copy stays in place when it is the Media Library file and neither the original nor the other format remains."),button))return;
		button.disabled=true;scan.disabled=true;
		if(status)status.textContent=mpcText("Moving copies… Progress is in Current Running Jobs.");
		try{
			var r=await persistentTask({op:'webpclean',format:format,progress_id:'webpclean'});
			var d=(r&&r.data)||{};
			var kept=Number(d.kept||0);
			var cleared={};
			cleared[format]=d[format]||{count:0,bytes:formatPreviewBytes(0),samples:[]};
			if(!d.fail && !kept){cleared.stats={};cleared.stats[format+'_count']=0;cleared.stats[format+'_bytes']=formatPreviewBytes(0);}
			applyWebpClean(cleared);
			if(button)button.disabled=!(cleared[format]&&Number(cleared[format].count));
			if(status){
				status.textContent=(d.ok||0)+mpcText(" moved")+(kept?', '+kept+mpcText(" kept because they are the only library file"):'')+', '+(d.fail||0)+mpcText(" failed. ")+((d.errors||[]).join(' | '));
				status.classList.remove('lucidlab-mpc-clean-empty');
				if(d.ok&&d.stamp){
					var link=document.createElement('a');
					link.href=LucidLabMPC.tabUrls.quarantine+'&lucidlab_mpc_load=1&lucidlab_mpc_view_set='+encodeURIComponent(d.stamp);
					link.textContent=mpcText(" View quarantine set");
					status.appendChild(link);
				}
			}
		}catch(e){if(status)status.textContent=e.message;button.disabled=false;}
		finally{scan.disabled=false;}
	});
  });
 }
 if(document.readyState!=='loading'){setTimeout(webpCleanInit,0);}else{document.addEventListener("DOMContentLoaded",webpCleanInit);}

 function webpPruneInit(){
  var check=el('#lucidlab-mpc-webpprune-check'),run=el('#lucidlab-mpc-webpprune-run'),status=el('#lucidlab-mpc-webpprune-status');
  if(!check||!run||check.hasAttribute('data-initialized'))return;check.setAttribute('data-initialized','1');
  async function pass(sub,button){
	check.disabled=true;run.disabled=true;buttonBusy(button,true);
	var d={};
	try{
		var first=true;
		do{
			var r=await post({op:'webpprune',sub:sub,reset:first?1:0,direct:1});
			if(!r||!r.success)throw new Error((r&&r.data&&r.data.message)||mpcText("The check failed."));
			d=r.data||{};first=false;
			if(status)status.textContent=d.label||'';
		}while(!d.done);
	}catch(e){if(status)status.textContent=e.message;d={};}
	finally{buttonBusy(button,false);check.disabled=false;}
	return d;
  }
  check.addEventListener('click',async function(){var d=await pass('check',check);run.disabled=!(Number(d.webp||0)+Number(d.avif||0));});
  run.addEventListener('click',async function(){
	if(!await inlineConfirm(mpcText("Remove the WebP and AVIF copies that are not smaller than the file visitors would get without them?"),run))return;
	await pass('remove',run);run.disabled=true;
  });
 }
 if(document.readyState!=='loading'){setTimeout(webpPruneInit,0);}else{document.addEventListener("DOMContentLoaded",webpPruneInit);}

	function exclusionsInit() {
		var wrap = el( '[data-mpc-exclude-folders]' );
		if ( wrap && ! wrap.hasAttribute( 'data-initialized' ) ) {
			wrap.setAttribute( 'data-initialized', '1' );
			var textarea = el( '#lucidlab-mpc-exclude-folders', wrap );
			var chips = el( '[data-mpc-exclude-chips]', wrap );
			var add = el( '[data-mpc-exclude-add]', wrap );
			function list() {
				return ( textarea.value || '' ).split( /[\r\n,]+/ ).map( function ( item ) { return item.trim(); } ).filter( Boolean );
			}
			function set( items ) {
				var unique = [];
				items.forEach( function ( item ) {
					item = String( item || '' ).replace( /\\/g, '/' ).replace( /^\/+|\/+$/g, '' );
					if ( ! item || item.indexOf( '..' ) !== -1 ) { return; }
					var key = item.toLowerCase();
					if ( unique.some( function ( existing ) { return existing.toLowerCase() === key; } ) ) { return; }
					unique.push( item );
				} );
				textarea.value = unique.join( '\n' );
				render( unique );
				markPicker( unique );
			}
			function render( items ) {
				chips.replaceChildren();
				items.forEach( function ( folder ) {
					var chip = document.createElement( 'span' );
					chip.className = 'lucidlab-mpc-chip';
					chip.appendChild( document.createTextNode( folder ) );
					var remove = document.createElement( 'button' );
					remove.type = 'button';
					remove.className = 'lucidlab-mpc-chip-x';
					remove.setAttribute( 'aria-label', mpcText("Remove ") + folder );
					remove.textContent = '\u00D7';
					remove.addEventListener( 'click', function () {
						set( list().filter( function ( item ) { return item.toLowerCase() !== folder.toLowerCase(); } ) );
					} );
					chip.appendChild( remove );
					chips.appendChild( chip );
				} );
			}
			function markPicker( items ) {
				var lower = items.map( function ( item ) { return item.toLowerCase(); } );
				els( '[data-mpc-exclude-folder]', wrap ).forEach( function ( button ) {
					button.classList.toggle( 'lucidlab-mpc-is-selected', lower.indexOf( ( button.getAttribute( 'data-mpc-exclude-folder' ) || '' ).toLowerCase() ) !== -1 );
				} );
			}
			function renderPicker( folders ) {
				var picker = el( '[data-mpc-exclude-picker]', wrap );
				var label = el( '[data-mpc-exclude-picker-label]', wrap );
				if ( ! picker ) { return; }
				picker.replaceChildren();
				( folders || [] ).forEach( function ( folder ) {
					var button = document.createElement( 'button' );
					button.type = 'button';
					button.className = 'button button-small';
					button.setAttribute( 'data-mpc-exclude-folder', folder );
					button.textContent = folder;
					picker.appendChild( button );
				} );
				picker.hidden = ! folders.length;
				if ( label ) { label.hidden = ! folders.length; }
				markPicker( list() );
			}
			var showFolders = el( '[data-mpc-exclude-show-folders]', wrap );
			if ( showFolders ) {
				showFolders.addEventListener( 'click', function () {
					if ( showFolders.disabled ) { return; }
					var status = el( '[data-mpc-exclude-folders-status]', wrap );
					var loaded = false;
					buttonBusyInside( showFolders, true );
					if ( status ) { status.textContent = mpcText("Loading folders…"); }
					post( { op: 'list_folders', tab: 'options' } ).then( function ( res ) {
						var folders = ( res && res.data && res.data.folders ) || [];
						renderPicker( folders );
						loaded = true;
						if ( status ) { status.textContent = folders.length ? folders.length + mpcText(" folders. Click a folder to skip it.") : mpcText("No folders were found in uploads yet."); }
					} ).catch( function ( error ) {
						if ( status ) { status.textContent = error.message; }
						showActionError( error );
					} ).finally( function () {
						buttonBusyInside( showFolders, false );
						if ( loaded ) { showFolders.textContent = mpcText("Refresh folders"); }
					} );
				} );
			}
			wrap.addEventListener( 'click', function ( event ) {
				var button = event.target.closest( '[data-mpc-exclude-folder]' );
				if ( ! button ) { return; }
				var name = button.getAttribute( 'data-mpc-exclude-folder' ) || '';
				var current = list();
				if ( current.some( function ( item ) { return item.toLowerCase() === name.toLowerCase(); } ) ) {
					set( current.filter( function ( item ) { return item.toLowerCase() !== name.toLowerCase(); } ) );
				} else {
					set( current.concat( [ name ] ) );
				}
			} );
			if ( add ) {
				add.addEventListener( 'keydown', function ( event ) {
					if ( event.key !== 'Enter' ) { return; }
					event.preventDefault();
					var name = add.value.trim();
					if ( name ) { set( list().concat( [ name ] ) ); add.value = ''; }
				} );
			}
			set( list() );
		}
		var mimeInput = el( '#lucidlab-mpc-exclude-mimes' );
		els( '[data-mpc-exclude-mime]' ).forEach( function ( button ) {
			if ( button.hasAttribute( 'data-initialized' ) ) { return; }
			button.setAttribute( 'data-initialized', '1' );
			button.addEventListener( 'click', function () {
				if ( ! mimeInput ) { return; }
				var mime = button.getAttribute( 'data-mpc-exclude-mime' ) || '';
				var parts = mimeInput.value.split( /[\s,;]+/ ).map( function ( item ) { return item.trim(); } ).filter( Boolean );
				if ( mime && ! parts.some( function ( item ) { return item.toLowerCase() === mime.toLowerCase(); } ) ) {
					parts.push( mime );
					mimeInput.value = parts.join( ', ' );
				}
			} );
		} );
	}
	function reportEmailsInit() {
		var wrap = el( '[data-mpc-report-emails]' );
		if ( ! wrap || wrap.hasAttribute( 'data-initialized' ) ) { return; }
		wrap.setAttribute( 'data-initialized', '1' );
		var hidden = el( '#lucidlab-mpc-report-recipient', wrap );
		var chips = el( '[data-mpc-report-email-chips]', wrap );
		var add = el( '[data-mpc-report-email-add]', wrap );
		if ( ! hidden || ! chips || ! add ) { return; }
		function list() {
			return ( hidden.value || '' ).split( /[\s,;]+/ ).map( function ( item ) { return item.trim(); } ).filter( Boolean );
		}
		function valid( email ) {
			return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email );
		}
		function set( items ) {
			var unique = [];
			items.forEach( function ( item ) {
				item = String( item || '' ).trim().toLowerCase();
				if ( ! valid( item ) ) { return; }
				if ( unique.indexOf( item ) === -1 ) { unique.push( item ); }
			} );
			hidden.value = unique.join( ', ' );
			render( unique );
		}
		function render( items ) {
			chips.replaceChildren();
			items.forEach( function ( email ) {
				var chip = document.createElement( 'span' );
				chip.className = 'lucidlab-mpc-chip';
				chip.appendChild( document.createTextNode( email ) );
				var remove = document.createElement( 'button' );
				remove.type = 'button';
				remove.className = 'lucidlab-mpc-chip-x';
				remove.setAttribute( 'aria-label', mpcText("Remove ") + email );
				remove.textContent = '\u00D7';
				remove.addEventListener( 'click', function () {
					set( list().filter( function ( item ) { return item.toLowerCase() !== email.toLowerCase(); } ) );
				} );
				chip.appendChild( remove );
				chips.appendChild( chip );
			} );
		}
		function commit() {
			var value = add.value.trim();
			if ( ! value ) { return; }
			if ( ! valid( value ) ) { return; }
			set( list().concat( [ value ] ) );
			add.value = '';
		}
		add.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' || event.key === ',' || event.key === ';' ) {
				event.preventDefault();
				commit();
			}
		} );
		add.addEventListener( 'blur', commit );
		set( list() );
	}
	if ( document.readyState !== 'loading' ) { setTimeout( exclusionsInit, 0 ); setTimeout( reportEmailsInit, 0 ); setTimeout( coreCheckInit, 0 ); } else { document.addEventListener( "DOMContentLoaded", function () { exclusionsInit(); reportEmailsInit(); coreCheckInit(); } ); }

	function coreCheckInit() {
		var box = el( '[data-mpc-core-check]' );
		if ( ! box || box.hasAttribute( 'data-initialized' ) ) { return; }
		box.setAttribute( 'data-initialized', '1' );
		var runBtn = el( '[data-mpc-core-check-run]', box );
		var status = el( '[data-mpc-core-check-status]', box );
		var results = el( '[data-mpc-core-check-results]', box );
		var dialog = el( '[data-mpc-core-check-dialog]' );
		var dialogTitle = el( '[data-mpc-core-check-dialog-title]', dialog || document );
		var dialogBody = el( '[data-mpc-core-check-dialog-body]', dialog || document );
		var dialogClose = el( '[data-mpc-core-check-dialog-close]', dialog || document );
		var dotsTimer = null;
		var kindLabels = {
			extra: mpcText( 'Extra file' ),
			changed: mpcText( 'Changed file' ),
			size: mpcText( 'Different size' )
		};
		function formatBytes( n ) {
			return formatPreviewBytes( n );
		}
		function escapeHtml( s ) {
			return String( s || '' ).replace( /[&<>"']/g, function ( ch ) {
				return ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' } )[ ch ];
			} );
		}
		function stopCheckingDots() {
			if ( dotsTimer ) { clearInterval( dotsTimer ); dotsTimer = null; }
		}
		function startCheckingDots() {
			stopCheckingDots();
			if ( ! status ) { return; }
			var base = mpcText( 'Checking WordPress core files' );
			var step = 0;
			status.textContent = base + '.';
			dotsTimer = setInterval( function () {
				step = ( step % 3 ) + 1;
				status.textContent = base + new Array( step + 1 ).join( '.' );
			}, 450 );
		}
		function selectedPaths() {
			return els( '[data-mpc-core-select]:checked', results || box ).map( function ( input ) {
				return input.value;
			} ).filter( Boolean );
		}
		function syncSelectAll() {
			var master = el( '[data-mpc-core-select-all]', results || box );
			if ( ! master ) { return; }
			var boxes = els( '[data-mpc-core-select]', results || box );
			var checked = boxes.filter( function ( input ) { return input.checked; } ).length;
			master.checked = boxes.length > 0 && checked === boxes.length;
			master.indeterminate = checked > 0 && checked < boxes.length;
		}
		function renderStatus( data ) {
			if ( ! status ) { return; }
			data = data || {};
			var counts = data.counts || {};
			var checked = Number( data.checked_at ) || 0;
			var checkedLine = checked ? '<p class="lucidlab-mpc-hint lucidlab-mpc-core-check-date">' + escapeHtml( mpcText( 'Last checked:' ) ) + ' ' + escapeHtml( new Date( checked * 1000 ).toLocaleString() ) + '</p>' : '';
			if ( data.ok ) {
				status.innerHTML = '<p class="lucidlab-mpc-core-check-ok"><span class="dashicons dashicons-yes-alt"></span> ' + escapeHtml( data.message || mpcText( 'Original WordPress files.' ) ) + '</p>' + checkedLine;
				return;
			}
			var versionLine = data.headline || ( data.version ? ( mpcText( 'WordPress' ) + ' ' + data.version ) : '' );
			var attention = data.attention || '';
			if ( ! attention && counts.total ) {
				attention = counts.total + ' ' + mpcText( 'file(s) need attention.' );
			}
			var summary = mpcText( 'Extra' ) + ': ' + ( counts.extra || 0 ) + ' · ' + mpcText( 'Changed' ) + ': ' + ( counts.changed || 0 ) + ' · ' + mpcText( 'Different sizes' ) + ': ' + ( counts.size || 0 );
			status.innerHTML =
				'<div class="lucidlab-mpc-core-check-report">' +
					( versionLine ? '<p class="lucidlab-mpc-core-check-version">' + escapeHtml( versionLine ) + '</p>' : '' ) +
					( attention ? '<p class="lucidlab-mpc-core-check-attention">' + escapeHtml( attention ) + '</p>' : '' ) +
					'<p class="lucidlab-mpc-core-check-summary">' + escapeHtml( summary ) + '</p>' +
				'</div>' + checkedLine;
		}
		function render( data ) {
			if ( ! results || ! status ) { return; }
			stopCheckingDots();
			data = data || {};
			var counts = data.counts || {};
			var items = data.items || [];
			if ( ! data.checked_at && ! items.length && ! data.message ) {
				status.textContent = '';
				results.hidden = true;
				results.replaceChildren();
				return;
			}
			renderStatus( data );
			if ( data.ok ) {
				results.hidden = true;
				results.replaceChildren();
				return;
			}
			results.hidden = false;
			results.replaceChildren();
			var list = document.createElement( 'ul' );
			list.className = 'lucidlab-mpc-core-check-list';
			items.forEach( function ( item ) {
				var li = document.createElement( 'li' );
				li.className = 'lucidlab-mpc-core-check-item';
				li.dataset.path = item.path || '';
				li.dataset.kind = item.kind || '';
				var pill = document.createElement( 'span' );
				pill.className = 'lucidlab-mpc-core-pill lucidlab-mpc-core-pill--' + ( item.kind || 'extra' );
				pill.textContent = kindLabels[ item.kind ] || item.kind || '';
				var path = document.createElement( 'code' );
				path.className = 'lucidlab-mpc-core-check-path';
				path.textContent = item.path || '';
				var meta = document.createElement( 'span' );
				meta.className = 'lucidlab-mpc-core-check-meta';
				var bits = [];
				if ( item.reason ) { bits.push( item.reason ); }
				if ( item.size || item.expected_size ) {
					bits.push( formatBytes( item.size ) + ( item.expected_size != null ? ' / ' + formatBytes( item.expected_size ) : '' ) );
				}
				meta.textContent = bits.join( ' · ' );
				var actions = document.createElement( 'span' );
				actions.className = 'lucidlab-mpc-core-check-actions';
				( item.actions || [] ).forEach( function ( action ) {
					var btn = document.createElement( 'button' );
					btn.type = 'button';
					btn.className = 'button button-small';
					btn.dataset.mpcCoreAction = action;
					btn.dataset.path = item.path || '';
					if ( action === 'view' ) { btn.textContent = mpcText( 'View' ); }
					else if ( action === 'compare' ) { btn.textContent = mpcText( 'Compare' ); }
					else if ( action === 'replace' ) { btn.textContent = mpcText( 'Replace' ); }
					else if ( action === 'delete' ) { btn.textContent = mpcText( 'Delete' ); }
					else if ( action === 'quarantine' ) { btn.textContent = mpcText( 'Quarantine' ); }
					else { btn.textContent = action; }
					actions.appendChild( btn );
				} );

				li.appendChild( pill );
				li.appendChild( path );
				li.appendChild( meta );
				li.appendChild( actions );
				list.appendChild( li );
			} );
			results.appendChild( list );
			syncSelectAll();
		}
		function openDialog( title, html ) {
			if ( ! dialog || ! dialogBody ) { return; }
			if ( dialogTitle ) { dialogTitle.textContent = title || mpcText( 'File compare' ); }
			dialogBody.innerHTML = html;
			if ( typeof dialog.showModal === 'function' ) { dialog.showModal(); }
			else { dialog.setAttribute( 'open', 'open' ); }
		}
		function closeDialog() {
			if ( ! dialog ) { return; }
			if ( typeof dialog.close === 'function' ) { dialog.close(); }
			else { dialog.removeAttribute( 'open' ); }
		}
		if ( dialogClose ) { dialogClose.addEventListener( 'click', closeDialog ); }
		if ( dialog ) {
			dialog.addEventListener( 'click', function ( event ) {
				if ( event.target === dialog ) { closeDialog(); }
			} );
		}
		try {
			var initial = JSON.parse( box.getAttribute( 'data-mpc-initial' ) || '{}' );
			if ( initial && ( initial.checked_at || ( initial.items && initial.items.length ) || initial.ok ) ) {
				render( initial );
			}
		} catch ( e ) {}
		if ( runBtn ) {
			runBtn.addEventListener( 'click', function () {
				if ( runBtn.disabled ) { return; }
				buttonBusyInside( runBtn, true );
				if ( results ) { results.hidden = true; results.replaceChildren(); }
				startCheckingDots();
				post( { op: 'core_check', sub: 'scan', tab: 'options', direct: 1 } ).then( function ( res ) {
					render( ( res && res.data ) || {} );
				} ).catch( function ( error ) {
					stopCheckingDots();
					if ( status ) { status.textContent = error.message; }
					showActionError( error );
				} ).finally( function () {
					stopCheckingDots();
					buttonBusyInside( runBtn, false );
				} );
			} );
		}
		function refreshResult( note ) {
			return post( { op: 'core_check', sub: 'result', tab: 'options', direct: 1 } ).then( function ( res2 ) {
				render( ( res2 && res2.data ) || {} );
				if ( note && status ) {
					var noteEl = document.createElement( 'p' );
					noteEl.className = 'lucidlab-mpc-hint lucidlab-mpc-core-check-note';
					noteEl.textContent = note;
					status.appendChild( noteEl );
				}
			} );
		}
		box.addEventListener( 'change', function ( event ) {
			var target = event.target;
			if ( ! target ) { return; }
			if ( target.matches( '[data-mpc-core-select-all]' ) ) {
				els( '[data-mpc-core-select]', results || box ).forEach( function ( input ) {
					input.checked = !! target.checked;
				} );
				syncSelectAll();
				return;
			}
			if ( target.matches( '[data-mpc-core-select]' ) ) {
				syncSelectAll();
			}
		} );
		box.addEventListener( 'click', async function ( event ) {
			var bulkBtn = event.target.closest( '[data-mpc-core-bulk]' );
			if ( bulkBtn ) {
				event.preventDefault();
				if ( bulkBtn.disabled ) { return; }
				var bulkAction = bulkBtn.getAttribute( 'data-mpc-core-bulk' ) || '';
				var paths = selectedPaths();
				if ( ! paths.length ) {
					alert( LucidLabMPC.i18n.nothing || mpcText( 'Nothing selected.' ) );
					return;
				}
				if ( bulkAction === 'delete' && ! await inlineConfirm( mpcText( 'Delete selected extra files permanently? Changed and different-size files are skipped. This cannot be undone.' ), box, { skip_note: true, skip_meta: true, require_text: 'CONFIRM' } ) ) { return; }
				if ( bulkAction === 'quarantine' && ! await inlineConfirm( mpcText( 'Move selected files to quarantine? You can restore them later from the Quarantine tab.' ), box, { skip_note: true, skip_meta: true } ) ) { return; }
				buttonBusyInside( bulkBtn, true );
				post( {
					op: 'core_check',
					sub: bulkAction,
					paths: JSON.stringify( paths ),
					tab: 'options',
					direct: 1
				} ).then( function ( res ) {
					var data = ( res && res.data ) || {};
					return refreshResult( data.message || '' );
				} ).catch( function ( error ) {
					showActionError( error );
				} ).finally( function () {
					buttonBusyInside( bulkBtn, false );
				} );
				return;
			}
			var btn = event.target.closest( '[data-mpc-core-action]' );
			if ( ! btn || btn.disabled ) { return; }
			var action = btn.getAttribute( 'data-mpc-core-action' ) || '';
			var path = btn.getAttribute( 'data-path' ) || '';
			if ( ! path ) { return; }
			if ( action === 'delete' && ! await inlineConfirm( mpcText( 'Delete this extra file permanently? This cannot be undone.' ), box, { skip_note: true, skip_meta: true, require_text: 'CONFIRM' } ) ) { return; }
			if ( action === 'replace' && ! await inlineConfirm( mpcText( 'Replace this file with the official WordPress copy?' ), box, { skip_note: true, skip_meta: true } ) ) { return; }
			if ( action === 'quarantine' && ! await inlineConfirm( mpcText( 'Move this file to quarantine? You can restore it later from the Quarantine tab.' ), box, { skip_note: true, skip_meta: true } ) ) { return; }
			buttonBusyInside( btn, true );
			var params = { op: 'core_check', sub: action === 'compare' ? 'compare' : action, path: path, tab: 'options', direct: 1 };
			if ( action === 'quarantine' ) { params.paths = JSON.stringify( [ path ] ); }
			if ( action === 'delete' ) { params.confirm = 'CONFIRM'; }
			if ( action === 'compare' ) { params.compare = 1; }
			post( params ).then( function ( res ) {
				var data = ( res && res.data ) || {};
				if ( action === 'view' || action === 'compare' ) {
					var html = '<p class="lucidlab-mpc-hint">' + escapeHtml( data.path || path ) + '</p>';
					html += '<p class="lucidlab-mpc-core-check-meta">' + mpcText( 'Local' ) + ': ' + formatBytes( data.local_size ) + ( data.original_size != null ? ' · ' + mpcText( 'Official' ) + ': ' + formatBytes( data.original_size ) : '' ) + '</p>';
					if ( data.binary ) {
						html += '<p>' + escapeHtml( mpcText( 'Binary file — content preview is not available. Size and checksum comparison still apply.' ) ) + '</p>';
					} else if ( action === 'compare' && data.original != null ) {
						html += '<div class="lucidlab-mpc-core-compare"><div><h4>' + escapeHtml( mpcText( 'This site' ) ) + '</h4><pre>' + escapeHtml( data.local || '' ) + '</pre></div><div><h4>' + escapeHtml( mpcText( 'Official' ) ) + '</h4><pre>' + escapeHtml( data.original || '' ) + '</pre></div></div>';
					} else {
						html += '<pre class="lucidlab-mpc-core-view">' + escapeHtml( data.local || '' ) + '</pre>';
					}
					if ( data.truncated ) { html += '<p class="lucidlab-mpc-hint">' + escapeHtml( mpcText( 'Preview truncated for large files.' ) ) + '</p>'; }
					openDialog( action === 'compare' ? mpcText( 'Compare with original' ) : mpcText( 'View file' ), html );
					return;
				}
				return refreshResult( data.message || mpcText( 'Done.' ) );
			} ).catch( function ( error ) {
				showActionError( error );
			} ).finally( function () {
				buttonBusyInside( btn, false );
			} );
		} );
	}

	/* ---------- Dimension-based quarantine (Image Sizes + Other Sizes on Disk, both stepped) ---------- */
	async function runDimQuar( tab, zone ) {
		var sel = els( '.lucidlab-mpc-row-check:checked', zone ).map( function ( c ) { return c.value; } );
		if ( ! sel.length ) { alert( LucidLabMPC.i18n.nothing ); return; }
		if ( ! await confirmImpact( LucidLabMPC.i18n.confirmQuar, zone, { preview: 'quarantine', tab: tab, items: JSON.stringify( sel ) } ) ) { return; }
		var pid = 'fs-sizes' === tab ? 'fs-sizes-quar' : 'sizes-quar';
		var pb = progress( pid );
		pb.show(); scrollToProgress( pid ); pb.set( 2, LucidLabMPC.i18n.quarantining );
		var stamp = '';
		function step() {
			post( { op: 'quarantine', tab: tab, stamp: stamp, items: JSON.stringify( sel ) } ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					pb.set( 100, ( res && res.data && res.data.message ) ? res.data.message : LucidLabMPC.i18n.error );
					return;
				}
				stamp = res.data.stamp || stamp;
				var lbl = 'Quarantining\u2026 ' + ( res.data.moved || 0 ) + mpcText(" moved");
				if ( res.data.missing ) { lbl += ', ' + res.data.missing + mpcText(" already missing on disk"); }
				pb.set( res.data.progress, lbl );
				if ( res.data.done ) { pb.set( 100, LucidLabMPC.i18n.done ); window.location = LucidLabMPC.tabUrls.sizes + '&lucidlab_mpc_quarantined=1'; return; }
				step();
			} ).catch( function () { pb.set( 100, LucidLabMPC.i18n.error ); } );
		}
		step();
	}

	/* ---------- Other Sizes on Disk (pure filesystem dimension scan) ---------- */
	function fsSizesInit() {
		var scanBtn = el( '#lucidlab-mpc-fssizes-scan' );
		if ( ! scanBtn ) { return; }
		var rows = el( '#lucidlab-mpc-fssizes-rows' );
		var summary = el( '#lucidlab-mpc-fssizes-summary' );
		var viewBtn = el( '#lucidlab-mpc-fssizes-view' );

		function updateSummary() {
			var files = 0;
			els( '.lucidlab-mpc-row-check:checked', rows ).forEach( function ( c ) {
				files += parseInt( c.getAttribute( 'data-count' ), 10 ) || 0;
			} );
			if ( summary ) { summary.textContent = files ? ( files + ' file(s) selected' ) : ''; }
		}
		document.addEventListener( 'change', function ( e ) {
			if ( rows && e.target.classList.contains( 'lucidlab-mpc-row-check' ) && rows.contains( e.target ) ) { updateSummary(); }
		} );

		if ( viewBtn ) {
			var loadSavedSizes = function () {
				buttonBusy( viewBtn, true );
				post( { op: 'load_scan', tab: 'fs-sizes', direct: true } ).then( function ( res ) {
					if ( ! res || ! res.success ) { throw new Error( ( res && res.data && res.data.message ) || mpcT('noSavedResultsShort','No saved results. Run a new scan.') ); }
					applyFsSizesResult( res.data );
				} ).catch( showActionError ).finally( function () { buttonBusy( viewBtn, false ); } );
			};
			viewBtn.addEventListener( 'click', loadSavedSizes );
			var sizesQuery = new URLSearchParams( window.location.search );
			if ( sizesQuery.get( 'lucidlab_mpc_fs_sizes' ) === '1' || sizesQuery.get( 'lucidlab_mpc_load' ) === '1' ) { loadSavedSizes(); }
		}

		scanBtn.addEventListener( 'click', function () {
			var panel = scanBtn.closest( '[data-mpc-panel="sizes"]' );
			if ( panel ) { panel.querySelectorAll( '.lucidlab-mpc-missing-results-notice' ).forEach( function ( notice ) { notice.remove(); } ); }
			hideFsSizesResults();
			runScan( 'fs-sizes' );
		} );
	}
	if ( document.readyState !== 'loading' ) { setTimeout( fsSizesInit, 0 ); } else { document.addEventListener( "DOMContentLoaded", fsSizesInit ); }

	/* ---------- Regenerate thumbnails (pause + background + resume) ---------- */
	function regenPost( sub, extra ) {
		var p = { op: 'regen', sub: sub };
		if ( extra ) { Object.keys( extra ).forEach( function ( k ) { p[ k ] = extra[ k ]; } ); }
		return post( p );
	}
	function regenMsg( text ) {
		var m = el( '#lucidlab-mpc-regen-msg' );
		if ( m ) { m.textContent = text; m.hidden = ! text; }
	}
	function renderRegenRunningJob(d,label){if(!['running','paused'].includes(d.status))return;var job=Object.assign({},d,{key:'lucidlab_mpc_regen_job',panel:'regenerate',scan_tab:'regenerate',special:'regen',label:mpcText("Regenerate thumbnails")});runningJobMessages[job.key]=label+' \u2014 '+(d.done||0)+'/'+(d.total||0)+mpcText(" images processed");if(job.driver==='run_all'||hasActiveRunAll())return;renderRunningJobs(knownRunningJobs.concat([job]));}
	function regenRender( d ) {
		mpcPaintRegen=regenRender;
		var finished = d.status === 'done';
        var label = finished ? ( Number( d.failed ) > 0 ? mpcText("Completed with errors") : mpcText("Completed") ) : d.status === 'paused' ? mpcText("Stopped") : d.status === 'cancelled' ? mpcText("Cancelled") : d.status === 'idle' ? mpcText("Ready") : d.driver === 'run_all' ? mpcText("Managed by Run All") : d.driver === 'cron' ? mpcText("Regenerating in background") : mpcText("Regenerating thumbnails");
		renderRegenRunningJob(d,label);
		var terminal = ['done','failed','cancelled'].includes(d.status), pb = progress( 'regen' ); pb.set( finished ? 100 : d.progress, label + ' \u2014 ' + d.done + '/' + d.total + mpcText(" images processed") );
		var progressBox = el('#lucidlab-mpc-progress-regen'); if ( progressBox ) { progressBox.hidden = true; }
		if ( terminal ) {
			if ( progressBox ) { progressBox.hidden = true; }
		}
        if ( finished ) { regenMsg( '' ); }
		var stat = el( '#lucidlab-mpc-regen-stat' );
		var terminalDate = d.updated_label || ( (formatJobDate(d.updated)||mpcText("date unavailable (older job)")) );
		if ( stat ) {
			stat.classList.toggle( 'lucidlab-mpc-job-summary', terminal );
			stat.style.margin = '';
			stat.style.fontFamily = '';
			if ( terminal ) {
				stat.hidden = false;
				stat.textContent = ( d.status === 'cancelled' ? mpcText("Cancelled on: ") : d.status === 'done' ? mpcText("Completed on: ") : mpcText("Failed on: ") ) + terminalDate + ' \u2014 ' + d.done + mpcText(" processed, ") + d.regenerated + mpcText(" regenerated, ") + d.skipped + mpcText(" skipped, ") + d.failed + mpcText(" failed.");
				var regenPanel = stat.closest( '[data-mpc-panel]' ) || el( '[data-mpc-panel="regenerate"]' );
				placeStatusAtPanelTop( regenPanel, stat );
			} else if ( d.status !== 'idle' ) {
				stat.hidden = true;
				stat.textContent = d.done + '/' + d.total + '  \u00B7  regenerated ' + d.regenerated + ', skipped ' + d.skipped + ', failed ' + d.failed + '  (' + d.status + ')';
			} else {
				stat.hidden = true;
			}
		}
		var b;
		// Stop / Cancel / Continue in background live under Current Running Jobs — keep page duplicates hidden.
		els( '#lucidlab-mpc-regen-cancel, #lucidlab-mpc-regen-pause, #lucidlab-mpc-regen-resume, #lucidlab-mpc-regen-bg' ).forEach( function ( button ) {
			if ( button ) { button.hidden = true; }
		} );
		if ( ( b = el( '#lucidlab-mpc-regen-start' ) ) ) { b.hidden = false; buttonBusyInside( b, d.status === 'running' ); }
		if ( ( b = el( '#lucidlab-mpc-regen-retry' ) ) ) { b.hidden = !( finished && Number( d.failed ) > 0 ); b.disabled=d.status==='running'; }
		if(d.driver==='run_all'){var startOnly=el('#lucidlab-mpc-regen-start');if(startOnly)startOnly.disabled=true;var retryOnly=el('#lucidlab-mpc-regen-retry');if(retryOnly)retryOnly.disabled=true;}
	}

	mpcPaintRegen=regenRender;
    var regenTimer,regenControl=false,regenQueue=Promise.resolve();
    function regenRequest(action,input){var p=regenQueue.catch(function(){}).then(async function(){var r=await regenPost(action,input);if(!r||!r.success)throw Error(r&&r.data&&r.data.message||mpcText("Regeneration request failed"));return r.data;});regenQueue=p;return p;}
	function regenLoop(d){regenRender(d);clearTimeout(regenTimer);if(regenControl||d.status!=='running')return;var owned=d.driver==='run_all'||hasActiveRunAll();regenTimer=setTimeout(async function(){try{var next=await regenRequest(owned||d.driver!=='ajax'?'status':'step');setRunningJobMessage('lucidlab_mpc_regen_job','');regenLoop(next);}catch(e){var message=mpcText("Connection interrupted: ")+e.message+mpcText(" Checking saved progress…");setRunningJobMessage('lucidlab_mpc_regen_job',message);regenTimer=setTimeout(function(){regenLoop(d);},3000);}},2500);}
	async function regenCommand(action,input){if(regenControl)return;regenControl=true;clearTimeout(regenTimer);if(action==='start'){var startButton=el('#lucidlab-mpc-regen-start');if(startButton){buttonBusyInside(startButton,true);startButton.hidden=false;}pendingRunningJobs['regen-starting']={key:'regen-starting',pending:true,status:'running',panel:'regenerate',scan_tab:'regenerate',special:'regen',label:mpcText("Starting Regenerate Thumbnails")};ensureRunningJobOrder(pendingRunningJobs['regen-starting']);renderRunningJobs(knownRunningJobs);scrollToRunningJobs();}regenMsg(action==='cancel'?mpcText("Canceling after the current batch…"):mpcText("Applying action after the current image…"));try{var d;do{d=await regenRequest(action,input);if(d.busy)await new Promise(resolve=>setTimeout(resolve,500));}while(d.busy);if(action==='start'){var pendingOrder=runningJobOrder['regen-starting'];delete pendingRunningJobs['regen-starting'];var regenJob=Object.assign({},d,{key:'lucidlab_mpc_regen_job',panel:'regenerate',scan_tab:'regenerate',special:'regen',label:mpcText("Regenerate thumbnails")});ensureRunningJobOrder(regenJob,pendingOrder);setRunningJobMessage('lucidlab_mpc_regen_job','');renderRunningJobs(knownRunningJobs.concat([regenJob]));}regenMsg('');regenControl=false;regenLoop(d);}catch(e){if(action==='start'){delete pendingRunningJobs['regen-starting'];renderRunningJobs(knownRunningJobs);}regenControl=false;if(action==='start'){var failedStart=el('#lucidlab-mpc-regen-start');if(failedStart)buttonBusyInside(failedStart,false);}setRunningJobMessage('lucidlab_mpc_regen_job',mpcText("Connection interrupted: ")+e.message+mpcText(" Checking saved progress…"));showActionError(e);}}
    function regenInit(){
        var box=el('#lucidlab-mpc-regen');if(!box||LucidLabMPC.tab!=='regenerate'||box.hasAttribute('data-initialized'))return;box.setAttribute('data-initialized','1');
		var startBtn=el('#lucidlab-mpc-regen-start');
		// Hide legacy Stop/Cancel/Background buttons next to Generate — those controls sit under the job card.
		els( '#lucidlab-mpc-regen-cancel, #lucidlab-mpc-regen-pause, #lucidlab-mpc-regen-resume, #lucidlab-mpc-regen-bg' ).forEach( function ( button ) {
			if ( button ) { button.hidden = true; }
		} );
		var retry=el('#lucidlab-mpc-regen-retry');
		if(!retry){
			retry=document.createElement('button');retry.type='button';retry.id='lucidlab-mpc-regen-retry';retry.className='button';retry.textContent=mpcText("Retry failed");retry.hidden=true;
			if(startBtn&&startBtn.parentElement){startBtn.parentElement.appendChild(retry);}
		}
		var regenProgress=el('#lucidlab-mpc-progress-regen');if(regenProgress)regenProgress.hidden=true;
		var controls=startBtn&&startBtn.parentElement;if(controls){controls.classList.add('lucidlab-mpc-scan-controls');if(regenProgress&&controls.previousElementSibling!==regenProgress)regenProgress.after(controls);else if(!regenProgress&&controls.parentElement!==box)box.prepend(controls);}
        if(startBtn){startBtn.onclick=function(){regenCommand('start',{mode:(el('input[name="lucidlab-mpc-regen-mode"]:checked',box)||{}).value||'skip',sizes:JSON.stringify(els('.lucidlab-mpc-regen-size:checked',box).map(c=>c.value)),quality:el('#lucidlab-mpc-regen-quality').value});};}
        retry.onclick=function(){regenCommand('retry',{});};
		if(['running','paused'].includes(box.getAttribute('data-status')))regenCommand('status');
		box.addEventListener('change',function(){queuePageSettings('regen',regenPageSettings());});
    }

	if ( document.readyState !== 'loading' ) { setTimeout( regenInit, 0 ); } else { document.addEventListener( "DOMContentLoaded", regenInit ); }
	document.addEventListener( 'mpc:tabshown', function ( event ) { if ( event.detail === 'regenerate' ) { regenInit(); } } );

	function backupJobItem(job) {
		var item = Object.assign({}, job, {
			key: 'lucidlab_mpc_backup_job',
			panel: 'backup',
			scan_tab: 'backup',
			special: 'backup',
			label: job.label || mpcText("Backup")
		});
		if (item.indeterminate == null && ['running', 'paused'].includes(item.status) && (Number(item.progress) || 0) < 1) {
			item.indeterminate = true;
		}
		return item;
	}
	function renderBackupFiles(list) {
		['db', 'uploads', 'site'].forEach(function (kind) {
			var host = el('[data-mpc-backup-files="' + kind + '"]');
			if (!host) { return; }
			var items = (list || []).filter(function (item) { return item.kind === kind; });
			host.replaceChildren();
			if (!items.length) {
				var empty = document.createElement('li');
				empty.className = 'lucidlab-mpc-hint';
				empty.textContent = mpcText("No backups stored yet.");
				host.appendChild(empty);
				return;
			}
			items.forEach(function (item) {
				var li = document.createElement('li');
				li.dataset.file = item.file || '';
				var code = document.createElement('code');
				code.textContent = item.file || '';
				var meta = document.createElement('span');
				meta.className = 'lucidlab-mpc-hint';
				meta.textContent = ' ' + [item.detail || item.size, item.when].filter(Boolean).join(' \u00B7 ');
				var download = document.createElement('a');
				download.className = 'button button-small';
				download.href = item.download || '#';
				download.textContent = mpcText("Download");
				var restore = document.createElement('button');
				restore.type = 'button';
				restore.className = 'button button-small';
				restore.dataset.mpcBackup = 'restore';
				restore.dataset.kind = kind;
				restore.dataset.file = item.file || '';
				restore.textContent = mpcText("Restore");
				var badge = null;
				if (item.signature === 'unsigned') {
					badge = document.createElement('span');
					badge.className = 'lucidlab-mpc-badge lucidlab-mpc-badge-danger';
					badge.dataset.mpcBackupUnsigned = '';
					badge.title = mpcText("This file was not created by Media & Products Cleanup on this site, or it was changed afterwards. Download it and restore it with your hosting provider's database tools.");
					badge.textContent = mpcText("Not signed by this site");
				}
				if (item.restorable === false) {
					delete restore.dataset.mpcBackup;
					restore.disabled = true;
					restore.title = mpcText("Restore is not available for backups that are not signed by this site.");
				}
				var del = document.createElement('button');
				del.type = 'button';
				del.className = 'button button-small';
				del.dataset.mpcBackup = 'delete';
				del.dataset.file = item.file || '';
				del.textContent = mpcText("Delete");
				li.append(code, meta, document.createTextNode(' '));
				if (badge) { li.append(badge, document.createTextNode(' ')); }
				li.append(download, document.createTextNode(' '), restore, document.createTextNode(' '), del);
				host.appendChild(li);
			});
		});
	}
	function panelNoticeCard( panel ) {
		return el( '[data-mpc-panel="' + panel + '"] [data-mpc-panel-notices]' ) || el( '[data-mpc-panel-notices]' );
	}
	function scrollToPanelNotice( card ) {
		var target = card || panelNoticeCard( 'backup' );
		if ( ! target || target.hidden ) {
			window.scrollTo( { top: 0, behavior: 'smooth' } );
			return;
		}
		// #lucidlab-mpc-top-notices is display:none when empty — never scroll to that.
		var offset = 72;
		try {
			var y = Math.max( 0, window.scrollY + target.getBoundingClientRect().top - offset );
			window.scrollTo( { top: y, behavior: 'smooth' } );
		} catch ( error ) {
			window.scrollTo( 0, 0 );
		}
		if ( target.scrollIntoView ) {
			target.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	}
	function showPanelNotice( panel, text, type ) {
		var card = panelNoticeCard( panel );
		if ( ! card ) { return; }
		card.hidden = ! text;
		card.innerHTML = '';
		if ( ! text ) { return; }
		var box = document.createElement( 'div' );
		box.className = 'notice notice-' + ( type === 'warning' ? 'warning' : ( type === 'error' ? 'error' : 'info' ) ) + ' inline lucidlab-mpc-notice';
		box.setAttribute( 'role', type === 'error' ? 'alert' : 'status' );
		var p = document.createElement( 'p' );
		p.textContent = text;
		box.appendChild( p );
		card.appendChild( box );
		// Backup notices sit under the hero — scroll up after paint so the blue confirmation is visible.
		requestAnimationFrame( function () {
			scrollToPanelNotice( card );
		} );
	}
	function backupMsg( text, type ) {
		showPanelNotice( 'backup', text, type );
		var status = el( '[data-mpc-backup-status]' );
		if ( status ) {
			status.hidden = true;
			status.textContent = '';
		}
	}
	var backupTimer, backupControl = false, backupQueue = Promise.resolve(), backupFirstStep = true;
	var backupNoticePending = false;
	function backupRequest(action, extra) {
		extra = extra || {};
		var p = backupQueue.catch(function () {}).then(async function () {
			var params = Object.assign({ op: 'backup', tab: 'backup', sub: action }, extra.params || {});
			var r = await post(params, extra.form ? { form: extra.form } : {});
			if (!r || !r.success) { throw Error(r && r.data && r.data.message || mpcText("Backup request failed")); }
			return r.data;
		});
		backupQueue = p;
		return p;
	}
	var backupEpoch = 0;
	function backupLoop(d) {
		d = d || {};
		if ( d._epoch != null && d._epoch !== backupEpoch ) { return; }
		backupRender(d);
		if ( ['done', 'failed', 'cancelled'].includes(d.status) && d._epoch != null ) {
			backupRequest('list').then(function (fresh) {
				if (fresh && fresh.backups && d._epoch === backupEpoch) { renderBackupFiles(fresh.backups); }
			}).catch(function () {});
		}
		clearTimeout(backupTimer);
		if (backupControl || runningCommands['lucidlab_mpc_backup_job'] === 'cancel') { return; }
		if (d.status !== 'running' && d.status !== 'paused') { return; }
		var delay = d.status === 'paused' ? 4000 : (backupFirstStep ? 0 : 250);
		var epoch = d._epoch != null ? d._epoch : backupEpoch;
		backupFirstStep = false;
		backupTimer = setTimeout(async function () {
			try {
				if ( epoch !== backupEpoch || runningCommands['lucidlab_mpc_backup_job'] === 'cancel') { return; }
				backupSent = Date.now();
				var next = await backupRequest(d.status === 'running' && d.driver === 'ajax' ? 'step' : 'status');
				if ( epoch !== backupEpoch ) { return; }
				if (next && next.busy) {
					backupTimer = setTimeout(function () { backupLoop(d); }, 1500);
					return;
				}
				next._epoch = epoch;
				backupSent = 0;
				setRunningJobMessage('lucidlab_mpc_backup_job', '');
				backupLoop(next);
			} catch (e) {
				backupSent = 0;
				if (runningCommands['lucidlab_mpc_backup_job'] === 'cancel') { return; }
				var message = mpcText("Connection interrupted: ") + e.message + mpcText(" Checking saved progress…");
				setRunningJobMessage('lucidlab_mpc_backup_job', message);
				backupTimer = setTimeout(function () { backupLoop(d); }, 3000);
			}
		}, delay);
	}
	function backupRender(d) {
		d = d || {};
		var running = ['running', 'paused'].includes(d.status);
		var terminal = ['done', 'failed', 'cancelled'].includes(d.status);
		// Progress lives in Current Running Jobs — only show the blue card when a job finishes.
		if ( running ) {
			backupNoticePending = true;
			showPanelNotice( 'backup', '' );
		} else if ( d._deletedFile ) {
			backupMsg( d.notice || '', 'warning' );
		} else if ( terminal && d.label && backupNoticePending ) {
			backupNoticePending = false;
			backupMsg( d.label );
		}
		if (d.backups) { renderBackupFiles(d.backups); }
		els('[data-mpc-backup="start"]').forEach(function (btn) {
			btn.disabled = running;
			if ( ! running ) { buttonBusyInside( btn, false ); }
		});
		if (running) {
			upsertRunningJob(backupJobItem(d));
		} else {
			renderRunningJobs(knownRunningJobs.filter(function (job) { return job.key !== 'lucidlab_mpc_backup_job'; }));
		}
	}
	async function backupCommand(action, extra) {
		if (backupControl) { return; }
		backupControl = true;
		clearTimeout(backupTimer);
		var epoch = ++backupEpoch;
		extra = extra || {};
		if (action === 'start') {
			backupFirstStep = true;
			backupNoticePending = true;
			showPanelNotice( 'backup', '' );
			pendingRunningJobs['backup-starting'] = { key: 'backup-starting', pending: true, status: 'running', panel: 'backup', scan_tab: 'backup', special: 'backup', kind: extra.kind || '', label: mpcText("Starting backup…") };
			ensureRunningJobOrder(pendingRunningJobs['backup-starting']);
			renderRunningJobs(knownRunningJobs);
			scrollToRunningJobs();
		}
		try {
			var d;
			do {
				d = await backupRequest(action, extra);
				if (d.busy) { await new Promise(function (resolve) { setTimeout(resolve, 500); }); }
			} while (d && d.busy);
			delete pendingRunningJobs['backup-starting'];
			d._epoch = epoch;
			if (action === 'delete' && extra.params && extra.params.file) {
				d._deletedFile = extra.params.file;
				var gone = document.querySelector('[data-mpc-backup-files] [data-file="' + CSS.escape(extra.params.file) + '"]');
				if (gone) { gone.remove(); }
			}
			if (action === 'start') {
				var pendingOrder = runningJobOrder['backup-starting'];
				var backupJob = backupJobItem(d);
				ensureRunningJobOrder(backupJob, pendingOrder);
				setRunningJobMessage('lucidlab_mpc_backup_job', '');
				upsertRunningJob(backupJob);
			}
			backupControl = false;
			backupLoop(d);
		} catch (e) {
			if (action === 'start') { backupNoticePending = false; }
			delete pendingRunningJobs['backup-starting'];
			renderRunningJobs(knownRunningJobs);
			backupControl = false;
			backupMsg(e.message);
			showActionError(e);
		}
	}
	function backupInit() {
		var box = el('[data-mpc-backup-panel]');
		if (!box || box.dataset.ready) { return; }
		box.dataset.ready = '1';
		box.addEventListener('click', async function (event) {
			var btn = event.target.closest('[data-mpc-backup]');
			if (!btn || btn.disabled) { return; }
			var action = btn.dataset.mpcBackup;
			var kind = btn.dataset.kind || '';
			var file = btn.dataset.file || '';
			if (action === 'start') {
				var startMsg = kind === 'uploads'
					? mpcText("Create an archive of the uploads folder? Large libraries take time. The ZIP is stored in the protected backup folder under uploads, which is left out of the archive.")
					: (kind === 'site'
						? mpcText("Archive WordPress core files, plugins, themes, uploads and the database? Other content folders are excluded. Download the archive and restore it with your hosting provider.")
						: mpcText("Create a SQL dump of this site's database? It is gzip-compressed when available. The file is stored in the protected backup folder under uploads."));
				if (!await inlineConfirm(startMsg, btn, { skip_note: true, skip_meta: true })) { return; }
				buttonBusyInside(btn, true);
				backupCommand('start', { params: { kind: kind } });
				return;
			}
			if (action === 'delete') {
				if (!await inlineConfirm(mpcText("Delete this stored backup file from uploads/lucidlab-mpc-cleanup?"), btn, { skip_note: true, skip_meta: true })) { return; }
				buttonBusyInside(btn, true);
				try {
					await backupCommand('delete', { params: { file: file } });
				} finally {
					buttonBusyInside(btn, false);
				}
				return;
			}
			if (action === 'restore') {
				if (kind === 'site') {
					if (!await inlineConfirm(mpcText("Open the emergency restore page for this full-site backup? That file can wipe the live site, unzip the archive and import the SQL. Type RESTORE to continue."), btn, { skip_note: true, skip_meta: true, require_text: "RESTORE" })) { return; }
					var restoreUrl = box.dataset.mpcRestoreUrl || '';
					if (!restoreUrl) { backupMsg(mpcText("The emergency restore file is not available in the WordPress root.")); return; }
					var href = restoreUrl + (file ? ((restoreUrl.indexOf('?') === -1 ? '?' : '&') + 'file=' + encodeURIComponent(file)) : '');
					window.open(href, '_blank', 'noopener');
					return;
				}
				var restoreKind = kind === 'uploads' ? 'restore_uploads' : 'restore_db';
				if (restoreKind === 'restore_db') {
					// Ask the server first, so a backup that is not signed by this site is refused before the RESTORE prompt.
					buttonBusyInside(btn, true);
					backupMsg(mpcText("Verifying the backup signature… A large backup can take a few seconds the first time."));
					try {
						await backupRequest('verify', { params: { kind: restoreKind, file: file } });
						backupMsg('');
					} catch (error) {
						backupMsg(error && error.message ? error.message : String(error), 'error');
						return;
					} finally {
						buttonBusyInside(btn, false);
					}
				}
				var message = restoreKind === 'restore_uploads'
					? mpcText("Restore this uploads archive over the live uploads folder? Existing files with the same path are overwritten. Type RESTORE to confirm.")
					: mpcText("Restore this SQL dump over the live database? This can sign you out and undo later WordPress changes. Type RESTORE to confirm.");
				if (!await inlineConfirm(message, btn, { skip_note: true, skip_meta: true, require_text: "RESTORE" })) { return; }
				buttonBusyInside(btn, true);
				backupCommand('start', { params: { kind: restoreKind, file: file } });
			}
		});
		var showRestoreKey = el('[data-mpc-show-restore-key]', box);
		if (showRestoreKey) {
			showRestoreKey.addEventListener('click', async function () {
				buttonBusyInside(showRestoreKey, true);
				try {
					var response = await post({ op: 'backup_restore_key', direct: 1 });
					if (!response || !response.success || !response.data || !response.data.key) { throw new Error(mpcText('Could not load restore key.')); }
					var label = showRestoreKey.closest('.lucidlab-mpc-emergency-key').querySelector('label');
					label.querySelector('input').value = response.data.key;
					label.hidden = false;
					showRestoreKey.hidden = true;
				} catch (error) { showActionError(error); }
				finally { buttonBusyInside(showRestoreKey, false); }
			});
		}
		var emailBtn = el('[data-mpc-email-emergency-restore]', box);
		if (emailBtn) {
			emailBtn.addEventListener('click', async function () {
				if (!LucidLabMPC.hasReportEmail) {
					backupMsg(mpcText("Add at least one email under Options → Email report before sending."));
					scrollToPanelNotice( panelNoticeCard( 'backup' ) );
					return;
				}
				if (!await inlineConfirm(mpcText("Email the emergency restore URL and restore key to the recipients saved under Options → Email report?"), emailBtn, { skip_note: true, skip_meta: true })) { return; }
				buttonBusyInside(emailBtn, true);
				try {
					var r = await post({ op: 'email_emergency_restore', direct: 1 });
					if (!r || !r.success) { throw new Error((r && r.data && r.data.message) || mpcText("Could not send email.")); }
					backupMsg((r.data && r.data.message) || mpcText("Emergency restore link and key emailed."));
				} catch (e) {
					backupMsg(e.message);
					showActionError(e);
				} finally {
					buttonBusyInside(emailBtn, false);
					if ( emailBtn.blur ) { emailBtn.blur(); }
					// Re-scroll after the button re-enables so focus does not keep the viewport down here.
					requestAnimationFrame( function () {
						scrollToPanelNotice( panelNoticeCard( 'backup' ) );
					} );
				}
			});
		}
		els('.lucidlab-mpc-backup-import', box).forEach(function (form) {
			form.addEventListener('submit', async function (event) {
				event.preventDefault();
				var input = form.querySelector('input[type="file"]');
				var importBtn = event.submitter || form.querySelector('button[type="submit"]');
				if (!input || !input.files || !input.files[0]) {
					backupMsg(mpcText("Choose a backup file to import."));
					return;
				}
				var kind = form.getAttribute('data-kind') || 'db';
				var importMsg = kind === 'uploads'
					? mpcText("Store this uploads archive in uploads/lucidlab-mpc-cleanup? Restore remains a separate step.")
					: (kind === 'site'
						? mpcText("Store this full-site archive in uploads/lucidlab-mpc-cleanup? Restore remains a separate step through your hosting provider or WordPress tools.")
						: mpcText("Store this SQL backup in uploads/lucidlab-mpc-cleanup? Restore remains a separate step."));
				if (!await inlineConfirm(importMsg, form, { skip_note: true, skip_meta: true })) { return; }
				buttonBusyInside(importBtn, true);
				var body = new FormData();
				body.append('file', input.files[0]);
				try {
					var d = await backupRequest('import', { params: { kind: kind }, form: body });
					backupRender(d);
					backupMsg(mpcText("Imported. Download or restore it from the list."));
					input.value = '';
				} catch (e) {
					backupMsg(e.message, 'error');
					input.value = '';
					showActionError(e);
				} finally {
					buttonBusyInside(importBtn, false);
				}
			});
		});
		backupCommand('status');
	}
	if ( document.readyState !== 'loading' ) { setTimeout( backupInit, 0 ); } else { document.addEventListener( "DOMContentLoaded", backupInit ); }
	document.addEventListener( 'mpc:tabshown', function ( event ) { if ( event.detail === 'backup' ) { backupInit(); } } );

	function dupcInit() { if ( el( '.lucidlab-mpc-dupc-form' ) ) { updateDupcPreview(); } }
	function renamePatternInit() {
		var input = el( '#lucidlab-mpc-rename-pattern' );
		var chips = el( '[data-mpc-pattern-chips]' ) || el( '#lucidlab-mpc-pattern-selected' );
		if ( ! input || ! chips ) { return; }
		function tokens() {
			return Array.from( input.value.matchAll( /\{[^}]+\}/g ) ).map( function ( match ) { return match[0]; } );
		}
		function setValue( value ) {
			input.value = value.replace( /--+/g, '-' ).replace( /^-|-$/g, '' );
			input.dispatchEvent( new Event( 'input' ) );
			draw();
		}
		function draw() {
			var current = tokens();
			chips.replaceChildren();
			current.forEach( function ( token ) {
				var chip = document.createElement( 'span' );
				chip.className = 'lucidlab-mpc-chip';
				chip.appendChild( document.createTextNode( token ) );
				var remove = document.createElement( 'button' );
				remove.type = 'button';
				remove.className = 'lucidlab-mpc-chip-x';
				remove.setAttribute( 'aria-label', mpcText("Remove ") + token );
				remove.textContent = '\u00D7';
				remove.addEventListener( 'click', function () {
					setValue( input.value.split( token ).join( '' ) );
				} );
				chip.appendChild( remove );
				chips.appendChild( chip );
			} );
			els( '[data-pattern-tag]' ).forEach( function ( button ) {
				button.classList.toggle( 'lucidlab-mpc-is-selected', current.indexOf( button.getAttribute( 'data-pattern-tag' ) || '' ) !== -1 );
			} );
		}
		if ( ! input.hasAttribute( 'data-mpc-pattern-bound' ) ) {
			input.setAttribute( 'data-mpc-pattern-bound', '1' );
			input.addEventListener( 'input', draw );
		}
		draw();
	}
	document.addEventListener( 'click', function ( event ) {
		var tag = event.target.closest( '[data-pattern-tag]' );
		if ( ! tag ) { return; }
		var input = el( '#lucidlab-mpc-rename-pattern' );
		if ( ! input ) { return; }
		var token = tag.getAttribute( 'data-pattern-tag' ) || '';
		if ( ! token ) { return; }
		if ( input.value.indexOf( token ) !== -1 ) {
			input.value = input.value.split( token ).join( '' ).replace( /--+/g, '-' ).replace( /^-|-$/g, '' );
		} else {
			input.value += ( input.value && ! /[-_ ]$/.test( input.value ) ? '-' : '' ) + token;
		}
		input.dispatchEvent( new Event( 'input' ) );
		input.focus();
		renamePatternInit();
	} );
	if ( document.readyState !== 'loading' ) { setTimeout( renamePatternInit, 0 ); } else { document.addEventListener( "DOMContentLoaded", renamePatternInit ); }
	document.addEventListener( 'mpc:tabshown', function ( event ) { if ( event.detail === 'rename' ) { renamePatternInit(); } } );
	/* AI suggestion workflow: filename and alt text each get their own status element
	   and their own apply/undo handler, instead of one shared node whose text was
	   re-parsed by a MutationObserver to decide what button to inject. */
	function getAiStatusElements( btn ) {
		var nameOut = btn.nextElementSibling;
		if ( nameOut ) { nameOut.setAttribute( 'role', 'status' ); nameOut.setAttribute( 'aria-live', 'polite' ); }
		var altOut = nameOut && nameOut.nextElementSibling;
		if ( ! altOut || ! altOut.classList.contains( 'lucidlab-mpc-ai-status-alt' ) ) {
			altOut = document.createElement( 'span' );
			altOut.className = 'lucidlab-mpc-ai-status-alt';
			altOut.setAttribute( 'role', 'status' );
			altOut.setAttribute( 'aria-live', 'polite' );
			nameOut.insertAdjacentElement( 'afterend', altOut );
		}
		return { nameOut: nameOut, altOut: altOut };
	}
    async function aiAction(btn, out, actionButton, request, pending) {
        actionButton.disabled = true;
        var message = document.createElement('span');
        message.textContent = ' ' + pending;
        out.appendChild(message);
        try {
            var response = await post(Object.assign({tab: 'rename_ai', attachment_id: btn.getAttribute('data-attachment-id')}, request));
            if (!response || !response.success) throw new Error(response && response.data && response.data.message || mpcText("Action failed. Please retry."));
            message.textContent = ' ' + (response.data.message || mpcText("Saved."));
            return response.data;
        } catch (error) {
            message.textContent = ' ' + error.message;
            throw error;
        } finally { actionButton.disabled = false; }
    }
    function addAltUndo(btn, out) {
        var existing = out.querySelector('[data-ai-alt-undo]');
        if (existing) return;
        var undo = document.createElement('button');
        undo.type = 'button'; undo.className = 'button button-small';
        undo.textContent = mpcText("Undo alt text"); undo.dataset.aiAltUndo = '1';
        undo.addEventListener('click', function () { undoAltText(btn, out, undo).catch(showActionError); });
        out.appendChild(undo);
    }
    async function undoAltText(btn, altOut, undoBtn) {
        await aiAction(btn, altOut, undoBtn, {op: 'ai_undo_alt'}, mpcText("Restoring alt text…"));
        undoBtn.remove();
    }
    async function applyAltText(btn, altOut, altText, applyBtn) {
        var result = await aiAction(btn, altOut, applyBtn, {op: 'ai_apply_alt', alt_text: altText, ai_fingerprint: btn.dataset.aiFingerprint || ''}, mpcText("Saving alt text…"));
        if (!result.skipped) addAltUndo(btn, altOut);
        applyBtn.disabled = true;
    }
    async function applyFilename(btn, nameOut, filename, applyBtn) {
        await aiAction(btn, nameOut, applyBtn, {op: 'ai_apply_filename', filename: filename, ai_fingerprint: btn.dataset.aiFingerprint || ''}, mpcText("Renaming image…"));
        btn.dataset.aiFingerprint='';
        applyBtn.disabled = true;
        var link = document.createElement('a');
        link.href = new URL('admin.php?page=lucidlab-mpc-cleanup&tab=rename&lucidlab_mpc_load=1#lucidlab-mpc-recent-renames', window.location.href).href;
        link.textContent = mpcText(" Switch to the Rename Images tab to view this change in Recent renames and undo it.");
        nameOut.appendChild(link);
    }
    async function applyTitle(btn, nameOut, title, applyBtn) {
        await aiAction(btn, nameOut, applyBtn, {op: 'ai_apply_title', title: title, ai_fingerprint: btn.dataset.aiFingerprint || ''}, mpcText("Saving title…"));
        applyBtn.disabled = true;
    }
    els('[data-ai-history-undo]').forEach(function(btn) {
        btn.addEventListener('click', function() { undoAltText(btn, btn.parentElement, btn).catch(showActionError); });
    });
    async function applyMediaText(btn, out, value, applyBtn, field) {
        await aiAction(btn, out, applyBtn, {op: 'ai_apply_' + field, [field]: value, ai_fingerprint: btn.dataset.aiFingerprint || ''}, mpcText("Saving image text…"));
        applyBtn.disabled = true;
    }
    function libraryRowFor(id, btn) {
        var tr = btn && btn.closest ? btn.closest('tr') : null;
        if (tr && tr.classList.contains('lucidlab-mpc-ai-result-row')) {
            var hostId = tr.getAttribute('data-ai-result-for') || String(id || '');
            tr = document.querySelector('tr[data-ai-row="'+hostId+'"]') || tr;
        }
        if ((!tr || !tr.getAttribute('data-ai-row')) && id) {
            tr = document.querySelector('tr[data-ai-row="'+id+'"]') || tr;
        }
        return tr;
    }
    function aiResultHost(tr) {
        if (!tr || !tr.parentNode) return null;
        var id = tr.getAttribute('data-ai-row') || '';
        var existing = id ? tr.parentNode.querySelector('tr[data-ai-result-for="'+id+'"]') : null;
        if (existing) return existing.querySelector('td') || existing;
        var row = document.createElement('tr');
        row.className = 'lucidlab-mpc-ai-result-row';
        if (id) row.setAttribute('data-ai-result-for', id);
        var td = document.createElement('td');
        td.colSpan = Math.max(1, tr.children.length);
        td.className = 'lucidlab-mpc-ai-result-cell';
        row.appendChild(td);
        tr.insertAdjacentElement('afterend', row);
        return td;
    }
    function renderAiResultRow(tr, data, btn) {
        var host = aiResultHost(tr);
        if (!host) return;
        host.replaceChildren();
        var id = (tr && tr.getAttribute('data-ai-row')) || (btn && btn.getAttribute('data-attachment-id')) || '';
        if (btn && data && data.fingerprint) btn.dataset.aiFingerprint = data.fingerprint;
        var current = {
            filename: (tr && tr.getAttribute('data-ai-current')) || (data.current || ''),
            title: (tr && tr.getAttribute('data-ai-current-title')) || (data.current_title || ''),
            alt: (tr && tr.getAttribute('data-ai-current-alt')) || (data.current_alt || ''),
            caption: data.current_caption || '', description: data.current_description || ''
        };
        var grid = document.createElement('div');
        grid.className = 'lucidlab-mpc-ai-result-grid';
        function previewField(label, now, proposed, multiline) {
            var wrap = document.createElement('div');
            wrap.className = 'lucidlab-mpc-ai-preview-field';
            var heading = document.createElement('strong');
            heading.textContent = label;
            var before = document.createElement('div');
            before.className = 'lucidlab-mpc-hint lucidlab-mpc-ai-preview-current';
            before.textContent = 'Now: ' + (now || '(empty)');
            var input = multiline ? document.createElement('textarea') : document.createElement('input');
            if (!multiline) { input.type = 'text'; }
            input.className = 'regular-text';
            input.value = proposed || '';
            wrap.append(heading, before, input);
            return { wrap: wrap, input: input };
        }
        var fileField = previewField(mpcText("Filename"), current.filename, data.filename || '', false);
        var applyName = document.createElement('button');
        applyName.type = 'button';
        applyName.className = 'button button-small';
        applyName.textContent = mpcText("Apply filename");
        applyName.addEventListener('click', function () { applyFilename(btn, fileField.wrap, fileField.input.value, applyName).catch(showActionError); });
        fileField.wrap.appendChild(applyName);
        var titleField = previewField(mpcText("Title"), current.title, data.title || data.suggested_title || '', false);
        var applyTitleBtn = document.createElement('button');
        applyTitleBtn.type = 'button';
        applyTitleBtn.className = 'button button-small';
        applyTitleBtn.textContent = mpcText("Apply title");
        applyTitleBtn.addEventListener('click', function () { applyTitle(btn, titleField.wrap, titleField.input.value, applyTitleBtn).catch(showActionError); });
        titleField.wrap.appendChild(applyTitleBtn);
        var altField = previewField(mpcText("Alt text"), current.alt, data.alt_text || '', true);
        var applyAlt = document.createElement('button');
        applyAlt.type = 'button';
        applyAlt.className = 'button button-small';
        applyAlt.textContent = mpcText("Apply alt text");
        applyAlt.addEventListener('click', function () { applyAltText(btn, altField.wrap, altField.input.value, applyAlt).catch(showActionError); });
        altField.wrap.appendChild(applyAlt);
        grid.append(fileField.wrap, titleField.wrap, altField.wrap);
        ['caption', 'description'].forEach(function(key) {
            var proposed = data[key] || '';
            if (!proposed) return;
            var field = previewField(mpcText(key === 'caption' ? 'Caption' : 'Description'), current[key], proposed, true);
            var apply = document.createElement('button');
            apply.type = 'button'; apply.className = 'button button-small';
            apply.textContent = mpcText(key === 'caption' ? 'Apply caption' : 'Apply description');
            apply.addEventListener('click', function() { applyMediaText(btn, field.wrap, field.input.value, apply, key).catch(showActionError); });
            field.wrap.appendChild(apply); grid.appendChild(field.wrap);
        });
        var summary = document.createElement('div');
        summary.className = 'lucidlab-mpc-ai-summary';
        var title = document.createElement('strong');
        title.textContent = data.filename ? 'Suggestion: ' + data.filename : mpcText("No new visual suggestion");
        summary.appendChild(title);
        var meta = document.createElement('span');
        meta.className = 'lucidlab-mpc-hint';
        var cleanup = data.cleanup_type ? String(data.cleanup_type).charAt(0).toUpperCase() + String(data.cleanup_type).slice(1) : mpcText("Review");
        var confidence = Number(data.confidence);
        var confidenceText = confidence > 0 ? mpcText("Confidence ") + confidence.toFixed(2) : (data.status || mpcText("Review required"));
        meta.textContent = ' \u00B7 ' + cleanup + ' \u00B7 ' + confidenceText;
        summary.appendChild(meta);
        var reasons = Array.isArray(data.reasons) ? data.reasons.filter(function(value) { return !!value && String(value).trim() !== ''; }) : [];
        if (reasons.length) {
            var reasonText = document.createElement('div');
            reasonText.className = 'lucidlab-mpc-hint';
            var firstReasons = reasons.slice(0, 2);
            reasonText.textContent = 'Why: ' + firstReasons.join(' \u00B7 ') + (reasons.length > firstReasons.length ? ' \u00B7 \u2026' : '');
            summary.appendChild(reasonText);
        }
        host.append(summary, grid);
        if (id) {
            var source = document.querySelector('tr[data-ai-row="'+id+'"]');
            if (source) {
                source.setAttribute('data-ai-suggest-filename', data.filename || '');
                source.setAttribute('data-ai-suggest-title', data.title || data.suggested_title || '');
                source.setAttribute('data-ai-suggest-alt', data.alt_text || '');
            }
        }
    }
    function renderAiSuggestion(btn, data) {
        var status=getAiStatusElements(btn), nameOut=status.nameOut, altOut=status.altOut;
        var minConfidence = Number(LucidLabMPC && LucidLabMPC.aiMinConfidence !== undefined ? LucidLabMPC.aiMinConfidence : 0.65);
        var confidence = Number(data && typeof data.confidence === 'number' ? data.confidence : 0);
        if (btn && data && data.fingerprint) btn.dataset.aiFingerprint = data.fingerprint;
        nameOut.textContent='';
        altOut.replaceChildren();

        if (confidence > 0 && confidence < minConfidence) {
            nameOut.textContent = mpcText("AI suggestion hidden below confidence threshold (") + confidence.toFixed(2) + ' < ' + minConfidence.toFixed(2) + mpcText("). Review this image manually.");
            return;
        }

        nameOut.textContent = mpcText("Suggestion ready — see the row below.");
        var tr = libraryRowFor(btn && btn.getAttribute('data-attachment-id'), btn);
        renderAiResultRow(tr, data, btn);
        upsertAiResult(suggestionToRow(btn.getAttribute('data-attachment-id'), data, btn));
    }
    function suggestionToRow(id, data, btn) {
        var tr = btn && btn.closest ? btn.closest('tr') : null;
        var img = tr && tr.querySelector('img');
        return {
            id: Number(id) || 0,
            title: (data && data.title) || '',
            current: (data && data.current) || (tr && tr.getAttribute('data-ai-current')) || '',
            current_title: (data && data.current_title) || (tr && tr.getAttribute('data-ai-current-title')) || '',
            current_alt: (data && data.current_alt) || (tr && tr.getAttribute('data-ai-current-alt')) || '',
            thumb: img ? img.src : '',
            edit: '',
            filename: (data && data.filename) || '',
            suggested_title: (data && data.title) || '',
            alt_text: (data && data.alt_text) || '',
            caption: (data && data.caption) || '',
            description: (data && data.description) || '',
            current_caption: (data && data.current_caption) || '',
            current_description: (data && data.current_description) || '',
            error: (data && data.error) || '',
            stale: false,
            time_label: mpcText("Just now"),
            status: (data && data.error) || mpcText("Saved · review required"),
            can_review: !(data && data.error),
            analyze_label: (data && data.error) ? mpcText("Retry analysis") : mpcText("Analyze again"),
            reason: (data && data.error) || mpcText("From AI analysis")
        };
    }
    function setCellText(node, text, asCode) {
        if (!node) return;
        node.replaceChildren();
        if (asCode) {
            var code = document.createElement('code');
            code.textContent = text || '';
            node.appendChild(code);
            return;
        }
        node.textContent = text || '';
    }
    function reviewSuggestionCell(row) {
        var td = document.createElement('td');
        td.dataset.aiCell = 'suggestion';
        var code = document.createElement('code');
        code.textContent = row.filename || '';
        td.appendChild(code);
        if (row.suggested_title) {
            var p = document.createElement('p');
            p.textContent = 'Title: ' + row.suggested_title;
            td.appendChild(p);
        }
        if (row.alt_text) {
            var alt = document.createElement('p');
            alt.textContent = 'Alt: ' + row.alt_text;
            td.appendChild(alt);
        }
        ['caption', 'description'].forEach(function(key) { if (row[key]) { var p = document.createElement('p'); p.textContent = mpcText(key === 'caption' ? 'Caption:' : 'Description:') + ' ' + row[key]; td.appendChild(p); } });
        return td;
    }
    function reviewAnalysisCell(row) {
        var td = document.createElement('td');
        td.dataset.aiCell = 'analysis';
        td.appendChild(document.createTextNode(row.time_label || ''));
        var p = document.createElement('p');
        p.textContent = row.status || '';
        td.appendChild(p);
        return td;
    }
    function reviewImageCell(row) {
        var td = document.createElement('td');
        td.dataset.aiCell = 'image';
        if (row.thumb) {
            var img = document.createElement('img');
            img.src = row.thumb;
            img.alt = '';
            img.width = 64;
            img.height = 64;
            td.appendChild(img);
            td.appendChild(document.createElement('br'));
        }
        td.appendChild(document.createTextNode('#' + row.id + (row.title ? ' ' + row.title : '')));
        if (row.edit) {
            td.appendChild(document.createTextNode(' '));
            var a = document.createElement('a');
            a.href = row.edit;
            a.target = '_blank';
            a.rel = 'noopener';
            a.textContent = mpcText("Edit media");
            td.appendChild(a);
        }
        return td;
    }
    function reviewActionsCell(row) {
        var td = document.createElement('td');
        td.dataset.aiCell = 'actions';
        var analyzeWrap = document.createElement('div');
        var analyze = document.createElement('button');
        analyze.type = 'button';
        analyze.className = 'button';
        analyze.dataset.mpcAiAnalyze = '1';
        analyze.setAttribute('data-attachment-id', String(row.id));
        analyze.textContent = row.analyze_label || mpcText("Analyze again");
        var analyzeStatus = document.createElement('span');
        analyzeStatus.className = 'lucidlab-mpc-ai-status';
        analyzeStatus.setAttribute('role', 'status');
        analyzeWrap.append(analyze, analyzeStatus);
        td.appendChild(analyzeWrap);
        if (row.can_review) {
            var reviewWrap = document.createElement('div');
            var reviewBtn = document.createElement('button');
            reviewBtn.type = 'button';
            reviewBtn.className = 'button';
            reviewBtn.dataset.aiSaved = '1';
            reviewBtn.setAttribute('data-attachment-id', String(row.id));
            reviewBtn.textContent = mpcText("Review saved suggestion");
            var reviewStatus = document.createElement('span');
            reviewStatus.className = 'lucidlab-mpc-ai-status';
            reviewStatus.setAttribute('role', 'status');
            reviewWrap.append(reviewBtn, reviewStatus);
            td.appendChild(reviewWrap);
        }
        return td;
    }
    function upsertAiResult(row) {
        if (!row || !row.id) return;
        var review = el('#lucidlab-mpc-ai-cleanup');
        if (review && review.tagName === 'DETAILS' && !upsertAiResult.opened) {
            review.open = true;
            upsertAiResult.opened = true;
        }
        var body = el('[data-ai-results]');
        var empty = el('.lucidlab-mpc-ai-results-empty');
        if (body) {
            if (empty) empty.hidden = true;
            var tr = body.querySelector('tr[data-ai-row="'+row.id+'"]');
            if (!tr) {
                tr = document.createElement('tr');
                tr.dataset.aiRow = String(row.id);
                tr.append(reviewImageCell(row), reviewSuggestionCell(row), reviewAnalysisCell(row), reviewActionsCell(row));
                body.prepend(tr);
                bindAiRowButtons(tr);
            } else {
                var suggestion = tr.querySelector('[data-ai-cell="suggestion"]');
                var analysis = tr.querySelector('[data-ai-cell="analysis"]');
                if (suggestion) suggestion.replaceWith(reviewSuggestionCell(row));
                if (analysis) analysis.replaceWith(reviewAnalysisCell(row));
                var actions = tr.querySelector('[data-ai-cell="actions"]');
                if (actions) { actions.replaceWith(reviewActionsCell(row)); bindAiRowButtons(tr); }
            }
        }
        updateAssistantRow(row);
    }
    function updateAssistantRow(row) {
        var table = el('.lucidlab-mpc-ai-panel table tbody');
        if (!table) return;
        var tr = table.querySelector('tr[data-ai-row="'+row.id+'"]');
        if (!tr) return;
        if (row.filename) setCellText(tr.querySelector('[data-ai-cell="filename"]'), row.filename, true);
        if (row.suggested_title) setCellText(tr.querySelector('[data-ai-cell="title"]'), row.suggested_title);
        if (row.alt_text) {
            var altCell = tr.querySelector('[data-ai-cell="alt"]');
            if (altCell) {
                altCell.replaceChildren();
                var span = document.createElement('span');
                span.className = 'lucidlab-mpc-status-ok';
                span.title = row.alt_text;
                span.textContent = row.alt_text;
                altCell.appendChild(span);
            }
        }
        if (row.reason) setCellText(tr.querySelector('[data-ai-cell="reason"]'), row.reason);
        var btn = tr.querySelector('[data-mpc-ai-analyze]');
        if (btn && (row.filename || row.suggested_title || row.alt_text || row.error)) {
            if (row.fingerprint) btn.dataset.aiFingerprint = row.fingerprint;
            renderAiResultRow(tr, {
                filename: row.filename,
                title: row.suggested_title || row.title,
                alt_text: row.alt_text, caption: row.caption, description: row.description,
                current_caption: row.current_caption, current_description: row.current_description,
                current: row.current,
                current_title: row.current_title,
                current_alt: row.current_alt,
                fingerprint: row.fingerprint,
                status: row.status,
                error: row.error,
                reasons: row.reason ? [row.reason] : [],
                confidence: 1
            }, btn);
        }
    }
    function bindAiRowButtons(root) {
        var scope = root || document;
        els('[data-mpc-ai-analyze]', scope).forEach(function(btn) {
            if (btn.dataset.bound) return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', function() { analyzeImage(btn); });
        });
        els('[data-ai-saved]', scope).forEach(function(btn) {
            if (btn.dataset.bound) return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', async function() {
                btn.disabled = true;
                try {
                    var r = await post({op:'ai_saved_result',tab:'rename_ai',attachment_id:btn.getAttribute('data-attachment-id')});
                    if (!r || !r.success) throw Error(r && r.data && r.data.message || mpcText("Saved result unavailable."));
                    renderAiSuggestion(btn, r.data);
                } catch (e) { getAiStatusElements(btn).nameOut.textContent = e.message; }
                finally { btn.disabled = false; }
            });
        });
    }
	function analyzeImage( btn ) {
		var id = Number( btn.getAttribute( 'data-attachment-id' ) ) || 0;
		var status = getAiStatusElements( btn ), nameOut = status.nameOut, altOut = status.altOut;
		var running = knownRunningJobs.filter( function ( job ) { return job.special === 'ai' && [ 'running', 'paused' ].includes( job.status ); } )[0];
		altOut.textContent = '';
		if ( id && ! running && ! ( aiPump.state && [ 'running', 'paused' ].includes( aiPump.state.status ) ) ) {
			nameOut.textContent = mpcText("Analyzing… This continues if you leave this page.");
			btn.disabled = true;
			aiPump.command = 'start';
			aiPump.startIds = [ id ];
			aiPump.focusId = id;
			pendingRunningJobs['ai-starting'] = { key: 'ai-starting', pending: true, status: 'running', panel: 'rename_ai', scan_tab: 'rename_ai', special: 'ai', scope: 'selected', label: mpcText("Starting image analysis") };
			ensureRunningJobOrder( pendingRunningJobs['ai-starting'] );
			renderRunningJobs( knownRunningJobs );
			scrollToRunningJobs();
			ensureAiPump();
			return;
		}
		var started = Date.now();
		var controller = ( typeof AbortController === 'function' ) ? new AbortController() : null;
		var tick, kill;
		function clock() {
			var s = Math.max( 1, Math.round( ( Date.now() - started ) / 1000 ) );
			nameOut.textContent = 'Analyzing\u2026 ' + s + mpcText("s. Vision models can take 1–3 minutes, especially the first run on CPU.");
		}
		function stopClock() {
			clearInterval( tick );
			if ( kill ) { clearTimeout( kill ); }
		}
		btn.disabled = true;
		clock();
		tick = setInterval( clock, 1000 );
		if ( controller ) {
			kill = setTimeout( function () { controller.abort(); }, 200000 );
		}
		return post( { op: 'ai_analyze', tab: 'rename_ai', attachment_id: String( id ) }, { signal: controller && controller.signal } ).then( function ( r ) {
			if ( ! r || ! r.success ) { throw new Error( r && r.data && r.data.message || mpcText("AI analysis failed") ); }
            renderAiSuggestion(btn, r.data);
		} ).catch( function ( e ) {
			nameOut.textContent = ( e && e.name === 'AbortError' )
				? mpcText("Analyze timed out after 200s. qwen2.5vl is slow on CPU; try qwen2.5vl:3b, confirm ollama ps shows the model, and Analyze again.")
				: ( ( e && e.message ) || mpcText("AI analysis failed") );
		} ).finally( function () { stopClock(); btn.disabled = false; } );
	}
	bindAiRowButtons();
	els('tr[data-ai-suggest-filename]').forEach(function(tr) {
		var btn = tr.querySelector('[data-mpc-ai-analyze]');
		if (!btn) return;
		if (tr.getAttribute('data-ai-fingerprint')) btn.dataset.aiFingerprint = tr.getAttribute('data-ai-fingerprint');
		renderAiResultRow(tr, {
			filename: tr.getAttribute('data-ai-suggest-filename') || '',
			title: tr.getAttribute('data-ai-suggest-title') || '',
			alt_text: tr.getAttribute('data-ai-suggest-alt') || '',
			current: tr.getAttribute('data-ai-current') || '',
			current_title: tr.getAttribute('data-ai-current-title') || '',
			current_alt: tr.getAttribute('data-ai-current-alt') || '',
			fingerprint: tr.getAttribute('data-ai-fingerprint') || '',
			confidence: 1
		}, btn);
	});
    function aiJobItem(job) {
        return Object.assign({}, job, {
            key: 'lucidlab_mpc_ai_library',
            panel: 'rename_ai',
            scan_tab: 'rename_ai',
            special: 'ai',
            progress: job.total ? Math.min(100, 100 * (job.processed || 0) / job.total) : (Number(job.progress) || 0),
            message: job.label || job.message || ''
        });
    }
    function syncAiRunningJob(job) {
        if (aiPump.command === 'start' && !['running', 'paused'].includes(job.status)) return;
        if (!['running', 'paused'].includes(job.status)) {
            delete pendingRunningJobs['ai-starting'];
            var focusId = Number(aiPump.focusId || 0);
            els('[data-mpc-ai-analyze]').forEach(function(btn) {
                if (Number(btn.getAttribute('data-attachment-id')) === focusId) {
                    btn.disabled = false;
                    var statusEls = getAiStatusElements(btn);
                    if (statusEls.nameOut && /Analyzing/i.test(statusEls.nameOut.textContent || '')) {
                        statusEls.nameOut.textContent = job.status === 'done' ? mpcText("Suggestion ready — see the row below.") : (job.status === 'cancelled' ? mpcText("Cancelled.") : '');
                    }
                }
            });
            if (focusId && job.status === 'done') {
                var renameAiRow = document.querySelector('[data-finished-source="rename_ai"]');
                if (renameAiRow) {
                    var stamp = Number(job.updated || Date.now() / 1000);
                    renameAiRow.dataset.finishedUpdated = stamp;
                    paintFinishedJob(renameAiRow, 'done', mpcT('completedOnPrefix', 'Completed on') + ' ' + formatJobDate(stamp, job.updated_label));
                }
            }
            aiPump.focusId = 0;
            renderRunningJobs(knownRunningJobs.filter(function(item){ return item.key !== 'lucidlab_mpc_ai_library'; }));
            return;
        }
        var item = aiJobItem(job);
        var pendingOrder = runningJobOrder['ai-starting'];
        delete pendingRunningJobs['ai-starting'];
        ensureRunningJobOrder(item, pendingOrder);
        renderRunningJobs(knownRunningJobs.concat([item]));
    }
    function drawAiJob(job) {
        aiPump.state = job;
        var status = el('[data-ai-job-status]');
        var usage = el('[data-ai-job-usage]');
        var stamp = job.updated ? mpcText(" · Last update: ") + (job.updated_label || formatJobDate(job.updated)) : '';
        var terminal = ['done', 'failed', 'cancelled'].includes(job.status);
        if (status) {
            if (aiPump.command === 'start' && !['running', 'paused'].includes(job.status)) status.textContent = mpcText("Starting image analysis…");
            else status.textContent = terminal ? (job.status === 'cancelled' ? mpcText("Cancelled. Saved suggestions are retained.") : job.status === 'done' ? mpcText("Completed. Saved suggestions are available for review.") : (job.label || mpcText("AI review stopped.")) + '.') : (job.status || mpcText("Ready")) + ' \u00B7 ' + (job.label || mpcText("No library job started.")) + stamp;
            if (job.driver === 'cron' && job.status === 'running') status.textContent += ' \u00B7 Background: ' + (job.next_cron ? mpcText("next tick ") + new Date(job.next_cron * 1000).toLocaleTimeString() : mpcText("no cron event scheduled"));
            if (job.driver === 'cron' && job.status === 'running' && job.updated && Date.now() / 1000 - job.updated > 300) status.textContent += mpcText(" · No progress for over five minutes. Check WP-Cron or press Resume in Current Running Jobs.");
        }
        if (usage) {
            var u = job.usage || {};
            usage.textContent = 'Today (UTC): ' + (u.requests || 0) + ' / ' + (u.limit || 0) + mpcText(" requests · estimated hosted cost: ") + Number(u.estimate || 0).toFixed(4) + (u.budget ? ' / ' + u.budget : '');
        }
        els('[data-ai-job]').forEach(function(btn) {
            var a = btn.dataset.aiJob, active = ['running', 'paused'].includes(job.status);
            btn.disabled = !!aiPump.command || ((a === 'start' || a === 'start-selected') && active);
        });
        if (job.latest) {
            var latestKey = String(job.latest.id || '') + ':' + (job.latest.time_label || '') + ':' + (job.processed || '');
            if (latestKey !== drawAiJob.latestKey) {
                drawAiJob.latestKey = latestKey;
                upsertAiResult(job.latest);
                if (aiPump.command !== 'start' && aiPump.focusId && Number(job.latest.id) === Number(aiPump.focusId)) {
                    var focusBtn = document.querySelector('[data-mpc-ai-analyze][data-attachment-id="'+aiPump.focusId+'"]');
                    if (focusBtn) {
                        getAiStatusElements(focusBtn).nameOut.textContent = job.latest.error || mpcText("Suggestion ready — see the row below.");
                        focusBtn.disabled = false;
                    }
                }
            }
        } else if (terminal && aiPump.focusId && job.status === 'done') {
            var pendingFocus = aiPump.focusId;
            var pendingBtn = document.querySelector('[data-mpc-ai-analyze][data-attachment-id="'+pendingFocus+'"]');
            if (pendingBtn) {
                post({ op: 'ai_saved_result', tab: 'rename_ai', attachment_id: String(pendingFocus) }).then(function (r) {
                    if (r && r.success) { renderAiSuggestion(pendingBtn, r.data); }
                    else { getAiStatusElements(pendingBtn).nameOut.textContent = mpcText("Suggestion ready — see the row below."); }
                }).catch(function () {
                    getAiStatusElements(pendingBtn).nameOut.textContent = mpcText("Suggestion ready — see the row below.");
                }).finally(function () { pendingBtn.disabled = false; });
            }
        }
        syncAiRunningJob(job);
    }
    async function pumpAiJob() {
        if (aiPump.pumping) return;
        aiPump.pumping = true;
        clearTimeout(aiPump.timer);
        var commanding = runningCommands['lucidlab_mpc_ai_library'];
        var action = aiPump.command || (commanding ? 'status' : (aiPump.state.status === 'running' && aiPump.state.driver === 'ajax' ? 'step' : 'status'));
        var status = el('[data-ai-job-status]');
        if (status && (action === 'step' || action === 'cron')) status.textContent += mpcText(" · Analyzing next image…");
        try {
            var payload = { op: 'ai_job', tab: 'rename_ai', sub: action };
            if (action === 'start' && Array.isArray(aiPump.startIds) && aiPump.startIds.length) {
                payload.ids = JSON.stringify(aiPump.startIds);
            }
            var r = await post(payload);
            if (!r || !r.success) throw Error(r && r.data && r.data.message || mpcText("AI job request failed."));
            if (r.data.busy) {
                if (status) status.textContent = r.data.message;
            } else {
                if (aiPump.command === action) aiPump.command = null;
                drawAiJob(r.data);
            }
        } catch (e) {
            var failedStart = aiPump.command === 'start';
            aiPump.command = null;
            aiPump.state.driver = 'unknown';
            if (failedStart && aiPump.focusId) {
                var focusBtn = document.querySelector('[data-mpc-ai-analyze][data-attachment-id="'+aiPump.focusId+'"]');
                if (focusBtn) {
                    getAiStatusElements(focusBtn).nameOut.textContent = (e && e.message) || mpcText("AI analysis failed");
                    focusBtn.disabled = false;
                }
                aiPump.focusId = 0;
                delete pendingRunningJobs['ai-starting'];
            }
            if (status) status.textContent = e.message + mpcText(" Checking saved progress…");
        } finally {
            aiPump.pumping = false;
            var active = aiPump.command || ['running', 'paused'].includes(aiPump.state.status) || !!runningCommands['lucidlab_mpc_ai_library'];
            if (!active && !el('#lucidlab-mpc-ai-job')) {
                aiPump.timer = null;
                return;
            }
            aiPump.timer = setTimeout(pumpAiJob, aiPump.command ? 1500 : (aiPump.state.status === 'running' ? 2000 : 10000));
        }
    }
    function ensureAiPump() {
        if (aiPump.pumping) return;
        if (aiPump.timer) {
            clearTimeout(aiPump.timer);
            aiPump.timer = null;
        }
        pumpAiJob();
    }
    function aiLibraryInit() {
        var box = el('#lucidlab-mpc-ai-job');
        if (box && !box.dataset.ready) {
            box.dataset.ready = '1';
            if (box.classList.contains('lucidlab-mpc-ai-job-status-card')) {
                box.classList.remove('lucidlab-mpc-ai-job-status-card');
                els('[data-ai-job]', box).forEach(function (button) { button.remove(); });
            }
        }
        var status = el('[data-ai-job-status]');
        els('[data-ai-job]').forEach(function(btn) {
            if (btn.dataset.aiJobBound) return;
            btn.dataset.aiJobBound = '1';
            btn.addEventListener('click', async function() {
                var a = btn.dataset.aiJob;
                aiPump.startIds = [];
                if (a === 'start-selected') {
                    var ids = els('.lucidlab-mpc-ai-select:checked').map(function(box) { return Number(box.value) || 0; }).filter(Boolean);
                    if (!ids.length) {
                        if (status) status.textContent = mpcText("Select one or more images in the library list below.");
                        return;
                    }
                    if (!await inlineConfirm(mpcText("Analyze ") + ids.length + mpcText(" selected image(s) using the saved AI provider? Hosted requests may incur charges, within your configured limits. Results are saved for manual review."), btn)) return;
                    aiPump.startIds = ids;
                    a = 'start';
                } else if (a === 'start' && !await inlineConfirm(mpcText("Analyze every library image using the saved AI provider? Hosted requests may incur charges, within your configured limits. Results are saved for manual review and appear in the lists as each image finishes."), btn)) return;
                aiPump.command = a;
                if (status) status.textContent = a === 'start' ? (aiPump.startIds.length ? mpcText("Starting analysis of ") + aiPump.startIds.length + mpcText(" selected image(s)…") : mpcText("Starting library analysis…")) : mpcText("Sending command…");
                els('[data-ai-job]').forEach(function(b) { b.disabled = true; });
                if (a === 'start') {
                    pendingRunningJobs['ai-starting'] = { key: 'ai-starting', pending: true, status: 'running', panel: 'rename_ai', scan_tab: 'rename_ai', special: 'ai', scope: aiPump.startIds.length ? 'selected' : 'library', label: aiPump.startIds.length ? mpcText("Starting selected image analysis") : mpcText("Starting AI library analysis") };
                    ensureRunningJobOrder(pendingRunningJobs['ai-starting']);
                    renderRunningJobs(knownRunningJobs);
                    scrollToRunningJobs();
                    upsertAiResult.opened = false;
                }
                ensureAiPump();
            });
        });
        els('[data-ai-select-all]').forEach(function(box) {
            if (box.dataset.bound) return;
            box.dataset.bound = '1';
            box.addEventListener('change', function() {
                var table = box.closest('table');
                els('.lucidlab-mpc-ai-select', table || document).forEach(function(rowBox) { rowBox.checked = box.checked; });
            });
        });
        var resume = initialJobs.filter(function(job) { return job.special === 'ai' && ['running', 'paused'].includes(job.status); })[0];
        if (resume) aiPump.state = resume;
        if (resume || box || aiPump.command) ensureAiPump();
    }
    function aiApplyFlags() {
        return {
            filename: els('.lucidlab-mpc-ai-apply-filename').some(function(box){ return box.checked; }),
            title: els('.lucidlab-mpc-ai-apply-title').some(function(box){ return box.checked; }),
            alt: els('.lucidlab-mpc-ai-apply-alt').some(function(box){ return box.checked; }),
            caption: els('.lucidlab-mpc-ai-apply-caption').some(function(box){ return box.checked; }),
            description: els('.lucidlab-mpc-ai-apply-description').some(function(box){ return box.checked; })
        };
    }
    function syncAiApplyFlags(source) {
        ['filename','title','alt','caption','description'].forEach(function(key) {
            if (!source.classList.contains('lucidlab-mpc-ai-apply-'+key)) return;
            els('.lucidlab-mpc-ai-apply-'+key).forEach(function(box){ box.checked = source.checked; });
        });
    }
    els('.lucidlab-mpc-ai-apply-filename, .lucidlab-mpc-ai-apply-title, .lucidlab-mpc-ai-apply-alt, .lucidlab-mpc-ai-apply-caption, .lucidlab-mpc-ai-apply-description').forEach(function(box) {
        box.addEventListener('change', function(){ syncAiApplyFlags(box); });
    });
    function aiRenameAllInit() {
        var buttons=els('.lucidlab-mpc-ai-rename-all');
        if (!buttons.length || LucidLabMPC.tab!=='rename_ai') return;
        function setStatus(text) {
            els('.lucidlab-mpc-ai-rename-all-status').forEach(function(node){ node.textContent=text || ''; });
        }
        buttons.forEach(function(btn) {
            if (btn.dataset.ready) return;
            btn.dataset.ready='1';
            btn.addEventListener('click', async function() {
                var flags=aiApplyFlags();
                if (!flags.filename && !flags.title && !flags.alt && !flags.caption && !flags.description) {
                    setStatus(mpcText("Choose filename, title and/or alt text before applying."));
                    return;
                }
                buttons.forEach(function(b){ b.disabled=true; });
                setStatus(mpcText("Looking up saved suggestions…"));
                try {
                    var r=await post({op:'ai_saved_rename_ids',tab:'rename_ai'});
                    var items=(r && r.data && r.data.items) || [];
                    items=items.filter(function(item){
                        return (flags.filename && item.filename) || (flags.title && item.title) || (flags.alt && item.alt_text) || (flags.caption && item.caption) || (flags.description && item.description);
                    });
                    if (!items.length) {
                        setStatus(mpcText("No saved AI suggestions match the selected fields. Analyze images first."));
                        return;
                    }
                    var parts=[];
                    if (flags.filename) parts.push('filenames');
                    if (flags.title) parts.push('titles');
                    if (flags.alt) parts.push(mpcText("alt text"));
                    if (flags.caption) parts.push(mpcText("caption"));
                    if (flags.description) parts.push(mpcText("description"));
                    var samples=items.slice(0,8).map(function(item){
                        var bits=['#'+item.id+(item.current?' '+item.current:'')];
                        if (flags.filename && item.filename) bits[0]+=' \u2192 '+item.filename;
                        if (flags.title && item.title) bits.push('Title: '+item.title);
                        if (flags.alt && item.alt_text) bits.push('Alt: '+item.alt_text);
                        if (flags.caption && item.caption) bits.push(mpcText('Caption:')+' '+item.caption);
                        if (flags.description && item.description) bits.push(mpcText('Description:')+' '+item.description);
                        return { label: bits.join(' \u00B7 ') };
                    });
                    if (!await inlineConfirm(mpcText("Apply saved AI ")+parts.join(', ')+mpcText(" to ")+items.length+mpcText(" image(s)? Filename changes files on disk. Undo individual names on the Rename Images tab."), btn, { skip_note: true, skip_meta: true, samples: samples, more: Math.max(0, items.length-8) })) {
                        setStatus('');
                        return;
                    }
                    var ok=0, skip=0, fail=0;
                    for (var i=0;i<items.length;i++) {
                        setStatus(mpcText("Applying ")+(i+1)+' / '+items.length+'\u2026');
                        try {
                            var oneOk=false, oneSkip=true;
                            if (flags.title && items[i].title) {
                                var titled=await post({op:'ai_apply_title',tab:'rename_ai',attachment_id:String(items[i].id),title:items[i].title,ai_fingerprint:items[i].fingerprint||''});
                                if (!titled || !titled.success) throw new Error(mpcText("Title update failed"));
                                oneOk=true; oneSkip=false;
                            }
                            if (flags.alt && items[i].alt_text) {
                                var alted=await post({op:'ai_apply_alt',tab:'rename_ai',attachment_id:String(items[i].id),alt_text:items[i].alt_text,ai_fingerprint:items[i].fingerprint||''});
                                if (!alted || !alted.success) throw new Error(mpcText("Alt text update failed"));
                                if (alted.data && alted.data.skipped) { /* unchanged */ }
                                else { oneOk=true; oneSkip=false; }
                            }
                            for (var field of ['caption', 'description']) {
                                if (!flags[field] || !items[i][field]) continue;
                                var changed = await post({op:'ai_apply_'+field,tab:'rename_ai',attachment_id:String(items[i].id),[field]:items[i][field],ai_fingerprint:items[i].fingerprint||''});
                                if (!changed || !changed.success) throw new Error(mpcText('Image text update failed'));
                                oneOk=true; oneSkip=false;
                            }
                            if (flags.filename && items[i].filename) {
                                var one=await post({op:'ai_apply_filename',tab:'rename_ai',attachment_id:String(items[i].id),filename:items[i].filename,ai_fingerprint:items[i].fingerprint||''});
                                if (one && one.success) {
                                    if (one.data && one.data.skipped) { /* unchanged */ }
                                    else { oneOk=true; oneSkip=false; }
                                } else { throw new Error(mpcText("Rename failed")); }
                            }
                            if (oneOk) ok++;
                            else if (oneSkip) skip++;
                            else fail++;
                        } catch (e) { fail++; }
                    }
                    setStatus(mpcText("Apply finished: ")+ok+mpcText(" updated, ")+skip+mpcText(" unchanged, ")+fail+mpcText(" failed."));
                } catch (e) {
                    setStatus(e.message);
                } finally { buttons.forEach(function(b){ b.disabled=false; }); }
            });
        });
    }
    aiLibraryInit();
    aiRenameAllInit();
    if (location.hash==='#lucidlab-mpc-ai-cleanup') {
        var review=el('#lucidlab-mpc-ai-cleanup');
        if (review && review.tagName==='DETAILS') review.open=true;
    }
    document.addEventListener('mpc:tabshown',function(){aiLibraryInit(); aiRenameAllInit();});
	function aiThumbnailInit(){
		if(LucidLabMPC.tab!=='rename_ai')return;
		els('.lucidlab-mpc-ai-panel [data-mpc-ai-analyze]').forEach(function(button){
			var row=button.closest('tr'),cell=row&&row.cells[1],id=button.getAttribute('data-attachment-id');
			if(!cell||!id||cell.querySelector('.lucidlab-mpc-ai-thumb')||!LucidLabMPC.restUrl)return;
			fetch(LucidLabMPC.restUrl+encodeURIComponent(id)+'?_fields=source_url,alt_text',{credentials:'same-origin'}).then(function(response){return response.ok?response.json():null;}).then(function(media){if(!media||!media.source_url||cell.querySelector('.lucidlab-mpc-ai-thumb'))return;var image=document.createElement('img');image.className='lucidlab-mpc-ai-thumb';image.src=media.source_url;image.alt=media.alt_text||'';image.loading='lazy';var wrap=document.createElement('div');wrap.className='lucidlab-mpc-ai-image-cell';while(cell.firstChild)wrap.appendChild(cell.firstChild);wrap.prepend(image);cell.appendChild(wrap);}).catch(function(){});
		});
	}
	aiThumbnailInit();
	document.addEventListener('mpc:tabshown',function(){aiThumbnailInit();});
	var aiLocale=el('[data-mpc-ai-locale]'),aiLocaleOther=el('[data-mpc-ai-locale-other]');
	if(aiLocale&&aiLocaleOther){var syncAiLocale=function(focus){var other=aiLocale.value==='__other';aiLocaleOther.hidden=!other;aiLocaleOther.disabled=!other;if(other&&focus)aiLocaleOther.focus();};aiLocale.addEventListener('change',function(){syncAiLocale(true);});syncAiLocale(false);}
	var aiProviderPresets={openai:{model:'gpt-4o-mini',endpoint:'https://api.openai.com/v1/chat/completions'},anthropic:{model:'claude-3-5-haiku-latest',endpoint:'https://api.anthropic.com/v1/messages'},local:{model:'llama3.2-vision',endpoint:'http://127.0.0.1:11434/api/chat'}};
	var aiProvider=el('[data-mpc-ai-provider]'),aiModel=el('[data-mpc-ai-model]'),aiEndpoint=el('[data-mpc-ai-endpoint]');
	if(aiProvider&&aiModel&&aiEndpoint){var initialModel=aiModel.value,initialEndpoint=aiEndpoint.value;aiProvider.addEventListener('change',function(){var preset=aiProviderPresets[aiProvider.value];if(!preset){return;}if(!aiModel.value||aiModel.value===initialModel||aiModel.dataset.mpcPreset===aiModel.value)aiModel.value=preset.model;if(!aiEndpoint.value||aiEndpoint.value===initialEndpoint||aiEndpoint.dataset.mpcPreset===aiEndpoint.value)aiEndpoint.value=preset.endpoint;aiModel.dataset.mpcPreset=preset.model;aiEndpoint.dataset.mpcPreset=preset.endpoint;});aiModel.addEventListener('input',function(){if(aiModel.value!==aiModel.dataset.mpcPreset)delete aiModel.dataset.mpcPreset;});aiEndpoint.addEventListener('input',function(){if(aiEndpoint.value!==aiEndpoint.dataset.mpcPreset)delete aiEndpoint.dataset.mpcPreset;});}
	els('[data-mpc-ai-test]').forEach(function(btn){btn.addEventListener('click',async function(){var out=btn.nextElementSibling,form=btn.closest('form');btn.disabled=true;out.textContent=mpcText("Testing…");try{var r=await post({op:'ai_test',tab:'options',ai_provider:el('[data-mpc-ai-provider]',form)?.value||'',ai_model:el('[data-mpc-ai-model]',form)?.value||'',ai_endpoint:el('[data-mpc-ai-endpoint]',form)?.value||'',ai_api_key:el('[data-mpc-ai-api-key]',form)?.value||''});out.textContent=r&&r.success?mpcText("Provider responded successfully."):(r&&r.data&&r.data.message||mpcText("Provider test failed."));}catch(e){out.textContent=e.message;}finally{btn.disabled=false;}});});
	var ais=el('#lucidlab-mpc-ai-search');if(ais)ais.addEventListener('input',function(){var q=ais.value.toLowerCase();els('.lucidlab-mpc-ai-panel tbody tr[data-ai-row]').forEach(function(row){var hide=!!(q&&row.textContent.toLowerCase().indexOf(q)===-1);row.hidden=hide;var extra=row.parentNode&&row.parentNode.querySelector('tr[data-ai-result-for="'+row.getAttribute('data-ai-row')+'"]');if(extra)extra.hidden=hide;});});
	function reviewBulkInit(){els('[data-mpc-review-quarantine]').forEach(function(btn){btn.addEventListener('click',async function(){var box=btn.closest('details'),ids=els('.lucidlab-mpc-review-item:checked',box).map(function(c){return c.value;});if(!ids.length){alert(mpcText("Select at least one finding."));return;}if(!await inlineConfirm(mpcText("Move the selected findings into restorable quarantine?"),btn))return;btn.disabled=true;var status=el('.lucidlab-mpc-review-status',box),stamp='',done=false;status.textContent=mpcText("Quarantining…");try{while(!done){var r=await post({op:'quarantine',tab:btn.getAttribute('data-mpc-review-quarantine'),panel:'review',stamp:stamp,items:JSON.stringify(ids)});if(!r||!r.success)throw Error(r&&r.data&&r.data.message||mpcText("Quarantine failed"));stamp=r.data.stamp;done=r.data.done;status.textContent=r.data.ok+mpcText(" moved, ")+r.data.fail+mpcText(" failed")+(done?' \u2014 finished':'\u2026');} }catch(e){status.textContent=e.message;}finally{btn.disabled=false;}});});}
	if(document.readyState!=='loading'){setTimeout(reviewBulkInit,0);}else{document.addEventListener("DOMContentLoaded",reviewBulkInit);}
	if ( document.readyState !== 'loading' ) { setTimeout( dupcInit, 0 ); } else { document.addEventListener( "DOMContentLoaded", dupcInit ); }

	/* Cleanup submissions retain the normal POST and show immediate feedback. */
		// Confirm undo in the page; embedded browsers may suppress native dialogs.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form.hasAttribute( 'data-mpc-undo-merge' ) && ! form.hasAttribute( 'data-mpc-restore' ) ) { return; }
        var undo = form.hasAttribute( 'data-mpc-undo-merge' );
		if ( form.getAttribute( 'data-undo-confirmed' ) === '1' ) { return; }
		event.preventDefault();
		form.setAttribute( 'data-undo-confirmed', '1' );
		var button = el( 'button', form );
		var original = button.innerHTML;
		button.textContent = undo ? mpcText("Confirm undo merge") : mpcText("Confirm restore");
		button.classList.add( 'lucidlab-mpc-confirm-restore' );
		var cancel = document.createElement( 'button' );
		cancel.type = 'button';
		cancel.className = 'button button-small';
		cancel.textContent = mpcText("Cancel");
		cancel.addEventListener( 'click', function () {
			form.removeAttribute( 'data-undo-confirmed' );
			button.innerHTML = original;
			button.classList.remove( 'lucidlab-mpc-confirm-restore' );
			cancel.remove();
		} );
		form.appendChild( cancel );
	}, true );

var purgeBusy = false, purgeButtons = [], purgeNotice = null, missingPurgeBusy = false;
	function quarantineRestoreControls() {
		return els( 'form[data-mpc-restore] button, form[data-mpc-undo-merge] button' );
	}
	/** Replace the confirmation controls with a visible status while a restore runs. */
	function setQuarantineRestoreBusy( busy, activeButton, undoMerge ) {
		var activeForm = activeButton && activeButton.form;
		quarantineRestoreControls().forEach( function ( btn ) {
			btn.disabled = !! busy;
			if ( ! busy || btn.form === activeForm ) { btn.hidden = !! busy; }
		} );
		if ( ! activeForm ) {
			if ( ! busy ) { els( '.lucidlab-mpc-restore-status' ).forEach( function ( status ) { status.remove(); } ); }
			return;
		}
		var status = activeForm.querySelector( '.lucidlab-mpc-restore-status' );
		if ( ! busy ) { if ( status ) { status.remove(); } return; }
		if ( status ) { return; }
		status = document.createElement( 'span' );
		status.className = 'lucidlab-mpc-restore-status';
		status.setAttribute( 'role', 'status' );
		var spinner = document.createElement( 'span' );
		spinner.className = 'spinner is-active';
		spinner.setAttribute( 'aria-hidden', 'true' );
		status.append( spinner, document.createTextNode( undoMerge ? mpcText("Quarantine — Undo merge") + '…' : mpcText("Restoring ").trim() + '…' ) );
		activeForm.appendChild( status );
	}
	function missingPurgeControls() {
		return els( 'input[name="lucidlab_mpc_op"][value="purge_missing"]' ).map( function ( input ) {
			return input.form ? input.form.querySelector( 'button' ) : null;
		} ).filter( Boolean );
	}
	/** Spinner replaces the clicked Purge media record; Purge All and other row purges wait. */
	function setMissingPurgeBusy( busy, activeButton ) {
		missingPurgeControls().forEach( function ( btn ) {
			if ( busy ) {
				btn.disabled = true;
				if ( btn === activeButton ) {
					buttonBusy( btn, true );
					btn.hidden = true;
				}
			} else {
				buttonBusy( btn, false );
				btn.disabled = false;
				btn.hidden = false;
			}
		} );
	}
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var op = el( 'input[name="lucidlab_mpc_op"]', form );
		if ( ! op || [ 'purge_quarantine', 'purge_source', 'clean_empty', 'restore_quarantine', 'restore_one', 'undo_merge' ].indexOf( op.value ) === -1 ) { return; }
		// Inline confirmation runs before this bubbling listener; cancellation stays idle.
		if ( event.defaultPrevented ) { return; }
		if ( purgeBusy ) { event.preventDefault(); return; }
        event.preventDefault();purgeBusy=true;
        var params={op:'form',sub_action:op.value,panel:(form.closest('[data-mpc-panel]')||{}).dataset?.mpcPanel||LucidLabMPC.tab};
        ['lucidlab_mpc_set','lucidlab_mpc_type','lucidlab_mpc_key','lucidlab_mpc_source'].forEach(function(key){var field=form.querySelector('[name="'+key+'"]');if(field)params[key]=field.value;});
        if(!params.lucidlab_mpc_source){var block=form.closest('[data-mpc-source]');if(block)params.lucidlab_mpc_source=block.getAttribute('data-mpc-source')||'';}
        var button=form.querySelector('button');
        var isRestore = op.value === 'restore_quarantine' || op.value === 'restore_one';
        var isUndoMerge = op.value === 'undo_merge';
        var simplePurge=params.panel==='quarantine'&&['purge_quarantine','purge_source'].includes(op.value);
        var spinner;
        params.quiet=true;
        if ( isRestore || isUndoMerge ) {
			setQuarantineRestoreBusy( true, button, isUndoMerge );
		} else {
			if ( button ) { button.disabled = true; }
			if ( simplePurge ) {
				spinner = document.createElement( 'span' );
				spinner.className = 'spinner is-active';
				spinner.style.float = 'none';
				spinner.setAttribute( 'role', 'status' );
				spinner.setAttribute( 'aria-label', mpcText("Purging…") );
				form.appendChild( spinner );
			}
		}
        persistentTask(params).then(function(r){
			var data=r&&r.data?r.data:{};
            if(data.fail)showActionError(Error(data.fail+mpcText(" action(s) failed. Review the remaining items.")));
            else if(simplePurge){
                var block=form.closest('.lucidlab-mpc-qblock')||form.closest('[data-mpc-source]')||form.closest('.lucidlab-mpc-card');
                if(op.value==='purge_source'){
                    if(block){
                        var table=block.querySelector('table');if(table)table.remove();
                        els('.lucidlab-mpc-purge-all',block).forEach(function(item){item.remove();});
                        els('.lucidlab-mpc-empty',block).forEach(function(item){item.remove();});
                        var empty=document.createElement('p');empty.className='lucidlab-mpc-empty';empty.textContent=mpcText("Nothing here.");block.appendChild(empty);
                    }
                }else{
                    var row=form.closest('tr');
                    if(row)row.remove();
                    if(block&&!block.querySelector('tbody tr')){
                        var leftover=block.querySelector('table');if(leftover)leftover.remove();
                        els('.lucidlab-mpc-purge-all',block).forEach(function(item){item.remove();});
                        if(!block.querySelector('.lucidlab-mpc-empty')){var empty=document.createElement('p');empty.className='lucidlab-mpc-empty';empty.textContent=mpcText("Nothing here.");block.appendChild(empty);}
                    }
                }
                var notice=document.createElement('p');notice.className='notice notice-success inline';notice.setAttribute('role','status');notice.textContent=data.label||mpcText("Set purged successfully.");
				var host=el('#lucidlab-mpc-top-notices')||el('.lucidlab-mpc-wrap');
				if(host)host.prepend(notice);
            }else if((isRestore||isUndoMerge) && !data.fail){
				var restored=Number(data.ok||0);
				var restoreUrl=new URL((LucidLabMPC.tabUrls&&LucidLabMPC.tabUrls.quarantine)||window.location.href,window.location.href);
				restoreUrl.searchParams.set('lucidlab_mpc_load','1');
				if(isUndoMerge){
					restoreUrl.searchParams.set('lucidlab_mpc_undone','1');
					if(data.label)restoreUrl.searchParams.set('lucidlab_mpc_msg',data.label);
				}else{
					restoreUrl.searchParams.set('lucidlab_mpc_restored',String(Math.max(0,restored)));
				}
				if(params.lucidlab_mpc_set)restoreUrl.searchParams.set('lucidlab_mpc_set',params.lucidlab_mpc_set);
				window.location.assign(restoreUrl.href);
			}else window.location.reload();
        }).catch(showActionError).finally(function(){
			purgeBusy = false;
			if ( isRestore || isUndoMerge ) {
				setQuarantineRestoreBusy( false, button );
			} else {
				if ( button ) { button.disabled = false; }
				if ( spinner ) { spinner.remove(); }
			}
		});
	} );
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var op = el( 'input[name="lucidlab_mpc_op"]', form );
		if ( ! op || op.value !== 'purge_missing' ) { return; }
		// Inline confirmation runs before this bubbling listener; cancellation stays idle.
		if ( event.defaultPrevented ) { return; }
		if ( missingPurgeBusy ) { event.preventDefault(); return; }
		event.preventDefault();
		missingPurgeBusy = true;
		var button = form.querySelector( 'button' );
		var imageField = el( 'input[name="lucidlab_mpc_image_id"]', form );
		var imageId = imageField ? imageField.value : '';
		setMissingPurgeBusy( true, button );
		post( { op: 'purge_missing', lucidlab_mpc_image_id: imageId, direct: 1 } ).then( function ( r ) {
			if ( ! r || ! r.success ) {
				throw new Error( ( r && r.data && r.data.message ) || mpcText("Purge failed.") );
			}
			window.location.reload();
		} ).catch( showActionError ).finally( function () {
			missingPurgeBusy = false;
			setMissingPurgeBusy( false, button );
		} );
	} );
	window.addEventListener( 'pageshow', function ( event ) {
		if ( ! event.persisted ) { return; }
		purgeButtons.forEach( function ( button ) { button.disabled = false; } );
		purgeButtons = [];
		purgeBusy = false;
		missingPurgeBusy = false;
		setQuarantineRestoreBusy( false, null );
		setMissingPurgeBusy( false, null );
		if ( purgeNotice ) { purgeNotice.remove(); purgeNotice = null; }
	} );

	document.addEventListener( 'submit', function ( e ) {
		var qSearch = e.target.closest( '.lucidlab-mpc-qfiles-search' );
		if ( qSearch ) {
			e.preventDefault();
			if ( qSearch.dataset.loading === '1' ) { return; }
			var zone = qSearch.closest( '.lucidlab-mpc-qfiles' ) || el( '#lucidlab-mpc-quarantine-items' );
			var button = el( '#lucidlab-mpc-qfiles-search-btn', qSearch ) || qSearch.querySelector( 'button[type="submit"], button' );
			var params = new URLSearchParams( new FormData( qSearch ) );
			var base = ( qSearch.getAttribute( 'action' ) || '' ).split( '#' )[ 0 ];
			if ( ! base ) {
				base = ( LucidLabMPC.tabUrls && LucidLabMPC.tabUrls.quarantine ) ? String( LucidLabMPC.tabUrls.quarantine ).split( '#' )[ 0 ] : window.location.pathname;
			}
			var nextUrl = base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) + params.toString();
			var historyUrl = nextUrl + '#lucidlab-mpc-quarantine-items';
			qSearch.dataset.loading = '1';
			buttonBusyInside( button, true );
			fetch( nextUrl, { credentials: 'same-origin' } ).then( function ( response ) {
				if ( ! response.ok ) { throw new Error( mpcText("Could not search quarantine (") + response.status + ').' ); }
				return response.text();
			} ).then( function ( html ) {
				var parsed = new DOMParser().parseFromString( html, 'text/html' );
				var loaded = parsed.querySelector( '#lucidlab-mpc-quarantine-items' );
				if ( ! zone || ! loaded ) { throw new Error( mpcText("Quarantine search results could not be found.") ); }
				var scrollY = window.scrollY;
				zone.replaceWith( loaded );
				window.history.pushState( null, '', historyUrl );
				var next = el( '#lucidlab-mpc-quarantine-items' );
				if ( next ) {
					var top = next.getBoundingClientRect().top;
					// Stay near the search/results area if the browser would leave it off-screen.
					if ( top < 8 || top > window.innerHeight * 0.75 ) {
						next.scrollIntoView( { behavior: 'smooth', block: 'start' } );
					} else {
						window.scrollTo( 0, scrollY );
					}
				}
			} ).catch( function ( error ) {
				qSearch.dataset.loading = '0';
				buttonBusyInside( button, false );
				showActionError( error );
			} );
			return;
		}
		var dupForm = e.target.closest( '.lucidlab-mpc-dup-form' );
		if ( dupForm ) {
			e.preventDefault();
			var toggle = el( '.lucidlab-mpc-enable', dupForm );
			if ( ! toggle || ! toggle.checked ) { alert( LucidLabMPC.i18n.enableFirst ); return; }
			runTrashDup( dupForm );
		}
	} );

	document.addEventListener( 'input', function ( e ) {
		if ( ! e.target.classList.contains( 'lucidlab-mpc-qsearch' ) ) { return; }
		var q = e.target.value.toLowerCase().trim();
		var zone = e.target.closest( '.lucidlab-mpc-qfiles' );
		var hits = 0;
		els( '.lucidlab-mpc-qfile-row', zone ).forEach( function ( row ) {
			var match = ! q || ( row.getAttribute( 'data-search' ) || '' ).indexOf( q ) !== -1;
			row.hidden = ! match;
			if ( match ) { hits++; }
		} );
		var none = el( '.lucidlab-mpc-qnohits', zone );
		if ( none ) { none.hidden = hits !== 0; }
	} );

	function updateDbCleanerSizeTotal( zone ) {
		if ( ! zone || zone.getAttribute( 'data-tab' ) !== 'dbcleaner' ) { return; }
		var totalEl = el( '[data-mpc-db-size-total]', zone );
		if ( ! totalEl ) { return; }
		var all = 0, selected = 0;
		els( '.lucidlab-mpc-row-check', zone ).forEach( function ( c ) {
			var bytes = parseInt( c.getAttribute( 'data-bytes' ) || '0', 10 ) || 0;
			all += bytes;
			if ( c.checked ) { selected += bytes; }
		} );
		var tpl = ( LucidLabMPC.i18n && LucidLabMPC.i18n.dbSizeSelectedAll ) || 'Selected: %1$s · All: %2$s';
		totalEl.textContent = tpl.replace( '%1$s', formatPreviewBytes( selected ) ).replace( '%2$s', formatPreviewBytes( all ) );
	}
	function dbCleanerSizeInit() {
		els( '.lucidlab-mpc-quar-zone[data-tab="dbcleaner"]' ).forEach( updateDbCleanerSizeTotal );
	}
	function dbCleanerOuterTabsInit() {
		var wrap = el( '[data-mpc-db-outer-tabs]' );
		var panels = el( '[data-mpc-db-outer-panels]' );
		if ( ! wrap || ! panels || wrap.hasAttribute( 'data-initialized' ) ) { return; }
		wrap.setAttribute( 'data-initialized', '1' );
		function showOuter( slug ) {
			els( '[data-mpc-db-outer-tab]', wrap ).forEach( function ( btn ) {
				var on = btn.getAttribute( 'data-mpc-db-outer-tab' ) === slug;
				btn.classList.toggle( 'nav-tab-active', on );
				btn.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			} );
			els( '[data-mpc-db-outer-panel]', panels ).forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-mpc-db-outer-panel' ) !== slug;
			} );
			if ( slug === 'shrink' ) {
				var shrinkCard = el( '[data-mpc-db-shrink]', panels );
				if ( shrinkCard && ! shrinkCard.hasAttribute( 'data-initialized' ) ) { dbShrinkInit(); }
			}
		}
		wrap.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '[data-mpc-db-outer-tab]' );
			if ( ! btn || ! wrap.contains( btn ) ) { return; }
			event.preventDefault();
			showOuter( btn.getAttribute( 'data-mpc-db-outer-tab' ) );
		} );
		var initial = el( '.nav-tab-active[data-mpc-db-outer-tab]', wrap ) || el( '[data-mpc-db-outer-tab]', wrap );
		if ( initial ) { showOuter( initial.getAttribute( 'data-mpc-db-outer-tab' ) ); }
	}
	function dbCleanerTabsInit() {
		var host = el( '[data-mpc-db-outer-panel="current"]' ) || document;
		var wrap = el( '[data-mpc-db-tabs]', host );
		var panels = el( '[data-mpc-db-tab-panels]', host );
		if ( ! wrap || ! panels || wrap.hasAttribute( 'data-initialized' ) ) { return; }
		wrap.setAttribute( 'data-initialized', '1' );
		function showTab( slug ) {
			els( '[data-mpc-db-tab]', wrap ).forEach( function ( btn ) {
				var on = btn.getAttribute( 'data-mpc-db-tab' ) === slug;
				btn.classList.toggle( 'nav-tab-active', on );
				btn.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			} );
			els( '[data-mpc-db-panel]', panels ).forEach( function ( panel ) {
				var on = panel.getAttribute( 'data-mpc-db-panel' ) === slug;
				panel.hidden = ! on;
			} );
			var activePanel = el( '[data-mpc-db-panel="' + slug + '"]', panels );
			if ( activePanel ) {
				els( '.lucidlab-mpc-quar-zone[data-tab="dbcleaner"]', activePanel ).forEach( updateDbCleanerSizeTotal );
			}
		}
		wrap.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '[data-mpc-db-tab]' );
			if ( ! btn || ! wrap.contains( btn ) ) { return; }
			event.preventDefault();
			showTab( btn.getAttribute( 'data-mpc-db-tab' ) );
		} );
		var initial = el( '.nav-tab-active[data-mpc-db-tab]', wrap ) || el( '[data-mpc-db-tab]', wrap );
		if ( initial ) { showTab( initial.getAttribute( 'data-mpc-db-tab' ) ); }
	}
	function dbCleanerBackupInit() {
		var btn = el( '[data-mpc-dbcleaner-backup]' );
		if ( ! btn || btn.hasAttribute( 'data-initialized' ) ) { return; }
		btn.setAttribute( 'data-initialized', '1' );
		btn.addEventListener( 'click', async function () {
			if ( btn.disabled || btn.classList.contains( 'is-busy' ) ) { return; }
			var msg = ( LucidLabMPC.i18n && LucidLabMPC.i18n.dbBackupConfirm ) || mpcText("Create a SQL dump of this site's database? The file is stored with other backups and listed on the Backup page.");
			if ( ! await inlineConfirm( msg, btn, { skip_note: true, skip_meta: true } ) ) { return; }
			crawlButtonBusy( btn, true );
			try {
				var d;
				do {
					d = await post( { op: 'backup', tab: 'backup', sub: 'start', kind: 'db', dbcleaner: 1 } );
					if ( ! d || ! d.success ) { throw new Error( ( d && d.data && d.data.message ) || mpcText("Backup failed") ); }
					d = d.data || {};
					if ( d.busy ) { await new Promise( function ( r ) { setTimeout( r, 500 ); } ); }
				} while ( d && d.busy );
				while ( d && ( d.status === 'running' || d.status === 'paused' ) ) {
					await new Promise( function ( r ) { setTimeout( r, d.status === 'paused' ? 2000 : 400 ); } );
					var next = await post( {
						op: 'backup',
						tab: 'backup',
						sub: d.status === 'running' && d.driver === 'ajax' ? 'step' : 'status',
						dbcleaner: 1
					} );
					if ( ! next || ! next.success ) { throw new Error( ( next && next.data && next.data.message ) || mpcText("Backup failed") ); }
					d = next.data || {};
					if ( d.busy ) { continue; }
				}
				if ( d && d.status === 'done' ) {
					var label = el( '[data-mpc-dbcleaner-backup-label]' );
					var text = d.dbcleaner_last_backup_label || ( ( LucidLabMPC.i18n && LucidLabMPC.i18n.dbBackupMade ) || mpcText("Backup made: %s") ).replace( '%s', d.updated_label || new Date().toLocaleString() );
					if ( label ) { label.hidden = false; label.textContent = text; }
				} else if ( d && d.status === 'failed' ) {
					throw new Error( d.label || d.message || mpcText("Backup failed") );
				}
			} catch ( err ) {
				showActionError( err );
			} finally {
				crawlButtonBusy( btn, false );
			}
		} );
	}
	function dbCleanerUiInit() {
		dbCleanerSizeInit();
		dbCleanerOuterTabsInit();
		dbCleanerTabsInit();
		dbCleanerBackupInit();
		dbShrinkInit();
	}
	function dbShrinkFormatBytes( n ) {
		return formatPreviewBytes( Math.max( 0, parseInt( n, 10 ) || 0 ) );
	}
	function dbShrinkEscape( s ) {
		return String( s == null ? '' : s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}
	function dbShrinkSetRunSelectedVisible( card, visible ) {
		var runSelected = el( '[data-mpc-db-shrink-run-selected]', card );
		if ( ! runSelected ) { return; }
		runSelected.hidden = ! visible;
		if ( ! visible ) { runSelected.disabled = true; }
	}
	function dbShrinkRender( card, data ) {
		var body = el( '[data-mpc-db-shrink-body]', card );
		var runSelected = el( '[data-mpc-db-shrink-run-selected]', card );
		if ( ! body ) { return; }
		var actions = ( data && data.actions ) || [];
		if ( ! actions.length ) {
			body.innerHTML = '<p class="lucidlab-mpc-empty lucidlab-mpc-good"><span class="dashicons dashicons-yes-alt"></span> ' +
				dbShrinkEscape( ( LucidLabMPC.i18n && LucidLabMPC.i18n.dbShrinkEmpty ) || mpcText("Nothing to shrink right now — no finished Action Scheduler jobs, orphan logs, or reclaimable free space.") ) +
				'</p>';
			dbShrinkSetRunSelectedVisible( card, false );
			return;
		}
		var html = '<ul class="lucidlab-mpc-db-shrink__list">';
		actions.forEach( function ( action, idx ) {
			var key = String( action.key || '' );
			var idle = action.available === false;
			var checked = action.selected && ! idle ? mpcText(" checked") : '';
			html += '<li class="lucidlab-mpc-db-shrink__item" data-shrink-key="' + dbShrinkEscape( key ) + '">';
			html += '<div class="lucidlab-mpc-db-shrink__main">';
			html += '<label class="lucidlab-mpc-db-shrink__label"><input type="checkbox" class="lucidlab-mpc-db-shrink-check" data-shrink-key="' + dbShrinkEscape( key ) + '"' + checked + ( idle ? ' disabled' : '' ) + ' /> ';
			html += '<span class="lucidlab-mpc-db-shrink__title">' + dbShrinkEscape( action.label || key ) + '</span></label>';
			html += '<div class="lucidlab-mpc-db-shrink__meta"><span class="lucidlab-mpc-db-shrink__savings">' + dbShrinkEscape( dbShrinkFormatBytes( action.estimated_bytes || 0 ) ) + '</span>';
			if ( action.count ) {
				html += ' <span class="lucidlab-mpc-hint">· ' + dbShrinkEscape( String( action.count ) ) + '</span>';
			}
			html += '</div>';
			if ( action.detail ) {
				html += '<p class="lucidlab-mpc-hint lucidlab-mpc-db-shrink__detail">' + dbShrinkEscape( action.detail ) + '</p>';
			}
			if ( key === 'optimize' && Array.isArray( action.tables ) && action.tables.length ) {
				html += '<ul class="lucidlab-mpc-db-shrink__tables">';
				action.tables.forEach( function ( t ) {
					var tChecked = t.selected !== false ? mpcText(" checked") : '';
					html += '<li><label><input type="checkbox" class="lucidlab-mpc-db-shrink-table" value="' + dbShrinkEscape( t.table || '' ) + '"' + tChecked + ' /> ';
					html += '<code>' + dbShrinkEscape( t.table || '' ) + '</code> <span class="lucidlab-mpc-hint">' + dbShrinkEscape( dbShrinkFormatBytes( t.data_free || 0 ) ) + '</span></label></li>';
				} );
				html += '</ul>';
			}
			html += '</div>';
			html += '<div class="lucidlab-mpc-db-shrink__actions"><button type="button" class="button button-small button-primary lucidlab-mpc-db-shrink__run' + ( action.needs_confirm ? ' lucidlab-mpc-danger' : '' ) + '" data-mpc-db-shrink-run="' + dbShrinkEscape( key ) + '" data-testid="db-shrink-run-' + dbShrinkEscape( key ) + '" id="lucidlab-mpc-db-shrink-run-' + idx + '"' + ( idle ? ' disabled' : '' ) + '>';
			html += dbShrinkEscape( ( LucidLabMPC.i18n && LucidLabMPC.i18n.dbShrinkRun ) || mpcText("Run") ) + '</button></div>';
			html += '</li>';
		} );
		html += '</ul>';
		var total = ( data.totals && data.totals.estimated_bytes ) || 0;
		html += '<p class="lucidlab-mpc-hint lucidlab-mpc-db-shrink__total">' + dbShrinkEscape( ( LucidLabMPC.i18n && LucidLabMPC.i18n.dbShrinkTotal ) || mpcText("Estimated savings") ) + ': <strong>' + dbShrinkEscape( dbShrinkFormatBytes( total ) ) + '</strong></p>';
		body.innerHTML = html;
		dbShrinkSetRunSelectedVisible( card, true );
		if ( runSelected ) {
			runSelected.disabled = ! els( '.lucidlab-mpc-db-shrink-check:checked', card ).length;
		}
	}
	async function dbShrinkLoad( card, announce ) {
		var status = el( '[data-mpc-db-shrink-status]', card ) || el( '[data-mpc-db-shrink-body]', card );
		var refresh = el( '[data-mpc-db-shrink-refresh]', card );
		dbShrinkSetRunSelectedVisible( card, false );
		if ( refresh ) { buttonBusy( refresh, true ); }
		try {
			var res = await post( { op: 'db_shrink_preview', direct: 1 } );
			if ( ! res || ! res.success ) {
				throw new Error( ( res && res.data && res.data.message ) || mpcText("Preview failed") );
			}
			dbShrinkUpdateSummaryCounts( res.data || {} );
			dbShrinkRender( card, res.data || {} );
			if ( announce ) {
				var box = el( '[data-mpc-db-shrink-result]', card );
				if ( box ) {
					box.hidden = false;
					box.className = 'notice notice-info inline lucidlab-mpc-notice lucidlab-mpc-db-shrink__result';
					box.textContent = mpcText("Estimates updated.");
				}
			}
		} catch ( err ) {
			dbShrinkSetRunSelectedVisible( card, false );
			if ( status ) {
				status.innerHTML = '<p class="notice notice-error inline lucidlab-mpc-notice">' + dbShrinkEscape( err.message || mpcText("Preview failed") ) + '</p>';
			}
		} finally {
			if ( refresh ) { buttonBusy( refresh, false ); }
		}
	}
	async function dbShrinkConfirm( actionKey, host ) {
		if ( actionKey === 'optimize' ) {
			return inlineConfirm( mpcText("Run OPTIMIZE TABLE on the selected tables? Each table may lock briefly. Free space that MariaDB keeps as a reserve will stay in the estimate."), host, {
				skip_note: true,
				skip_meta: true,
				require_text: "CONFIRM",
				compact: false
			} );
		}
		if ( actionKey !== 'as_clear_finished' && actionKey !== 'as_keep_last' && actionKey !== 'as_orphan_logs' ) { return true; }
		var msg = ( actionKey === 'as_clear_finished' || actionKey === 'as_keep_last' )
			? ( ( LucidLabMPC.i18n && LucidLabMPC.i18n.dbShrinkConfirmAs ) || mpcText("Quarantine and remove all finished Action Scheduler jobs (complete / failed / canceled)? Pending and in-progress jobs are kept. Rows are saved in restorable quarantine chunks first.") )
			: ( ( LucidLabMPC.i18n && LucidLabMPC.i18n.dbShrinkConfirmOrphans ) || mpcText("Quarantine and remove orphan Action Scheduler log rows with no matching action? Rows are saved in restorable quarantine first.") );
		return inlineConfirm( msg, host, {
			skip_note: true,
			skip_meta: true,
			require_text: "CONFIRM",
			compact: false
		} );
	}
	function dbShrinkSetToolbarBusy( card, busy ) {
		var refresh = el( '[data-mpc-db-shrink-refresh]', card );
		var runSelected = el( '[data-mpc-db-shrink-run-selected]', card );
		if ( refresh ) { refresh.disabled = !! busy; }
		if ( runSelected ) {
			if ( busy ) {
				runSelected.disabled = true;
			} else if ( ! runSelected.hidden ) {
				runSelected.disabled = ! els( '.lucidlab-mpc-db-shrink-check:checked', card ).length;
			}
		}
	}
	/** Patch Optimize estimates in place so a running spinner is not destroyed. */
	async function dbShrinkRefreshOptimizeEstimates( card ) {
		try {
			var res = await post( { op: 'db_shrink_preview', direct: 1 } );
			if ( ! res || ! res.success ) { return; }
			var actions = ( res.data && res.data.actions ) || [];
			var opt = null;
			actions.forEach( function ( a ) {
				if ( String( a.key || '' ) === 'optimize' ) { opt = a; }
			} );
			var item = el( '[data-shrink-key="optimize"]', card );
			if ( ! item ) { return; }
			var savings = el( '.lucidlab-mpc-db-shrink__savings', item );
			if ( savings ) {
				savings.textContent = dbShrinkFormatBytes( opt ? ( opt.estimated_bytes || 0 ) : 0 );
			}
			var countHint = el( '.lucidlab-mpc-db-shrink__meta .lucidlab-mpc-hint', item );
			if ( countHint ) {
				countHint.textContent = '· ' + String( opt && opt.count ? opt.count : 0 );
			}
			var actionCheck = el( '.lucidlab-mpc-db-shrink-check', item );
			var actionRun = el( '[data-mpc-db-shrink-run="optimize"]', item );
			if ( actionCheck ) {
				actionCheck.disabled = ! opt;
				if ( ! opt ) { actionCheck.checked = false; }
			}
			if ( actionRun ) { actionRun.disabled = ! opt; }
			var byTable = {};
			if ( opt && Array.isArray( opt.tables ) ) {
				opt.tables.forEach( function ( t ) {
					if ( t && t.table ) { byTable[ String( t.table ) ] = t; }
				} );
			}
			els( '.lucidlab-mpc-db-shrink-table', item ).forEach( function ( cb ) {
				var li = cb.closest( 'li' );
				var hint = li ? el( '.lucidlab-mpc-hint', li ) : null;
				var info = byTable[ cb.value ];
				if ( ! info ) {
					if ( hint ) { hint.textContent = dbShrinkFormatBytes( 0 ); }
					cb.checked = false;
					cb.disabled = true;
					if ( li ) { li.classList.add( 'is-done' ); }
				} else {
					cb.disabled = false;
					if ( hint ) { hint.textContent = dbShrinkFormatBytes( info.data_free || 0 ); }
					if ( li ) { li.classList.remove( 'is-done' ); }
				}
			} );
			var totalEl = el( '.lucidlab-mpc-db-shrink__total strong', card );
			if ( totalEl && res.data && res.data.totals ) {
				totalEl.textContent = dbShrinkFormatBytes( res.data.totals.estimated_bytes || 0 );
			}
			var runSelected = el( '[data-mpc-db-shrink-run-selected]', card );
			if ( runSelected ) {
				runSelected.disabled = ! els( '.lucidlab-mpc-db-shrink-check:checked:not(:disabled)', card ).length;
			}
		} catch ( e ) { /* keep running; next table will retry estimates */ }
	}
	function dbShrinkUpdateSummaryCounts( data ) {
		var counts = data && data.summary_counts;
		if ( ! counts ) { return; }
		[ 'bytes', 'optimize_bytes' ].forEach( function ( key ) {
			var value = parseInt( counts[ key ], 10 );
			var number = el( '[data-mpc-panel="dbcleaner"] [data-mpc-stat-key="' + key + '"] .lucidlab-mpc-num' );
			if ( number && ! isNaN( value ) ) { number.textContent = dbShrinkFormatBytes( value ); }
		} );
	}
	function dbShrinkOptimizeProgress( card, done, total ) {
		var box = el( '[data-mpc-db-shrink-progress]', card );
		if ( ! box ) { return; }
		var progress = el( 'progress', box );
		var label = el( '[data-mpc-db-shrink-progress-label]', box );
		var pct = total > 0 ? Math.min( 100, 100 * done / total ) : 0;
		box.hidden = false;
		if ( progress ) { progress.value = pct; }
		if ( label ) {
			label.textContent = mpcText("Optimizing tables: ") + String( done ) + ' / ' + String( total ) + ' (' + pct.toFixed( 2 ) + '%)';
		}
	}
	async function dbShrinkRunOptimize( card, button, opts ) {
		opts = opts || {};
		var tables = [];
		els( '.lucidlab-mpc-db-shrink-table:checked', card ).forEach( function ( c ) {
			if ( c.value && ! c.disabled ) { tables.push( c.value ); }
		} );
		if ( ! tables.length ) {
			throw new Error( ( LucidLabMPC.i18n && LucidLabMPC.i18n.dbShrinkNoTables ) || mpcText("No tables selected for OPTIMIZE.") );
		}
		crawlButtonBusy( button, true );
		if ( ! opts.keepToolbar ) { dbShrinkSetToolbarBusy( card, true ); }
		var aggregated = {
			action: 'optimize',
			optimize: [],
			estimated_bytes: 0,
			message: ''
		};
		var okN = 0;
		dbShrinkOptimizeProgress( card, 0, tables.length );
		try {
			for ( var i = 0; i < tables.length; i++ ) {
				var payload = {
					op: 'db_shrink_run',
					shrink_action: 'optimize',
					tables: JSON.stringify( [ tables[ i ] ] ),
					direct: 1
				};
				var res = await post( payload );
				if ( ! res || ! res.success ) {
					throw new Error( ( res && res.data && res.data.message ) || mpcText("Shrink failed") );
				}
				var data = res.data || {};
				dbShrinkUpdateSummaryCounts( data );
				if ( Array.isArray( data.optimize ) ) {
					aggregated.optimize = aggregated.optimize.concat( data.optimize );
					data.optimize.forEach( function ( row ) {
						if ( row && row.ok && row.status === 'optimized' ) { okN += 1; }
					} );
				}
				aggregated.estimated_bytes += parseInt( data.estimated_bytes, 10 ) || 0;
				dbShrinkOptimizeProgress( card, i + 1, tables.length );
				await dbShrinkRefreshOptimizeEstimates( card );
			}
			aggregated.message = ( ( LucidLabMPC.i18n && LucidLabMPC.i18n.dbShrinkOptimizedCount ) || mpcText("Optimized %d table(s). Free space is reclaimed after InnoDB rebuild; check Refresh estimates for remaining Data_free.") ).replace( '%d', String( okN ) );
			return aggregated;
		} finally {
			var progressBox = el( '[data-mpc-db-shrink-progress]', card );
			if ( progressBox ) { progressBox.hidden = true; }
			crawlButtonBusy( button, false );
			if ( ! opts.keepToolbar ) { dbShrinkSetToolbarBusy( card, false ); }
		}
	}
	async function dbShrinkRunOne( card, actionKey, button, opts ) {
		opts = opts || {};
		if ( ! opts.alreadyConfirmed ) {
			var ok = await dbShrinkConfirm( actionKey, card );
			if ( ! ok ) { return null; }
		}
		if ( actionKey === 'optimize' ) {
			return dbShrinkRunOptimize( card, button, opts );
		}
		crawlButtonBusy( button, true );
		if ( ! opts.keepToolbar ) { dbShrinkSetToolbarBusy( card, true ); }
		try {
			var payload = { op: 'db_shrink_run', shrink_action: actionKey, direct: 1 };
			if ( actionKey === 'as_clear_finished' || actionKey === 'as_keep_last' || actionKey === 'as_orphan_logs' ) { payload.confirm = "CONFIRM"; }
			var res = await post( payload );
			if ( ! res || ! res.success ) {
				throw new Error( ( res && res.data && res.data.message ) || mpcText("Shrink failed") );
			}
			return res.data || {};
		} finally {
			crawlButtonBusy( button, false );
			if ( ! opts.keepToolbar ) { dbShrinkSetToolbarBusy( card, false ); }
		}
	}
	function dbShrinkShowResult( card, results ) {
		var box = el( '[data-mpc-db-shrink-result]', card );
		if ( ! box ) { return; }
		var lines = [];
		( results || [] ).forEach( function ( r ) {
			if ( ! r ) { return; }
			if ( r.message ) { lines.push( r.message ); }
			else if ( r.action === 'optimize' ) {
				lines.push( ( LucidLabMPC.i18n && LucidLabMPC.i18n.dbShrinkOptimized ) || mpcText("OPTIMIZE finished.") );
			}
			if ( r.deleted_rows ) {
				lines.push( mpcText("Deleted rows: ") + String( r.deleted_rows ) + ( r.estimated_bytes ? ' · ~' + dbShrinkFormatBytes( r.estimated_bytes ) : '' ) );
			}
		} );
		if ( ! lines.length ) { box.hidden = true; box.textContent = ''; return; }
		box.hidden = false;
		box.className = 'notice notice-success inline lucidlab-mpc-notice lucidlab-mpc-db-shrink__result';
		box.innerHTML = '<p>' + lines.map( dbShrinkEscape ).join( '</p><p>' ) + '</p>';
	}
	function dbShrinkInit() {
		var card = el( '[data-mpc-db-shrink]' );
		if ( ! card || card.hasAttribute( 'data-initialized' ) ) { return; }
		card.setAttribute( 'data-initialized', '1' );
		dbShrinkLoad( card );
		card.addEventListener( 'click', async function ( event ) {
			var refresh = event.target.closest( '[data-mpc-db-shrink-refresh]' );
			if ( refresh && card.contains( refresh ) ) {
				event.preventDefault();
				await dbShrinkLoad( card, true );
				return;
			}
			var runOne = event.target.closest( '[data-mpc-db-shrink-run]' );
			if ( runOne && card.contains( runOne ) ) {
				event.preventDefault();
				try {
					var data = await dbShrinkRunOne( card, runOne.getAttribute( 'data-mpc-db-shrink-run' ), runOne );
					if ( data ) {
						dbShrinkShowResult( card, [ data ] );
						await dbShrinkLoad( card );
					}
				} catch ( err ) {
					dbShrinkShowResult( card, [ { message: err.message || mpcText("Shrink failed") } ] );
					var box = el( '[data-mpc-db-shrink-result]', card );
					if ( box ) { box.className = 'notice notice-error inline lucidlab-mpc-notice lucidlab-mpc-db-shrink__result'; box.hidden = false; }
				}
				return;
			}
			var runSel = event.target.closest( '[data-mpc-db-shrink-run-selected]' );
			if ( runSel && card.contains( runSel ) ) {
				event.preventDefault();
				var keys = [];
				els( '.lucidlab-mpc-db-shrink-check:checked', card ).forEach( function ( c ) {
					var k = c.getAttribute( 'data-shrink-key' );
					if ( k ) { keys.push( k ); }
				} );
				if ( ! keys.length ) { return; }
				var collected = [];
				dbShrinkSetToolbarBusy( card, true );
				try {
					for ( var i = 0; i < keys.length; i++ ) {
						var key = keys[ i ];
						var btn = el( '[data-mpc-db-shrink-run="' + key + '"]', card );
						if ( ! btn ) { continue; }
						var confirmed = await dbShrinkConfirm( key, card );
						if ( ! confirmed ) { break; }
						var one = await dbShrinkRunOne( card, key, btn, { alreadyConfirmed: true, keepToolbar: true } );
						if ( one ) { collected.push( one ); }
						else { break; }
					}
					if ( collected.length ) {
						dbShrinkShowResult( card, collected );
						await dbShrinkLoad( card );
					}
				} catch ( err ) {
					collected.push( { message: err.message || mpcText("Shrink failed") } );
					dbShrinkShowResult( card, collected );
					var errBox = el( '[data-mpc-db-shrink-result]', card );
					if ( errBox ) { errBox.className = 'notice notice-error inline lucidlab-mpc-notice lucidlab-mpc-db-shrink__result'; errBox.hidden = false; }
				} finally {
					dbShrinkSetToolbarBusy( card, false );
				}
			}
		} );
		card.addEventListener( 'change', function ( event ) {
			if ( ! event.target.classList.contains( 'lucidlab-mpc-db-shrink-check' ) && ! event.target.classList.contains( 'lucidlab-mpc-db-shrink-table' ) ) { return; }
			var runSelected = el( '[data-mpc-db-shrink-run-selected]', card );
			if ( runSelected ) {
				runSelected.disabled = ! els( '.lucidlab-mpc-db-shrink-check:checked', card ).length;
			}
		} );
	}
	if ( document.readyState !== 'loading' ) { setTimeout( dbCleanerUiInit, 0 ); }
	else { document.addEventListener( "DOMContentLoaded", dbCleanerUiInit ); }
	// View current results replaces the panel via AJAX — re-bind filter tabs on the new markup.
	document.addEventListener( 'mpc:tabshown', function ( event ) {
		if ( event.detail === 'dbcleaner' ) { dbCleanerUiInit(); }
	} );

	function syncQuarantineButton( zone ) {
		if ( ! zone ) { return; }
		var btn = el( '[data-mpc-quarantine]', zone ) || el( '.lucidlab-mpc-danger', zone );
		if ( ! btn || btn.getAttribute( 'aria-busy' ) === 'true' ) { return; }
		var enable = el( '.lucidlab-mpc-enable', zone );
		if ( enable && ! enable.checked ) {
			btn.disabled = true;
			return;
		}
		if ( zone.dataset.mpcAllResults === '1' ) {
			btn.disabled = false;
			return;
		}
		btn.disabled = ! els( '.lucidlab-mpc-row-check:checked', zone ).length;
	}
	document.addEventListener( 'change', function ( e ) {
		var t = e.target;
		if ( t.classList.contains( 'lucidlab-mpc-enable' ) ) {
			var host = t.closest( '.lucidlab-mpc-quar-zone' ) || t.closest( '.lucidlab-mpc-dupc-form' ) || t.closest( 'form' );
			var btn = host ? el( '.lucidlab-mpc-danger', host ) : null;
			if ( btn && host && host.classList && host.classList.contains( 'lucidlab-mpc-quar-zone' ) ) {
				syncQuarantineButton( host );
			} else if ( btn ) {
				btn.disabled = ! t.checked;
			}
		}
		if ( t.classList.contains( 'lucidlab-mpc-selectall' ) ) {
			var table = t.closest( 'table' );
			var selectZone = t.closest( '.lucidlab-mpc-quar-zone' );
			function applySelectAll() {
				els( '.lucidlab-mpc-row-check:not(:disabled)', table ).forEach( function ( c ) { c.checked = t.checked; } );
				if ( selectZone && selectZone.getAttribute( 'data-tab' ) === 'uploads' ) {
					if ( t.checked ) { selectZone.dataset.mpcAllResults = '1'; }
					else { delete selectZone.dataset.mpcAllResults; }
				}
				if ( selectZone ) { updateDbCleanerSizeTotal( selectZone ); syncQuarantineButton( selectZone ); }
			}
			if ( t.checked && selectZone && selectZone.getAttribute( 'data-mpc-db-advanced' ) === '1' ) {
				var advMsg = ( LucidLabMPC.i18n && LucidLabMPC.i18n.confirmDbAdvancedSelectAll ) || mpcText("Select all higher-risk Database Cleaner items? Review each group carefully before quarantining.");
				inlineConfirm( advMsg, table || t, { skip_note: true, skip_meta: true } ).then( function ( ok ) {
					if ( ! ok ) { t.checked = false; return; }
					applySelectAll();
				} );
				return;
			}
			applySelectAll();
		}
		if ( t.classList.contains( 'lucidlab-mpc-row-check' ) ) {
			var rowZone = t.closest( '.lucidlab-mpc-quar-zone' );
			if ( rowZone && rowZone.getAttribute( 'data-tab' ) === 'uploads' && ! t.checked ) {
				delete rowZone.dataset.mpcAllResults;
				var master = el( '.lucidlab-mpc-selectall', rowZone );
				if ( master ) { master.checked = false; }
			}
			if ( rowZone ) { updateDbCleanerSizeTotal( rowZone ); syncQuarantineButton( rowZone ); }
		}
		if ( t.classList.contains( 'lucidlab-mpc-keep-radio' ) ) {
			setSurvivor( t );
		}
		if ( t.classList.contains( 'lucidlab-mpc-dupc-keep' ) ) {
			setDupSurvivor( t );
		}
	} );
	function syncAllQuarantineButtons() {
		els( '.lucidlab-mpc-quar-zone' ).forEach( syncQuarantineButton );
	}
	if ( document.readyState !== 'loading' ) { setTimeout( syncAllQuarantineButtons, 0 ); setTimeout( function () { labelChoiceControls(); }, 0 ); }
	else { document.addEventListener( 'DOMContentLoaded', function () { syncAllQuarantineButtons(); labelChoiceControls(); } ); }
	document.addEventListener( 'mpc:tabshown', function () { syncAllQuarantineButtons(); labelChoiceControls(); } );

	function inventoryInit() {
		var box = el( '#lucidlab-mpc-inventory' );
		if ( ! box || box.hasAttribute( 'data-initialized' ) ) { return; }
		box.setAttribute( 'data-initialized', '1' );
		var diskBtn = el( '#lucidlab-mpc-inventory-disk', box ), refsBtn = el( '#lucidlab-mpc-inventory-refs', box );
		var status = el( '#lucidlab-mpc-inventory-status', box ), progress = el( '#lucidlab-mpc-inventory-progress', box ), bar = el( '#lucidlab-mpc-inventory-bar', box );
		var diskHost = el( '#lucidlab-mpc-inventory-disk-result', box ), refsHost = el( '#lucidlab-mpc-inventory-refs-result', box );
		var diskBody = el( '#lucidlab-mpc-inventory-disk-body', box ), refsBody = el( '#lucidlab-mpc-inventory-refs-body', box );
		function setProgress( pct, label ) {
			if ( status ) { status.textContent = label || ''; }
			if ( progress ) { progress.hidden = !( pct > 0 && pct < 100 ); }
			if ( bar ) { bar.style.width = Math.max( 0, Math.min( 100, pct || 0 ) ) + '%'; }
		}
		function setSummary( panel, htmlStrong, hintText ) {
			if ( ! panel ) { return; }
			var line = panel.querySelector( '.lucidlab-mpc-inventory-summary-line' );
			if ( ! line ) { return; }
			line.replaceChildren();
			var strong = document.createElement( 'strong' );
			strong.textContent = htmlStrong;
			line.appendChild( strong );
			if ( hintText ) {
				var hint = document.createElement( 'span' );
				hint.className = 'lucidlab-mpc-hint';
				hint.textContent = hintText;
				line.appendChild( hint );
			}
		}
		function renderDisk( disk ) {
			if ( ! diskHost || ! diskBody ) { return; }
			if ( ! disk ) { diskHost.hidden = true; diskBody.replaceChildren(); return; }
			diskHost.hidden = false;
			diskHost.open = true;
			setSummary(
				diskHost,
				mpcText("Total ") + ( Number( disk.total_files ) || 0 ).toLocaleString() + ' files \u00b7 ' + ( disk.total_size || formatPreviewBytes( 0 ) ),
				Number( disk.skipped_dirs ) > 0 ? Number( disk.skipped_dirs ).toLocaleString() + mpcText(" excluded directory instances skipped") : ''
			);
			var table = document.createElement( 'table' );
			table.className = 'widefat lucidlab-mpc-table lucidlab-mpc-inventory-table';
			table.innerHTML = ("<thead><tr><th>" + escapeHtml(mpcText("Type")) + "</th><th>" + escapeHtml(mpcText("Category")) + "</th><th>" + escapeHtml(mpcText("Files")) + "</th><th>" + escapeHtml(mpcText("Size")) + "</th></tr></thead>");
			var tbody = document.createElement( 'tbody' );
			( disk.rows || [] ).forEach( function ( row ) {
				var tr = document.createElement( 'tr' );
				tr.innerHTML = '<td><code></code></td><td></td><td></td><td></td>';
				tr.children[0].firstChild.textContent = row.ext || '';
				tr.children[1].textContent = row.label || '';
				tr.children[2].textContent = ( Number( row.count ) || 0 ).toLocaleString();
				tr.children[3].textContent = row.size || formatPreviewBytes( 0 );
				tbody.appendChild( tr );
			} );
			table.appendChild( tbody );
			diskBody.replaceChildren( table );
			if ( disk.folders && disk.folders.length ) {
				var heading = document.createElement( 'h4' );
				heading.textContent = mpcText( 'Uploads folder' );
				var folderTable = document.createElement( 'table' );
				folderTable.className = 'widefat lucidlab-mpc-table lucidlab-mpc-inventory-table';
				folderTable.innerHTML = '<thead><tr><th>' + escapeHtml( mpcText( 'Uploads folder' ) ) + '</th><th>' + escapeHtml( mpcText( 'Files' ) ) + '</th><th>' + escapeHtml( mpcText( 'Size' ) ) + '</th></tr></thead>';
				var folderBody = document.createElement( 'tbody' );
				disk.folders.forEach( function ( folder ) {
					var tr = document.createElement( 'tr' );
					tr.innerHTML = '<td><code></code></td><td></td><td></td>';
					tr.children[0].firstChild.textContent = folder.name || '';
					tr.children[1].textContent = ( Number( folder.count ) || 0 ).toLocaleString();
					tr.children[2].textContent = folder.size || formatPreviewBytes( folder.bytes || 0 );
					folderBody.appendChild( tr );
				} );
				folderTable.appendChild( folderBody );
				diskBody.appendChild( heading );
				diskBody.appendChild( folderTable );
			}
		}
		var dialog = el( '#lucidlab-mpc-inventory-dialog', box );
		var dialogTitle = el( '#lucidlab-mpc-inventory-dialog-title', dialog || box );
		var dialogBody = el( '#lucidlab-mpc-inventory-dialog-body', dialog || box );
		function inventoryAllowLinks() {
			return !!( window.LucidLabMPC && LucidLabMPC.pro ) || box.getAttribute( 'data-mpc-inventory-pro' ) === '1';
		}
		function appendInventoryAction( host, label, href, allow ) {
			if ( allow && href ) {
				var link = document.createElement( 'a' );
				link.href = href;
				link.target = '_blank';
				link.rel = mpcText("noopener noreferrer");
				link.textContent = label;
				host.appendChild( link );
				return;
			}
			var locked = document.createElement( 'button' );
			locked.type = 'button';
			locked.className = 'button-link lucidlab-mpc-inventory-action-locked';
			locked.textContent = label;
			locked.disabled = true;
			locked.title = mpcText("Requires Pro");
			host.appendChild( locked );
		}
		function openInventoryItems( label, items ) {
			if ( ! dialog || ! dialogBody ) { return; }
			if ( dialogTitle ) { dialogTitle.textContent = label || mpcText("References"); }
			dialogBody.replaceChildren();
			if ( ! items || ! items.length ) {
				var empty = document.createElement( 'p' );
				empty.className = 'lucidlab-mpc-hint';
				empty.textContent = mpcText("No items available for this count.");
				dialogBody.appendChild( empty );
			} else {
				var allowLinks = inventoryAllowLinks();
				var list = document.createElement( 'ul' );
				list.className = 'lucidlab-mpc-inventory-item-list';
				items.forEach( function ( item ) {
					var li = document.createElement( 'li' );
					if ( ! allowLinks ) { li.classList.add( 'lucidlab-mpc-inventory-item--blurred' ); }
					var title = document.createElement( 'strong' );
					title.className = 'lucidlab-mpc-inventory-item-title';
					title.textContent = ( item.title || ( '#' + item.id ) ) + ( item.type ? ' \u00b7 ' + item.type : '' );
					li.appendChild( title );
					var actions = document.createElement( 'span' );
					actions.className = 'lucidlab-mpc-inventory-item-actions';
					appendInventoryAction( actions, mpcText("View"), item.view || '', allowLinks );
					appendInventoryAction( actions, mpcText("Edit"), item.edit || '', allowLinks );
					li.appendChild( actions );
					list.appendChild( li );
				} );
				dialogBody.appendChild( list );
				if ( ! allowLinks ) {
					var proNote = document.createElement( 'p' );
					proNote.className = 'lucidlab-mpc-hint';
					proNote.textContent = mpcText("View / Edit links require the Pro add-on.");
					dialogBody.appendChild( proNote );
				}
				if ( items.length >= 80 ) {
					var note = document.createElement( 'p' );
					note.className = 'lucidlab-mpc-hint';
					note.textContent = mpcText("Showing up to 80 items from this scan.");
					dialogBody.appendChild( note );
				}
			}
			if ( typeof dialog.showModal === 'function' ) { dialog.showModal(); }
			else { dialog.setAttribute( 'open', 'open' ); }
		}
		box.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '.lucidlab-mpc-inventory-count' );
			if ( ! btn || ! box.contains( btn ) ) { return; }
			event.preventDefault();
			var items = [];
			try { items = JSON.parse( btn.getAttribute( 'data-mpc-inv-items' ) || '[]' ); } catch ( e ) { items = []; }
			openInventoryItems( btn.getAttribute( 'data-mpc-inv-label' ) || mpcText("References"), items );
		} );
		function renderRefs( refs ) {
			if ( ! refsHost || ! refsBody ) { return; }
			if ( ! refs ) { refsHost.hidden = true; refsBody.replaceChildren(); return; }
			refsHost.hidden = false;
			refsHost.open = true;
			setSummary( refsHost, mpcText("Media library & references"), '' );
			var hint = document.createElement( 'p' );
			hint.className = 'lucidlab-mpc-hint';
			hint.textContent = inventoryAllowLinks()
				? mpcText("Click a count to see which products, pages or posts are included (sample of up to 80). Use View / Edit to open each item in a new tab.")
				: mpcText("Click a count to see which products, pages or posts are included (sample of up to 80). View / Edit links require the Pro add-on.");
			var table = document.createElement( 'table' );
			table.className = 'widefat lucidlab-mpc-table lucidlab-mpc-inventory-table';
			table.id = 'lucidlab-mpc-inventory-refs-table';
			table.innerHTML = ("<thead><tr><th>" + escapeHtml(mpcText("Reference")) + "</th><th>" + escapeHtml(mpcText("Count")) + "</th></tr></thead>");
			var tbody = document.createElement( 'tbody' );
			var samples = refs.samples || {};
			var allowLinks = inventoryAllowLinks();
			( refs.rows || [] ).forEach( function ( row ) {
				var tr = document.createElement( 'tr' );
				var tdLabel = document.createElement( 'td' );
				tdLabel.textContent = row.label || '';
				var tdCount = document.createElement( 'td' );
				var count = Number( row.count ) || 0;
				var key = row.key || '';
				var items = ( key && samples[ key ] ) ? samples[ key ] : [];
				if ( ! allowLinks && items && items.length ) {
					items = items.map( function ( item ) {
						return Object.assign( {}, item, { view: '', edit: '' } );
					} );
				}
				if ( count > 0 && items && items.length ) {
					var btn = document.createElement( 'button' );
					btn.type = 'button';
					btn.className = 'button-link lucidlab-mpc-inventory-count';
					btn.dataset.mpcInvKey = key;
					btn.dataset.mpcInvLabel = row.label || '';
					btn.dataset.mpcInvItems = JSON.stringify( items );
					btn.textContent = count.toLocaleString();
					tdCount.appendChild( btn );
				} else {
					tdCount.textContent = count.toLocaleString();
				}
				tr.append( tdLabel, tdCount );
				tbody.appendChild( tr );
			} );
			table.appendChild( tbody );
			refsBody.replaceChildren( hint, table );
		}
		async function run( sub ) {
			// In-button spinner on the pressed control; the other waits (shared status line).
			var pressed = sub === 'refs' ? refsBtn : diskBtn, other = sub === 'refs' ? diskBtn : refsBtn;
			buttonBusyInside( pressed, true );
			if ( other ) { other.disabled = true; }
			setProgress( 3, sub === 'refs' ? mpcText("Counting library references…") : mpcText("Scanning uploads…") );
			try {
				var first = true, d = {};
				do {
					var r = await post( { op: 'inventory', sub: sub, reset: first ? 1 : 0, direct: 1 } );
					if ( ! r || ! r.success ) { throw new Error( ( r && r.data && r.data.message ) || mpcText("Inventory scan failed.") ); }
					d = r.data || {};
					first = false;
					setProgress( Number( d.progress ) || 0, d.label || '' );
				} while ( ! d.done );
				// Replace only the scan that just finished; leave the other panel as-is until its next run.
				if ( sub === 'refs' ) {
					if ( d.refs ) { renderRefs( d.refs ); }
				} else if ( d.disk ) {
					renderDisk( d.disk );
				}
				setProgress( 100, ( d.label || mpcText("Done.") ) + mpcText(" Kept until the next scan.") );
				if ( progress ) { progress.hidden = true; }
			} catch ( e ) {
				setProgress( 0, e.message || mpcText("Scan failed.") );
				showActionError( e );
			} finally {
				buttonBusyInside( pressed, false );
				if ( other ) { other.disabled = false; }
			}
		}
		if ( diskBtn ) { diskBtn.addEventListener( 'click', function () { run( 'disk' ); } ); }
		if ( refsBtn ) { refsBtn.addEventListener( 'click', function () { run( 'refs' ); } ); }
	}
	if ( document.readyState !== 'loading' ) { setTimeout( inventoryInit, 0 ); } else { document.addEventListener( "DOMContentLoaded", inventoryInit ); }

	// After Options actions, land at the top so the notice in #lucidlab-mpc-top-notices is visible.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form || ! form.matches ) { return; }
		if ( form.matches( '.lucidlab-mpc-options-form, .lucidlab-mpc-index-form, .lucidlab-mpc-reset-form' ) ) {
			try { sessionStorage.setItem( 'lucidlab-mpc-options-scroll-top', '1' ); } catch ( error ) {}
		}
	} );
	window.addEventListener( 'load', function () {
		try {
			if ( sessionStorage.getItem( 'lucidlab-mpc-options-scroll-top' ) ) {
				sessionStorage.removeItem( 'lucidlab-mpc-options-scroll-top' );
				window.scrollTo( 0, 0 );
			}
		} catch ( error ) {}
	} );
	document.addEventListener( 'submit', function ( event ) {
		if ( event.defaultPrevented ) { return; }
		var form = event.target;
		if ( ! form || ! form.matches ) { return; }
		var button = event.submitter || form.querySelector( 'button[type="submit"]' );
		if ( ! button ) { return; }
		// Options + Logs form posts: keep the label, prepend a spinner inside the pressed button.
		// Reset uses the confirmed-submit path above; skip it here so Cancel does not leave a spinner.
		if ( form.matches( '.lucidlab-mpc-options-form, .lucidlab-mpc-index-form, .lucidlab-mpc-logging-form, .lucidlab-mpc-sizes-form' ) ) {
			preserveSubmitter( form, button );
			buttonBusyInside( button, true );
			form.querySelectorAll( 'button[type="submit"]' ).forEach( function ( b ) {
				if ( b !== button ) { b.disabled = true; }
			} );
		}
		// Rename Images search (GET) — keep the label and show an in-button spinner until navigation.
		if ( form.querySelector( '#lucidlab-mpc-rename-search' ) && button && ! button.id ) {
			buttonBusyInside( button, true );
		}
	} );
} )();
// Read-only comparison: merge choices stay in the group controls.
document.addEventListener('click', function(event) {
    var button=event.target.closest('[data-mpc-compare]');
    if(!button)return;
    var group=button.closest('.lucidlab-mpc-group'), dialog=document.createElement('dialog');
    dialog.style.cssText='width:90vw;max-width:1200px;max-height:90vh;overflow:auto';
    dialog.setAttribute('aria-label',mpcText("Compare candidate images"));
    var close=document.createElement('button');close.textContent=mpcText("Close comparison");close.className='button';
    close.onclick=function(){dialog.close();};dialog.appendChild(close);
    var grid=document.createElement('div');grid.style.cssText='display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px';
    group.querySelectorAll('.lucidlab-mpc-visual-thumb').forEach(function(link){
        var figure=document.createElement('figure'),img=document.createElement('img');
        img.src=link.href;img.style.cssText='width:100%;height:400px;object-fit:contain';
        var caption=document.createElement('figcaption');caption.textContent=link.querySelector('img')?.getAttribute('data-caption')||mpcText("Image");
        img.alt=caption.textContent;figure.append(img,caption);grid.appendChild(figure);
    });
    dialog.appendChild(grid);document.body.appendChild(dialog);dialog.addEventListener('close',function(){dialog.remove();button.focus();});dialog.showModal();
});

/* Install ping (opt-in): "Help Lucid Lab count active installs?" notice on the plugin screen. */
( function () {
	'use strict';
	var cfg = window.LucidLabMPC || {};
	var msgs = cfg.messages || {};
	function text( key ) {
		return Object.prototype.hasOwnProperty.call( msgs, key ) ? msgs[ key ] : key;
	}
	function call( params ) {
		var body = new URLSearchParams();
		body.set( 'action', 'lucidlab_mpc_op' );
		body.set( 'nonce', cfg.nonce || '' );
		Object.keys( params ).forEach( function ( k ) { body.set( k, params[ k ] ); } );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
			return r.text().then( function ( raw ) {
				var data = null;
				try { data = JSON.parse( raw ); } catch ( e ) { data = null; }
				if ( ! r.ok || ! data || ! data.success ) {
					throw new Error( ( data && data.data && data.data.message ) || ( 'HTTP ' + r.status ) );
				}
				return data.data;
			} );
		} );
	}
	document.querySelectorAll( '[data-mpc-ping-notice]' ).forEach( function ( notice ) {
		var yes = notice.querySelector( '[data-mpc-ping-yes]' );
		var no = notice.querySelector( '[data-mpc-ping-no]' );
		var message = notice.querySelector( '[data-mpc-ping-notice-message]' );
		if ( ! yes || ! no || ! message ) { return; }
		yes.addEventListener( 'click', function () {
			yes.disabled = true;
			no.disabled = true;
			message.textContent = text( 'Saving\u2026' );
			message.style.color = '';
			call( { op: 'ping_save', on: '1' } ).then( function () {
				message.textContent = text( 'Saved. This site now tells Lucid Lab that it uses the plugin. You can turn it off on the Options tab.' );
				var row = yes.parentNode;
				if ( row && row.parentNode ) { row.parentNode.removeChild( row ); }
			} ).catch( function ( e ) {
				message.textContent = e.message;
				message.style.color = '#a12224';
				yes.disabled = false;
				no.disabled = false;
			} );
		} );
		no.addEventListener( 'click', function () {
			yes.disabled = true;
			no.disabled = true;
			call( { op: 'ping_dismiss' } ).then( function () {
				notice.hidden = true;
			} ).catch( function ( e ) {
				message.textContent = e.message;
				message.style.color = '#a12224';
				yes.disabled = false;
				no.disabled = false;
			} );
		} );
	} );
} )();
