/* Sticky Navigation */



$(function() {
	'use strict';

	var $navBar = $('#page-header'),
		$navParent = $(window),
		$sidebar = $('#sticker'),
		$sidebarParent = $('.page-body')

	$navBar.clingify({
		extraClass : 'navClingy',
		lockedClass : 'sticky-active' 
		
	});
	$sidebar.clingify({
		breakpointWidth : 1355,
		extraClass : 'sidebarClingy',
		lockedClass : 'sticky-active'		
	});
});