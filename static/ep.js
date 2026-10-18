/* EPDeutsch: общее для всех модулей (Wörter, Schreiben, хаб, админка).
   Рисованные аватары и талисман Öli. Только ванильный JS, без зависимостей.
   Аватар в базе, число: 0–7 цвет с первой буквой имени, 8 и дальше, картинки из AVATARS. */
(function(){
"use strict";
const COLORS=["#7E6FB8","#8B5CF6","#E8505B","#F28C28","#18AE79","#0EA5B7","#E0A800","#282E3E"];
const esc=s=>String(s??"").replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
const INK="#2B2440";
// глаза с бликом: так любой зверь выглядит живым
const eye=(x,y,r)=>`<circle cx="${x}" cy="${y}" r="${r||3.2}" fill="${INK}"/><circle cx="${x+1.1}" cy="${y-1.1}" r="${(r||3.2)*.36}" fill="#fff"/>`;
const eyes=(a,b,y,r)=>eye(a,y,r)+eye(b,y,r);
const cheeks=(a,b,y,c)=>`<ellipse cx="${a}" cy="${y}" rx="3.6" ry="2.3" fill="${c||"#FF8FA3"}" opacity=".55"/><ellipse cx="${b}" cy="${y}" rx="3.6" ry="2.3" fill="${c||"#FF8FA3"}" opacity=".55"/>`;
const smile=(y,w)=>`<path d="M${32-(w||4)} ${y}q${w||4} ${(w||4)*.8} ${(w||4)*2} 0" fill="none" stroke="${INK}" stroke-width="2" stroke-linecap="round"/>`;

// [ключ, имя по-русски, имя по-английски, фон, рисунок 64×64]
const AVATARS=[
 ["fox","Лис","Fox","#FFE3D3",`<path d="M13 27 9 7l17 11q6-2 12 0l17-11-4 20q2 18-19 25Q11 45 13 27z" fill="#F28C28"/><path d="M12 10l9 7-6 5zM52 10l-9 7 6 5z" fill="#B85A12"/><path d="M17 34q15-6 30 0-3 14-15 18-12-4-15-18z" fill="#fff"/>${eyes(24,40,30)}<ellipse cx="32" cy="40" rx="3" ry="2.2" fill="${INK}"/>${smile(43,2.6)}`],
 ["cat","Кот","Cat","#E8E2FB",`<path d="M13 22 15 7l11 9h12l11-9 2 15q3 24-19 28-22-4-19-28z" fill="#9C93C6"/><path d="M17 11l6 6-5 2zM47 11l-6 6 5 2z" fill="#FFB8C8"/>${eyes(24,40,31)}<path d="M30 37h4l-2 2.4z" fill="#FF7A93"/><path d="M32 39.5q-2 3-5 2M32 39.5q2 3 5 2" fill="none" stroke="${INK}" stroke-width="1.6" stroke-linecap="round"/><path d="M8 35l10 1M8 40l10-1M56 35l-10 1M56 40l-10-1" stroke="${INK}" stroke-width="1.2" stroke-linecap="round" opacity=".5"/>${cheeks(19,45,38)}`],
 ["panda","Панда","Panda","#DDF4EA",`<circle cx="15" cy="17" r="8" fill="${INK}"/><circle cx="49" cy="17" r="8" fill="${INK}"/><circle cx="32" cy="35" r="20" fill="#fff"/><ellipse cx="24" cy="32" rx="5.5" ry="7" transform="rotate(25 24 32)" fill="${INK}"/><ellipse cx="40" cy="32" rx="5.5" ry="7" transform="rotate(-25 40 32)" fill="${INK}"/><circle cx="24.5" cy="31" r="2.2" fill="#fff"/><circle cx="39.5" cy="31" r="2.2" fill="#fff"/><ellipse cx="32" cy="40" rx="3.2" ry="2.3" fill="${INK}"/>${smile(43,2.6)}${cheeks(19,45,41)}`],
 ["bear","Мишка","Bear","#FFF0E1",`<circle cx="15" cy="17" r="7.5" fill="#B07A4F"/><circle cx="49" cy="17" r="7.5" fill="#B07A4F"/><circle cx="15" cy="17" r="3.8" fill="#E7B98E"/><circle cx="49" cy="17" r="3.8" fill="#E7B98E"/><circle cx="32" cy="35" r="20" fill="#B07A4F"/><ellipse cx="32" cy="41" rx="10" ry="7.5" fill="#E7B98E"/>${eyes(24,40,31)}<ellipse cx="32" cy="38" rx="3.4" ry="2.4" fill="${INK}"/>${smile(42,2.6)}`],
 ["frog","Лягух","Frog","#DDF6E3",`<ellipse cx="32" cy="40" rx="23" ry="16" fill="#3FB45C"/><circle cx="20" cy="24" r="9" fill="#3FB45C"/><circle cx="44" cy="24" r="9" fill="#3FB45C"/><circle cx="20" cy="24" r="5.5" fill="#fff"/><circle cx="44" cy="24" r="5.5" fill="#fff"/>${eyes(21,43,24,3)}<path d="M20 42q12 9 24 0" fill="none" stroke="${INK}" stroke-width="2.2" stroke-linecap="round"/>${cheeks(15,49,40)}`],
 ["bunny","Зайка","Bunny","#FFECEF",`<ellipse cx="23" cy="15" rx="5.5" ry="13" fill="#fff" stroke="#F3C6D0" stroke-width="1.5"/><ellipse cx="41" cy="15" rx="5.5" ry="13" fill="#fff" stroke="#F3C6D0" stroke-width="1.5"/><ellipse cx="23" cy="16" rx="2.6" ry="9" fill="#FFB8C8"/><ellipse cx="41" cy="16" rx="2.6" ry="9" fill="#FFB8C8"/><circle cx="32" cy="38" r="18" fill="#fff" stroke="#F3C6D0" stroke-width="1.5"/>${eyes(25,39,36)}<path d="M30 41h4l-2 2.2z" fill="#FF7A93"/><path d="M32 43.5v2.5M30 47h4" stroke="${INK}" stroke-width="1.5" stroke-linecap="round"/>${cheeks(21,43,42)}`],
 ["penguin","Пингвин","Penguin","#E0ECFF",`<circle cx="32" cy="34" r="21" fill="#2E3856"/><path d="M32 22q-5-6-12-2-6 5-5 14 2 14 17 17 15-3 17-17 1-9-5-14-7-4-12 2z" fill="#fff"/>${eyes(25,39,33)}<path d="M28 38h8l-4 5z" fill="#F28C28"/>${cheeks(20,44,40)}`],
 ["koala","Коала","Koala","#EDEFF4",`<circle cx="13" cy="24" r="10" fill="#9AA3B8"/><circle cx="51" cy="24" r="10" fill="#9AA3B8"/><circle cx="13" cy="24" r="5.5" fill="#E4E7EF"/><circle cx="51" cy="24" r="5.5" fill="#E4E7EF"/><circle cx="32" cy="35" r="19" fill="#9AA3B8"/>${eyes(24,40,31)}<ellipse cx="32" cy="39" rx="5" ry="6.5" fill="${INK}"/>${smile(47,2.4)}`],
 ["tiger","Тигр","Tiger","#FFF1D6",`<circle cx="15" cy="17" r="7" fill="#F2A03D"/><circle cx="49" cy="17" r="7" fill="#F2A03D"/><circle cx="32" cy="35" r="20" fill="#F2A03D"/><path d="M32 16v7M26 17l2 6M38 17l-2 6M13 31h6M13 36h5M51 31h-6M51 36h-5" stroke="${INK}" stroke-width="2.4" stroke-linecap="round"/><ellipse cx="32" cy="42" rx="11" ry="8" fill="#FFF6E8"/>${eyes(24,40,31)}<ellipse cx="32" cy="38.5" rx="3.2" ry="2.3" fill="${INK}"/>${smile(42.5,2.6)}`],
 ["chick","Цыпа","Chick","#FFF7CC",`<path d="M29 12q3-6 6 0M33 11q4-4 5 2" fill="none" stroke="#F2A03D" stroke-width="2.4" stroke-linecap="round"/><circle cx="32" cy="35" r="20" fill="#FFCD1F"/>${eyes(25,39,32)}<path d="M28 37h8l-4 5z" fill="#F28C28"/>${cheeks(19,45,39)}<path d="M12 37q-4 4 0 8M52 37q4 4 0 8" fill="none" stroke="#F5B700" stroke-width="3" stroke-linecap="round"/>`],
 ["unicorn","Единорог","Unicorn","#F4E8FF",`<path d="M32 4l4 15h-8z" fill="#FFCD1F"/><path d="M29 9l6 2M28.5 13l7 2" stroke="#F2A03D" stroke-width="1.3"/><path d="M14 30q-2-14 12-15 4 0 4 6-8 0-9 11zM50 26q2-10-8-11" fill="#C9A7F5"/><circle cx="32" cy="36" r="18" fill="#fff" stroke="#E6D6FA" stroke-width="1.5"/><path d="M15 26q4-9 13-7-3 3-3 9z" fill="#8FD3F5"/><path d="M42 20q8 1 8 9-4-4-9-4z" fill="#FF9FC0"/>${eyes(25,39,35)}${smile(41,3)}${cheeks(20,44,41)}`],
 ["owl","Сова","Owl","#EFE6DA",`<path d="M14 18l6 6M50 18l-6 6" stroke="#8A6040" stroke-width="5" stroke-linecap="round"/><ellipse cx="32" cy="36" rx="20" ry="19" fill="#8A6040"/><ellipse cx="32" cy="42" rx="12" ry="11" fill="#D9B48F"/><circle cx="24" cy="30" r="7.5" fill="#fff"/><circle cx="40" cy="30" r="7.5" fill="#fff"/>${eyes(24,40,30,3.6)}<path d="M29 36h6l-3 5z" fill="#F2A03D"/>`],
 ["robot","Робот","Robot","#DDF3F6",`<path d="M32 6v7" stroke="#6B7591" stroke-width="2.4"/><circle cx="32" cy="6" r="3.2" fill="#E8505B"/><rect x="12" y="13" width="40" height="38" rx="12" fill="#B9C2D6"/><rect x="17" y="20" width="30" height="20" rx="8" fill="${INK}"/><circle cx="25" cy="30" r="3.6" fill="#5DE0E6"/><circle cx="39" cy="30" r="3.6" fill="#5DE0E6"/><path d="M24 45h16" stroke="#6B7591" stroke-width="2.4" stroke-linecap="round"/><rect x="7" y="27" width="5" height="10" rx="2.5" fill="#8E98B0"/><rect x="52" y="27" width="5" height="10" rx="2.5" fill="#8E98B0"/>`],
 ["alien","Пришелец","Alien","#E4F8E7",`<path d="M32 9q20 0 20 19 0 16-20 26Q12 44 12 28 12 9 32 9z" fill="#7FD67F"/><ellipse cx="23" cy="30" rx="6.5" ry="4.2" transform="rotate(25 23 30)" fill="${INK}"/><ellipse cx="41" cy="30" rx="6.5" ry="4.2" transform="rotate(-25 41 30)" fill="${INK}"/><circle cx="21.5" cy="28.5" r="1.4" fill="#fff"/><circle cx="39.5" cy="28.5" r="1.4" fill="#fff"/>${smile(41,2.6)}`],
 ["oli","Öli","Öli","#FFF4CC",`<circle cx="32" cy="38" r="18" fill="#FFCD1F"/><circle cx="24" cy="13" r="6" fill="#fff"/><circle cx="40" cy="13" r="6" fill="#fff"/>${eyes(24,40,13.5,3)}<path d="M26 40q6 6 12 0" fill="none" stroke="${INK}" stroke-width="2.2" stroke-linecap="round"/>${cheeks(21,43,38)}`],
 ["ghost","Призрак","Ghost","#ECEEFF",`<path d="M14 54V30q0-18 18-18t18 18v24l-6-4-6 4-6-4-6 4-6-4z" fill="#fff" stroke="#DADDF5" stroke-width="1.5"/>${eyes(25,39,30)}<ellipse cx="32" cy="38" rx="3" ry="3.6" fill="${INK}"/>${cheeks(20,44,36)}`],
];

function avatarSVG(i){const a=AVATARS[i];if(!a)return "";return `<svg viewBox="0 0 64 64" width="100%" height="100%" aria-hidden="true">${a[4]}</svg>`}
// аватар пользователя: картинка или цветной кружок с первой буквой
function uava(u,sz,av){
  const n=+(av??(u&&u.avatar))||0,s=sz||32;
  if(n>=8&&AVATARS[n-8]){const a=AVATARS[n-8];return `<span class="uava uava-pic" style="--av:${a[3]};background:${a[3]};width:${s}px;height:${s}px" title="${esc(a[1])}">${avatarSVG(n-8)}</span>`}
  const c=COLORS[n%8];
  return `<span class="uava" style="--av:${c};background:${c};width:${s}px;height:${s}px;font-size:${Math.round(s*.43)}px">${esc(((u&&u.name||"").trim()[0]||"?").toUpperCase())}</span>`;
}
// выбор аватара для профиля: сначала рисованные, потом цвета
function picker(cur,lang){
  const en=lang==="en";
  return `<div class="ep-avp" role="radiogroup" aria-label="${en?"Avatar":"Аватар"}">`+
    AVATARS.map((a,i)=>`<button type="button" class="ep-av${+cur===i+8?" on":""}" data-av="${i+8}" role="radio" aria-checked="${+cur===i+8}" title="${esc(en?a[2]:a[1])}" style="background:${a[3]}">${avatarSVG(i)}</button>`).join("")+
    COLORS.map((c,i)=>`<button type="button" class="ep-av ep-avc${+cur===i?" on":""}" data-av="${i}" role="radio" aria-checked="${+cur===i}" title="${en?"Letter":"Буква"}" style="background:${c}"><b>Aa</b></button>`).join("")+"</div>";
}

/* Талисман Öli: круглый, а точки умлаута над ним, его глаза. Ö → Öli.
   Цвет, цвет платформы: страница задаёт --oli (тело) и --oli-d (тень, ручки); без них Öli жёлтый.
   Настроения: happy, cheer, wow, think, sad, sleep, write. */
function mascot(mood,size,cls){
  mood=mood||"happy";const s=size||96;
  const body="var(--oli,#FFCD1F)",shade="var(--oli-d,#F5B700)";
  const look=mood==="think"?{dx:-1.6,dy:-1.4}:{dx:0,dy:0};
  const eyeG=(x)=>mood==="sleep"
    ?`<circle cx="${x}" cy="34" r="11" fill="#fff"/><path d="M${x-6} 34q6 5 12 0" fill="none" stroke="${INK}" stroke-width="2.8" stroke-linecap="round"/>`
    :mood==="cheer"
    ?`<circle cx="${x}" cy="34" r="11" fill="#fff"/><path d="M${x-5.5} 36q5.5-7 11 0" fill="none" stroke="${INK}" stroke-width="3" stroke-linecap="round"/>`
    :`<circle cx="${x}" cy="34" r="11" fill="#fff"/><g class="oli-pupil"><circle cx="${x+look.dx}" cy="${35+look.dy}" r="${mood==="wow"?6.2:5.4}" fill="${INK}"/><circle cx="${x+2+look.dx}" cy="${33+look.dy}" r="2" fill="#fff"/></g>`;
  const mouth={happy:`<path d="M51 74q9 9 18 0" fill="none" stroke="${INK}" stroke-width="3.2" stroke-linecap="round"/>`,
    cheer:`<path d="M48 71q12 0 24 0-2 12-12 12t-12-12z" fill="${INK}"/><path d="M53 78q7 5 14 0" fill="#FF7A93"/>`,
    wow:`<ellipse cx="60" cy="76" rx="5" ry="6" fill="${INK}"/>`,
    think:`<path d="M53 77q4-3 8 0t8 0" fill="none" stroke="${INK}" stroke-width="3" stroke-linecap="round"/>`,
    sad:`<path d="M52 79q8-7 16 0" fill="none" stroke="${INK}" stroke-width="3.2" stroke-linecap="round"/>`,
    sleep:`<ellipse cx="60" cy="77" rx="3.5" ry="2.6" fill="${INK}"/>`,
    write:`<path d="M51 74q9 8 18 0" fill="none" stroke="${INK}" stroke-width="3.2" stroke-linecap="round"/>`}[mood]||"";
  const arms=mood==="cheer"
    ?`<path d="M28 62q-10-8-8-20" style="stroke:${shade}" stroke-width="8" stroke-linecap="round" fill="none"/><path d="M92 62q10-8 8-20" style="stroke:${shade}" stroke-width="8" stroke-linecap="round" fill="none"/>`
    :mood==="write"
    ?`<path d="M27 72q-8 4-8 10" style="stroke:${shade}" stroke-width="8" stroke-linecap="round" fill="none"/><path d="M93 70q9 0 12 6" style="stroke:${shade}" stroke-width="8" stroke-linecap="round" fill="none"/><g transform="rotate(35 106 78)"><rect x="102" y="62" width="8" height="26" rx="2" fill="#E8505B"/><path d="M102 88h8l-4 7z" fill="#F7D9A8"/><path d="M104.6 92.5h2.8l-1.4 2.5z" fill="${INK}"/></g>`
    :`<path d="M27 70q-9 3-9 11" style="stroke:${shade}" stroke-width="8" stroke-linecap="round" fill="none"/><path d="M93 70q9 3 9 11" style="stroke:${shade}" stroke-width="8" stroke-linecap="round" fill="none"/>`;
  const extra=mood==="sleep"?`<text x="88" y="30" font-family="Manrope,system-ui,sans-serif" font-weight="800" font-size="15" fill="#8E98B0">z</text><text x="98" y="18" font-family="Manrope,system-ui,sans-serif" font-weight="800" font-size="11" fill="#8E98B0">z</text>`
    :mood==="cheer"?`<path d="M14 26l3 6 6 1-5 4 1 6-5-3-5 3 1-6-5-4 6-1z" fill="#FF8FA3"/><path d="M104 18l2 4 4 .6-3 3 .7 4-3.7-2-3.7 2 .7-4-3-3 4-.6z" fill="#7FA6FF"/>`
    :mood==="think"?`<circle cx="98" cy="30" r="3" fill="#C9CEDC"/><circle cx="106" cy="20" r="4.5" fill="#C9CEDC"/>`:"";
  return `<svg class="oli oli-${mood}${cls?" "+cls:""}" viewBox="0 0 120 120" width="${s}" height="${s}" role="img" aria-label="Öli">
<ellipse cx="60" cy="112" rx="28" ry="5" fill="${INK}" opacity=".10" class="oli-shadow"/>
<g class="oli-body">${arms}
<path d="M60 38c22 0 36 15 36 36 0 19-15 34-36 34S24 93 24 74c0-21 14-36 36-36z" style="fill:${body}"/>
<path d="M30 86c6 13 17 20 30 20s24-7 30-20c-8 8-18 11-30 11s-22-3-30-11z" style="fill:${shade}" opacity=".55"/>
<ellipse cx="44" cy="58" rx="7" ry="4" fill="#fff" opacity=".45" transform="rotate(-25 44 58)"/>
<ellipse cx="38" cy="80" rx="6" ry="3.6" fill="#FF8FA3" opacity=".6"/><ellipse cx="82" cy="80" rx="6" ry="3.6" fill="#FF8FA3" opacity=".6"/>
${mouth}
<g class="oli-eyes">${eyeG(45)}${eyeG(75)}</g></g>${extra}</svg>`;
}

// стили один раз: аватары-картинки, выбор аватара, анимации талисмана
const css=`.uava.uava-pic{overflow:hidden;padding:0;display:inline-flex;align-items:center;justify-content:center}
.uava.uava-pic svg{width:100%;height:100%;display:block}
.ep-avp{display:grid;grid-template-columns:repeat(auto-fill,minmax(52px,1fr));gap:10px;max-width:560px}
.ep-av{aspect-ratio:1;border-radius:50%;border:0;padding:0;cursor:pointer;display:grid;place-items:center;overflow:hidden;box-shadow:0 0 0 0 transparent;transition:transform .25s cubic-bezier(.34,1.56,.64,1),box-shadow .2s}
.ep-av svg{width:100%;height:100%}
.ep-av:hover{transform:scale(1.08) rotate(-4deg)}
.ep-av.on{box-shadow:0 0 0 3px var(--panel,#fff),0 0 0 5.5px var(--primary,#7E6FB8);transform:scale(1.04)}
.ep-av:focus-visible{outline:2px solid var(--primary,#7E6FB8);outline-offset:3px}
.ep-avc b{color:#fff;font-weight:800;font-size:15px;letter-spacing:-.02em}
.oli .oli-body{transform-origin:60px 108px;animation:oliBob 3.2s ease-in-out infinite}
.oli .oli-eyes{transform-origin:60px 34px;animation:oliBlink 5s infinite}
.oli .oli-shadow{transform-origin:60px 112px;animation:oliShadow 3.2s ease-in-out infinite}
.oli-cheer .oli-body{animation:oliJump 1.1s cubic-bezier(.3,1.5,.5,1) infinite}
.oli-sleep .oli-body{animation:oliBreath 3.6s ease-in-out infinite}
@keyframes oliBob{0%,100%{transform:translateY(0)}50%{transform:translateY(-3px)}}
@keyframes oliShadow{0%,100%{transform:scaleX(1)}50%{transform:scaleX(.9)}}
@keyframes oliBlink{0%,92%,100%{transform:scaleY(1)}95%{transform:scaleY(.1)}}
@keyframes oliJump{0%,100%{transform:translateY(0) scale(1,1)}30%{transform:translateY(-9px) scale(.97,1.03)}60%{transform:translateY(0) scale(1.04,.96)}}
@keyframes oliBreath{0%,100%{transform:scale(1)}50%{transform:scale(1.025,.985)}}
@media (prefers-reduced-motion:reduce){.oli *{animation:none!important}}`;
function injectCSS(){if(document.getElementById("ep-css"))return;const st=document.createElement("style");st.id="ep-css";st.textContent=css;document.head.appendChild(st)}
if(document.head)injectCSS();else document.addEventListener("DOMContentLoaded",injectCSS);

window.EP={COLORS,AVATARS,avatarSVG,uava,picker,mascot,esc};
})();

/* Верхняя панель модулей (вместо бургера) и поиск по всем словам. Общие для Wörter, Schreiben и /de.
   EP.modbar(текущий) → HTML полосы вкладок; EP.search(), открыть поиск (⌘K / Ctrl+K / «/»). */
(function(){
"use strict";
const esc=window.EP.esc;
const I={
 home:'<path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/>',
 cards:'<rect x="3" y="7" width="14" height="14" rx="3"/><path d="M7 3h11a3 3 0 0 1 3 3v11"/>',
 pen:'<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
 gram:'<path d="M4 7V4h16v3"/><path d="M9 20h6"/><path d="M12 4v16"/>',
 book:'<path d="M3 5h6a3 3 0 0 1 3 3v12a2.5 2.5 0 0 0-2.5-2.5H3z"/><path d="M21 5h-6a3 3 0 0 0-3 3v12a2.5 2.5 0 0 1 2.5-2.5H21z"/>',
 ear:'<path d="M3 14v-2a9 9 0 0 1 18 0v2"/><rect x="2" y="14" width="5" height="7" rx="2"/><rect x="17" y="14" width="5" height="7" rx="2"/>',
 mic:'<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0 0 14 0"/><path d="M12 17v4"/>',
 star:'<path d="M12 3l2.6 5.6 6.1.7-4.5 4.2 1.2 6L12 16.6 6.6 19.5l1.2-6L3.3 9.3l6.1-.7z"/>',
 cal:'<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4"/>',
 search:'<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
 flame:'<path d="M12 22c4 0 7-2.7 7-6.6 0-3.1-1.8-5.3-3.4-7-.4 1.9-1.5 3-2.6 3.4.3-3.3-1.3-6.6-4.3-8.8.3 3-1.2 5-2.7 6.8C4.8 11.3 5 12.6 5 15.4 5 19.3 8 22 12 22z"/><path d="M12 22c-1.7 0-3-1.2-3-3 0-1.6 1.3-2.6 2-3.6.4 1 1.2 1.5 2 1.6.6-.6 1-1.4 1.1-2.3 1 .9 1.9 2.4 1.9 4.3 0 1.8-1.3 3-4 3z"/>',
 video:'<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M10 9l5 3-5 3z"/>',
 chev:'<path d="M9 6l6 6-6 6"/>',
 spark:'<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M19 17l.7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7z"/>',
 bulb:'<path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0 0 12 3z"/>',
 menu:'<path d="M4 7h16M4 12h16M4 17h16"/>',
 speaker:'<path d="M11 5L6 9H3v6h3l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"/>',
 gear:'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
 moon:'<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
 globe:'<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
 x:'<path d="M6 6l12 12M18 6L6 18"/>',
 target:'<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.2"/>',
 chart:'<path d="M4 20V4M4 20h16"/><path d="M7 15l4-4 3 3 5-6"/>',
 swap:'<path d="M7 4L3 8l4 4"/><path d="M3 8h14"/><path d="M17 20l4-4-4-4"/><path d="M21 16H7"/>',
 plus:'<path d="M12 5v14M5 12h14"/>',
 check:'<path d="M5 12l5 5 9-10"/>',
 clock:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
 quote:'<path d="M7 7h4v4c0 3-1.5 5-4 6"/><path d="M14 7h4v4c0 3-1.5 5-4 6"/>',
 doc:'<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
 bars:'<path d="M6 20V10M12 20V4M18 20v-7"/>',
 chat:'<path d="M4 5h16v11H9l-5 4z"/>',
 edit:'<path d="M4 20h4L19 9l-4-4L4 16z"/>'
};
const ic=(n,s)=>`<svg viewBox="0 0 24 24" width="${s||18}" height="${s||18}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${I[n]}</svg>`;
// [ключ, подпись, адрес, иконка, цвет]
const MODS=[
 ["home","Обзор","/de#/","home","#0E8C7F"],
 ["woerter","Wörter","/woerter","cards","#7E6FB8"],
 ["schreiben","Schreiben","/schreiben","pen","#C4687A"],
 ["grammatik","Grammatik","/de#/grammatik","gram","#F28C28"],
 ["lesen","Lesen","/de#/lesen","book","#3D7BF7"],
 ["hoeren","Hören","/de#/hoeren","ear","#0EA5B7"],
 ["sprechen","Sprechen","/de#/sprechen","mic","#18AE79"],
 ["frei","Свободный","/de#/frei","star","#E8505B"]
];
function modbar(cur){
  return `<nav class="ep-mods" aria-label="Модули">${MODS.map(([k,t,h,i,c],n)=>
    (k==="frei"?'<span class="ep-msep" aria-hidden="true"></span>':"")+
    `<a href="${h}" data-m="${k}" style="--c:${c}"${k===cur?' aria-current="page"':""}><span class="ep-mi">${ic(i,16)}</span><span class="ep-ml">${t}</span></a>`).join("")}</nav>`+
    `<button type="button" class="ep-sbtn" data-ep-search title="Поиск слова (⌘K)" aria-label="Поиск">${ic("search",18)}<span>Поиск</span><kbd>⌘K</kbd></button>`;
}
const css=`.ep-mods{display:flex;align-items:center;gap:2px;min-width:0;overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch;flex:1 1 auto;padding:4px 2px}
.ep-mods::-webkit-scrollbar{display:none}
.ep-mods a{--c:var(--primary);display:inline-flex;align-items:center;gap:7px;flex:none;padding:6px 10px 6px 6px;border-radius:12px;color:var(--fg);font-weight:700;font-size:13.5px;text-decoration:none;white-space:nowrap;transition:background .15s}
.ep-mods a:hover{background:var(--hover,var(--panel2))}
.ep-mi{width:28px;height:28px;border-radius:9px;display:grid;place-items:center;color:var(--c);background:color-mix(in srgb,var(--c) 13%,transparent);transition:transform .2s cubic-bezier(.34,1.56,.64,1),background .2s,color .2s}
.ep-mods a:hover .ep-mi{transform:scale(1.08) rotate(-4deg)}
.ep-mods a[aria-current="page"]{background:color-mix(in srgb,var(--c) 12%,transparent)}
.ep-mods a[aria-current="page"] .ep-mi{background:var(--c);color:#fff;box-shadow:0 4px 12px -4px var(--c)}
.ep-msep{width:1px;height:24px;background:var(--line);margin:0 6px;flex:none}
.ep-sbtn{display:inline-flex;align-items:center;gap:8px;flex:none;border:1px solid var(--line);background:var(--panel);color:var(--muted);border-radius:12px;padding:7px 10px;font:inherit;font-size:13px;font-weight:700;cursor:pointer}
.ep-sbtn:hover{color:var(--fg);border-color:var(--muted)}
.ep-sbtn kbd{font:inherit;font-size:11px;background:var(--panel2);border-radius:6px;padding:1px 5px;color:var(--muted)}
@media (max-width:1480px){.ep-mods .ep-ml{display:none}.ep-mods a{padding:6px}.ep-mods a[aria-current="page"] .ep-ml{display:inline}.ep-mods a[aria-current="page"]{padding-right:10px}}
@media (max-width:760px){.ep-sbtn span,.ep-sbtn kbd{display:none}.ep-sbtn{padding:8px}}
.ep-sf{position:relative;flex:1 1 auto;min-width:0;max-width:560px;margin:0 auto;display:flex;align-items:center;gap:10px;background:var(--bg);border-radius:999px;padding:0 14px 0 16px;height:42px;color:var(--muted);border:2px solid transparent;transition:border-color .15s,background .15s}
.ep-sf:focus-within{border-color:var(--primary);background:var(--panel)}
.ep-sf input{flex:1;min-width:0;border:0;background:none;font:inherit;font-size:14.5px;font-weight:600;color:var(--fg);outline:none;padding:0}
.ep-sf input::placeholder{color:var(--muted);font-weight:500}
.ep-sf input::-webkit-search-cancel-button{display:none}
.ep-sf kbd{font:inherit;font-size:11px;font-weight:700;background:var(--panel2);border-radius:6px;padding:2px 6px;color:var(--muted)}
.ep-sf:focus-within kbd{display:none}
.ep-sdrop{position:absolute;left:0;right:0;top:calc(100% + 8px);background:var(--panel);border-radius:18px;box-shadow:0 24px 50px -18px rgba(10,9,45,.45);padding:8px;max-height:min(460px,70vh);overflow:auto;z-index:50;color:var(--fg)}
.ep-sdrop a{display:flex;gap:12px;align-items:baseline;justify-content:space-between;padding:9px 10px;border-radius:12px;color:inherit;text-decoration:none}
.ep-sdrop a:hover{background:var(--hover,var(--panel2))}
.ep-sdrop .ep-sg{font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);padding:10px 10px 4px}
.ep-sdrop b{font-weight:800}.ep-sdrop small{color:var(--muted);font-size:12.5px;text-align:right;flex:none;max-width:45%}
.ep-sdrop .ep-sru{color:var(--muted);font-weight:500;margin-left:6px}
.ep-sdrop mark{background:var(--mark,#FFF1B8);color:inherit;border-radius:3px}
.ep-sfbtn{display:none;flex:none;width:40px;height:40px;border:0;border-radius:12px;background:var(--panel2);color:var(--fg);cursor:pointer;place-items:center;margin-left:auto}
@media (max-width:640px){.ep-sf{display:none}.ep-sfbtn{display:grid}.ep-sfbtn+*{margin-left:6px!important}}
.ep-sov{position:fixed;inset:0;z-index:200;background:rgba(10,9,45,.42);display:flex;align-items:flex-start;justify-content:center;padding:9vh 16px 16px;animation:epIn .15s ease-out}
.ep-sbox{width:min(640px,100%);max-height:78vh;display:flex;flex-direction:column;background:var(--panel);color:var(--fg);border-radius:22px;box-shadow:0 30px 70px -20px rgba(10,9,45,.5);overflow:hidden;font-family:var(--font)}
.ep-shead{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--line2)}
.ep-shead input{flex:1;border:0;outline:0;background:none;font:inherit;font-size:18px;font-weight:600;color:var(--fg);min-width:0}
.ep-shead button{border:0;background:var(--panel2);color:var(--muted);border-radius:10px;width:34px;height:34px;display:grid;place-items:center;cursor:pointer}
.ep-sres{overflow:auto;padding:8px}
.ep-sres .ep-sg{font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);padding:10px 10px 4px}
.ep-sres a,.ep-sres div.ep-si{display:flex;gap:12px;align-items:baseline;justify-content:space-between;padding:9px 10px;border-radius:12px;color:inherit;text-decoration:none}
.ep-sres a:hover,.ep-sres .ep-si:hover,.ep-sres .on{background:var(--hover,var(--panel2))}
.ep-sres b{font-weight:800}.ep-sres small{color:var(--muted);font-size:12.5px;text-align:right;flex:none;max-width:45%}
.ep-sres .ep-sru{color:var(--muted);font-weight:500;margin-left:6px}
.ep-sres mark{background:var(--mark,#FFF1B8);color:inherit;border-radius:3px}
.ep-sempty{padding:26px 16px;text-align:center;color:var(--muted)}
@keyframes epIn{from{opacity:0}}`;
const st=document.createElement("style");st.textContent=css;(document.head||document.documentElement).appendChild(st);

// ---------- поиск ----------
const fold=s=>String(s||"").toLowerCase().replace(/ä/g,"a").replace(/ö/g,"o").replace(/ü/g,"u").replace(/ß/g,"ss").replace(/ё/g,"е").replace(/[*()\[\]]/g,"").trim();
const strip=s=>fold(s).replace(/^(der|die|das|sich|ein|eine)\s+/,"");
let IDX=null,loading=null;
function loadScript(src){return new Promise((ok,bad)=>{const s=document.createElement("script");s.src=src;s.onload=ok;s.onerror=bad;document.head.appendChild(s)})}
const getJSON=u=>fetch(u,{credentials:"same-origin"}).then(r=>r.ok?r.json():null).catch(()=>null);
async function buildIndex(){
  const out=[];
  try{if(typeof BOOK==="undefined")await loadScript("/assets/book.js")}catch(e){}
  try{if(typeof BOOK!=="undefined")BOOK.forEach(ch=>(ch.sets||[]).forEach(s=>(s.items||[]).forEach(it=>{
    const de=Array.isArray(it)?it[0]:it.de,ru=Array.isArray(it)?it[1]:it.ru;
    out.push({g:"EPD · Wörter",de,ru,where:ch.id+" "+ch.title+" › "+s.name,href:"/woerter"})})))}catch(e){}
  const [me,wd,gde,gfr,lde,lfr,hde,hfr]=await Promise.all(["/api/me","/data/frei/wendungen.json","/data/de/grammatik.json","/data/frei/grammatik.json","/data/de/lesen.json","/data/frei/lesen.json","/data/de/hoeren.json","/data/frei/hoeren.json"].map(getJSON));
  const pr=me&&me.progress;(pr&&pr.usets||[]).forEach(u=>(u.cards||[]).forEach(c=>out.push({g:"Мои наборы",de:c.de,ru:c.ru,where:u.name,href:"/woerter"})));
  (wd&&wd.decks||[]).forEach(d=>(d.cards||[]).forEach(c=>out.push({g:"Свободный · "+d.title,de:c.de,ru:c.ru,where:d.title,href:"/de#/frei/karten/"+d.id})));
  [[gde,"EPD · Grammatik","/de#/grammatik/"],[gfr,"Свободный · Grammatik","/de#/frei/grammatik/"]].forEach(([g,n,h])=>(g&&g.topics||[]).forEach(t=>out.push({g:n,de:t.title,ru:t.ru||"",where:t.level||"",href:h+t.id,topic:1})));
  [[lde,"EPD · Lesen","/de#/lesen/"],[lfr,"Свободный · Lesen","/de#/frei/lesen/"],[hde,"EPD · Hören","/de#/hoeren/"],[hfr,"Свободный · Hören","/de#/frei/hoeren/"]].forEach(([g,n,h])=>(g&&(g.texts||g.items)||[]).forEach(t=>out.push({g:n,de:t.title,ru:t.topic||"",where:t.level||"",href:h+t.id,topic:1})));
  out.forEach(o=>{o.fd=strip(o.de);o.fr=fold(o.ru)});
  return out;
}
function hl(s,q){const f=fold(s),i=f.indexOf(q);if(i<0||!q)return esc(s);return esc(s.slice(0,i))+"<mark>"+esc(s.slice(i,i+q.length))+"</mark>"+esc(s.slice(i+q.length))}
// результаты поиска в любой контейнер: и в окно ⌘K, и в выпадашку под полем в шапке
function results(res,val){
  const q=fold(val),qs=strip(val);
  if(!q){res.innerHTML='<div class="ep-sempty">Есть ли слово в твоих наборах? Начни печатать.</div>';return}
  if(!IDX){res.innerHTML='<div class="ep-sempty">Собираю словарь…</div>';return}
  const hits=IDX.map(o=>{let sc=-1;if(o.fd===qs)sc=0;else if(o.fd.startsWith(qs))sc=1;else if(o.fd.includes(qs))sc=2;else if(o.fr.split(/[,;\/ ]+/).includes(q))sc=1.5;else if(o.fr.includes(q))sc=3;return [sc,o]}).filter(x=>x[0]>=0).sort((a,b)=>a[0]-b[0]||a[1].de.length-b[1].de.length).slice(0,60);
  if(!hits.length){res.innerHTML=`<div class="ep-sempty">«${esc(val)}» нет ни в одном наборе.<br><a href="/woerter" style="color:var(--link,var(--primary));font-weight:700">Добавить в свой набор в Wörter →</a></div>`;return}
  const groups={};hits.forEach(([,o])=>(groups[o.g]=groups[o.g]||[]).push(o));
  res.innerHTML=`<div class="ep-sempty" style="padding:6px 10px;text-align:left">Нашлось: ${hits.length}${hits.length===60?"+":""}</div>`+Object.entries(groups).map(([g,a])=>`<div class="ep-sg">${esc(g)}</div>`+a.map(o=>`<a href="${o.href}"><span><b>${hl(o.de,qs)}</b>${o.ru?`<span class="ep-sru">${hl(o.ru,q)}</span>`:""}</span><small>${esc(o.where)}</small></a>`).join("")).join("");
}
const need=cb=>{if(IDX)return;if(!loading)loading=buildIndex().then(x=>{IDX=x});loading.then(cb)};
function search(val){
  if(document.querySelector(".ep-sov"))return;
  const ov=document.createElement("div");ov.className="ep-sov";
  ov.innerHTML=`<div class="ep-sbox" role="dialog" aria-label="Поиск"><div class="ep-shead">${ic("search",20)}<input type="search" placeholder="Слово по-немецки или по-русски…" autocomplete="off" spellcheck="false"><button type="button" aria-label="Закрыть">${ic("x",16)}</button></div><div class="ep-sres"></div></div>`;
  document.body.appendChild(ov);
  const inp=ov.querySelector("input"),res=ov.querySelector(".ep-sres");inp.value=val||"";
  const close=()=>{ov.remove();document.removeEventListener("keydown",key,true)};
  ov.addEventListener("click",e=>{if(e.target===ov||e.target.closest(".ep-shead button"))close()});
  function key(e){if(e.key==="Escape"){e.stopPropagation();close()}}
  document.addEventListener("keydown",key,true);
  setTimeout(()=>inp.focus(),20);
  const render=()=>results(res,inp.value);need(render);render();
  inp.addEventListener("input",render);
}
// поле поиска в шапке (вместо полосы модулей); на телефоне — кнопка, открывающая окно поиска
function searchField(){return `<div class="ep-sf" role="search"><span class="ep-sfi">${ic("search",18)}</span><input type="search" placeholder="Найти слово: по-немецки или по-русски" autocomplete="off" spellcheck="false" aria-label="Поиск слова"><kbd>⌘K</kbd><div class="ep-sdrop" hidden></div></div><button type="button" class="ep-sfbtn" data-ep-search aria-label="Поиск">${ic("search",20)}</button>`}
function sfOpen(f,on){const d=f.querySelector(".ep-sdrop");d.hidden=!on;f.classList.toggle("on",on)}
document.addEventListener("input",e=>{const f=e.target.closest&&e.target.closest(".ep-sf");if(!f)return;const d=f.querySelector(".ep-sdrop"),v=e.target.value;sfOpen(f,!!v.trim());const r=()=>results(d,e.target.value);need(r);r()});
document.addEventListener("focusin",e=>{const f=e.target.closest&&e.target.closest(".ep-sf");if(f){need(()=>{});if(e.target.value.trim())sfOpen(f,true)}});
document.addEventListener("mousedown",e=>{document.querySelectorAll(".ep-sf.on").forEach(f=>{if(!f.contains(e.target))sfOpen(f,false)})});
document.addEventListener("keydown",e=>{
  const t=e.target,typing=t&&(t.tagName==="INPUT"||t.tagName==="TEXTAREA"||t.isContentEditable);
  const f=t&&t.closest&&t.closest(".ep-sf");
  if(f&&e.key==="Escape"){sfOpen(f,false);t.blur();return}
  if(f&&e.key==="Enter"){const a=f.querySelector(".ep-sdrop a");if(a){e.preventDefault();location.href=a.getAttribute("href");sfOpen(f,false)}return}
  const field=[...document.querySelectorAll(".ep-sf input")].find(x=>x.offsetParent);
  if((e.metaKey||e.ctrlKey)&&e.key.toLowerCase()==="k"){e.preventDefault();field?field.focus():search()}
  else if(e.key==="/"&&!typing&&!e.metaKey&&!e.ctrlKey){e.preventDefault();field?field.focus():search()}
});
document.addEventListener("click",e=>{if(e.target.closest("[data-ep-search]")){e.preventDefault();search()}
  const a=e.target.closest(".ep-sdrop a");if(a){const f=a.closest(".ep-sf");setTimeout(()=>sfOpen(f,false),0)}});
Object.assign(window.EP,{modbar,search,searchField,icon:ic,MODS});
})();

/* Общая «рамка» всех страниц: бургер, логотип EPDeutsch с названием модуля, экран загрузки,
   меню модулей (для /de и телефона). Одинаково в Wörter, Schreiben, /de и на входе.
   EP.logo(модуль, адрес) · EP.burger(id) · EP.boot(модуль) · EP.ready() · EP.modlist(текущий) · EP.drawer(текущий, доп.HTML) */
(function(){
"use strict";
const ic=window.EP.icon,MODS=window.EP.MODS,esc=window.EP.esc;
const NAME={home:"Deutsch",woerter:"Wörter",schreiben:"Schreiben",grammatik:"Grammatik",lesen:"Lesen",hoeren:"Hören",sprechen:"Sprechen",frei:"Свободный",plan:"План",settings:"Настройки",start:"Учёба"};
const DESC={home:"план дня и отсчёт до экзамена",woerter:"карточки слов EPD",schreiben:"тексты с проверкой ИИ",grammatik:"темы EPD с теорией",lesen:"статьи и задания",hoeren:"аудио и вопросы",sprechen:"устная часть с разбором",frei:"живой немецкий без экзамена",plan:"даты, норма, формат тестов"};
const modOf=k=>MODS.find(m=>m[0]===k);
const CV={woerter:"--c-w",schreiben:"--c-s",grammatik:"--c-g",lesen:"--c-l",hoeren:"--c-h",sprechen:"--c-sp",frei:"--c-f",plan:"--c-p"};
try{const o=JSON.parse(localStorage.getItem("ep-colors")||"{}");Object.keys(o).forEach(k=>{if(CV[k]&&/^#[0-9a-f]{6}$/i.test(o[k]))document.documentElement.style.setProperty(CV[k],o[k])})}catch(e){}
// цвет раздела: пользователь может выбрать свой (--c-…), иначе стандартный
const color=k=>{const h=(modOf(k)||[])[4]||"#0E8C7F";return CV[k]?`var(${CV[k]},${h})`:h};
// знак EPDeutsch: буква D и жёлтая галочка, цвет квадрата — цвет модуля
const mark=s=>`<svg class="ep-mark" viewBox="0 0 34 34" width="${s||32}" height="${s||32}" aria-hidden="true"><rect x="1" y="1" width="28" height="28" rx="8" fill="var(--c)"/><path class="ep-md" d="M10.5 8h3.5a7 7 0 0 1 0 14h-3.5z" fill="none" stroke="#fff" stroke-width="3.4" stroke-linejoin="round"/><g class="ep-mb"><circle cx="26.5" cy="26.5" r="6.5" fill="#FFCD1F" stroke="var(--panel,#fff)" stroke-width="2.5"/><path d="M23.7 26.6l2 2 3.5-4" fill="none" stroke="#282E3E" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></g></svg>`;
function logo(k,href,attrs){const n=NAME[k]||k;
  return `<a class="ep-logo" href="${href||"/de#/"}" style="--c:${color(k)}" aria-label="EPDeutsch ${esc(n)}, на главную"${attrs||""}>${mark(32)}<span class="ep-lt"><span class="ep-ln">EP<b>Deutsch</b></span>${k==="home"||k==="start"?"":`<small class="ep-ls" lang="de">${esc(n)}</small>`}</span></a>`}
function burger(id,label){return `<button type="button" class="ep-burger" id="${id||"burger"}" aria-label="${label||"Меню"}" aria-controls="nav"><span class="bm">${ic("menu",22)}</span><span class="bx">${ic("x",22)}</span></button>`}
function modlist(cur){return `<div class="ep-modlist" aria-label="Модули">${MODS.map(([k,t,h,i,c])=>`<a href="${h}" style="--c:${color(k)}"${k===cur?' aria-current="page"':""}><span class="ep-mi">${ic(i,22)}</span><span><b>${t}</b><small>${DESC[k]||""}</small></span>${k===cur?`<span class="ep-mnow">сейчас</span>`:ic("chev",16)}</a>`).join("")}</div>`}

// ---------- бургер: «Выбрать модуль» → вторая панель со списком модулей ----------
function switcher(cur){const m=modOf(cur)||modOf("home"),n=cur==="home"?"Обзор":(NAME[cur]||m[1]);
  return `<button type="button" class="ep-swbtn" data-ep-sw style="--c:${color(cur)}"><span class="ep-mi">${ic(m[3],18)}</span><span class="ep-swt"><small>Модуль</small><b>${esc(n)}</b></span><span class="ep-swgo">Выбрать${ic("chev",16)}</span></button>`+
    `<div class="ep-swpanel"><button type="button" class="ep-swback" data-ep-swback>${ic("chev",16)}Назад</button><div class="ep-dgt">Выбери модуль</div>${modlist(cur)}</div>`}
const TIPS={
 woerter:["Повторяй слова каждый день понемногу: 20 карточек ежедневно лучше, чем 150 раз в неделю.","Учи слово в связке: глагол с предлогом и падежом (sich beschäftigen mit + D).","Существительное, всегда с артиклем и формой мн. числа: der Antrag, die Anträge.","Не уверен, жми «Не знаю»: честный ответ ставит повторение вовремя."],
 schreiben:["На EPD считают слова: недобор режет баллы за выполнение задания.","План за 5 минут экономит 15: тезис, 2–3 аргумента, пример, вывод.","Связки дают баллы за связность: einerseits … andererseits, folglich, zudem.","Оставь 5 минут на проверку: артикли, окончания, порядок слов в придаточных."],
 grammatik:["Passiversatz: «lässt sich + Infinitiv», «ist zu + Infinitiv», «-bar».","Nominalstil: «weil die Preise steigen» → «wegen des Anstiegs der Preise».","Konjunktiv II Vergangenheit: hätte/wäre + Partizip II.","Partizip I, действие одновременно и активно: die steigenden Preise."],
 lesen:["Сначала прочитай задания, потом текст: так ищешь нужное, а не читаешь всё подряд.","«Nicht im Text», если в тексте об этом нет ни подтверждения, ни опровержения.","Заголовки к абзацам: ищи главную мысль абзаца, а не совпадающее слово.","Не знаешь слово, смотри на контекст и корень: Abwanderung ← wandern."],
 hoeren:["На EPD текст звучит дважды: первый раз, общий смысл, второй, детали.","Записывай ключевые слова, а не предложения целиком.","Числа и даты, частая ловушка: проверяй их при втором прослушивании.","Оценивают понимание: в открытых ответах хватает кратких формулировок."],
 sprechen:["Говори 5 минут по Leitfragen: каждая, 40–60 секунд.","Не понял вопрос, так и скажи: «Könnten Sie die Frage bitte wiederholen?»","Начни презентацию с плана: «Zuerst … dann … abschließend …».","Своё мнение обосновывай примером из Австрии или своей страны."],
 frei:["Живой немецкий учится ушами: сериалы с немецкими субтитрами.","Австрийские словечки: Paradeiser, Erdäpfel, Jänner, пригодятся в Вене.","Разговорная речь сокращает: «hast du» → «haste», «ich habe» → «ich hab’»."],
 plan:["Укажи дату EPD, норма карточек подстроится под неё.","Одна тема в неделю: слова, текст, аудио и Sprechen по ней складываются."],
 home:["Начни с плана дня: шаги подобраны по теме недели.","Короткие занятия каждый день работают лучше длинных раз в неделю."]};
function tip(cur){const a=TIPS[cur]||TIPS.home,t=a[Math.floor(Date.now()/864e5)%a.length];
  return `<div class="ep-tip"><span class="ep-tipi">${ic("bulb",16)}</span><div><b>Совет</b><p>${esc(t)}</p></div></div>`}
document.addEventListener("click",e=>{
  const o=e.target.closest("[data-ep-sw]"),b=e.target.closest("[data-ep-swback]");
  if(o){const w=o.parentElement;w.classList.add("ep-swon");w.scrollTop=0}
  if(b){b.closest(".ep-swon").classList.remove("ep-swon")}
});

// ---------- экран загрузки: кружок крутится вокруг знака в цвете модуля ----------
const ring=k=>`<div class="ep-ring" style="--c:${color(k)}"><svg viewBox="0 0 100 100" aria-hidden="true"><circle cx="50" cy="50" r="45" class="ep-rt"/><circle cx="50" cy="50" r="45" class="ep-ra"/></svg>${mark(44)}</div>`;
// загрузка при переходе между экранами внутри страницы (/de): шапка остаётся, ниже — кружок с логотипом
let plT=0,plShown=0;
function pageLoad(k){let el=document.getElementById("ep-pl");if(!el){el=document.createElement("div");el.id="ep-pl";el.setAttribute("aria-hidden","true");document.body.appendChild(el)}
  el.innerHTML=ring(k);clearTimeout(plT);plShown=performance.now();el.classList.add("on")}
function pageDone(){const el=document.getElementById("ep-pl");if(!el)return;const left=Math.max(0,350-(performance.now()-plShown));clearTimeout(plT);plT=setTimeout(()=>el.classList.remove("on"),left)}
let bootT0=0;
function boot(k){
  if(document.getElementById("ep-boot"))return;bootT0=performance.now();
  const n=NAME[k]||"";
  document.body.insertAdjacentHTML("afterbegin",`<div id="ep-boot" aria-hidden="true" style="--c:${color(k)}"><div class="ep-bin">${ring(k)}<div class="ep-bname"><span>EP<b>Deutsch</b></span>${n&&k!=="home"&&k!=="start"?` <small lang="de">${esc(n)}</small>`:""}</div></div></div>`);
  setTimeout(ready,8000); // страховка: что бы ни случилось, через 8 с страница открывается
}
// снять экран, когда страница отрисована и шрифты на месте; не короче 450 мс, чтобы не мигало
function ready(){const d=document.documentElement;if(!d.classList.contains("boot")||d.classList.contains("bootout"))return;
  const wait=ms=>new Promise(r=>setTimeout(r,ms));
  const fonts=document.fonts&&document.fonts.ready?Promise.race([document.fonts.ready,wait(1500)]):Promise.resolve();
  const left=Math.max(0,450-(performance.now()-bootT0));
  Promise.all([fonts,wait(left)]).then(()=>{let done=false;const out=()=>{if(done)return;done=true;d.classList.add("bootout");setTimeout(()=>{d.classList.remove("boot","bootout")},520)};
    requestAnimationFrame(()=>requestAnimationFrame(out));setTimeout(out,150)}); // кадр отрисован, или, в фоновой вкладке, просто через 150 мс
}

// ---------- выезжающее меню (для /de, где нет своей боковой панели) ----------
function drawer(cur,sections){
  let dr=document.getElementById("ep-drawer");
  if(!dr){dr=document.createElement("div");dr.id="ep-drawer";dr.innerHTML='<div class="ep-dveil"></div><nav class="ep-dpanel" aria-label="Меню"></nav>';document.body.appendChild(dr);
    dr.addEventListener("click",e=>{if(e.target.closest(".ep-dveil")||e.target.closest("a"))toggle(false);if(e.target.closest("[data-ep-theme]"))theme()});
    addEventListener("keydown",e=>{if(e.key==="Escape")toggle(false)})}
  const p=dr.querySelector(".ep-dpanel");p.classList.remove("ep-swon");
  p.innerHTML=`${switcher(cur)}${sections||""}${tip(cur)}<button type="button" class="ep-dtheme" data-ep-theme>${ic("moon",18)}<span>Сменить тему</span></button>`;
  return dr;
}
function toggle(on){const b=document.body;on=on===undefined?!b.classList.contains("ep-dopen"):on;b.classList.toggle("ep-dopen",on);document.querySelectorAll(".ep-burger").forEach(x=>x.setAttribute("aria-expanded",on))}
function theme(){const d=document.documentElement,dark=d.getAttribute("data-theme")==="dark"||(!d.getAttribute("data-theme")&&matchMedia("(prefers-color-scheme: dark)").matches),t=dark?"light":"dark";d.setAttribute("data-theme",t);try{const o=JSON.parse(localStorage.getItem("usd3-v1")||"{}");o.theme=t;localStorage.setItem("usd3-v1",JSON.stringify(o))}catch(e){}}

const css=`
/* ---- шапка: одна для всех модулей ---- */
.ep-burger{display:inline-flex;align-items:center;justify-content:center;flex:none;width:42px;height:42px;border:0;background:none;border-radius:12px;cursor:pointer;color:var(--muted);padding:0}
.ep-burger:hover{background:var(--hover);color:var(--fg)}
.ep-burger .bx,body.navopen .ep-burger .bm,body.ep-dopen .ep-burger .bm{display:none}
body.navopen .ep-burger .bx,body.ep-dopen .ep-burger .bx{display:inline-flex}
.ep-logo{--c:var(--primary);display:flex;align-items:center;gap:9px;flex:none;font-weight:800;font-size:21px;letter-spacing:-.03em;white-space:nowrap;color:var(--fg);text-decoration:none;line-height:1}
.ep-logo .ep-lt{display:flex;align-items:center;gap:8px}
.ep-logo .ep-ln b{color:var(--c);font-weight:800}
.ep-logo .ep-ls{font-size:12.5px;font-weight:800;letter-spacing:.01em;color:#fff;background:var(--c);border-radius:8px;padding:5px 8px;line-height:1}
.ep-mark{overflow:visible;transition:transform .45s cubic-bezier(.34,1.56,.64,1)}
.ep-mark .ep-md,.ep-mark .ep-mb{transform-box:fill-box;transform-origin:center;transition:transform .45s cubic-bezier(.34,1.56,.64,1)}
.ep-logo:hover .ep-mark{transform:translateY(-2px)}.ep-logo:hover .ep-md{transform:scale(.88) rotate(-6deg)}.ep-logo:hover .ep-mb{transform:scale(1.15)}
.top .ep-mods{margin-left:6px}
@media (max-width:1180px){.ep-logo .ep-ln{display:none}}
@media (max-width:760px){.top .ep-mods,.top .ep-msep{display:none!important}.ep-burger{width:38px;height:38px}}
@media (max-width:640px){.top .home-de,.top a.home{display:none!important}.top{gap:8px!important}}
@media (max-width:340px){.ep-logo .ep-ls{display:none}}
/* ---- список модулей (в меню и на телефоне) ---- */
.ep-modlist{display:flex;flex-direction:column;gap:8px}
.ep-modlist a{position:relative;display:flex;align-items:center;gap:14px;min-height:64px;padding:11px 12px;border-radius:18px;color:var(--fg);text-decoration:none;background:var(--panel);border:1.5px solid var(--line2);box-shadow:0 1px 0 rgba(40,46,62,.03);transition:transform .2s cubic-bezier(.34,1.56,.64,1),border-color .15s,background .15s,box-shadow .2s}
.ep-modlist a:hover{border-color:color-mix(in srgb,var(--c) 45%,transparent);background:color-mix(in srgb,var(--c) 6%,var(--panel));transform:translateX(3px);box-shadow:0 10px 22px -16px var(--c)}
.ep-modlist .ep-mi{width:42px;height:42px;border-radius:14px;flex:none}
.ep-modlist a:hover .ep-mi{background:var(--c);color:#fff;transform:rotate(-5deg) scale(1.05)}
.ep-modlist a>span:nth-child(2){display:flex;flex-direction:column;min-width:0;flex:1}
.ep-modlist b{font-weight:800;font-size:15.5px;line-height:1.25;letter-spacing:-.01em}
.ep-modlist small{color:var(--muted);font-size:12.5px;font-weight:500;line-height:1.35}
.ep-modlist a>svg{color:var(--muted);opacity:.6;transition:transform .2s,opacity .2s}.ep-modlist a:hover>svg{opacity:1;color:var(--c);transform:translateX(2px)}
.ep-modlist a[aria-current="page"]{background:color-mix(in srgb,var(--c) 11%,var(--panel));border-color:var(--c)}
.ep-modlist a[aria-current="page"] .ep-mi{background:var(--c);color:#fff;box-shadow:0 6px 14px -6px var(--c)}
.ep-mnow{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--c);background:color-mix(in srgb,var(--c) 14%,transparent);border-radius:999px;padding:3px 8px;flex:none}
/* ---- выбор модуля и совет в меню ---- */
.ep-swbtn{--c:var(--primary);display:flex;align-items:center;gap:12px;width:100%;border:1.5px solid color-mix(in srgb,var(--c) 30%,transparent);background:color-mix(in srgb,var(--c) 8%,var(--panel));border-radius:16px;padding:10px 12px;margin:0 0 10px;cursor:pointer;font:inherit;color:var(--fg);text-align:left;transition:border-color .15s,transform .15s}
.ep-swbtn:hover{border-color:var(--c)}.ep-swbtn:active{transform:scale(.98)}
.ep-swbtn .ep-mi{width:36px;height:36px;border-radius:11px;background:var(--c);color:#fff}
.ep-swt{display:flex;flex-direction:column;line-height:1.2;min-width:0;flex:1}
.ep-swt small{font-size:11.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}
.ep-swt b{font-size:15.5px;font-weight:800}
.ep-swgo{display:inline-flex;align-items:center;gap:2px;font-size:13px;font-weight:700;color:var(--c)}
.ep-swpanel{display:none}
.ep-swon>*:not(.ep-swpanel){display:none!important}
.ep-swon>.ep-swpanel{display:block;animation:epSw .28s cubic-bezier(.2,.9,.3,1)}
.ep-swback{display:inline-flex;align-items:center;gap:4px;border:0;background:none;color:var(--muted);font:inherit;font-weight:700;font-size:13.5px;padding:6px 8px;border-radius:10px;cursor:pointer;margin-bottom:2px}
.ep-swback svg{transform:rotate(180deg)}.ep-swback:hover{background:var(--hover);color:var(--fg)}
@keyframes epSw{from{opacity:0;transform:translateX(24px)}}
.ep-tip{display:flex;gap:10px;margin:12px 4px 4px;padding:12px 14px;border-radius:16px;background:color-mix(in srgb,#FFCD1F 16%,var(--panel));box-shadow:var(--sh)}
.ep-tipi{width:28px;height:28px;border-radius:9px;background:#FFCD1F;color:#282E3E;display:grid;place-items:center;flex:none}
.ep-tip b{font-size:13px;font-weight:800}.ep-tip p{margin:2px 0 0;font-size:13px;line-height:1.45;color:var(--muted)}
.ep-dsec a{display:flex;align-items:center;gap:12px;padding:9px 10px;border-radius:14px;color:var(--fg);text-decoration:none;font-weight:600;font-size:14.5px}
.ep-dsec a:hover{background:var(--hover)}
.ep-dsec .ep-mi{width:30px;height:30px;border-radius:10px}
.ep-dsec a[aria-current="page"]{background:color-mix(in srgb,var(--c) 12%,transparent);color:var(--fg)}
.ep-dsec a[aria-current="page"] .ep-mi{background:var(--c);color:#fff}
/* ---- выезжающее меню ---- */
#ep-drawer .ep-dveil{position:fixed;inset:0;z-index:60;background:rgba(10,9,45,.38);opacity:0;visibility:hidden;transition:opacity .25s,visibility .25s}
#ep-drawer .ep-dpanel{position:fixed;left:0;top:0;bottom:0;z-index:61;width:min(300px,86vw);background:var(--panel);box-shadow:var(--sh-card);border-radius:0 26px 26px 0;padding:max(14px,env(safe-area-inset-top)) 12px calc(14px + env(safe-area-inset-bottom));overflow:auto;transform:translateX(-102%);transition:transform .3s cubic-bezier(.3,1.1,.5,1)}
body.ep-dopen #ep-drawer .ep-dveil{opacity:1;visibility:visible}
body.ep-dopen #ep-drawer .ep-dpanel{transform:none}
body.ep-dopen{overflow:hidden}
.ep-dgt{font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);padding:12px 10px 6px}
.ep-dtheme{display:flex;align-items:center;gap:12px;width:100%;border:0;background:none;color:var(--fg);font:inherit;font-weight:600;font-size:14px;padding:10px;border-radius:14px;cursor:pointer;margin-top:8px}
.ep-dtheme:hover{background:var(--hover)}.ep-dtheme svg{color:var(--muted)}
.ep-dlinks a{display:flex;align-items:center;gap:12px;padding:9px 10px;border-radius:14px;color:var(--fg);text-decoration:none;font-weight:600;font-size:14px}
.ep-dlinks a:hover{background:var(--hover)}.ep-dlinks a svg{color:var(--muted)}
.ep-dlinks a[aria-current="page"]{background:var(--primary-soft);color:var(--link)}
/* ---- экран загрузки ---- */
#ep-boot{position:fixed;inset:0;z-index:10000;background:var(--bg,#F6F7FB);display:none;place-items:center;transition:opacity .45s ease,visibility .45s}
html.boot #ep-boot{display:grid}
html.bootout #ep-boot{opacity:0;visibility:hidden}
.ep-bin{display:flex;flex-direction:column;align-items:center;gap:16px;transform:translateY(-4vh)}
.ep-ring{position:relative;width:104px;height:104px;display:grid;place-items:center}
.ep-ring>svg:not(.ep-mark){position:absolute;inset:0;width:100%;height:100%;animation:epSpin 1s linear infinite}
.ep-ring .ep-mark{position:static;width:44px;height:44px}
.ep-ring .ep-rt{fill:none;stroke:color-mix(in srgb,var(--c) 15%,transparent);stroke-width:5}
.ep-ring .ep-ra{fill:none;stroke:var(--c);stroke-width:5;stroke-linecap:round;stroke-dasharray:70 213}
.ep-ring .ep-mark{animation:epPulse 1.6s ease-in-out infinite}
#ep-pl{position:fixed;left:0;right:0;top:66px;bottom:0;z-index:28;background:color-mix(in srgb,var(--bg) 92%,transparent);display:grid;place-items:center;opacity:0;visibility:hidden;transition:opacity .18s,visibility .18s;pointer-events:none}
#ep-pl.on{opacity:1;visibility:visible}
#ep-pl .ep-ring{transform:translateY(-6vh) scale(.85)}
@media (max-width:640px){#ep-pl{top:58px}}
@keyframes epSpin{to{transform:rotate(360deg)}}
@keyframes epPulse{0%,100%{transform:scale(1)}50%{transform:scale(.92)}}
.ep-bname{font-family:var(--font,Manrope,system-ui,sans-serif);font-weight:800;font-size:26px;letter-spacing:-.03em;color:var(--fg,#282E3E);display:flex;align-items:center;gap:9px}
.ep-bname b{color:var(--c)}
.ep-bname small{font-size:14px;color:#fff;background:var(--c);border-radius:9px;padding:6px 9px;letter-spacing:.01em;line-height:1}
@keyframes epBob{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
@media (prefers-reduced-motion:reduce){#ep-boot *{animation:none!important}}
`;
const st=document.createElement("style");st.id="ep-frame";st.textContent=css;(document.head||document.documentElement).appendChild(st);
document.addEventListener("click",e=>{const b=e.target.closest("[data-ep-drawer]");if(b){e.preventDefault();toggle()}});
Object.assign(window.EP,{logo,burger,mark,boot,ready,ring,pageLoad,pageDone,modlist,switcher,tip,drawer,toggleDrawer:toggle,toggleTheme:theme,NAME});
})();
