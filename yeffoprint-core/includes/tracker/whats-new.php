<?php
/**
 * Dose Tracker "What's new" entries, newest first. Shown on the Me tab
 * and as a one-time banner on Today when there's something the customer
 * hasn't seen yet.
 *
 * Direct request (Jeff): keep this updated every time a tracker feature
 * ships. Every PR that changes what customers see in the tracker adds an
 * entry at the top: `id` must be new and sort after the previous one
 * (date plus a letter), `title` a few words, `text` one or two plain
 * sentences written for customers.
 */

defined( 'ABSPATH' ) || exit;

return [
	[
		'id'    => '2026-09-29c',
		'date'  => '2026-09-29',
		'title' => 'Travel mode',
		'text'  => 'Flying somewhere with a different time zone? Today asks how to handle your reminders: ease into local time a couple of hours a day, switch right away, or keep your home time.',
	],
	[
		'id'    => '2026-09-29b',
		'date'  => '2026-09-29',
		'title' => 'Share a protocol',
		'text'  => 'Open any peptide or medication and tap "Share this protocol" to send a link with the dose and schedule (and how to mix it, if you like). Whoever opens it can add it to their own tracker in one tap. Your name and history are never included, and you can stop any link from Me › Shared links.',
	],
	[
		'id'    => '2026-09-29a',
		'date'  => '2026-09-29',
		'title' => 'Your whole supply',
		'text'  => 'The Vials tab is now Supply. Add the vials, pens and pills you have at home to see how long they\'ll last, plan when to mix each new vial, and get a heads-up before you run low.',
	],
	[
		'id'    => '2026-09-28c',
		'date'  => '2026-09-28',
		'title' => 'Blends',
		'text'  => 'Tracking a blend like BPC-157 + TB-500? When you mix a vial, pick "Blend" and enter each peptide. It works for vials you bought blended and ones you mix yourself. Dose by the whole blend or by one peptide, and every dose shows how much of each you\'re getting.',
	],
	[
		'id'    => '2026-09-28b',
		'date'  => '2026-09-28',
		'title' => 'Multi-dose pens',
		'text'  => 'Using a pen instead of a syringe? Pick "Multi-dose pen" when you add an injection, then mix your pen just like a vial (3 mL is filled in for you). Each dose shows the units to dial and how many doses are left in the pen.',
	],
	[
		'id'    => '2026-09-28a',
		'date'  => '2026-09-28',
		'title' => 'Track any medication',
		'text'  => 'The tracker now works for more than peptides. Choose how you take it (pill, nasal spray, inhaler, patch, drops and more), with matching units like tablets and sprays. Start typing a name for suggestions, or enter anything.',
	],
	[
		'id'    => '2026-09-27b',
		'date'  => '2026-09-27',
		'title' => 'Order labels from your vials',
		'text'  => 'On the Vials tab, tap "Order labels" to get a label for every vial in one order, with each peptide and strength filled in.',
	],
	[
		'id'    => '2026-09-27a',
		'date'  => '2026-09-27',
		'title' => 'Dose Tracker is here',
		'text'  => 'Log doses, see how many units to draw, keep track of your vials and get reminders when it\'s time. Everything you enter is encrypted.',
	],
];
