/* Admin-only visual journey recorder. Only selectors and public paths; never input values. */
(function(){
'use strict';
if(!window.formexposedRecorder||window.__feRecorder)return;
window.__feRecorder=true;
var hash=new URLSearchParams(location.hash.slice(1)),nonce=hash.get('fe_nonce'),origin=formexposedRecorder.origin;
var storage='formexposed_recorder',data;
try{data=JSON.parse(sessionStorage.getItem(storage)||'null');}catch(e){}
if(nonce&&/^[a-f0-9]{48}$/.test(nonce)){data={nonce:nonce,entry_url:location.origin+location.pathname,steps:[],expires:Date.now()+900000};sessionStorage.setItem(storage,JSON.stringify(data));history.replaceState(null,'',location.pathname+location.search);}
if(!data||!data.nonce||data.expires<Date.now()||!window.opener){sessionStorage.removeItem(storage);return;}
var expiryTimer=setTimeout(stop,Math.max(0,data.expires-Date.now()));
var dict={
 de:['Formexposed: Prüfweg aufnehmen','Klicken Sie die Schritte bis zum Formular. Keine Eingaben oder Einsendungen.','Formular auswählen','Aufnahme beenden','Schritte: ','Dieses Element ist nicht eindeutig auswählbar.','Dieser Klick wird nicht aufgezeichnet. Wählen Sie einen Link oder einen sicheren Öffnen-Knopf.','Maximal fünf Schritte.','Formular übernommen. Im Formexposed-Fenster prüfen und speichern.'],
 en:['Formexposed: record check journey','Click the steps to the form. No typing or submissions.','Select form','Stop recording','Steps: ','This element cannot be selected uniquely.','This click is not recorded. Choose a link or a safe open button.','Maximum five steps.','Form copied. Review and save in the Formexposed window.'],
 pl:['Formexposed: nagraj ścieżkę testu','Klikaj kroki do formularza. Bez wpisywania i wysyłania danych.','Wybierz formularz','Zakończ nagrywanie','Kroki: ','Nie można jednoznacznie wybrać tego elementu.','Kliknięcie nie zostało zapisane. Wybierz link lub bezpieczny przycisk.','Maksymalnie pięć kroków.','Formularz skopiowany. Sprawdź i zapisz w oknie Formexposed.']
},t=dict[formexposedRecorder.lang]||dict[String(formexposedRecorder.lang).split('-')[0]]||dict.en,selecting=false;
var host=document.createElement('div');host.id='fe-recorder-toolbar';host.style.cssText='position:fixed;z-index:2147483647;bottom:12px;left:12px;right:12px';document.body.append(host);
var shadow=host.attachShadow({mode:'open'}),box=document.createElement('div');box.style.cssText='background:#111827;color:white;padding:18px;border:2px solid #fb923c;border-radius:12px;font:16px/1.5 sans-serif';shadow.append(box);
var title=document.createElement('strong');title.textContent=t[0];var info=document.createElement('p');info.textContent=t[1];box.append(title,info);
var count=document.createElement('span'),pick=document.createElement('button'),cancel=document.createElement('button');pick.textContent=t[2];cancel.textContent=t[3];
[pick,cancel].forEach(function(b){b.type='button';b.style.cssText='padding:8px;margin:8px;font:inherit;cursor:pointer';box.append(b);});box.append(count);
function refresh(){count.textContent=t[4]+data.steps.length;}refresh();
pick.onclick=function(){selecting=true;pick.style.outline='3px solid #fb923c';};
function stop(){clearTimeout(expiryTimer);sessionStorage.removeItem(storage);host.remove();document.removeEventListener('click',click,true);document.removeEventListener('submit',block,true);document.removeEventListener('beforeinput',block,true);window.__feRecorder=false;var u=new URL(location.href);u.searchParams.delete('formexposed_record');history.replaceState(null,'',u.pathname+u.search);}
cancel.onclick=stop;
function block(e){e.preventDefault();e.stopImmediatePropagation();}
document.addEventListener('submit',block,true);document.addEventListener('beforeinput',block,true);
var danger=/(logout|log-out|signout|delete|remove|unsubscribe|checkout|add-to-cart|payment|purchase)/i;
function selector(el){
 if(el.id){var id='#'+CSS.escape(el.id);if(document.querySelectorAll(id).length===1&&id.length<=200)return id;}
 for(var a of ['data-testid','aria-controls']){var v=el.getAttribute(a);if(v&&v.length<80){var s=el.tagName.toLowerCase()+'['+a+'="'+CSS.escape(v)+'"]';if(s.length<=200&&document.querySelectorAll(s).length===1)return s;}}
 var parts=[],cur=el;
 while(cur&&cur.nodeType===1&&cur!==document.body){var tag=cur.tagName.toLowerCase(),siblings=Array.from(cur.parentElement.children).filter(function(x){return x.tagName===cur.tagName;});parts.unshift(tag+':nth-of-type('+(siblings.indexOf(cur)+1)+')');var path='body > '+parts.join(' > ');if(path.length<=200&&document.querySelectorAll(path).length===1)return path;cur=cur.parentElement;}
 return '';
}
function click(e){
 if(e.composedPath().includes(host))return;
 if(selecting){block(e);var form=e.target.closest('form');if(!form)return;var s=selector(form);if(!s){info.textContent=t[5];return;}
  window.opener.postMessage({type:'formexposed-recording',nonce:data.nonce,page_url:location.origin+location.pathname,entry_url:data.entry_url,form_selector:s,client_selector:form.id?'#'+CSS.escape(form.id):'form:nth-of-type('+(Array.from(document.forms).indexOf(form)+1)+')',steps:data.steps},origin);info.textContent=t[8];stop();return;
 }
 var el=e.target.closest('a,button,[role=button]');if(!el){block(e);info.textContent=t[6];return;}
 var url=el.tagName==='A'?new URL(el.href,location.href):null;
 if(el.closest('form')||el.matches('button:not([type]),button[type=submit]')||(url&&(url.origin!==location.origin||danger.test(url.pathname+url.search)))||danger.test(el.id+' '+(el.getAttribute('data-action')||''))){block(e);info.textContent=t[6];return;}
 if(data.steps.length>=5){block(e);info.textContent=t[7];return;}
 var s=selector(el);if(!s){block(e);info.textContent=t[5];return;}data.steps.push(s);sessionStorage.setItem(storage,JSON.stringify(data));refresh();
 if(url&&url.protocol==='https:'&&url.pathname!==location.pathname){url.searchParams.set('formexposed_record','1');el.href=url.href;}
}
document.addEventListener('click',click,true);
})();
