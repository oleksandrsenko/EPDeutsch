"use strict";
// Общие куски для Grammatik / Lesen / Hören: повтор запросов, генерация ИИ короткими шагами, обратный отсчёт, перетаскивание.
// Подключается до основного скрипта de.html; всё, что нужно от страницы ($, $$, esc, ic, api, toast, ST, save, get), берётся при вызове.

const sleep=ms=>new Promise(r=>setTimeout(r,ms));
// запрос с повтором: сбой хостинга или короткая пауза ИИ не должны ронять всю генерацию
async function apiR(p,o,tries=2){
  for(let k=0;;k++){
    try{return await api(p,o)}
    catch(e){if(k>=tries||!(e&&e.retry))throw e;await sleep(e.wait||1500*(k+1))}
  }
}
const post=(p,body,tries)=>apiR(p,{method:"POST",body:JSON.stringify(body)},tries);
// выполняет функции с ограничением по числу одновременных; результаты в порядке функций
async function pool(limit,fns,onDone){
  const out=new Array(fns.length);let next=0,done=0;
  async function worker(){while(next<fns.length){const i=next++;out[i]=await fns[i]();done++;if(onDone)onDone(done,fns.length)}}
  await Promise.all(Array.from({length:Math.min(limit,fns.length)},worker));
  return out;
}
// полоска прогресса генерации: el — контейнер; set(текст, доля 0..1)
function genUI(el){
  el.innerHTML=`<div class="genp"><div class="bar"><i style="width:3%"></i></div><small class="muted"></small></div>`;
  const bar=el.querySelector(".bar i"),tx=el.querySelector("small");
  return {set(msg,f){tx.textContent=msg;bar.style.width=Math.max(3,Math.min(100,Math.round((f||0)*100)))+"%"},done(){el.innerHTML=""}};
}

// ---------- обратный отсчёт: только вниз, минуты выбираешь сам ----------
const TMK="de-tm2";
const TM=(()=>{let o={mins:15,st:"idle",end:0,left:0};try{o={...o,...JSON.parse(sessionStorage.getItem(TMK)||"{}")}}catch(e){}if(!(o.mins>=1))o.mins=15;return o})();
const tmSave=()=>{try{sessionStorage.setItem(TMK,JSON.stringify(TM))}catch(e){}};
const tmTotal=()=>TM.mins*60000;
const tmLeft=()=>TM.st==="run"?Math.max(0,TM.end-Date.now()):TM.st==="pause"?TM.left:TM.st==="done"?0:tmTotal();
// сколько секунд ушло с начала отсчёта (для записи в результат)
const tmSec=()=>TM.st==="idle"?0:Math.round((tmTotal()-tmLeft())/1000);
const tmFmt=ms=>{const t=Math.max(0,Math.ceil(ms/1000)),h=Math.floor(t/3600),m=Math.floor(t%3600/60),s=t%60;return (h?h+":"+String(m).padStart(2,"0"):String(m).padStart(2,"0"))+":"+String(s).padStart(2,"0")};
function tmTick(){
  if(TM.st==="run"&&tmLeft()<=0){TM.st="done";tmSave();toast("Время вышло")}
  document.querySelectorAll(".tmr").forEach(b=>{
    b.classList.toggle("over",TM.st==="done");b.classList.toggle("run",TM.st==="run");
    const t=b.querySelector(".tmrT");if(t)t.textContent=tmFmt(tmLeft());
    const g=b.querySelector('[data-tm="go"]');if(g)g.textContent=TM.st==="run"?"Пауза":TM.st==="pause"?"Дальше":"Старт";
    const m=b.querySelector('[data-tm="min"]');if(m&&document.activeElement!==m)m.value=TM.mins;
  });
}
setInterval(tmTick,500);
const timerHTML=()=>`<div class="tmr" role="timer" aria-label="Таймер: обратный отсчёт">${EP.icon("clock",16)}<span class="tmrT">${tmFmt(tmLeft())}</span><button type="button" data-tm="go">${TM.st==="run"?"Пауза":TM.st==="pause"?"Дальше":"Старт"}</button><button type="button" data-tm="rst">Сброс</button><label class="tmm">на<input type="number" min="1" max="240" step="1" inputmode="numeric" value="${TM.mins}" data-tm="min" aria-label="Минут на задание"><span>мин</span></label></div>`;
function bindTimer(){
  document.querySelectorAll(".tmr").forEach(b=>{
    b.onclick=e=>{const x=e.target.closest("button[data-tm]");if(!x)return;
      if(x.dataset.tm==="go"){
        if(TM.st==="run"){TM.left=tmLeft();TM.st="pause"}
        else if(TM.st==="pause"){TM.end=Date.now()+TM.left;TM.st="run"}
        else{TM.end=Date.now()+tmTotal();TM.st="run"}
      }else{TM.st="idle"}
      tmSave();tmTick()};
    const inp=b.querySelector('[data-tm="min"]');
    if(inp)inp.onchange=()=>{const v=Math.max(1,Math.min(240,Math.round(+inp.value)||15));TM.mins=v;TM.st="idle";inp.value=v;tmSave();tmTick()};
  });
  tmTick();
}

// ---------- перетаскивание: карточки (слова, предложения) в пропуски; тот же код для мыши и пальца, плюс «нажми карточку, потом пропуск» ----------
// root — контейнер, внутри которого лежат .dchip (в лотке и в пропусках) и .dgap[data-q][data-k]; cfg.get(i) → ans[i], cfg.place(i,k,val|null), cfg.val(i,str) → значение карточки
const DND={sel:null};
function dndBind(root,cfg){
  if(root._dnd)return;root._dnd=1;
  const clearSel=()=>{DND.sel=null;root.querySelectorAll(".dchip.sel").forEach(x=>x.classList.remove("sel"));root.classList.remove("picking")};
  root.addEventListener("pointerdown",e=>{
    if(root.dataset.locked==="1")return;
    const chip=e.target.closest(".dchip");if(!chip||!root.contains(chip))return;
    if(e.button!=null&&e.button>0)return;
    const gap0=chip.closest(".dgap"),from=gap0?{i:+gap0.dataset.q,k:+gap0.dataset.k}:null,i=from?from.i:+chip.dataset.q,val=cfg.val(i,chip.dataset.v);
    const sx=e.clientX,sy=e.clientY;let moved=false,ghost=null,over=null;
    const mv=ev=>{
      if(!moved&&Math.hypot(ev.clientX-sx,ev.clientY-sy)<6)return;
      if(!moved){moved=true;ghost=chip.cloneNode(true);ghost.classList.add("dghost");ghost.style.width=Math.min(chip.offsetWidth,300)+"px";document.body.appendChild(ghost);chip.classList.add("dragging");clearSel()}
      ev.preventDefault();ghost.style.left=ev.clientX+"px";ghost.style.top=ev.clientY+"px";
      const el=document.elementFromPoint(ev.clientX,ev.clientY),g=el&&el.closest&&el.closest(".dgap");
      if(over&&over!==g)over.classList.remove("over");
      over=g&&root.contains(g)&&+g.dataset.q===i?g:null;if(over)over.classList.add("over");
    };
    const up=ev=>{
      document.removeEventListener("pointermove",mv);document.removeEventListener("pointerup",up);document.removeEventListener("pointercancel",up);
      if(over)over.classList.remove("over");
      if(moved){
        ghost.remove();chip.classList.remove("dragging");
        if(over){const k=+over.dataset.k;if(from&&from.k===k)return;if(from)cfg.place(i,from.k,null,true);cfg.place(i,k,val)}
        else if(from)cfg.place(i,from.k,null);               // вынес из пропуска в пустоту: карточка возвращается в лоток
        return;
      }
      // обычный клик: из пропуска убираем, из лотка выбираем
      if(from){cfg.place(i,from.k,null);return}
      const was=DND.sel&&DND.sel.chip===chip;clearSel();
      if(!was){DND.sel={i,val,chip};chip.classList.add("sel");root.classList.add("picking")}
    };
    document.addEventListener("pointermove",mv,{passive:false});document.addEventListener("pointerup",up);document.addEventListener("pointercancel",up);
  });
  root.addEventListener("click",e=>{
    if(root.dataset.locked==="1")return;
    const g=e.target.closest(".dgap");if(!g||!root.contains(g)||e.target.closest(".dchip"))return;
    if(DND.sel&&+g.dataset.q===DND.sel.i){const k=+g.dataset.k,s=DND.sel;clearSel();cfg.place(s.i,k,s.val)}
  });
}
