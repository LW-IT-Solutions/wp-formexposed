/* Optional counters only. Never reads input values or names; field positions are structural. */
(function(){
'use strict';
if(!window.formexposedMetrics||!window.crypto||!crypto.subtle)return;
var attached=new WeakSet(),usage=new Map(),sent=0;
function attach(form,index){
 if(attached.has(form)||form.querySelector('input[type=password]'))return;attached.add(form);
 var selector=form.id?'#'+CSS.escape(form.id):'form:nth-of-type('+(index+1)+')';
 var used=usage.get(selector)||{};usage.set(selector,used);
 var fieldUsed=new WeakSet(),keyPromise=crypto.subtle.digest('SHA-256',new TextEncoder().encode(location.pathname+'|'+selector)).then(function(buf){return Array.from(new Uint8Array(buf)).map(function(x){return x.toString(16).padStart(2,'0');}).join('');});
 function emit(event,details){
  var token=event+(details?':'+details.position:'');if(used[token]||sent>=50)return;used[token]=true;sent++;
  keyPromise.then(function(key){fetch(formexposedMetrics.url,{method:'POST',credentials:'omit',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({token:formexposedMetrics.token,key:key,event:event},details||{})),keepalive:true}).catch(function(){});});
 }
 if(window.IntersectionObserver){var observer=new IntersectionObserver(function(entries){if(entries.some(function(e){return e.isIntersecting;})){emit('seen');observer.disconnect();}});observer.observe(form);}
 form.addEventListener('input',function(){emit('started');},{once:true});
 form.addEventListener('invalid',function(e){
  emit('invalid');
  if(!formexposedMetrics.fields||fieldUsed.has(e.target))return;
  var fields=Array.from(form.querySelectorAll('input:not([type=hidden]):not([type=password]):not([type=file]):not([type=submit]):not([type=button]):not([type=reset]),textarea,select')).slice(0,30),position=fields.indexOf(e.target)+1;
  if(position<1||!e.target.validity)return;fieldUsed.add(e.target);
  var v=e.target.validity,category=v.valueMissing?'required':(v.typeMismatch?'type':(v.patternMismatch?'pattern':(v.rangeUnderflow||v.rangeOverflow||v.stepMismatch?'range':(v.tooShort||v.tooLong?'length':(v.badInput?'bad_input':'custom')))));
  emit('field_invalid',{position:position,category:category});
 },true);
 form.addEventListener('submit',function(){emit('attempted');},true);
 document.addEventListener('wpcf7mailsent',function(e){if(e.target===form||e.target.contains(form))emit('accepted_client');});
 document.addEventListener('wpcf7mailfailed',function(e){if(e.target===form||e.target.contains(form))emit('mail_failed_client');});
}
function scan(){Array.from(document.forms).slice(0,20).forEach(attach);}
scan();var timer;new MutationObserver(function(){clearTimeout(timer);timer=setTimeout(scan,300);}).observe(document.documentElement,{childList:true,subtree:true});
})();
