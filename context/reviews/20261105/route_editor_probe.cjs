// Real controller projection + Mustache + server policy; synthetic module catalogue, no Moodle/network.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'../../..');
const {JSDOM}=require(root+'/tests/release_20260912/node_modules/jsdom');
const Mustache=require(root+'/tests/release_20260912/node_modules/mustache');
const php=process.env.USTAR_PHP||'php';
function run(code){const r=spawnSync(php,['-r',code],{encoding:'utf8'});if(r.status!==0)throw Error(r.stderr+r.stdout);return JSON.parse(r.stdout);}
const src=fs.readFileSync(root+'/moodle/local/ustar/route_studio.php','utf8');
const a=src.indexOf('        $selectedcontents = [];'),b=src.indexOf('        $state =',a);
assert(a>=0&&b>a,'controller projection must be found');
const requirements=[{type:'cm',sourceid:37,required:true,label:'SCORM fixture'},{type:'cm',sourceid:40,required:false,label:'Quiz fixture'}];
const projected=run('$requirements=json_decode('+JSON.stringify(JSON.stringify(requirements))+',true);'+src.slice(a,b)+'echo json_encode(["selectedcm"=>$selectedcm]);');
const tpl=fs.readFileSync(root+'/moodle/local/ustar/templates/route_studio.mustache','utf8');
const policy=fs.readFileSync(root+'/moodle/local/ustar/templates/route_access_policy.mustache','utf8');
const model={routeexists:true,sesskey:'fixture',route:{haspoints:true,points:[{id:1,editing:true,phase:'adaptation',expectedmodified:1,formtitle:'Fixture',activechecked:'checked',publishedselected:true,accessmanual:true,accessclose:true,finishnext:true,activityoptions:[{id:37,name:'SCORM',selected:projected.selectedcm===37},{id:40,name:'Quiz',selected:projected.selectedcm===40}]}]}};
const dom=new JSDOM(Mustache.render(tpl,model,{'local_ustar/route_access_policy':policy}));
const doc=dom.window.document,form=doc.querySelector('input[value=save_version]').form;
const cmids=[...form.querySelectorAll('select[name=cmid] option:checked')].map(o=>Number(o.value)).filter(Boolean);
const policyInput={advance:'manual',repeat:'close',seconds:0,finish:'next'};
function validate(req){return run('define("MOODLE_INTERNAL",true);define("IGNORE_MISSING",0);class invalid_parameter_exception extends Exception{} function get_coursemodule_from_id($a,$id,...$rest){return (object)["modname"=>$id===37?"scorm":"quiz"];} require '+JSON.stringify(root+'/moodle/local/ustar/classes/route_point_access.php')+'; try { $p=\\local_ustar\\route_point_access::normalize(json_decode('+JSON.stringify(JSON.stringify(policyInput))+',true)); \\local_ustar\\route_point_access::validate_for_requirements($p,json_decode('+JSON.stringify(JSON.stringify(req))+',true));echo json_encode(["accepted"=>true]);}catch(invalid_parameter_exception $e){echo json_encode(["accepted"=>false,"exception"=>get_class($e),"message"=>$e->getMessage()]);}');}
const original=validate(requirements),serialized=validate(cmids.map(id=>({type:'cm',sourceid:id,required:true})));
assert(original.accepted);assert(!serialized.accepted);assert.deepEqual(cmids,[40]);
const out={scope:'actual PHP projection, real Mustache form and route_point_access validation; synthetic catalogue; no HTTP501 or production point identity claimed',source_commit:'719bf47c1ba5942c094a04312898890ec7de00c1',initial_requirements:requirements,projected_activity_ids:cmids,original_policy_validation:original,serialized_policy_validation:serialized,form:{inputs:form.querySelectorAll('input,select,textarea').length,fieldsets:form.querySelectorAll('fieldset').length,collapsed_sections:form.querySelectorAll('details').length,required_checkbox_always_checked:form.querySelector('input[name=required]').checked,active_unchecked_not_submitted:null,draft_status_submitted:null},observations:[]};
form.querySelector('input[name=active]').checked=false;form.querySelector('select[name=status]').value='draft';
const payload=new dom.window.FormData(form);out.form.active_unchecked_not_submitted=!payload.has('active');out.form.draft_status_submitted=payload.get('status');
assert(out.form.active_unchecked_not_submitted);assert.equal(out.form.draft_status_submitted,'draft');
out.observations=['Unchecking active correctly omits it; optional_param default0 is expected, not a PARAM_BOOL failure','Multiple CMs collapse to last CM; non-SCORM last selection fails unchanged SCORM review policy','Per-requirement optional flag is not representable by the always-checked required checkbox'];
console.log(JSON.stringify(out,null,2));
