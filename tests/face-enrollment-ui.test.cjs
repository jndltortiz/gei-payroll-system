'use strict';
// Run with Node; all camera/network/model objects are mocked.
async function runEnrollmentUiTests(source) {
 let count=0;
 const check=(v,label)=>{if(!v)throw Error(label);count++};
 async function setup() {
  const nodes={},requests=[];let tick=0,sample=0,confirmed=true;
  const option={dataset:{count:'0',consent:''},textContent:'E1 — Test Employee — Not enrolled'};
  const el=id=>nodes[id] ||= {value:'',disabled:false,checked:false,dataset:{},selectedOptions:[option],listeners:{},textContent:'',readyState:2,videoWidth:640,videoHeight:480,
   addEventListener(k,fn){this.listeners[k]=fn},replaceChildren(){},append(){},getContext(){return {drawImage(){},clearRect(){}}},async play(){}};
  el('employee').value='1';el('mode').value='add';el('enrollment').action='/save';
  const document={getElementById:el,createElement:()=>el('canvas'),addEventListener(){}};
  const net={loadFromUri:async()=>{}};
  const faceapi={TinyFaceDetectorOptions:function(){},nets:{tinyFaceDetector:net,faceLandmark68TinyNet:net,faceRecognitionNet:net},draw:{drawDetections(){}},
   detectAllFaces(){return {then(resolve){resolve([{}])},withFaceLandmarks(){return {withFaceDescriptors:async()=>[{descriptor:new Float32Array(128).fill(++sample/1000),detection:{score:0.9}}]}}}}};
  const window={isSecureContext:true,faceapi,addEventListener(){}};
  const navigator={mediaDevices:{getUserMedia:async()=>({getTracks:()=>[{stop(){}}],getVideoTracks:()=>[{addEventListener(){}}]})}};
  class FormData {constructor(){this.values={employee_id:el('employee').value,mode:el('mode').value,consent:'1'}}set(k,v){this.values[k]=v}delete(k){delete this.values[k]}}
  const fetch=async(url,{body})=>{requests.push({...body.values});return {ok:true,headers:{get:()=> 'application/json'},json:async()=>({success:true,count:body.values.mode==='remove'?0:3,consented_at:'test timestamp',message:'Completed'})}};
  await new Function('document','window','navigator','faceapi','FormData','fetch','confirm','Date','setTimeout','URL','location','return '+source.replace("'use strict';",'').trim())(
   document,window,navigator,faceapi,FormData,fetch,()=>confirmed,{now:()=>tick},()=>0,class URL{constructor(){this.href='/models'}},{href:'http://localhost/enrollment'});
  const flush=async()=>{for(let i=0;i<25;i++)await Promise.resolve()};
  return {el,option,requests,setConfirm(v){confirmed=v},async event(id,type='click'){el(id).listeners[type]?.({preventDefault(){}});await flush()},
   async capture(){await this.event('start');for(let i=0;i<3;i++){tick+=1600;await this.event('capture')}el('consent').checked=true;await this.event('consent','change')}};
 }
 let h=await setup();
 check(h.el('remove').disabled,'unenrolled removal disabled');
 await h.capture();await h.event('enrollment','submit');
 check(h.requests.length===1&&h.requests[0].mode==='add','registration request');
 check(h.option.dataset.count===3&&!h.el('remove').disabled,'status and removal refreshed');
 h.setConfirm(false);await h.event('remove');
 check(h.requests.length===1,'removal confirmation cancel');
 h.setConfirm(true);await h.event('remove');
 check(h.requests[1].mode==='remove'&&h.requests[1].confirmed==='1','confirmed remove payload');
 check(!('descriptors' in h.requests[1]),'removal sends no descriptors');
 check(h.option.dataset.count==='0'&&h.option.textContent.endsWith('Not enrolled')&&h.el('remove').disabled,'removal clears enrollment status');
 h=await setup();h.el('mode').value='replace';await h.capture();h.setConfirm(false);await h.event('enrollment','submit');
 check(!h.requests.length,'replacement confirmation cancel');
 h.setConfirm(true);await h.event('enrollment','submit');
 check(h.requests[0].confirmed==='1'&&h.requests[0].mode==='replace','confirmed replacement payload');
 return count;
}
const fs=require('node:fs'),path=require('node:path');
runEnrollmentUiTests(fs.readFileSync(path.join(__dirname,'../assets/js/face-enrollment.js'),'utf8'))
 .then(n=>console.log(n+' enrollment UI assertions passed')).catch(e=>{console.error(e);process.exitCode=1});

