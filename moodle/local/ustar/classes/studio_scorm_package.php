<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Standalone, accessible SCORM 1.2 page player generated from Studio source. */
final class studio_scorm_package {
    public static function html(array $item): string {
        $pages = json_encode(array_values($item['pages']),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $title = htmlspecialchars((string)$item['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $template = <<<'HTML'
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>__TITLE__</title>
<style>
:root{color-scheme:light;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#202535;background:#f5f6f8}
*{box-sizing:border-box}body{margin:0}.shell{max-width:980px;margin:32px auto;background:white;border:1px solid #e6e8ed;border-radius:16px;overflow:hidden;box-shadow:0 12px 40px #2025350d}
header{padding:22px 32px;background:#212638;color:white;border-bottom:5px solid #ebc500}header small{display:block;color:#ebc500;font-weight:700;letter-spacing:.08em}
header h1{margin:8px 0 0;font-size:clamp(1.3rem,3vw,2rem)}main{padding:32px;min-height:280px}main h2{margin-top:0}main img{max-width:100%}
.progress{height:6px;background:#e9ebef}.progress span{display:block;height:100%;background:#ebc500;transition:width .2s}
footer{padding:20px 32px;display:flex;gap:12px;justify-content:space-between;align-items:center;border-top:1px solid #e6e8ed;flex-wrap:wrap}
.buttons{display:flex;gap:8px}button{border:1px solid #8c929e;border-radius:9px;padding:11px 18px;background:white;color:#202535;font:inherit;cursor:pointer}
button.primary{background:#ebc500;border-color:#ebc500;color:#202535;font-weight:700}button:disabled{opacity:.45;cursor:default}
button:focus-visible{outline:3px solid #376ab6;outline-offset:3px}#status{font-size:.9rem;color:#3e4959}
@media(max-width:600px){.shell{margin:0;border:0;border-radius:0}main,header,footer{padding:20px}}
</style></head><body>
<div class="shell"><header><small>USTAR ACADEMY · SCORM</small><h1>__TITLE__</h1></header>
<div class="progress" aria-label="Прогресс"><span id="bar"></span></div>
<main id="page" aria-live="polite"></main>
<footer><span id="status" role="status"></span><div class="buttons">
<button id="prev" type="button">Назад</button><button id="next" class="primary" type="button">Далее</button>
</div></footer></div>
<script>
"use strict";
const pages=__PAGES__;
let api=null,initialized=false,index=0,done=false;
function findApi(win){for(let i=0;i<8&&win;i++){try{if(win.API)return win.API;
if(win.parent===win)break;win=win.parent;}catch(e){break;}}return null;}
function call(name,...args){if(!api||!initialized)return "";try{return api[name](...args);}catch(e){return "";}}
api=findApi(window);if(!api&&window.opener)api=findApi(window.opener);
if(api){try{initialized=api.LMSInitialize("")==="true";}catch(e){initialized=false;}}
if(initialized){const place=Number.parseInt(call("LMSGetValue","cmi.core.lesson_location"),10);
if(Number.isInteger(place)&&place>=0&&place<pages.length)index=place;
const state=call("LMSGetValue","cmi.core.lesson_status");done=state==="completed"||state==="passed";
if(!done&&(!state||state==="not attempted"))call("LMSSetValue","cmi.core.lesson_status","incomplete");}
const panel=document.getElementById("page"),status=document.getElementById("status");
function render(){const page=pages[index];panel.innerHTML="";
const heading=document.createElement("h2");heading.textContent=page.title;panel.append(heading);
const content=document.createElement("div");content.innerHTML=page.body;panel.append(content);
if(typeof page.image==="string"&&/^data:image\/(png|jpeg|webp);base64,/.test(page.image)){
const photo=document.createElement("img");photo.src=page.image;photo.alt=page.title;
photo.style.cssText="display:block;max-width:100%;height:auto;margin:18px 0;border-radius:12px";panel.append(photo);}
document.getElementById("prev").disabled=index===0;
document.getElementById("next").textContent=index===pages.length-1?(done?"Завершено":"Завершить"):"Далее";
document.getElementById("next").disabled=done&&index===pages.length-1;
status.textContent="Страница "+(index+1)+" из "+pages.length+(done?" · Завершено":!initialized?" · Предпросмотр":"");
document.getElementById("bar").style.width=(((index+1)/pages.length)*100)+"%";
if(initialized){call("LMSSetValue","cmi.core.lesson_location",String(index));call("LMSCommit","");}}
document.getElementById("prev").addEventListener("click",()=>{if(index>0){index--;render();}});
document.getElementById("next").addEventListener("click",()=>{if(index<pages.length-1){index++;render();return;}
if(!done){done=true;if(initialized){call("LMSSetValue","cmi.core.lesson_status","completed");
call("LMSSetValue","cmi.core.score.raw","100");call("LMSSetValue","cmi.core.exit","");call("LMSCommit","");}render();}});
window.addEventListener("pagehide",()=>{if(initialized){if(!done)call("LMSSetValue","cmi.core.exit","suspend");
call("LMSCommit","");call("LMSFinish","");initialized=false;}});
render();
</script></body></html>
HTML;
        return str_replace(['__TITLE__', '__PAGES__'], [$title, $pages], $template);
    }
}
