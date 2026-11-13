/* Keeps the existing form DOM. No input reads, storage, interception or automatic resend. */
(function(){
'use strict';
if(!window.formexposedRecovery)return;
var lang=formexposedRecovery.lang,attached=new WeakSet(),states=new WeakMap();
var words={
 de:['Die Anfrage wurde nicht gesendet. Ihre Eingaben bleiben in diesem geöffneten Formular.','Sie sind offline. Bitte verbinden Sie sich und senden Sie anschließend erneut.','Der Versand ist fehlgeschlagen. Sie können die Eingaben prüfen und erneut senden.','Zurück zum Formular','Alternativ per E-Mail kontaktieren','Der Versandstatus ist unklar. Bitte prüfen Sie eine mögliche Bestätigung, bevor Sie erneut senden.'],
 en:['The enquiry was not sent. Your entries remain in this open form.','You are offline. Reconnect before sending again.','Sending failed. Review your entries and send again.','Back to the form','Contact by email instead','The delivery status is unclear. Check for a confirmation before sending again.'],
 pl:['Zapytanie nie zostało wysłane. Dane pozostają w tym otwartym formularzu.','Brak połączenia. Połącz się z internetem przed ponownym wysłaniem.','Wysyłka nie powiodła się. Sprawdź dane i wyślij ponownie.','Wróć do formularza','Skontaktuj się przez e-mail','Status wysyłki jest nieznany. Sprawdź potwierdzenie przed ponownym wysłaniem.']
},t=words[lang]||words[String(lang).split('-')[0]]||words.en;
function show(form,kind){
 var s=states.get(form);if(!s)return;clearTimeout(s.timer);
 if(!s.box){s.box=document.createElement('div');s.box.setAttribute('role','status');s.box.setAttribute('aria-live','polite');s.box.style.cssText='padding:16px;margin:12px 0;border:2px solid #b45309;border-radius:8px;background:#fff7ed;color:#431407;font:16px/1.5 sans-serif';form.after(s.box);}
 s.box.hidden=false;s.box.replaceChildren();
 var p=document.createElement('p');p.textContent=kind==='offline'?t[1]:(kind==='unknown'?t[5]:t[2]);s.box.append(p);
 var note=document.createElement('p');note.textContent=t[0];if(kind!=='unknown')s.box.append(note);
 var back=document.createElement('button');back.type='button';back.textContent=t[3];back.onclick=function(){var el=form.querySelector('input:not([type=hidden]):not([type=password]),textarea,select,button');if(el)el.focus();form.scrollIntoView({block:'center',behavior:'smooth'});};s.box.append(back);
 if(/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formexposedRecovery.email||'')){var a=document.createElement('a');a.href='mailto:'+formexposedRecovery.email;a.textContent=t[4];a.style.marginLeft='12px';s.box.append(a);}
}
function attach(form){
 if(attached.has(form)||form.querySelector('input[type=password]'))return;attached.add(form);states.set(form,{box:null,timer:null});
 form.addEventListener('submit',function(e){
  var s=states.get(form);
  if(navigator.onLine===false){e.preventDefault();e.stopImmediatePropagation();show(form,'offline');return;}
  if(s.box)s.box.hidden=true;
  // CF7 has explicit completion events. A timeout is uncertainty, never an invitation to duplicate a lead.
  clearTimeout(s.timer);if(form.classList.contains('wpcf7-form'))s.timer=setTimeout(function(){show(form,'unknown');s.box.hidden=false;},30000);
 },true);
}
function forms(e,fn){var target=e.target;if(!target||!target.querySelectorAll)return;if(target.tagName==='FORM')fn(target);else target.querySelectorAll('form').forEach(fn);}
['wpcf7mailfailed','formexposed:submissionfailed'].forEach(function(event){document.addEventListener(event,function(e){forms(e,function(form){attach(form);show(form,'failed');states.get(form).box.hidden=false;});});});
['wpcf7mailsent','wpcf7spam','wpcf7invalid'].forEach(function(event){document.addEventListener(event,function(e){forms(e,function(form){var s=states.get(form);if(s){clearTimeout(s.timer);if(s.box)s.box.remove();s.box=null;}});});});
function scan(){Array.from(document.forms).slice(0,20).forEach(attach);}
scan();var timer;new MutationObserver(function(){clearTimeout(timer);timer=setTimeout(scan,300);}).observe(document.documentElement,{subtree:true,childList:true});
})();
