/* EPDeutsch: общее для всех модулей (Wörter, Schreiben, хаб, админка).
   Рисованные аватары и талисман Öli. Только ванильный JS, без зависимостей.
   Аватар в базе — число: 0–7 цвет с первой буквой имени, 8 и дальше — картинки из AVATARS. */
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

/* Талисман Öli: круглый, а точки умлаута над ним — его глаза. Ö → Öli.
   Цвет — цвет платформы: страница задаёт --oli (тело) и --oli-d (тень, ручки); без них Öli жёлтый.
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
