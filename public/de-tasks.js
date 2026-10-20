"use strict";
// Задания Lesen / Hören как на EPD: типы, баллы (Lesen 20, Hören 15), отображение, перетаскивание, локальные «Satz einfügen» и «Lückentext».
// Типы: tf, rfb (R/F + Beleg), mc, mcg (группа вопросов A–D), qs / open / short (короткие ответы, оценивает ИИ), head / match (выпадающие списки),
// sent (предложения в пропуски текста, перетаскивание), box (Wortkasten, перетаскивание), sum (Paralleltext / конспект), cl4 (слово a–d), ct (C-Test).

const uMark=h=>h.replace(/\[\[(.+?)\]\]/g,'<u class="ul">$1</u>');
const wordsOf=s=>String(s||"").toLowerCase().replace(/[^\p{L}\p{N}\s]+/gu," ").split(/\s+/).filter(Boolean);
const AI_T=q=>q.t==="qs"||q.t==="open";
const LETTER=i=>String.fromCharCode(65+i);
const fmtPts=n=>String(Math.round(n*2)/2).replace(".",",");
const norm20=(s,m)=>m?Math.round(s/m*20*2)/2:0;

// блоки заданий, которые можно включать и выключать сбоку от текста
const LBLK=[["tf","Richtig / Falsch / Nicht im Text","да, нет, в тексте об этом не сказано"],["rfb","Richtig / Falsch + Beleg","ответ и первые 4 слова предложения-доказательства"],["zu","Zuordnung","заголовки и утверждения к абзацам"],["mc","Multiple Choice","три варианта a–c"],["ks","Kurzantworten","ответ в 4 слова, оценивает ИИ"],["lt","Lückentext","перетащи слова в пропуски"],["si","Satz einfügen","перетащи предложения в текст"]];
const BLK_ONE=Object.fromEntries(LBLK.map(x=>[x[0],x]));
const blockOf=q=>({tf:"tf",rfb:"rfb",mc:"mc",qs:"ks",open:"ks",short:"ks",gap:"ks",head:"zu",match:"zu",box:"lt",sum:"lt",cl4:"lt",sent:"si"}[q.t]||"ks");
// какие запросы к ИИ дают блок (каждый запрос — не меньше 8 заданий)
const BLK_KINDS={tf:["tf"],rfb:["rfb"],zu:["head","zu2"],mc:["mc3"],ks:["qs"],lt:["box"],si:["sdis"]};
// остальные официальные типы EPD (кроме перечисленных)
const LKINDS_MORE=[["sum","Paralleltext","8 пропусков, слова из текста","lt"],["cl4","Слово a–d","8 пропусков, 4 варианта","lt"],["ref","Bezugswörter","на что ссылается слово","ks"],["who","Wer sagt was?","чьё это мнение","ks"],["para","Umschreibungen","найди слово по определению","ks"]];

// ---------- баллы: каждое верное задание 1 балл, наполовину верное 0,5 ----------
function evOK(q,a){
  const e=wordsOf(a&&a.e).slice(0,6),w=wordsOf(q.ev).slice(0,4);
  if(!w.length||e.length<3)return false;
  let n=0;for(let i=0;i<w.length&&i<e.length;i++){if(e[i]===w[i])n++;else break}
  return n>=Math.min(3,w.length)||e.join(" ").includes(w.join(" "));
}
// C-Test: оставляют первую половину слова (с округлением вверх), вторую пишет студент
const ctStub=w=>w.slice(0,Math.ceil(w.length/2));
const ctOK=(q,k,v)=>{const w=q.a[k],t=String(v||"").trim().toLowerCase();return !!t&&(t===w.toLowerCase()||ctStub(w).toLowerCase()+t===w.toLowerCase())};
// [набрано, максимум] по одному заданию; r — результат ИИ или самооценка {score}
function taskCalc(q,a,r){
  const sc=r&&typeof r.score==="number"?r.score:0;
  switch(q.t){
    case "mc":case "tf":return [a===q.a?1:0,1];
    case "gap":return [(Array.isArray(q.a)?q.a:[q.a]).map(loose).includes(loose(a||""))?1:0,1];
    case "short":return [a&&(q.a||[]).map(loose).includes(loose(a))?1:sc,1];
    case "qs":case "open":return [sc,1];
    case "rfb":{const ev=evOK(q,a),v=!!(a&&a.v&&a.v===q.a);return [(ev?.5:0)+(v?.5:0),1]}   // ответ и доказательство по половине балла
    case "head":case "sent":case "match":return [q.a.reduce((n,x,k)=>n+(a&&a[k]===x?1:0),0),q.a.length];
    case "sum":return [q.a.reduce((n,f,k)=>n+(a&&a[k]&&f.map(loose).includes(loose(a[k]))?1:0),0),q.a.length];
    case "box":case "cl4":return [q.a.reduce((n,x,k)=>n+(a&&a[k]===x?1:0),0),q.a.length];
    case "mcg":return [(q.items||[]).reduce((n,x,j)=>n+((a||[])[j]===x.a?1:0),0),(q.items||[]).length];
    case "ct":return [q.a.reduce((n,w,k)=>n+(ctOK(q,k,(a||[])[k])?1:0),0),q.a.length];
  }
  return [0,0];
}
// idx — какие задания считать (выбранные блоки); по умолчанию все
function taskScore(tasks,ans,res,idx){let s=0,m=0;(idx||tasks.map((_,i)=>i)).forEach(i=>{const [x,y]=taskCalc(tasks[i],ans[i],res&&res[i]);s+=x;m+=y});return [s,m]}
function taskRight(q,a){const [s,m]=taskCalc(q,a);return s===m}

// ---------- отображение ----------
// пропуски [[n]] в связном тексте: поля ввода (sum), перетаскивание слов (box), выпадающие варианты a–d (cl4)
function gapText(q,i,a,checked){
  a=a||[];
  return esc(q.text).replace(/\[\[(\d+)\]\]/g,(m,n)=>{
    const k=+n-1,v=a[k];let ok=null,corr="";
    if(checked){
      if(q.t==="sum"){ok=!!v&&q.a[k].map(loose).includes(loose(v));corr=q.a[k][0]}
      else if(q.t==="box"){ok=v===q.a[k];corr=q.a[k]}
      else{ok=v===q.a[k];corr=String.fromCharCode(97+q.a[k])+") "+q.o[k][q.a[k]]}
    }
    const cls=checked?(ok?" right":" wrong"):"";
    let el;
    if(q.t==="sum")el=`<input class="gi${cls}" data-q="${i}" data-g="${k}" value="${esc(v||"")}" ${checked?"readonly":""} style="width:${Math.max(9,Math.min(22,(v||"").length+4))}ch" autocomplete="off" spellcheck="false" aria-label="Lücke ${n}">`;
    else if(q.t==="box")el=`<span class="dgap${cls}${v?" full":""}" data-q="${i}" data-k="${k}"><b class="gn">${n}</b>${v?`<span class="dchip" data-v="${esc(v)}">${esc(v)}</span>`:""}</span>`;
    else el=`<select class="gs${cls}" data-q="${i}" data-g="${k}" data-n="1" ${checked?"disabled":""} aria-label="Lücke ${n}"><option value="">(${n})</option>${q.o[k].map((w,j)=>`<option value="${j}" ${v===j?"selected":""}>${String.fromCharCode(97+j)}) ${esc(w)}</option>`).join("")}</select>`;
    return `<span class="gw">${el}${checked&&!ok?`<small class="gc">${esc(corr)}</small>`:""}</span>`;
  });
}
// C-Test: слово напечатано наполовину, вторую половину вписывает студент
function ctText(q,i,a,checked){
  a=a||[];
  return esc(q.q).replace(/\[\[(\d+)\]\]/g,(m,n)=>{
    const k=+n-1,w=q.a[k]||"",st=ctStub(w),v=a[k]||"",ok=checked?ctOK(q,k,v):null;
    const rest=w.length-st.length;
    return `<span class="ctw${checked?(ok?" right":" wrong"):""}"><b>${esc(st)}</b><input data-q="${i}" data-g="${k}" value="${esc(v)}" ${checked?"readonly":""} style="width:${Math.max(4,rest+2)}ch" maxlength="${w.length+4}" autocomplete="off" autocapitalize="off" spellcheck="false" aria-label="Wort ${n}, ${st}…">${checked&&!ok?`<small class="gc">${esc(w)}</small>`:""}</span>`;
  });
}
function aiBlock(q,i,r,checked,hasKey){
  if(!checked||!AI_T(q)&&q.t!=="short")return "";
  const a=q.a,models=Array.isArray(a)?a:[a];
  if(q.t==="short")return "";
  if(r&&typeof r.score==="number")return `<div class="airow"><span class="sc s${String(r.score).replace(".","")}">${fmtPts(r.score)}</span><span>${esc(r.why||"")}${r.self?'<i class="muted"> · твоя оценка</i>':""}</span></div><div class="why"><b>Образец:</b> ${models.map(esc).join(" / ")}</div>`;
  return `<div class="why"><b>Образец:</b> ${models.map(esc).join(" / ")}</div>${hasKey?"":`<div class="row" style="margin-top:6px"><span class="muted" style="font-size:12.5px">Нет ключа Claude, оцени сам:</span><button class="btn sm" data-self="${i}" data-v="1">✓ верно</button><button class="btn sm" data-self="${i}" data-v="0.5">½</button><button class="btn sm" data-self="${i}" data-v="0">✗ неверно</button></div>`}`;
}
// предложения для перетаскивания в текст: лоток + список пропусков
function sentHTML(q,i,a,checked){
  a=a||[];const used=new Set(a.filter(x=>x!=null));
  const tray=`<div class="dtray" data-q="${i}">${q.sents.map((s,hi)=>used.has(hi)?"":`<span class="dchip" data-q="${i}" data-v="${hi}"><b>${LETTER(hi)}</b>${esc(s)}</span>`).join("")||'<small class="muted">Все предложения расставлены</small>'}</div>`;
  const slots=q.a.map((x,k)=>{const v=a[k],ok=checked?v===x:null;
    return `<div class="dslot"><span class="sn">${k+1}</span><span class="dgap${checked?(ok?" right":" wrong"):""}${v!=null?" full":""}" data-q="${i}" data-k="${k}">${v!=null?`<span class="dchip" data-v="${v}"><b>${LETTER(v)}</b>${esc(q.sents[v])}</span>`:'<i class="muted">сюда</i>'}</span>${checked&&!ok?`<small class="gc">→ ${LETTER(x)}</small>`:""}</div>`}).join("");
  return `${tray}<div class="dslots">${slots}</div>`;
}
function tasksHTML(tasks,ans,checked,res,opt){
  opt=opt||{};
  const hasKey=!!(ST.keys&&ST.keys.anthropic),idx=opt.idx||tasks.map((_,i)=>i);
  let n=0,lastB=null;
  return idx.map(i=>{
    const q=tasks[i],a=ans[i],r=res&&res[i];let body="",why="",head="",grp="";
    n++;
    if(opt.group){const b=blockOf(q);if(b!==lastB){lastB=b;grp=`<h3 class="tgrp">${esc(BLK_ONE[b][1])}<small>${esc(BLK_ONE[b][2])}</small></h3>`}}
    if(q.task&&!/^(head|sent|match)$/.test(q.t))head+=`<div class="ttask">${esc(q.task)}${q.sec?` · Absatz ${q.sec}`:""}</div>`;
    if(q.src==="ai")head+=`<span class="aitag">${EP.icon("spark",12)} ИИ</span>`;
    if(q.t==="tf"){const L=[["r","richtig"],["f","falsch"],["n","nicht im Text"]].filter(x=>q.two?x[0]!=="n":true);
      body=`<div class="seg">${L.map(([v,t])=>`<button data-q="${i}" data-v="${v}" class="${a===v?"sel":""} ${checked?(v===q.a?"right":a===v?"wrong":""):""}">${t}</button>`).join("")}</div>`}
    else if(q.t==="rfb"){const L=[["r","richtig"],["f","falsch"]];const evOk=checked&&evOK(q,a);
      body=`<div class="seg">${L.map(([v,t])=>`<button data-q="${i}" data-v="${v}" class="${a&&a.v===v?"sel":""} ${checked?(v===q.a?"right":a&&a.v===v?"wrong":""):""}">${t}</button>`).join("")}</div>
        <input class="evin${checked?(evOk?" right":" wrong"):""}" data-q="${i}" data-e="1" value="${esc((a&&a.e)||"")}" ${checked?"readonly":""} placeholder="Erste vier Wörter des Beweissatzes" autocomplete="off" spellcheck="false">`}
    else if(q.t==="mc")body=`<div class="mco">${q.o.map((o,k)=>`<button data-q="${i}" data-v="${k}" class="${a===k?"sel":""} ${checked?(k===q.a?"right":a===k?"wrong":""):""}">${String.fromCharCode(97+k)}) ${esc(o)}</button>`).join("")}</div>`;
    else if(q.t==="mcg")body=(q.items||[]).map((sq,j)=>`<div class="sq"><div class="qt"><span class="qn">${j+1}.</span>${esc(sq.q)}</div><div class="mco">${sq.o.map((o,k)=>`<button data-q="${i}" data-j="${j}" data-v="${k}" class="${(a||[])[j]===k?"sel":""} ${checked?(k===sq.a?"right":(a||[])[j]===k?"wrong":""):""}">${LETTER(k)}) ${esc(o)}</button>`).join("")}</div>${checked&&sq.ex?`<div class="why">${esc(sq.ex)}</div>`:""}</div>`).join("");
    else if(q.t==="open")body=`<textarea data-q="${i}" placeholder="Kurze Antwort auf Deutsch" ${checked?"readonly":""}>${esc(a||"")}</textarea>`;
    else if(q.t==="qs")body=`<input class="evin${checked&&r&&typeof r.score==="number"?(r.score>=1?" right":r.score>0?"":" wrong"):""}" data-q="${i}" value="${esc(a||"")}" ${checked?"readonly":""} placeholder="Maximal 4 Wörter" autocomplete="off" spellcheck="false">`;
    else if(q.t==="short")body=`<input class="evin${checked?(taskCalc(q,a,r)[0]>=1?" right":taskCalc(q,a,r)[0]>0?"":" wrong"):""}" data-q="${i}" value="${esc(a||"")}" ${checked?"readonly":""} placeholder="Antwort" autocomplete="off" spellcheck="false">`;
    else if(q.t==="sum"||q.t==="cl4")body=`<div class="gaptext">${gapText(q,i,a,checked)}</div>`;
    else if(q.t==="box")body=`<div class="dtray wbox" data-q="${i}">${q.words.filter(w=>!(a||[]).includes(w)).map(w=>`<span class="dchip" data-q="${i}" data-v="${esc(w)}">${esc(w)}</span>`).join("")||'<small class="muted">Все слова расставлены</small>'}</div><div class="gaptext">${gapText(q,i,a,checked)}</div>`;
    else if(q.t==="ct")body=`<div class="gaptext ctx">${ctText(q,i,a,checked)}</div>`;
    else if(q.t==="sent")body=sentHTML(q,i,a,checked);
    else if(q.t==="head"||q.t==="match"){
      // выпадающие списки: заголовок к абзацу, высказывание к человеку или абзацу
      const rows=q.t==="head"?q.paras.map(pn=>"Absatz "+pn):q.items,opts=q.t==="head"?q.heads:q.opts;
      const lab=hi=>q.t==="match"?esc(opts[hi]):String.fromCharCode(65+hi);
      body=(q.t==="head"?`<div class="sents">${q.heads.map((x,hi)=>`<div><b>${String.fromCharCode(65+hi)}</b>${esc(x)}</div>`).join("")}</div>`:"")+
        rows.map((rw,k)=>`<div class="mrow${q.t==="match"?" wide":""}"><span class="ml">${q.t==="match"?`${k+1}. `:""}${esc(rw)}</span><span class="mr"><select data-q="${i}" data-k="${k}" ${checked?"disabled":""}><option value="">—</option>${opts.map((h,hi)=>`<option value="${hi}" ${a&&a[k]===hi?"selected":""}>${q.t==="head"?String.fromCharCode(65+hi)+") "+esc(h.length>60?h.slice(0,58)+"…":h):esc(h)}</option>`).join("")}</select>${checked?(a&&a[k]===q.a[k]?' <span class="ok">✓</span>':` <span class="no">→ ${lab(q.a[k])}</span>`):""}</span></div>`).join("");}
    else if(q.t==="gap")body=`<input data-q="${i}" value="${esc(a||"")}" ${checked?"readonly":""} style="width:100%">`;
    if(checked){
      const [s,m]=taskCalc(q,a,r);
      if(q.t==="open"||q.t==="qs")why=aiBlock(q,i,r,true,hasKey)+(q.ex?`<div class="why">${esc(q.ex)}</div>`:"");
      else if(q.t==="short")why=`${s>=1?'<div class="why" style="color:var(--good);font-weight:800">✓ верно</div>':s>0?'<div class="why" style="color:var(--warn,#c77f00);font-weight:800">½</div>':'<div class="why" style="color:var(--bad);font-weight:800">✗ неверно</div>'}<div class="why"><b>Ответ:</b> ${esc((q.a||[]).join(" / "))}${r&&r.why&&s<1?`<br>${esc(r.why)}`:""}</div>`;
      else if(q.t==="rfb")why=`${s>=1?'<div class="why" style="color:var(--good);font-weight:800">✓ верно, 1 балл</div>':s>0?`<div class="why" style="color:var(--warn,#c77f00);font-weight:800">½ балла: ${a&&a.v===q.a?"ответ верный, доказательство нет":"доказательство верное, ответ нет"}</div>`:'<div class="why" style="color:var(--bad);font-weight:800">✗ 0 баллов</div>'}<div class="why"><b>Beweissatz:</b> «${esc(q.ev)} …»${q.ex?`<br>${esc(q.ex)}`:""}</div>`;
      else if(q.t==="tf")why=(s>=1?'<div class="why" style="color:var(--good);font-weight:800">✓ верно</div>':'<div class="why" style="color:var(--bad);font-weight:800">✗ неверно</div>')+`<div class="why">${q.ev?`<b>Beleg:</b> «${esc(q.ev)} …»<br>`:""}${q.ex?esc(q.ex):""}</div>`;
      else if(q.t==="sum"||q.t==="box"||q.t==="cl4"||q.t==="ct"||q.t==="mcg")why=`<div class="why"><b>${fmtPts(s)} из ${m}.</b> ${q.t==="mcg"?"Правильные ответы подсвечены.":"Неверные пропуски подписаны правильным ответом."}${q.ex?" "+esc(q.ex):""}</div>`;
      else if(q.t==="head"||q.t==="sent"||q.t==="match")why=`<div class="why">${fmtPts(s)} из ${m}${q.ex?" · "+esc(q.ex):""}</div>`;
      else why=(s>=1?'<div class="why" style="color:var(--good);font-weight:800">✓ верно</div>':'<div class="why" style="color:var(--bad);font-weight:800">✗ неверно</div>')+`<div class="why">${q.t==="gap"?`<b>Ответ:</b> ${esc(Array.isArray(q.a)?q.a.join(" / "):q.a)}<br>`:""}${q.ex?esc(q.ex):""}</div>`;
    }
    const noq=q.t==="sum"||q.t==="box"||q.t==="cl4"||q.t==="mcg"||q.t==="ct"||q.t==="sent";
    const qt=noq?esc(q.q):uMark(esc(q.q));
    return `${grp}<div class="tq">${head}<div class="qt"><span class="qn">${n}.</span>${qt}</div>${body}${why}</div>`;
  }).join("");
}
const gapsHTML=h=>h.replace(/\[\[(\d+)\]\]/g,(m,n)=>`<span class="lgap" data-gap="${n}">${n}</span>`);
// абзацы текста: если активно задание «Satz einfügen», в пропуски можно перетаскивать предложения прямо в статью
function artParasHTML(paras,sentTask,sentIdx,ans,checked){
  return (paras||[]).map((p,i)=>{
    const h=esc(p).replace(/\[\[(\d+)\]\]/g,(m,n)=>{
      if(!sentTask)return `<span class="lgap" data-gap="${n}">${n}</span>`;
      const k=+n-1,v=(ans[sentIdx]||[])[k],ok=checked?v===sentTask.a[k]:null;
      return `<span class="dgap lgap${checked?(ok?" right":" wrong"):""}${v!=null?" full":""}" data-q="${sentIdx}" data-k="${k}"><b class="gn">${n}</b>${v!=null?`<span class="dchip" data-v="${v}">${esc(sentTask.sents[v])}</span>`:""}</span>`});
    return `<p class="pa" data-i="${i}"><span class="pn">${i+1}</span>${h}</p>`}).join("");
}
function bindTasks(root,tasks,ans,onChange,persist){
  const sv=()=>{if(persist)persist()};
  root.querySelectorAll("button[data-q]").forEach(b=>b.onclick=()=>{const i=+b.dataset.q,q=tasks[i];
    if(q.t==="mcg"){const a=Array.isArray(ans[i])?ans[i]:[];a[+b.dataset.j]=+b.dataset.v;ans[i]=a}
    else if(q.t==="rfb")ans[i]=Object.assign({},ans[i],{v:b.dataset.v});else ans[i]=q.t==="mc"?+b.dataset.v:b.dataset.v;sv();onChange()});
  root.querySelectorAll("textarea[data-q],input[data-q]").forEach(t=>t.oninput=()=>{const i=+t.dataset.q;
    if(t.dataset.e)ans[i]=Object.assign({},ans[i],{e:t.value});
    else if(t.dataset.g!=null){ans[i]=ans[i]||[];ans[i][+t.dataset.g]=t.value}
    else ans[i]=t.value;sv()});
  root.querySelectorAll("select[data-q]").forEach(s=>s.onchange=()=>{const i=+s.dataset.q;ans[i]=ans[i]||[];
    if(s.dataset.g!=null){const k=+s.dataset.g;ans[i][k]=s.value===""?null:(s.dataset.n?+s.value:s.value);sv();if(tasks[i].t==="box")onChange()}
    else{ans[i][+s.dataset.k]=s.value===""?null:+s.value;sv()}});
}
// перетаскивание: root — контейнер со всеми пропусками и карточками (для Lesen это вся страница с текстом и заданиями); задания и ответы берутся по месту
function bindDnd(root,getTasks,getAns,onChange,persist){
  dndBind(root,{
    val:(i,v)=>getTasks()[i].t==="sent"?+v:v,
    place:(i,k,val,quiet)=>{
      const ans=getAns(),a=Array.isArray(ans[i])?ans[i]:[];
      if(val!=null)for(let j=0;j<a.length;j++)if(a[j]===val)a[j]=null;   // у карточки одно место
      a[k]=val;ans[i]=a;if(persist)persist();if(!quiet)onChange();
    }});
}
// сколько баллов в задании (для счётчиков)
const taskCount=q=>q.t==="head"?q.paras.length:q.t==="match"||q.t==="mcg"?q.items.length:["sent","box","sum","cl4","ct"].includes(q.t)?q.a.length:1;
// оценка открытых ответов ИИ; без ключа или при ошибке оценивает пользователь сам (кнопки ✓ / ½ / ✗)
async function gradeOpen(tasks,ans,res,paras,idx){
  const pend=[];
  (idx||tasks.map((_,i)=>i)).forEach(i=>{const q=tasks[i];
    if(res[i]&&typeof res[i].score==="number")return;
    const a=ans[i];
    if(AI_T(q)&&String(a||"").trim())pend.push([i,q]);
    else if(q.t==="short"&&a&&!(q.a||[]).map(loose).includes(loose(a)))pend.push([i,q]);
  });
  if(!pend.length||!(ST.keys&&ST.keys.anthropic))return false;
  try{
    const r=await post("/de/lcheck",{paras,items:pend.map(([i,q])=>({q:(q.task?q.task+": ":"")+String(q.q).replace(/\[\[|\]\]/g,""),models:Array.isArray(q.a)?q.a:[q.a],ans:String(ans[i]),max:q.t==="qs"?4:0}))});
    (r.results||[]).forEach((x,n)=>{res[pend[n][0]]={score:x.score,why:x.why}});
    return true;
  }catch(e){toast(e.message);return false}
}

// ---------- предложения и локальные задания (без ИИ) ----------
const ABBR=new Set(["z","b","d","h","u","a","o","ä","bzw","ca","usw","vgl","nr","dr","prof","mag","ing","evtl","ggf","inkl","etc","sog","mio","mrd","abs","art","bspw","zb","str","tel","jh","jhd","ev","lt","ua","uä","udgl","zt","bsp","min","max","mind","std","kfz","pkw"]);
function splitSents(text){
  const out=[],re=/([.!?])(["“”»]?)(\s+)(?=[„"“»A-ZÄÖÜ])/g;let last=0,m;
  while((m=re.exec(text))){
    const before=text.slice(last,m.index+1),lw=((before.match(/(\S+)$/)||[""])[0]).replace(/[.!?]+$/,"").replace(/^[„"“(]+/,"").toLowerCase();
    if(m[1]==="."&&(ABBR.has(lw)||/^\d{1,3}$/.test(lw)||lw.length===1))continue;
    out.push(text.slice(last,m.index+1+m[2].length));last=m.index+m[0].length;
  }
  out.push(text.slice(last));
  return out.map(s=>s.trim()).filter(Boolean);
}
const CUE=/^(Dabei|Dennoch|Allerdings|Daher|Deshalb|Deswegen|Zudem|Außerdem|Gleichzeitig|Trotzdem|Damit|Dies|Das|Diese[rsnm]?|Dazu|Dafür|Darauf|Darüber|Dort|Doch|Aber|Jedoch|Zugleich|Folglich|Somit|Umgekehrt|Stattdessen|Immerhin|Freilich|Sie|Er|Es|Auch|Ebenso|Entsprechend|Ähnlich|Weniger|Mehr|Zwar|Schließlich|Zuletzt|Vor allem)\b/;
function seededShuffle(a,seed){a=a.slice();let s=(seed>>>0)||1;const rnd=()=>{s=(s*1664525+1013904223)>>>0;return s/4294967296};for(let i=a.length-1;i>0;i--){const j=Math.floor(rnd()*(i+1));[a[i],a[j]]=[a[j],a[i]]}return a}
const hashStr=s=>{let h=5381;for(let i=0;i<s.length;i++)h=((h<<5)+h+s.charCodeAt(i))>>>0;return h};
// «Satz einfügen»: из нескольких абзацев выносим по одному предложению, добавляем лишние; paras2 — текст с пропусками [[n]]
function localSent(paras,distract){
  if(paras.some(p=>/\[\[\d+\]\]/.test(p)))return null;   // в этом тексте пропуски уже стоят
  const cand=[];
  paras.forEach((p,pi)=>{const ss=splitSents(p);if(ss.length<3)return;let best=null;
    ss.forEach((s,si)=>{if(si===0)return;const w=s.split(/\s+/).length;if(w<8||w>34)return;
      let sc=0;if(CUE.test(s))sc+=3;if(si===1)sc+=1;if(si===ss.length-1)sc-=1;if(/^[„"“]/.test(s)||/[„“]/.test(s))sc-=2;
      if(!best||sc>best.sc)best={pi,si,s,sc,ss}});
    if(best)cand.push(best)});
  if(cand.length<5)return null;
  const want=Math.min(7,cand.length);let pick=cand;
  if(cand.length>want){pick=[];for(let k=0;k<want;k++)pick.push(cand[Math.round(k*(cand.length-1)/(want-1))])}
  const paras2=paras.slice(),gaps=[];
  pick.forEach((c,k)=>{const ss=c.ss.slice();ss[c.si]="[[" +(k+1)+"]]";paras2[c.pi]=ss.join(" ");gaps.push(c.s)});
  const extra=(distract||[]).filter(x=>x&&!gaps.includes(x)).slice(0,2);
  const all=gaps.concat(extra),order=seededShuffle(all.map((_,i)=>i),hashStr(gaps.join("|")));
  const sents=order.map(i=>all[i]),a=gaps.map((_,k)=>order.indexOf(k));
  return {t:"sent",src:"local",q:`Welcher Satz passt in welche Lücke (1–${gaps.length})? ${extra.length===1?"Ein Satz bleibt":extra.length+" Sätze bleiben"} übrig.`,sents,a,paras2,ex:"Achte auf Bezugswörter (dies, dort, dabei) und Konnektoren vor und nach der Lücke."};
}
const STOPW=new Set(["können","könnte","werden","wurde","wurden","worden","würde","haben","hatte","hatten","dieser","diese","dieses","diesem","diesen","wieder","immer","schon","jedoch","dennoch","deshalb","zwischen","während","andere","anderen","anderer","anderem","einige","einigen","mehrere","manche","jedem","jeder","jedes","gegenüber","gewissermaßen","allerdings","nämlich","sowie","sondern","obwohl","außerdem","bereits","seiner","seinen","seinem","ihrer","ihren","ihrem","solche","solchen","dadurch","dagegen","dafür","davon","dabei","darüber","dazu","darauf","daraus","damit","hierzu","hierbei","zudem","somit","daher","dessen","deren","wollen","sollen","müssen","dürfen","möchte","mussten","konnten","sollten","wollten","wären","hätten","würden"]);
// «Lückentext с Wortkasten» по тексту: окно в 2–3 абзаца, 8 содержательных слов вынесены, добавлены 3 лишних слова из остального текста
function localBox(paras,seed){
  const plain=paras.filter(p=>!/\[\[\d+\]\]/.test(p));if(plain.length<3)return null;
  const wc=p=>p.split(/\s+/).length;let start=(seed||0)%Math.max(1,plain.length-2),win=[],n=0;
  for(let i=start;i<plain.length&&n<190;i++){win.push(i);n+=wc(plain[i])}
  while(n<150&&start>0){start--;win.unshift(start);n+=wc(plain[start])}
  const toks=win.map(i=>plain[i]).join("\n\n").split(/(\s+)/);
  const info=toks.map((t,ix)=>{const m=t.match(/^([„"“(]*)([A-Za-zÄÖÜäöüß-]+)([.,;:!?“")]*)$/);return m&&m[2].length>=6&&!STOPW.has(m[2].toLowerCase())?{ix,w:m[2],cap:/^[A-ZÄÖÜ]/.test(m[2]),pre:m[1],post:m[3]}:null});
  // не берём слово в начале предложения (заглавная буква без существительного) и слова после тире
  const ok=info.map((x,k)=>{if(!x)return null;let j=k-1;while(j>=0&&(toks[j]===""||/^\s+$/.test(toks[j])))j--;const prev=j>=0?toks[j]:"";return x.cap&&(!prev||/[.!?:]["“”]?$/.test(prev))?null:x}).filter(Boolean);
  const caps=ok.filter(x=>x.cap),low=ok.filter(x=>!x.cap),pickEven=(a,k)=>{const o=[];if(!a.length)return o;for(let q=0;q<k&&q<a.length;q++)o.push(a[Math.floor(q*a.length/Math.min(k,a.length))]);return o};
  let chosen=pickEven(caps,4).concat(pickEven(low,4));const seen=new Set();chosen=chosen.filter(x=>{const l=x.w.toLowerCase();if(seen.has(l))return false;seen.add(l);return true});
  if(chosen.length<6)return null;chosen.sort((a,b)=>a.ix-b.ix);chosen=chosen.slice(0,8);
  const ans=chosen.map(x=>x.w),text=toks.map((t,ix)=>{const k=chosen.findIndex(x=>x.ix===ix);return k<0?t:chosen[k].pre+"[["+(k+1)+"]]"+chosen[k].post}).join("").replace(/\n\n/g,"\n\n");
  const inWin=new Set(toks.join("").toLowerCase().match(/[a-zäöüß-]+/g)||[]);
  const pool=[];plain.filter((_,i)=>!win.includes(i)).join(" ").split(/\s+/).forEach(t=>{const m=t.match(/^([A-Za-zÄÖÜäöüß-]{6,13})[.,;:!?“")]*$/);if(m&&!STOPW.has(m[1].toLowerCase())&&!inWin.has(m[1].toLowerCase())&&!pool.includes(m[1]))pool.push(m[1])});
  const majorityCap=caps.length>=low.length;
  const dis=seededShuffle(pool.filter(w=>(/^[A-ZÄÖÜ]/.test(w))===majorityCap),hashStr(text)).slice(0,3);
  if(dis.length<2)return null;
  const words=seededShuffle(ans.concat(dis),hashStr(text+"w"));
  return {t:"box",src:"local",q:"Auszug aus dem Text: Fügen Sie die passenden Wörter aus dem Wortkasten ein. Einige Wörter bleiben übrig.",text,words,a:ans,ex:"Achte auf Artikel und Endungen vor und nach der Lücke und auf den Sinn des Satzes."};
}
// предложения-«чужие» для Satz einfügen, если ИИ недоступен: из других текстов того же модуля
function foreignSents(texts,selfId,k){
  const out=[];
  seededShuffle(texts.filter(t=>t.id!==selfId),hashStr(selfId+"f")).slice(0,6).forEach(t=>{(t.paras||[]).slice(1,4).forEach(p=>{const ss=splitSents(p).filter(s=>{const w=s.split(/\s+/).length;return w>=12&&w<=26&&!/[„“]/.test(s)&&!CUE.test(s)});if(ss[0])out.push(ss[0])})});
  return out.slice(0,k||2);
}

// ---------- ИИ: задания к тексту по типам (каждый запрос — не меньше 8 заданий) ----------
let GID=Date.now();
async function genBlockTasks(blk,paras,texts,selfId,ui){
  const kinds=BLK_KINDS[blk],gid=++GID,out=[];
  if(blk==="si"){
    const loc0=localSent(paras,[]);if(!loc0)throw new Error("В этом тексте уже есть пропуски или он слишком короткий для такого задания");
    let dis=[];try{const r=await post("/de/ltasks",{kind:"sdis",paras,removed:loc0.sents.filter((_,i)=>loc0.a.includes(i))});dis=r.tasks||[]}catch(e){if(ui)ui(" (лишние предложения возьмём из других текстов)")}
    if(dis.length<2)dis=foreignSents(texts||[],selfId,2);
    const fromAi=dis.length>=2;if(!fromAi)dis=foreignSents(texts||[],selfId,2);
    const t=localSent(paras,dis);t.src=fromAi?"ai":"local";t.gid=gid;return [t];
  }
  const res=await pool(2,kinds.map(k=>()=>post("/de/ltasks",{kind:k,paras})));
  res.forEach(r=>(r.tasks||[]).forEach(t=>{t.src="ai";t.gid=gid;out.push(t)}));
  return out;
}

// ---------- ИИ короткими шагами: хостинг обрывает долгий запрос, поэтому текст, запись и набор заданий собираются из нескольких коротких ----------
const wcount=s=>String(s||"").trim().split(/\s+/).filter(Boolean).length;
const FEM_V=["sage","coral","nova"],MALE_V=["onyx","echo","ash"],AT_STYLE="Sprich natürliches Hochdeutsch mit leichter österreichischer Färbung, lebendig wie in einem echten Gespräch oder Radiobeitrag.";
function voiceMap(speakers){const v={};let f=0,m=0;(speakers||[]).forEach(s=>{v[s.id]={voice:s.female?FEM_V[f++%3]:MALE_V[m++%3],name:s.name||s.id,style:AT_STYLE}});return v}
// задания к тексту: несколько видов параллельно, сбой одного вида не рушит остальные
async function genLtasksSet(kinds,paras,ui,from,to){
  let n=0,err=null;
  const sets=await pool(2,kinds.map(k=>()=>post("/de/ltasks",{kind:k,paras}).then(r=>r.tasks||[]).catch(e=>{err=e;return[]})),()=>{n++;if(ui)ui(`Составляю задания: ${n} из ${kinds.length}`,from+(to-from)*n/kinds.length)});
  const tasks=sets.flat().map(x=>({...x,src:"ai"}));
  if(!tasks.length)throw err||new Error("ИИ не составил задания, попробуй ещё раз");
  return tasks;
}
async function genLesen(topic,level,diff,prev,ui,kinds){
  const S=ui||(()=>{});
  S("План текста…",.03);
  const plan=await post("/de/gen",{step:"lplan",topic,level,diff,prev}),n=plan.paras.length;
  const paras=await pool(3,plan.paras.map((_,i)=>()=>post("/de/gen",{step:"lpara",i:i+1,plan,level,diff}).then(r=>r.text)),d=>S(`Пишу абзацы: ${d} из ${n}`,.06+.54*d/n));
  const words=paras.reduce((a,p)=>a+wcount(p),0);
  const tasks=await genLtasksSet(kinds||["tf","mc3","qs","head"],paras,S,.62,.97);
  return {id:"ai",level,topic,kicker:plan.kicker,title:plan.title,lead:plan.lead,paras,tasks,src:"Текст от ИИ в стиле der Standard",words,minutes:Math.max(5,Math.round(words/28))};
}
async function genHoeren(topic,level,diff,prev,ui){
  const S=ui||(()=>{});
  S("План записи…",.03);
  const plan=await post("/de/gen",{step:"hplan",topic,level,diff,prev}),n=plan.segments.length;
  const segs=await pool(3,plan.segments.map((_,i)=>()=>post("/de/gen",{step:"hseg",i:i+1,plan,level,diff})),d=>S(`Пишу запись: блок ${d} из ${n}`,.06+.6*d/n));
  const lines=segs.flatMap(s=>s.lines),words=lines.reduce((a,l)=>a+wcount(l[1]),0),voices=voiceMap(plan.speakers);
  if(words<850)throw new Error("Запись получилась слишком короткой ("+words+" слов), попробуй ещё раз");
  S("Составляю вопросы и конспект…",.7);
  const [mc,notes]=await Promise.all([post("/de/gen",{step:"hmc",voices,lines}),post("/de/gen",{step:"hnotes",voices,lines})]);
  return {id:"ai",level,kind:plan.kind,topic,title:plan.title,intro:plan.intro,voices,lines,words,minutes:Math.max(6,Math.round(words/145)),
    tasks:[{t:"mcg",q:"Aufgabe 1: Wählen Sie die richtige Antwort (A–D).",items:mc.items},notes.task]};
}
// три вида заданий «Strukturen und Wortschatz»: C-Test (5), Umformen (5), Lückentext или Rektion (5) = 15 баллов
async function genGrammItems(topic,level,diff,prev,ui,third,from,to){
  const kinds=["ct","uf",third||"lt"];let n=0;
  const sets=await Promise.all(kinds.map(k=>post("/de/gen",{step:"gitems",kind:k,topic,level,diff,prev}).then(r=>{n++;if(ui)ui(`Составляю задания: ${n} из ${kinds.length}`,from+(to-from)*n/kinds.length);return r.items})));
  return sets.flat();
}
async function genGramm(topic,level,diff,prev,ui,withTheory){
  const S=ui||(()=>{});
  S("Теория и задания…",.05);
  const [th,items]=await Promise.all([withTheory?post("/de/gen",{step:"gtheory",topic,level,diff}):Promise.resolve(null),genGrammItems(topic,level,diff,prev,S,"lt",.1,.95)]);
  return {id:"ai",title:th?th.title:topic,ru:th?th.ru:"",level,tag:"ИИ",theory:th?th.theory:"",items,exam:true};
}
