(function ($) {
	var wxrExport = {
		complete: {
			posts: 0,
			media: 0,
			users: 0,
			comments: 0,
			terms: 0,
			links: 0,
		},

		updateDelta: function (type, delta) {
			this.complete[ type ] += delta;

			var self = this;
			requestAnimationFrame(function () {
				self.render();
			});
		},
		updateProgress: function ( type, complete, total ) {
			var text = complete + '/' + total;
			$( '#completed-' + type).text( text );
			total = parseInt( total, 10 );
			if ( 0 === total || isNaN( total ) ) {
				total = 1;
			}
			var percent = parseInt( complete, 10 ) / total;
			$( '#progressbar-' + type ).val( percent * 100 );
			$( '#progress-' + type ).text( Math.round( percent * 100 ) + '%' );
		},
		render: function () {
			var types = Object.keys( this.complete );
			var complete = 0;
			var total = 0;

			for (var i = types.length - 1; i >= 0; i--) {
				var type = types[i];
				this.updateProgress( type, this.complete[ type ], this.data.count[ type ] );

				complete += this.complete[ type ];
				total += this.data.count[ type ];
			}

			this.updateProgress( 'total', complete, total );
		},
		log_level_orderby: function ( a, b ) {
			switch ( a ) {
				case 'error':
					switch ( b ) {
						case 'error':
							return 0;
						default:
							return 1;
					}
				case 'warning':
					switch ( b ) {
						case 'error':
							return -1;
						case 'warning':
							return 0;
						default:
							return 1;
					}
				case 'notice':
					switch ( b ) {
						case 'error':
						case 'warning':
							return -1;
						case 'notice':
							return 0;
						default:
							return 1;
					}
				case 'info':
					return -1;
				default:
					return 0;
			}
		},
		type_order_by: 	function ( a, b ) {
			switch ( a ) {
				case 'Post':
					switch ( b ) {
						case 'Post':
							return 0;
						default:
							return 1;
					}
				case 'Media':
					switch ( b ) {
						case 'Post':
							return -1;
						case 'Media':
							return 0;
						default:
							return 1;
					}
				case 'User':
					switch ( b ) {
						case 'Post':
						case 'Media':
							return -1;
						case 'User':
							return 0;
						default:
							return 1;
					}
				case 'Term':
					switch ( b ) {
						case 'Post':
						case 'Media':
						case 'User':
							return -1;
						case 'Term':
							return 0;
						default:
							return 1;
					}
				case 'Link':
					switch ( b ) {
						case 'Post':
						case 'Media':
						case 'User':
						case 'Term':
							return -1;
						case 'Link':
							return 0;
						default:
							return 1;
					}
				default:
					return 0;
			}
		}
	};
	wxrExport.data = wxrExportData;
	wxrExport.render();
    
	var evtSource = new EventSource( wxrExport.data.url );
	evtSource.onmessage = function ( message ) {
		var data = JSON.parse( message.data );
		switch ( data.action ) {
			case 'updateDelta':
				wxrExport.updateDelta( data.type, data.delta );
				break;

			case 'complete':
				evtSource.close();
				var export_status_msg = jQuery('#export-status-message');
				export_status_msg.text( wxrExport.data.strings.complete );
				export_status_msg.removeClass('notice-info');
				export_status_msg.addClass('notice-success');
				
				$( 'form#download' ).show();
				
				break;
		}
	};
	evtSource.addEventListener( 'log', function ( message ) {
		var data = JSON.parse( message.data );

		// add row to the table, allowing DataTable to keep rows sorted by log-level
		var table = $('#export-log').DataTable();
		var rowNode = table
			.row.add( [data.level, data.message] )
			.draw()
			.node();
	 
		$( rowNode ).addClass( data.level );
	});

	// sorting/pagination of log messages, using the DataTables jquery plugin
	$( '#export-log' ).dataTable( {
		order: [[ 0, 'desc' ]],
// @todo: orderFixed is supposed to make sure that the levels stay sorted when Message
//		is sorted, but it doesn't seem to be working
//	    orderFixed: {
//	        pre: [[ 0, 'desc' ]],
//	    },
		columnDefs: [
			{ orderable: true, targets: [ 0, 1 ] },
			{ type: 'log-level', targets: 0 },
			{ type: 'string', targets: 1 },
		],
		lengthMenu: [[ 10, 20, 40, -1 ], [ 10, 20, 40, 'All' ]],
		pageLength: 10,
		pagingType: 'full_numbers',
		language: {
			url: wxrExport.data.dataTables_language,
		}
	});
	
	// extend DataTables to allow sorting by log-level
	$.extend( jQuery.fn.dataTableExt.oSort, {
	    'log-level-asc': function( a, b ) {
	    	return wxrExport.log_level_orderby( a, b );
	    },
	    'log-level-desc': function(a,b) {
	    	return - wxrExport.log_level_orderby( a, b );
	    },
	    'type-asc': function( a, b ) {
	    	return wxrExport.type_orderby( a, b );
	    },
	    'type-desc': function(a,b) {
	    	return - wxrExport.type_orderby( a, b );
	    },
	} );
	
	$( 'form#download #submit' ).click( function ( event ) {
		$( 'form#download' ).hide();
		var export_status_msg = jQuery('#export-status-message');
		export_status_msg.text( wxrExport.data.strings.downloaded );

		return true;
	} );
})(jQuery);
