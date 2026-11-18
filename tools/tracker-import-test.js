// Checks the Backup, restore & import helpers in tracker.js (CSV parsing,
// dates, doses and injection site names from other apps).
//   node tools/tracker-import-test.js

const fs=require('fs');const src=fs.readFileSync(require('path').join(__dirname, '../yeffoprint-core/assets/tracker/tracker.js'),'utf8');
function grab(name){const at=src.indexOf('\tfunction '+name+'(');let i=src.indexOf('{',at),d=0;for(;i<src.length;i++){if(src[i]==='{')d++;else if(src[i]==='}'&&--d===0)break;}return src.slice(at,i+1);}
const lines=src.split('\n');const v=n=>lines.find(l=>l.startsWith('\tvar '+n+' ='));
let sites=src.slice(src.indexOf('\tvar SITES = ['),src.indexOf('];',src.indexOf('\tvar SITES = ['))+2);
const api=new Function(v('UNITS')+'\n'+sites+'\n'+['parseImportDate','parseImportDose','normUnit','matchSiteText','parseCsv','guessColumns'].map(grab).join('\n')+'\n'+src.slice(src.indexOf('\tvar IMPORT_FIELDS'),src.indexOf('];',src.indexOf('\tvar IMPORT_FIELDS'))+2)+'\nreturn {parseImportDate,parseImportDose,matchSiteText,parseCsv,guessColumns};')();
let failed=0;const eq=(a,b,m)=>{const A=JSON.stringify(a),B=JSON.stringify(b);if(A!==B){failed++;console.log('FAIL '+m+' got '+A+' want '+B);}};
eq(api.parseImportDate('10/01/2026 8:00 AM'),{date:'2026-10-01',time:'08:00'},'US date+time');
eq(api.parseImportDate('2026-10-15'),{date:'2026-10-15',time:null},'ISO');
eq(api.parseImportDate('25/10/2026'),{date:'2026-10-25',time:null},'D/M when day>12');
eq(api.parseImportDate('Oct 3, 2026 9:30 PM'),{date:'2026-10-03',time:'21:30'},'text date');
eq(api.parseImportDose('2.5 mg'),{dose:2.5,unit:'mg'},'dose mg');
eq(api.parseImportDose('250mcg'),{dose:250,unit:'mcg'},'dose mcg');
eq(api.parseImportDose('10','units'),{dose:10,unit:'units'},'unit column');
eq(api.matchSiteText('Abdomen - Left Lower'),'belly-ll','abdomen left lower');
eq(api.matchSiteText('Left Upper Abdomen'),'belly-ul','left upper abdomen');
eq(api.matchSiteText('R thigh'),'thigh-r','R thigh');
eq(api.matchSiteText('Belly, lower right'),'belly-lr','our own label');
eq(api.matchSiteText('stomach'),'','no side');
eq(api.parseCsv('a,b\n"x, y",2\r\n\'=1,3').slice(1),[['x, y','2'],['=1','3']],'csv quotes + guard');
eq(api.guessColumns(['Date','Time','Medication','Dose','Unit','Site','Weight','Status','Notes']),{date:0,time:1,compound:2,dose:3,unit:4,site:5,weight:6,status:7,note:8},'own headers');
eq(api.guessColumns(['Date','Time','Compound','Dose','Unit','Status','Units drawn','Injection site','How you felt','Note']).site,7,'our export site column');
console.log(failed ? failed + ' failed' : 'All import checks passed');process.exitCode = failed ? 1 : 0;
