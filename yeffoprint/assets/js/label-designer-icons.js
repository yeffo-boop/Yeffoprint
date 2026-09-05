/**
 * Label Designer's curated icon set — direct request ("easy graphics").
 * Every shape here is hand-authored specifically for this feature (basic
 * geometric primitives — polygons, circles, simple paths), not pulled
 * from any licensed clip-art/icon library, matching this codebase's
 * existing "simple generic glyphs, no trademarked logos" convention
 * (assets/js/payment-icons.js). Each entry's `svg` is loaded into the
 * Fabric.js canvas via fabric.loadSVGFromString() and grouped into one
 * selectable, recolorable object (label-designer.js's addIcon()).
 */
( function () {
	'use strict';

	window.yeffoprintLabelDesignerIcons = [
		{ id: 'star', label: 'Star', svg: '<svg viewBox="0 0 100 100"><polygon points="50,2 61,35 96,35 67,56 78,89 50,68 22,89 33,56 4,35 39,35" fill="currentColor"/></svg>' },
		{ id: 'heart', label: 'Heart', svg: '<svg viewBox="0 0 100 100"><path d="M50 85 C20 65 0 40 0 25 C0 8 15 0 30 0 C42 0 50 8 50 20 C50 8 58 0 70 0 C85 0 100 8 100 25 C100 40 80 65 50 85 Z" fill="currentColor"/></svg>' },
		{ id: 'circle', label: 'Circle', svg: '<svg viewBox="0 0 100 100"><circle cx="50" cy="50" r="45" fill="currentColor"/></svg>' },
		{ id: 'diamond', label: 'Diamond', svg: '<svg viewBox="0 0 100 100"><polygon points="50,5 95,50 50,95 5,50" fill="currentColor"/></svg>' },
		{ id: 'drop', label: 'Drop', svg: '<svg viewBox="0 0 100 100"><path d="M50 5 C70 35 90 55 90 72 C90 88 72 98 50 98 C28 98 10 88 10 72 C10 55 30 35 50 5 Z" fill="currentColor"/></svg>' },
		{ id: 'leaf', label: 'Leaf', svg: '<svg viewBox="0 0 100 100"><path d="M10 90 C10 40 40 10 90 10 C90 60 60 90 10 90 Z" fill="currentColor"/></svg>' },
		{ id: 'hexagon', label: 'Hexagon', svg: '<svg viewBox="0 0 100 100"><polygon points="25,5 75,5 95,50 75,95 25,95 5,50" fill="currentColor"/></svg>' },
		{ id: 'pentagon', label: 'Pentagon', svg: '<svg viewBox="0 0 100 100"><polygon points="50,5 95,38 78,92 22,92 5,38" fill="currentColor"/></svg>' },
		{ id: 'sun', label: 'Sun', svg: '<svg viewBox="0 0 100 100"><circle cx="50" cy="50" r="24" fill="currentColor"/><g stroke="currentColor" stroke-width="7" stroke-linecap="round"><line x1="50" y1="2" x2="50" y2="16"/><line x1="50" y1="84" x2="50" y2="98"/><line x1="2" y1="50" x2="16" y2="50"/><line x1="84" y1="50" x2="98" y2="50"/><line x1="16" y1="16" x2="26" y2="26"/><line x1="74" y1="74" x2="84" y2="84"/><line x1="84" y1="16" x2="74" y2="26"/><line x1="26" y1="74" x2="16" y2="84"/></g></svg>' },
		{ id: 'arrow-right', label: 'Arrow', svg: '<svg viewBox="0 0 100 100"><path d="M10 40 H70 V25 L95 50 L70 75 V60 H10 Z" fill="currentColor"/></svg>' },
		{ id: 'arrow-up', label: 'Arrow Up', svg: '<svg viewBox="0 0 100 100"><path d="M40 90 V30 H25 L50 5 L75 30 H60 V90 Z" fill="currentColor"/></svg>' },
		{ id: 'check', label: 'Check', svg: '<svg viewBox="0 0 100 100"><path d="M15 55 L40 80 L88 20" fill="none" stroke="currentColor" stroke-width="12" stroke-linecap="round" stroke-linejoin="round"/></svg>' },
		{ id: 'plus', label: 'Plus', svg: '<svg viewBox="0 0 100 100"><path d="M40 10 H60 V40 H90 V60 H60 V90 H40 V60 H10 V40 H40 Z" fill="currentColor"/></svg>' },
		{ id: 'bolt', label: 'Bolt', svg: '<svg viewBox="0 0 100 100"><path d="M55 5 L20 55 H45 L35 95 L85 40 H55 Z" fill="currentColor"/></svg>' },
		{ id: 'flame', label: 'Flame', svg: '<svg viewBox="0 0 100 100"><path d="M50 95 C25 95 15 75 15 55 C15 35 30 20 35 5 C40 25 55 25 60 40 C70 30 75 15 75 15 C85 30 90 45 90 60 C90 80 75 95 50 95 Z" fill="currentColor"/></svg>' },
		{ id: 'banner', label: 'Banner', svg: '<svg viewBox="0 0 100 100"><path d="M5 20 H95 L80 45 L95 70 H5 L20 45 Z" fill="currentColor"/></svg>' },
		{ id: 'flower', label: 'Flower', svg: '<svg viewBox="0 0 100 100"><g fill="currentColor"><ellipse cx="50" cy="22" rx="14" ry="20"/><ellipse cx="78" cy="50" rx="20" ry="14"/><ellipse cx="50" cy="78" rx="14" ry="20"/><ellipse cx="22" cy="50" rx="20" ry="14"/><circle cx="50" cy="50" r="13"/></g></svg>' },
		{ id: 'shield', label: 'Shield', svg: '<svg viewBox="0 0 100 100"><path d="M50 5 L90 20 V50 C90 75 70 90 50 98 C30 90 10 75 10 50 V20 Z" fill="currentColor"/></svg>' },
		{ id: 'bell', label: 'Bell', svg: '<svg viewBox="0 0 100 100"><path d="M50 5 C35 5 25 18 25 35 V55 L12 75 H88 L75 55 V35 C75 18 65 5 50 5 Z" fill="currentColor"/><rect x="42" y="82" width="16" height="10" rx="5" fill="currentColor"/></svg>' }
	];
} )();
