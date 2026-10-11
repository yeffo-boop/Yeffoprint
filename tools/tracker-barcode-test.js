// Checks the tracker's own barcode reader (barcodeFromImage() in
// yeffoprint-core/assets/tracker/tracker.js, used where the browser has no
// BarcodeDetector, like iPhone Safari) on generated barcodes: blurred,
// noisy, unevenly lit, small, upside down, plus random stripes and smudged
// codes that must never read as a wrong number.
//   node tools/tracker-barcode-test.js
const fs = require('fs');
const path = require('path');
const src = fs.readFileSync(process.argv[2] || path.join(__dirname, '../yeffoprint-core/assets/tracker/tracker.js'), 'utf8');
const block = src.slice(src.indexOf('/* bc:start'), src.indexOf('/* bc:end */'));
const api = new Function(block + '; return { barcodeFromImage, bcValid, bcRuns, bcFromRuns };')();

const L = [[3,2,1,1],[2,2,2,1],[2,1,2,2],[1,4,1,1],[1,1,3,2],[1,2,3,1],[1,1,1,4],[1,3,1,2],[1,2,1,3],[3,1,1,2]];
const FIRST = ['LLLLLL','LLGLGG','LLGGLG','LLGGGL','LGLLGG','LGGLLG','LGGGLL','LGLGLG','LGLGGL','LGGLGL'];
const UPCE = ['GGGLLL','GGLGLL','GGLLGL','GGLLLG','GLGGLL','GLLGGL','GLLLGG','GLGLGL','GLGLLG','GLLGLG'];
function check(d) { let s = 0, w = 3; for (let i = d.length - 1; i >= 0; i--) { s += +d[i] * w; w = 4 - w; } return String((10 - s % 10) % 10); }
// modules: array of 0/1 (1 = dark), starting with quiet zone
function mods(widths, startDark) { const out = []; let c = startDark ? 1 : 0; for (const w of widths) { for (let i = 0; i < w; i++) out.push(c); c ^= 1; } return out; }
function digitWidths(d, p) { return p === 'G' ? L[d].slice().reverse() : L[d]; }
function ean13(code) {
	let w = [1,1,1]; const par = FIRST[+code[0]];
	for (let i = 0; i < 6; i++) w = w.concat(digitWidths(+code[1+i], par[i]));
	w = w.concat([1,1,1,1,1]);
	for (let i = 0; i < 6; i++) w = w.concat(L[+code[7+i]]);
	w = w.concat([1,1,1]);
	return [].concat(Array(11).fill(0), mods(w, true), Array(11).fill(0));
}
function ean8(code) {
	let w = [1,1,1];
	for (let i = 0; i < 4; i++) w = w.concat(L[+code[i]]);
	w = w.concat([1,1,1,1,1]);
	for (let i = 0; i < 4; i++) w = w.concat(L[+code[4+i]]);
	w = w.concat([1,1,1]);
	return [].concat(Array(9).fill(0), mods(w, true), Array(9).fill(0));
}
function upce(six, ns, c) {
	let par = UPCE[+c]; if (ns === 1) par = par.replace(/[LG]/g, x => x === 'L' ? 'G' : 'L');
	let w = [1,1,1];
	for (let i = 0; i < 6; i++) w = w.concat(digitWidths(+six[i], par[i]));
	w = w.concat([1,1,1,1,1,1]);
	return [].concat(Array(9).fill(0), mods(w, true), Array(9).fill(0));
}
// Render: scale (px per module), offset, blur, contrast, lighting gradient, noise, flip.
function render(m, opts) {
	const W = opts.W || 1000, H = 40, scale = opts.scale, x0 = opts.x0 || 80;
	const row = new Float32Array(W);
	for (let x = 0; x < W; x++) {
		// area-sample the module grid for sub-pixel edges
		let v = 0; const N = 4;
		for (let s = 0; s < N; s++) { const mx = Math.floor((x + (s + .5) / N - x0) / scale); v += (mx >= 0 && mx < m.length && m[mx]) ? 1 : 0; }
		row[x] = v / N;
	}
	let out = row;
	for (let b = 0; b < (opts.blur || 0); b++) { const t = new Float32Array(W); for (let x = 0; x < W; x++) t[x] = (out[Math.max(0,x-1)] + out[x] + out[Math.min(W-1,x+1)]) / 3; out = t; }
	const img = { width: W, height: H, data: new Uint8ClampedArray(W * H * 4) };
	let seed = opts.seed || 1; const rnd = () => (seed = (seed * 16807) % 2147483647) / 2147483647;
	for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
		const light = (opts.light || 220) - (opts.grad || 0) * x / W;
		const dark = opts.dark || 40;
		let v = light - out[opts.flip ? W - 1 - x : x] * (light - dark) + (rnd() - .5) * (opts.noise || 0);
		const p = (y * W + x) * 4; img.data[p] = img.data[p+1] = img.data[p+2] = Math.max(0, Math.min(255, v)); img.data[p+3] = 255;
	}
	return img;
}
let pass = 0, fail = 0;
function t(name, m, expect, opts) {
	const got = api.barcodeFromImage(render(m, opts));
	if (got === expect) pass++; else { fail++; console.log('FAIL', name, JSON.stringify(opts), 'expected', expect, 'got', got); }
}
const codes13 = ['5449000000996', '3017620422003', '4006381333931', '9780201379624', '8710398527301'];
const upcas = ['05260305440', '01200000129', '03600029145', '04900002891', '07447100500'].map(x => x + check('0' + x));
const configs = [
	{ scale: 2.2 }, { scale: 3.1, blur: 1 }, { scale: 4.7, blur: 2, noise: 20 }, { scale: 6, blur: 3, noise: 30, grad: 90 },
	{ scale: 3.6, blur: 2, noise: 25, flip: true }, { scale: 2.6, blur: 1, light: 170, dark: 90, noise: 10 }, { scale: 5.3, blur: 4, noise: 15, grad: 60 }, { scale: 1.8, blur: 1, noise: 8 }, { scale: 8, blur: 6, noise: 40, grad: 120 }, { scale: 3, blur: 2, light: 140, dark: 95, noise: 6 },
];
for (const c of codes13) for (const o of configs) t('ean13 ' + c, ean13(c), c, o);
for (const c of upcas) { const e = '0' + c; if (check(e.slice(0, 12)) !== e[12]) throw new Error('bad test upc ' + c); for (const o of configs) t('upca ' + c, ean13(e), c, o); }
for (const c of ['96385074', '40170725', '55123457']) { if (check(c.slice(0,7)) !== c[7]) throw new Error('bad ean8 ' + c); for (const o of configs) t('ean8 ' + c, ean8(c), c, o); }
// UPC-E 0 425261 4 expands to 042100005264 (well-known example)
const upceCases = [['425261', 0, '4', '042100005264'], ['123456', 0, null, null], ['654321', 1, null, null]];
for (const [six, ns, c0, expect0] of upceCases) {
	// compute expansion + check
	const x = six[5]; const d = six;
	const mid = x <= '2' ? d.slice(0,2)+x+'0000'+d.slice(2,5) : x === '3' ? d.slice(0,3)+'00000'+d.slice(3,5) : x === '4' ? d.slice(0,4)+'00000'+d[4] : d.slice(0,5)+'0000'+x;
	const c = c0 || check(ns + mid); const expect = expect0 || (ns + mid + c);
	if (check(expect.slice(0, 11)) !== expect[11]) throw new Error('bad upce test');
	for (const o of configs) t('upce ' + six, upce(six, ns, c), expect, o);
}
// No false reads on noise / text-like patterns
for (let s = 1; s < 300; s++) {
	let seed = s; const rnd = () => (seed = (seed * 48271) % 2147483647) / 2147483647;
	const m = []; for (let i = 0; i < 260; i++) { const w = 1 + Math.floor(rnd() * 4); for (let j = 0; j < w; j++) m.push(i % 2); }
	t('random ' + s, m, '', { scale: 3, blur: 1, noise: 10, seed: s });
}
// A partly damaged code (smudge) must not read as a wrong number
for (const c of codes13) {
	const m = ean13(c); for (let i = 40; i < 46; i++) m[i] ^= 1;
	const got = api.barcodeFromImage(render(m, { scale: 3, blur: 1 }));
	if (got && got !== c) { fail++; console.log('WRONG READ', c, got); } else pass++;
}
console.log(pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
