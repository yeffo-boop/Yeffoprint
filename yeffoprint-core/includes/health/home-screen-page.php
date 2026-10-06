<?php
/**
 * Page body for YeffoPrint_Home_Screen_Help (class-home-screen-help.php).
 *
 * @var string $calculator_url
 * @var string $tracker_url
 * @var string $images
 * @var string $calculator_icon
 * @var string $tracker_icon
 */

defined( 'ABSPATH' ) || exit;

$share_svg = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 3v12M8 7l4-4 4 4M7 11H5v10h14V11h-2"/></svg>';
$plus_svg  = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="4" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 8v8M8 12h8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Add YeffoHealth to your Home Screen</title>
<meta name="description" content="Step-by-step pictures for adding the YeffoHealth Peptide Calculator and Dose Tracker to your iPhone or Android Home Screen.">
<link rel="canonical" href="<?php echo esc_url( YeffoPrint_Home_Screen_Help::url() ); ?>">
<style>
:root{--ink:#141414;--muted:#5d5d5d;--line:#e4e2dd;--bg:#f7f6f3;--card:#fff;--pink:#e6007e;--blue:#0a84ff;--green:#34c759;--chrome:#1a73e8}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
a{color:inherit}
.wrap{max-width:760px;margin:0 auto;padding:24px 16px 64px}
.top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:28px}
.brand{font-weight:700;font-size:20px;text-decoration:none}
.back{font-size:14px;color:var(--muted)}
.eyebrow{display:inline-block;font:600 12px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.12em;text-transform:uppercase;border:1px solid var(--line);border-radius:999px;padding:8px 12px;background:var(--card)}
h1{font-size:34px;line-height:1.1;margin:16px 0 10px;letter-spacing:-.02em}
.lede{color:var(--muted);margin:0 0 20px;font-size:17px}
.apps{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:28px}
.app{display:flex;align-items:center;gap:12px;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:12px;text-decoration:none}
.app img{width:48px;height:48px;border-radius:12px;flex:none}
.app b{display:block;font-size:15px}
.app span{font-size:13px;color:var(--muted)}
.tabs{display:flex;gap:6px;background:#ebe9e4;border-radius:14px;padding:4px;margin-bottom:20px;position:sticky;top:8px;z-index:2}
.tabs button{flex:1;border:0;background:transparent;border-radius:10px;padding:10px;font:600 15px/1.2 inherit;color:var(--muted);cursor:pointer}
.tabs button[aria-selected=true]{background:var(--card);color:var(--ink);box-shadow:0 1px 3px rgba(0,0,0,.12)}
.panel[hidden]{display:none}
.step{display:grid;grid-template-columns:1fr 240px;gap:20px;align-items:center;background:var(--card);border:1px solid var(--line);border-radius:20px;padding:20px;margin-bottom:14px}
.step h2{font-size:19px;margin:0 0 6px;display:flex;gap:10px;align-items:baseline}
.num{flex:none;display:inline-grid;place-items:center;width:28px;height:28px;border-radius:50%;background:var(--ink);color:#fff;font-size:14px}
.step p{margin:0;color:var(--muted)}
.step p+p{margin-top:8px}
.ico{display:inline-flex;vertical-align:-3px;color:var(--blue)}
.note{background:#fff7e0;border:1px solid #f1dfa6;border-radius:14px;padding:14px 16px;margin:6px 0 14px;font-size:15px}
.shot{width:100%;border-radius:14px;border:1px solid var(--line);display:block}
/* drawn phone screens */
.phone{position:relative;width:240px;height:200px;border-radius:26px;background:#fff;border:6px solid #1c1c1e;overflow:hidden;font-size:11px;color:#111;margin:0 auto}
.page{position:absolute;inset:0;background:#f7f6f3;padding:14px}
.page i{display:block;height:8px;border-radius:4px;background:#dedbd5;margin-bottom:8px}
.page i:nth-child(2){width:70%}.page i:nth-child(3){width:85%}
.page b{display:block;font-size:14px;margin-bottom:10px}
.sbar{position:absolute;left:0;right:0;bottom:0;background:rgba(249,249,249,.97);border-top:1px solid #ddd;padding:8px 10px 10px}
.url{background:#e9e9eb;border-radius:9px;padding:6px 10px;text-align:center;font-size:11px;margin-bottom:8px}
.tools{display:flex;justify-content:space-between;align-items:center;color:var(--blue);padding:0 4px}
.tools span{display:inline-grid;place-items:center;width:28px;height:24px;font-size:15px;line-height:1}
.ibar{display:flex;align-items:center;gap:8px;background:#e9e9eb;border-radius:18px;padding:4px 8px;color:#333;font-size:13px}
.ibar span{display:inline-grid;place-items:center;width:24px;height:24px}
.ibar .iurl{flex:1;width:auto;font-size:11px}
.imenu{position:absolute;left:10px;bottom:50px;width:150px;background:#fff;border-radius:12px;box-shadow:0 4px 18px rgba(0,0,0,.2);overflow:hidden}
.imenu div{display:flex;justify-content:space-between;align-items:center;padding:7px 10px;border-bottom:1px solid #eee;font-size:11.5px}
.imenu div:last-child{border-bottom:0}
.hl{position:relative;border-radius:50%;box-shadow:0 0 0 3px var(--pink);background:rgba(230,0,126,.08)}
.hl-row{box-shadow:inset 0 0 0 3px var(--pink);background:rgba(230,0,126,.06)!important}
.sheet{position:absolute;left:0;right:0;bottom:0;background:#f2f2f7;border-radius:14px 14px 0 0;padding:10px 8px;box-shadow:0 -6px 20px rgba(0,0,0,.15)}
.sheet .grp{background:#fff;border-radius:10px;overflow:hidden;margin-top:6px}
.sheet .row{display:flex;justify-content:space-between;align-items:center;padding:8px 10px;border-bottom:1px solid #eee;font-size:11.5px}
.sheet .row:last-child{border-bottom:0}
.sheet .row .ico{color:#111}
.dim{position:absolute;inset:0;background:rgba(0,0,0,.25)}
.dialog{position:absolute;inset:0;background:#f2f2f7}
.dhead{display:flex;justify-content:space-between;align-items:center;padding:10px 12px;font-size:12px;border-bottom:1px solid #ddd;background:#f9f9f9}
.dhead .blue{color:var(--blue)}
.dhead .add{color:var(--blue);font-weight:700;padding:3px 7px;border-radius:8px}
.dbody{display:flex;gap:10px;align-items:center;background:#fff;margin:10px;border-radius:10px;padding:10px}
.dbody img{width:40px;height:40px;border-radius:9px}
.dbody .nm{font-size:13px;font-weight:600;border-bottom:1px solid #eee;padding-bottom:4px;margin-bottom:4px}
.dbody small{color:#888}
.toggle{display:flex;justify-content:space-between;align-items:center;background:#fff;margin:0 10px;border-radius:10px;padding:8px 10px;font-size:11.5px}
.sw{width:30px;height:18px;border-radius:9px;background:var(--green);position:relative}
.sw:after{content:"";position:absolute;right:2px;top:2px;width:14px;height:14px;border-radius:50%;background:#fff}
.home{position:absolute;inset:0;background:linear-gradient(160deg,#5e4ab8,#d64d8f 60%,#f39a6b);padding:16px 14px;display:grid;grid-template-columns:repeat(4,1fr);gap:14px 8px;align-content:start}
.home div{text-align:center;color:#fff;font-size:9.5px;line-height:1.2}
.home div i{display:block;width:38px;height:38px;border-radius:10px;margin:0 auto 4px;background:rgba(255,255,255,.35)}
.home div img{display:block;width:38px;height:38px;border-radius:10px;margin:0 auto 4px}
.home .new img{box-shadow:0 0 0 3px #fff,0 0 0 6px var(--pink)}
.ctop{position:absolute;top:0;left:0;right:0;background:#fff;border-bottom:1px solid #e0e0e0;padding:8px 8px;display:flex;align-items:center;gap:6px}
.ctop .curl{flex:1;background:#f1f3f4;border-radius:14px;padding:5px 10px;font-size:11px}
.ctop .dots{display:inline-grid;place-items:center;width:24px;height:24px;font-size:16px;font-weight:700;color:#444}
.cmenu{position:absolute;top:6px;right:6px;width:170px;background:#fff;border-radius:8px;box-shadow:0 4px 18px rgba(0,0,0,.25);padding:4px 0}
.cmenu div{padding:6px 12px;font-size:11.5px}
.cmenu .icons{display:flex;justify-content:space-between;color:#555;border-bottom:1px solid #eee;font-size:13px}
.adlg{position:absolute;left:14px;right:14px;top:40px;background:#fff;border-radius:18px;padding:14px;box-shadow:0 6px 24px rgba(0,0,0,.3)}
.adlg h3{margin:0 0 10px;font-size:13px}
.adlg .appl{display:flex;gap:8px;align-items:center;font-size:11.5px}
.adlg .appl img{width:32px;height:32px;border-radius:8px}
.adlg .btns{display:flex;justify-content:flex-end;gap:14px;margin-top:14px;font-weight:600;color:var(--chrome);font-size:12px}
.adlg .btns span{padding:3px 8px;border-radius:12px}
.foot{margin-top:28px;color:var(--muted);font-size:15px}
.foot b{color:var(--ink)}
@media (max-width:620px){
  h1{font-size:28px}
  .apps{grid-template-columns:1fr}
  .step{grid-template-columns:1fr;gap:14px;padding:16px}
  .phone{width:100%;max-width:280px}
}
</style>
</head>
<body>
<div class="wrap">
	<div class="top">
		<a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">YeffoDesign</a>
		<a class="back" href="<?php echo esc_url( home_url( '/health/' ) ); ?>">YeffoHealth</a>
	</div>

	<span class="eyebrow">How to</span>
	<h1>Add YeffoHealth to your Home Screen</h1>
	<p class="lede">Put the Peptide Calculator and Dose Tracker on your phone so they open like an app. They're free, and there's nothing to download from an app store. Open the one you want on your phone, then follow the steps below.</p>

	<div class="apps">
		<a class="app" href="<?php echo esc_url( $calculator_url ); ?>"><img src="<?php echo esc_url( $calculator_icon ); ?>" alt=""><div><b>Peptide Calculator</b><span>Open it on your phone</span></div></a>
		<a class="app" href="<?php echo esc_url( $tracker_url ); ?>"><img src="<?php echo esc_url( $tracker_icon ); ?>" alt=""><div><b>Dose Tracker</b><span>Open it on your phone</span></div></a>
	</div>

	<div class="tabs" role="tablist">
		<button type="button" role="tab" id="tab-ios" aria-controls="ios" aria-selected="true">iPhone &amp; iPad</button>
		<button type="button" role="tab" id="tab-android" aria-controls="android" aria-selected="false">Android</button>
	</div>

	<section class="panel" id="ios" role="tabpanel" aria-labelledby="tab-ios">
		<div class="step">
			<div>
				<h2><span class="num">1</span>Open the page in Safari</h2>
				<p>Tap the Peptide Calculator or Dose Tracker link above. On iPhone, Safari works best for this.</p>
				<p>You may see this card at the top of the page. It's a reminder of the next steps.</p>
			</div>
			<img class="shot" src="<?php echo esc_url( $images . 'iphone-card.png' ); ?>" alt="The calculator's card: Add it to your Home Screen. Tap Share, then Add to Home Screen.">
		</div>

		<div class="step">
			<div>
				<h2><span class="num">2</span>Tap ☰, then Share</h2>
				<p>Tap the menu button with three lines <b>☰</b>, just to the left of the web address. Then tap <b>Share</b>.</p>
				<p>On older iPhones, the Share button <span class="ico"><?php echo $share_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span> (a square with an arrow pointing up) is right in the bar at the bottom of Safari, so tap that instead.</p>
			</div>
			<div class="phone" aria-hidden="true">
				<div class="page"><b>Peptide Calculator</b><i></i><i></i><i></i></div>
				<div class="imenu">
					<div>Reload</div>
					<div class="hl-row"><b>Share</b><span class="ico"><?php echo $share_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></div>
					<div>Add to Favorites</div>
				</div>
				<div class="sbar">
					<div class="ibar"><span class="hl">☰</span><span class="iurl">yeffodesign.com</span><span>↻</span></div>
				</div>
			</div>
		</div>

		<div class="step">
			<div>
				<h2><span class="num">3</span>Tap "Add to Home Screen"</h2>
				<p>Scroll down the list that pops up until you see <b>Add to Home Screen</b> <span class="ico" style="color:inherit"><?php echo $plus_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>, then tap it.</p>
			</div>
			<div class="phone" aria-hidden="true">
				<div class="page"><b>Peptide Calculator</b><i></i><i></i><i></i></div>
				<div class="dim"></div>
				<div class="sheet">
					<div class="grp">
						<div class="row">Copy<span>⎘</span></div>
						<div class="row">Add Bookmark<span>📖</span></div>
						<div class="row hl-row"><b>Add to Home Screen</b><span class="ico"><?php echo $plus_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></div>
						<div class="row">Find on Page<span>🔍</span></div>
					</div>
				</div>
			</div>
		</div>

		<div class="step">
			<div>
				<h2><span class="num">4</span>Tap Add</h2>
				<p>If you see <b>Open as Web App</b>, leave it switched on. Then tap <b>Add</b> at the top right.</p>
			</div>
			<div class="phone" aria-hidden="true">
				<div class="dialog">
					<div class="dhead"><span class="blue">Cancel</span><b>Add to Home Screen</b><span class="add hl-row">Add</span></div>
					<div class="dbody"><img src="<?php echo esc_url( $calculator_icon ); ?>" alt=""><div><div class="nm">YH Calculator</div><small>yeffodesign.com</small></div></div>
					<div class="toggle">Open as Web App<span class="sw"></span></div>
				</div>
			</div>
		</div>

		<div class="step">
			<div>
				<h2><span class="num">5</span>Open it from your Home Screen</h2>
				<p>The new icon is on your Home Screen. Tap it and it opens full screen, like an app.</p>
				<p>For the Dose Tracker, sign in once after opening it from the icon. It keeps you signed in. Dose reminders on iPhone only work when the tracker is opened from the Home Screen (iOS 16.4 or newer).</p>
			</div>
			<div class="phone" aria-hidden="true">
				<div class="home">
					<div><i></i>Photos</div><div><i></i>Mail</div><div><i></i>Maps</div><div><i></i>Notes</div>
					<div class="new"><img src="<?php echo esc_url( $calculator_icon ); ?>" alt="">YH Calculator</div><div class="new"><img src="<?php echo esc_url( $tracker_icon ); ?>" alt="">YeffoHealth</div>
				</div>
			</div>
		</div>
	</section>

	<section class="panel" id="android" role="tabpanel" aria-labelledby="tab-android" hidden>
		<div class="step">
			<div>
				<h2><span class="num">1</span>Open the page in Chrome</h2>
				<p>Tap the Peptide Calculator or Dose Tracker link above.</p>
				<p>If the page shows an <b>Install</b> button like this one, tap it and skip to step 3.</p>
			</div>
			<img class="shot" src="<?php echo esc_url( $images . 'android-card.png' ); ?>" alt="The calculator's card: Add it to your Home Screen, with an Install button.">
		</div>

		<div class="step">
			<div>
				<h2><span class="num">2</span>Tap ⋮, then "Add to Home screen"</h2>
				<p>Tap the three dots <b>⋮</b> at the top right of Chrome. Then tap <b>Add to Home screen</b>. On some phones it says <b>Install app</b> instead.</p>
			</div>
			<div class="phone" aria-hidden="true">
				<div class="page" style="padding-top:52px"><b>Peptide Calculator</b><i></i><i></i><i></i></div>
				<div class="ctop"><span>⌂</span><span class="curl">yeffodesign.com</span><span class="dots hl">⋮</span></div>
				<div class="cmenu" style="top:40px">
					<div class="icons"><span>→</span><span>☆</span><span>⬇</span><span>ⓘ</span><span>↻</span></div>
					<div>New tab</div>
					<div>Bookmarks</div>
					<div class="hl-row"><b>Add to Home screen</b></div>
					<div>Settings</div>
				</div>
			</div>
		</div>

		<div class="step">
			<div>
				<h2><span class="num">3</span>Tap Install</h2>
				<p>Chrome asks to confirm. Tap <b>Install</b> (or <b>Add</b>). If it asks where to put it, choose <b>Add to Home screen</b>.</p>
			</div>
			<div class="phone" aria-hidden="true">
				<div class="page"><b>Peptide Calculator</b><i></i><i></i><i></i></div>
				<div class="dim"></div>
				<div class="adlg">
					<h3>Install app</h3>
					<div class="appl"><img src="<?php echo esc_url( $calculator_icon ); ?>" alt=""><div><b>YH Calculator</b><br>yeffodesign.com</div></div>
					<div class="btns"><span>Cancel</span><span class="hl-row">Install</span></div>
				</div>
			</div>
		</div>

		<div class="step">
			<div>
				<h2><span class="num">4</span>Open it from your Home Screen</h2>
				<p>The new icon is on your Home Screen (or in your app list). Tap it and it opens full screen, like an app.</p>
				<p>For the Dose Tracker, sign in once after opening it from the icon. It keeps you signed in.</p>
			</div>
			<div class="phone" aria-hidden="true">
				<div class="home">
					<div><i></i>Phone</div><div><i></i>Messages</div><div><i></i>Camera</div><div><i></i>Chrome</div>
					<div class="new"><img src="<?php echo esc_url( $calculator_icon ); ?>" alt="">YH Calculator</div><div class="new"><img src="<?php echo esc_url( $tracker_icon ); ?>" alt="">YeffoHealth</div>
				</div>
			</div>
		</div>
	</section>

	<p class="note"><b>Want both?</b> The calculator and the tracker are two separate icons. Open each one and repeat the steps.</p>

	<p class="foot">Still stuck? <a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><b>Message us</b></a> and we'll walk you through it.</p>
</div>
<script>
(function () {
	var tabs = document.querySelectorAll( '.tabs button' );
	function show( id ) {
		tabs.forEach( function ( t ) {
			var on = t.getAttribute( 'aria-controls' ) === id;
			t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			document.getElementById( t.getAttribute( 'aria-controls' ) ).hidden = ! on;
		} );
	}
	tabs.forEach( function ( t ) {
		t.addEventListener( 'click', function () { show( t.getAttribute( 'aria-controls' ) ); } );
	} );
	if ( location.hash === '#android' || /android/i.test( navigator.userAgent ) ) {
		show( 'android' );
	}
})();
</script>
</body>
</html>
