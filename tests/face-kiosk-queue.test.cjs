'use strict';
// Database-free mocked DOM, camera, face-api and HTTP tests. Run: node tests/face-kiosk-queue.test.cjs

async function runTests(SOURCE) {
 
async function harness(cfg={}) {
 let t=0,seq=0; const timers=new Map(), nodes={}, events={};
 const state={faces:1,captures:0,posts:[],starts:0,stops:0};
 const node=id=>nodes[id] ||= {disabled:true,hidden:false,textContent:'',dataset:{},value:0,readyState:2,videoWidth:640,videoHeight:480,listeners:{},addEventListener(k,f){this.listeners[k]=f},focus(){},getContext(){return {drawImage(){}}},async play(){}};
 const document={hidden:false,getElementById:node,createElement:()=>node('frame'),addEventListener(k,f){events[k]=f}};
 const window={isSecureContext:true,location:{href:'http://localhost/modules/attendance/face-kiosk.php'},addEventListener(k,f){events[k]=f}};
 const track={stop(){state.stops++},addEventListener(){}};
 const navigator={mediaDevices:{async getUserMedia(){state.starts++;if(cfg.cameraPending)await new Promise(resolve=>{state.openCamera=resolve});if(cfg.cameraError)throw {name:'NotAllowedError'};return {getTracks:()=>[track],getVideoTracks:()=>[track]}}}};
 const net={loadFromUri:async()=>{if(cfg.modelError)throw Error()}};
 const faceapi={TinyFaceDetectorOptions:function(){},nets:{tinyFaceDetector:net,faceLandmark68TinyNet:net,faceRecognitionNet:net},draw:{drawDetections(){}},detectAllFaces(){return {then(resolve,reject){if(cfg.detectionPending)return new Promise(done=>{state.detect=()=>done(Array(state.faces).fill({}))}).then(resolve,reject);return Promise.resolve(Array(state.faces).fill({})).then(resolve,reject)},withFaceLandmarks(){return {withFaceDescriptors:async()=>{state.captures++;return Array.from({length:cfg.captureFaces??state.faces},()=>({descriptor:new Float32Array(128)}))}}}}}};
 window.faceapi=faceapi;
 class FormData {constructor(){this.data={}} append(k,v){this.data[k]=v}delete(k){delete this.data[k]}}
 class AbortController {constructor(){this.signal={}}abort(){this.signal.onabort?.()}}
 const fetch=async(url,{body,signal})=>{if(!state.stops)throw Error('camera active at POST');state.posts.push(body.data.action);if(cfg.networkError)throw Error();if(cfg.pending)return new Promise((res,rej)=>{state.respond=()=>res(response());signal.onabort=()=>rej(Error())});return response()};
 const response=()=>({ok:true,headers:{get:()=> 'application/json'},json:async()=>({result:cfg.result||'time_in',employee:'Test Employee',message:'Server outcome'})});
 const setTimeout=(fn,ms)=>{timers.set(++seq,{fn,due:t+ms});return seq}, clearTimeout=id=>timers.delete(id);
 const flush=async()=>{for(let i=0;i<40;i++)await Promise.resolve()};
 await new Function('window','document','navigator','faceapi','fetch','FormData','AbortController','URL','setTimeout','clearTimeout','performance','return '+SOURCE.trim())(window,document,navigator,faceapi,fetch,FormData,AbortController,class URL {constructor(path,base){this.href=base+path}},setTimeout,clearTimeout,{now:()=>t});
 await flush();
 return {state,node,events,document,async click(id,event='click',args={}){node(id).listeners[event]?.({button:0,detail:0,preventDefault(){},...args});await flush()},async advance(ms){const end=t+ms;for(;;){let next=[...timers].filter(([,v])=>v.due<=end).sort((a,b)=>a[1].due-b[1].due)[0];if(!next)break;t=next[1].due;timers.delete(next[0]);next[1].fn();await flush()}t=end;await flush()}};
}

 let count=0;
 const check=(value,label)=>{if(!value)throw Error(label);count++};
 let h=await harness();
 check(!h.state.starts&&!h.node('time-in').disabled,'camera off initially');
 await h.click('time-in'); await h.click('time-out'); await h.advance(1900);
 check(h.state.starts===1&&!h.state.captures,'rapid clicks and stable gate');
 await h.advance(300);
 check(h.state.posts.length===1&&h.state.posts[0]==='time_in'&&h.state.stops===1,'one fixed-action POST after camera stop');
 check(!h.node('time-in').disabled&&h.node('result-employee').textContent.includes('Test Employee'),'next employee enabled after settling');
 await h.click('time-out');
 check(h.state.starts===2&&h.node('result-panel').hidden&&!h.node('result-employee').textContent,'new session clears personal information');
 h.state.faces=0;await h.advance(9000);
 check(!h.node('scan-panel').hidden&&h.node('time-in').disabled,'old countdown cannot clear new scan');
 await h.click('stop');check(!h.node('time-in').disabled,'cancel permits retry after cleanup');
 await h.advance(8000);check(h.node('result-panel').hidden,'eight second expiry');
 h=await harness();await h.click('time-in');await h.advance(2200);
 await h.advance(7700);await h.click('extend-result','pointerdown');await h.click('extend-result','click',{detail:1});
 await h.advance(500);check(!h.node('result-panel').hidden&&!h.node('time-in').disabled,'extension survives old deadline and never blocks queue');
 await h.click('time-out');check(!h.node('result-employee').textContent,'extended result replaced');
 await h.advance(2200);check(h.node('result-action').textContent.includes('Time Out'),'replacement result has new action');
 for(let i=0;i<7;i++)await h.click('extend-result','keydown',{key:'Enter'});
 check(h.node('extend-result').disabled,'extension capped');
 await h.advance(59000);check(!h.node('result-panel').hidden,'held result visible');
 await h.advance(1000);check(h.node('result-panel').hidden,'60 second privacy cap');
 h=await harness({pending:true});await h.click('time-in');await h.advance(2200);
 await h.click('time-out');check(h.state.starts===1&&h.node('time-in').disabled,'pending request blocks next camera');
 h.state.respond();await h.advance(0);check(!h.node('time-in').disabled,'settled request unlocks queue');
 await h.click('time-out');await h.advance(2200);check(h.state.posts.length===2,'next person submits once');
 for(const result of ['cooldown','duplicate_time_in','duplicate_time_out','not_recognized','invalid_action']){
 h=await harness({result});await h.click('time-in');await h.advance(2200);
 check(h.node('result').textContent==='Server outcome'&&!h.node('time-out').disabled,'server outcome preserved and retry available: '+result);
 check(h.node('result-panel').dataset.kind===(result==='invalid_action'?'error':'notice'),'outcome styling: '+result);
 await h.advance(8000);check(h.state.posts.length===1,'no automatic resubmission: '+result);
 }
 for(const faces of [0,2]){
 h=await harness();await h.click('time-in');await h.advance(1500);h.state.faces=faces;await h.advance(200);h.state.faces=1;
 await h.advance(1900);check(!h.state.posts.length,'stability reset '+faces);await h.advance(500);check(h.state.posts.length===1,'stability resumes '+faces);
 }
 h=await harness({cameraPending:true});await h.click('time-in');await h.click('stop');await h.click('time-out');
 check(h.state.starts===1&&h.node('time-in').disabled,'pending camera prevents overlap after cancellation');
 h.state.openCamera();await h.advance(0);check(h.state.stops===1&&!h.node('time-in').disabled,'late camera closed before retry');
 h=await harness({detectionPending:true});await h.click('time-in');await h.click('stop');await h.click('time-out');
 check(h.state.starts===1&&h.node('time-in').disabled,'pending detection prevents overlap');
 h.state.detect();await h.advance(0);check(!h.state.posts.length&&!h.node('time-in').disabled,'stale detection cannot submit');
 h=await harness({pending:true});await h.click('time-in');await h.advance(2200);
 h.events.pagehide();h.state.respond();await h.advance(0);
 check(h.node('result-panel').hidden&&!h.node('result-employee').textContent,'stale response cannot restore cleared result');
 h=await harness({cameraError:true});await h.click('time-in');check(h.node('result-panel').dataset.kind==='error'&&!h.node('time-in').disabled,'camera denial retry');
 h=await harness();h.state.faces=0;await h.click('time-in');await h.advance(30000);
 check(h.state.stops===1&&!h.state.posts.length&&!h.node('time-in').disabled,'scan timeout cleanup');
 h=await harness({networkError:true});await h.click('time-in');await h.advance(2200);
 check(h.node('result-title').textContent==='Result not confirmed'&&!h.node('time-in').disabled,'network failure feedback');
 await h.advance(30000);check(h.state.posts.length===1,'network failure no auto retry');
 return count;
}

const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/face-kiosk.js'), 'utf8').replace("'use strict';", '').trim();
runTests(source).then(count => console.log(count + ' assertions passed')).catch(error => { console.error(error); process.exitCode = 1; });

