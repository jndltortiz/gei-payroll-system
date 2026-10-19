'use strict';
(async () => {
 const el = id => document.getElementById(id);
 const form=el('enrollment'), employee=el('employee'), video=el('video'), overlay=el('overlay');
 const start=el('start'), capture=el('capture'), stop=el('stop'), save=el('save'), consent=el('consent');
 const frame=document.createElement('canvas');
 let stream=null, generation=0, busy=false, ready=false, saving=false, samples=[], faceCount=0, nextCapture=0;
 const poses=['Look straight at the camera.','Turn your head slightly left.','Turn your head slightly right.','Raise your chin slightly.','Lower your chin slightly.'];
 const options=()=>new faceapi.TinyFaceDetectorOptions({inputSize:320,scoreThreshold:0.6});
 function message(text,error=false){el('status').textContent=text;el('status').className=error?'error':'';}
 function controls(){el('remove').disabled=saving||busy||!employee.value||!Number(employee.selectedOptions[0]?.dataset.count);start.disabled=!ready||!!stream||busy||saving||!employee.value;capture.disabled=!stream||busy||saving||faceCount!==1||samples.length>=5||Date.now()<nextCapture;stop.disabled=!stream;save.disabled=saving||busy||samples.length<3||!employee.value||!consent.checked;}
 function render(){el('samples').replaceChildren();samples.forEach((s,i)=>{const li=document.createElement('li');li.textContent=`Sample ${i+1}: ${(s.score*100).toFixed(2)}% detection confidence`;el('samples').append(li);});el('prompt').textContent=samples.length===5?'Five samples captured. Confirm consent and save.':`Sample ${samples.length+1}: ${poses[samples.length]}${samples.length>=3?' You may save now or capture more.':''}`;controls();}
 function stopCamera(){generation++;if(stream)stream.getTracks().forEach(t=>t.stop());stream=null;video.srcObject=null;faceCount=0;overlay.getContext('2d').clearRect(0,0,overlay.width,overlay.height);controls();}
 function reset(){samples.forEach(s=>s.descriptor.fill(0));samples=[];consent.checked=false;nextCapture=0;render();}
 function existing(){const o=employee.selectedOptions[0];el('existing').textContent=employee.value?`${Number(o.dataset.count)?'Enrolled':'Not enrolled'} — ${o.dataset.count} saved samples.${o.dataset.consent?' Last consent: '+o.dataset.consent+' (Asia/Manila).':''}`:'Select an employee to view enrollment status.';}
 employee.addEventListener('change',()=>{stopCamera();reset();existing();});
 el('clear').addEventListener('click',()=>{if(saving)return;stopCamera();reset();message('New samples cleared. Start the camera to try again.');});
 consent.addEventListener('change',controls);
 stop.addEventListener('click',()=>{stopCamera();message('Camera stopped. Captured samples remain available until saved or cleared.');});
 function draw(results,w,h){overlay.width=w;overlay.height=h;faceapi.draw.drawDetections(overlay,results);}
 async function loop(token){
  if(token!==generation||!stream)return;
  if(!busy&&video.readyState>=2){busy=true;controls();try{
   const results=await faceapi.detectAllFaces(video,options());if(token!==generation)return;
   faceCount=results.length;draw(results,video.videoWidth,video.videoHeight);
   message(faceCount===1?'One face visible. Follow the next pose prompt.':faceCount?'Multiple faces visible. Keep only the employee in view.':'No face detected. Face the camera with good lighting.');
  }catch(e){if(token===generation){stopCamera();message('Detection failed: '+e.message,true);}}finally{busy=false;controls();}}
  if(token===generation&&stream)setTimeout(()=>loop(token),250);
 }
 start.addEventListener('click',async()=>{
  if(busy||saving||!employee.value)return;busy=true;controls();const token=++generation;message('Allow camera access in your browser…');
  try{const acquired=await navigator.mediaDevices.getUserMedia({video:{width:{ideal:640},height:{ideal:480},facingMode:'user'},audio:false});if(token!==generation){acquired.getTracks().forEach(t=>t.stop());return;}stream=acquired;video.srcObject=stream;stream.getVideoTracks()[0].addEventListener('ended',()=>{if(token===generation){stopCamera();message('Camera disconnected. Reconnect and retry.',true);}});await video.play();}
  catch(e){if(token===generation){stopCamera();const errors={NotAllowedError:'Camera permission denied. Allow camera access in the browser and Windows settings.',NotFoundError:'No webcam found. Connect or enable a camera.',NotReadableError:'Camera unavailable. Close other apps using it.',SecurityError:'Camera access is blocked by security settings.'};message(errors[e.name]||'Camera error: '+e.message,true);}}
  finally{busy=false;controls();if(token===generation&&stream)loop(token);}
 });
 capture.addEventListener('click',async()=>{
  if(busy||saving||!stream||samples.length>=5||Date.now()<nextCapture)return;
  busy=true;controls();const token=generation;
  try{frame.width=video.videoWidth;frame.height=video.videoHeight;frame.getContext('2d').drawImage(video,0,0);
   const results=await faceapi.detectAllFaces(frame,options()).withFaceLandmarks(true).withFaceDescriptors();if(token!==generation)return;
   if(results.length!==1){message('Capture requires exactly one face. Try again.',true);return;}
   const descriptor=Array.from(results[0].descriptor);if(descriptor.length!==128||!descriptor.every(Number.isFinite))throw Error('Invalid descriptor');
   if(samples.some(s=>s.descriptor.every((v,i)=>v===descriptor[i]))){message('Identical sample. Move slightly and capture again.',true);return;}
   samples.push({descriptor,score:results[0].detection.score});nextCapture=Date.now()+1500;render();
  }catch(e){if(token===generation)message('Capture failed: '+e.message,true);}
  finally{frame.width=0;frame.height=0;busy=false;controls();}
 });
 form.addEventListener('submit',async event=>{
  event.preventDefault();if(saving||busy||samples.length<3||samples.length>5||!employee.value||!consent.checked)return;
  const option=employee.selectedOptions[0];if(el('mode').value==='replace'&&!confirm('Replace all saved samples for this employee?'))return;
  const fd=new FormData(form);if(el('mode').value==='replace')fd.set('confirmed','1');fd.set('descriptors',JSON.stringify(samples.map(s=>s.descriptor)));
  saving=true;stopCamera();employee.disabled=true;el('mode').disabled=true;el('clear').disabled=true;consent.disabled=true;controls();
  try{const response=await fetch(form.action,{method:'POST',body:fd});if(response.redirected)throw Error('Session expired. Sign in again and reload this page.');if(!(response.headers.get('content-type')||'').includes('application/json'))throw Error('Session or CSRF validation failed. Reload and sign in again.');const data=await response.json();if(!response.ok||!data.success)throw Error(data.message||'Save failed');option.dataset.count=data.count;option.dataset.consent=data.consented_at;option.textContent=option.textContent.replace(/ — (?:Enrolled \(\d+ samples\)|Not enrolled)$/,'')+` — Enrolled (${data.count} samples)`;reset();existing();message(`Saved. ${data.count} samples enrolled. Consent recorded ${data.consented_at} (Asia/Manila).`);}
  catch(e){message(e.message+' Samples have not been cleared; verify status before retrying if the connection was interrupted.',true);}
  finally{fd.delete('descriptors');saving=false;employee.disabled=false;el('mode').disabled=false;el('clear').disabled=false;consent.disabled=false;controls();}
 });
 el('remove').addEventListener('click',async()=>{
  if(saving||busy||!employee.value||!Number(employee.selectedOptions[0].dataset.count))return;
  const option=employee.selectedOptions[0];
  if(!confirm('Remove all face samples for '+option.textContent+'? The employee and attendance history will be retained.'))return;
  const fd=new FormData(form);fd.set('mode','remove');fd.set('confirmed','1');
  saving=true;stopCamera();reset();employee.disabled=true;el('mode').disabled=true;el('clear').disabled=true;consent.disabled=true;controls();
  try {
   const response=await fetch(form.action,{method:'POST',body:fd});
   if(response.redirected||!(response.headers.get('content-type')||'').includes('application/json'))throw Error('Session or CSRF validation failed. Sign in again and reload.');
   const data=await response.json();if(!response.ok||!data.success)throw Error(data.message||'Removal failed.');
   option.dataset.count='0';option.dataset.consent='';
   option.textContent=option.textContent.replace(/Enrolled \(\d+ samples\)$|Not enrolled$/,'Not enrolled');
   existing();message(data.message);
  }catch(e){message(e.message+' Reload enrollment status before retrying if the connection was interrupted.',true);}
  finally{saving=false;employee.disabled=false;el('mode').disabled=false;el('clear').disabled=false;consent.disabled=false;controls();}
 });
 window.addEventListener('pagehide',()=>{stopCamera();reset();});
 document.addEventListener('visibilitychange',()=>{if(document.hidden&&stream){stopCamera();message('Camera stopped while tab is hidden.');}});
 if(!window.isSecureContext||!navigator.mediaDevices?.getUserMedia){message('Camera requires HTTPS or localhost and a supported browser. Open this page on the XAMPP computer using localhost.',true);return;}
 try{if(!window.faceapi)throw Error('Local face-api.js missing');const url=new URL('../../assets/vendor/face-api/models/',location.href).href;await Promise.all([faceapi.nets.tinyFaceDetector.loadFromUri(url),faceapi.nets.faceLandmark68TinyNet.loadFromUri(url),faceapi.nets.faceRecognitionNet.loadFromUri(url)]);ready=true;controls();message('Local models ready. Choose an employee and start the camera.');}
 catch(e){message('Local model loading failed: '+e.message,true);}
})();
