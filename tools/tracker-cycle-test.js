// Checks the Dose Tracker's cycle planner ("8 weeks on, 4 off, repeating")
// in yeffoprint-core/assets/tracker/tracker.js against a customer's real
// setup, and that the PHP reminder sweep (YeffoPrint_Tracker_Schedule)
// gives the same answer as the app for every day of a year.
//   node tools/tracker-cycle-test.js
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const src = fs.readFileSync(path.join(__dirname, '../yeffoprint-core/assets/tracker/tracker.js'), 'utf8');

// Pull named top-level functions/vars out of tracker.js without running the app.
function grab(name) {
	const at = src.indexOf('\tfunction ' + name + '(');
	if (at < 0) throw new Error('missing ' + name);
	let i = src.indexOf('{', at), depth = 0;
	for (; i < src.length; i++) {
		if (src[i] === '{') depth++;
		else if (src[i] === '}' && --depth === 0) break;
	}
	return src.slice(at, i + 1);
}
const lines = src.split('\n');
const vars = ['DOW_LONG', 'MONTHS'].map((v) => lines.find((l) => l.startsWith('\tvar ' + v + ' ='))).join('\n');
const fns = ['pad', 'dateStr', 'parseDate', 'addDays', 'daysBetween', 'shortDate', 'fmtDay', 'isDueOn', 'timesOf', 'scheduleLabel',
	'weekLabel', 'cycleOf', 'cyclePhase', 'stepsOf', 'doseOn', 'restingNote', 'startAfterResume'].map(grab).join('\n');
const api = new Function(vars + '\nfunction amountLabel(d, u) { return d + " " + u; }\n' + fns +
	'\nreturn { isDueOn, cyclePhase, restingNote, startAfterResume, addDays, daysBetween, weekLabel };')();

let failed = 0;
function ok(cond, msg) {
	if (!cond) { failed++; console.log('FAIL ' + msg); }
}

// The customer's setup, all started the same Monday.
const start = '2026-09-07';
const cyc = { on: 8, off: 4 };
const setup = [
	{ compound: 'CJC-1295 no DAC Ipamorelin', schedule: { type: 'cycle', on: 5, off: 2 }, cycle: cyc },
	{ compound: 'Glow', schedule: { type: 'daily' }, cycle: cyc },
	{ compound: 'KPV', schedule: { type: 'daily' }, cycle: cyc },
	{ compound: 'MOTS-c', schedule: { type: 'weekdays', days: [2, 5] }, cycle: { on: 6, off: 4 } },
	{ compound: 'Semax', schedule: { type: 'cycle', on: 5, off: 2 }, cycle: { on: 4, off: 4 } },
].map((p) => Object.assign({ start, dose: 1, unit: 'mg', times: ['09:00'] }, p));

// Each one is due in every on week and never in an off week, over three rounds.
setup.forEach((p) => {
	const len = (p.cycle.on + p.cycle.off) * 7;
	for (let d = 0; d < len * 3; d++) {
		const date = api.addDays(start, d);
		const on = d % len < p.cycle.on * 7;
		if (!on) ok(!api.isDueOn(p, date), p.compound + ' due in an off week on ' + date);
		const ph = api.cyclePhase(p, date);
		ok(ph.on === on, p.compound + ' phase wrong on ' + date);
	}
	// At least one dose in the last on week (so it can't vanish two weeks early).
	let lastWeek = 0;
	for (let d = (p.cycle.on - 1) * 7; d < p.cycle.on * 7; d++) lastWeek += api.isDueOn(p, api.addDays(start, d)) ? 1 : 0;
	ok(lastWeek > 0, p.compound + ' has no doses in on week ' + p.cycle.on);
});

// The 8-week ones stay on Today through week 8 and come back after the 4 off.
const kpv = setup[2];
ok(api.isDueOn(kpv, api.addDays(start, 41)), 'KPV due in week 6');
ok(api.isDueOn(kpv, api.addDays(start, 55)), 'KPV due on the last day of week 8');
ok(!api.isDueOn(kpv, api.addDays(start, 56)), 'KPV off from week 9');
ok(api.isDueOn(kpv, api.addDays(start, 84)), 'KPV back after 4 weeks off');
ok(api.weekLabel(kpv, api.addDays(start, 41)) === 'on wk 6 of 8', 'week label in week 6: ' + api.weekLabel(kpv, api.addDays(start, 41)));
// MOTS-c's 6-week cycle doesn't touch the others.
ok(!api.isDueOn(setup[3], api.addDays(start, 43)) && api.isDueOn(setup[1], api.addDays(start, 43)), 'MOTS-c off while Glow is on in week 7');

// Off weeks and an ended length explain themselves on Today, naming the start date.
const offNote = api.restingNote(kpv, api.addDays(start, 60));
ok(offNote && /off week 1 of 4/.test(offNote.title) && /Sep 7/.test(offNote.text), 'off-week note: ' + JSON.stringify(offNote));
ok(api.restingNote(kpv, api.addDays(start, 20)) === null, 'no note in an on week');
const short = Object.assign({}, kpv, { weeks: 6 });
const endNote = api.restingNote(short, api.addDays(start, 43));
ok(endNote && /finished/.test(endNote.title) && /6-week length ended Oct 18/.test(endNote.text), 'length note: ' + JSON.stringify(endNote));
ok(api.restingNote(short, api.addDays(start, 42 + 14)) === null, 'length note gone after two weeks');

// Pausing stops the cycle clock: two weeks paused moves the start two weeks on.
const paused = Object.assign({}, kpv, { paused: true, pausedAt: '2026-10-05' });
ok(api.startAfterResume(paused, start, false, '2026-10-19') === '2026-09-21', 'resume shifts start by the paused days');
ok(api.startAfterResume(paused, '2026-09-01', false, '2026-10-19') === '2026-09-01', 'a start date typed while resuming wins');
ok(api.startAfterResume(paused, start, true, '2026-10-19') === start, 'still paused: start unchanged');
ok(api.startAfterResume(Object.assign({}, paused, { cycle: null }), start, false, '2026-10-19') === start, 'no cycle/steps/length: start unchanged');
ok(api.startAfterResume(Object.assign({}, paused, { pausedAt: '' }), start, false, '2026-10-19') === start, 'paused before this change: start unchanged');

// PHP reminder sweep agrees with the app every day for a year.
const php = `
define('ABSPATH', '/'); define('DAY_IN_SECONDS', 86400);
require ${JSON.stringify(path.join(__dirname, '../yeffoprint-core/includes/tracker/class-tracker-schedule.php'))};
$in = json_decode(stream_get_contents(STDIN), true); $out = [];
foreach ($in['protocols'] as $p) { $row = []; foreach ($in['dates'] as $d) { $row[] = YeffoPrint_Tracker_Schedule::is_due_on($p, $d) ? 1 : 0; } $out[] = $row; }
echo json_encode($out);`;
const dates = Array.from({ length: 366 }, (_, i) => api.addDays(start, i));
try {
	const res = JSON.parse(execFileSync('php', ['-r', php], { input: JSON.stringify({ protocols: setup, dates }) }).toString());
	setup.forEach((p, i) => dates.forEach((d, j) => ok(res[i][j] === (api.isDueOn(p, d) ? 1 : 0), 'PHP and app disagree for ' + p.compound + ' on ' + d)));
} catch (e) {
	console.log('(skipped the PHP check: ' + e.message.split('\n')[0] + ')');
}

console.log(failed ? failed + ' check(s) failed' : 'All cycle checks passed');
process.exit(failed ? 1 : 0);
