const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const {JSDOM} = require('jsdom'), Mustache = require('mustache');
const base = path.resolve(__dirname, '../../moodle/local/ustar');
const read = name => fs.readFileSync(path.join(base, name), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));

async function chat() {
    const html = Mustache.render(read('templates/messages.mustache'), {
        available:true, hascurrent:true, conversationid:42, apiurl:'/api', sesskey:'fixture',
        requestid:'fixture_send_000000000', maxbytes:25000000, maxpostbytes:64000000,
        current:{id:42, title:'Fixture', cansend:true, isgroup:true, canmanage:true,
            members:[{id:7, fullname:'Colleague', removable:true}]},
    }, {'local_ustar/messages_thread':read('templates/messages_thread.mustache')});
    const dom = new JSDOM(html, {url:'https://academy.invalid/messages', runScripts:'outside-only', pretendToBeVisual:true});
    const win = dom.window, requests = []; let interval;
    win.setInterval = callback => {interval = callback; return 1;};
    win.URL.createObjectURL = () => 'blob:fixture'; win.URL.revokeObjectURL = () => {};
    class XHR {
        constructor() {this.upload = {addEventListener(){}}; requests.push(this);}
        open(method, url) {assert.equal(method,'POST'); assert.equal(url,'/api');}
        setRequestHeader() {}
        send(body) {this.body = body;}
        answer(data) {this.status = 200; this.responseText = JSON.stringify(data); this.onload(); this.onloadend();}
    }
    win.XMLHttpRequest = XHR;
    await tick(); win.eval(read('messages.js'));
    const form = win.document.querySelector('[data-chat-compose]'), text = form.querySelector('textarea');
    const file = form.querySelector('input[type=file]'), token = form.querySelector('[name=requestid]');
    let uploads = [];
    Object.defineProperty(file,'files',{get:()=>uploads,set:value=>{uploads=Array.from(value);}});
    Object.defineProperty(file,'value',{get:()=>'',set:value=>{if(value==='')uploads=[];}});
    win.DataTransfer = class {constructor(){this.files=[];this.items={add:item=>this.files.push(item)};}};
    const submit = () => form.dispatchEvent(new win.Event('submit',{bubbles:true,cancelable:true}));
    text.value = 'Draft survives a lost response'; text.dispatchEvent(new win.Event('input'));
    uploads = [new win.File(['document'],'fixture.txt',{type:'text/plain'})]; file.dispatchEvent(new win.Event('change'));
    const firstToken = token.value; submit();
    assert.equal(requests[0].body.get('sesskey'),'fixture');
    requests[0].onerror(); requests[0].onloadend();
    assert.equal(text.value,'Draft survives a lost response'); assert.equal(uploads.length,1);
    assert.equal(token.value,firstToken,'Uncertain delivery must retain its retry token');
    submit(); assert.equal(requests[1].body.get('requestid'),firstToken);
    requests[1].onerror(); requests[1].onloadend();
    win.document.querySelector('[data-chat-uploads] button').click();
    assert.notEqual(token.value,firstToken,'Removing an attachment creates a new payload');
    submit(); requests[2].answer({ok:true,html:'<p>Sent</p>',signature:'1',cansend:true});
    assert.equal(text.value,''); assert.equal(uploads.length,0);
    assert.equal(form.querySelector('button[type=submit]').disabled,false);
    assert.equal(win.document.querySelector('form input[name=action][value=remove]').parentNode
        .querySelector('[name=conversationid]').value,'42','Member ID must not replace the conversation ID');
    win.fetch = async () => ({ok:false,status:400,json:async()=>({ok:false})});
    await interval();
    assert.equal(text.disabled,true,'Membership loss must disable sending');
    assert.match(form.querySelector('[data-chat-status]').textContent,/доступ/);
    dom.window.close();
}

async function worker() {
    const handlers = {}, entries = new Map(), calls = [];
    const publicAssets = ['https://academy.invalid/offline','https://academy.invalid/icon'];
    const cache = {put:async(url,response)=>entries.set(url,response),match:async url=>entries.get(url)};
    const context = {URL, USTAR_APP_CACHE:'ustar-app-new', USTAR_PUBLIC_ASSETS:publicAssets,
        self:{location:{origin:'https://academy.invalid'},clients:{claim:async()=>{}},addEventListener:(name,fn)=>handlers[name]=fn},
        caches:{open:async()=>cache,keys:async()=>['unrelated','ustar-app-old'],delete:async key=>calls.push(key)},
        fetch:async (url,options)=>{calls.push({url,options});return {ok:true};}};
    vm.runInNewContext(read('app_worker.js'),context);
    let work; handlers.install({waitUntil:promise=>{work=promise;}}); await work;
    assert.deepEqual([...entries.keys()],publicAssets);
    assert(calls.every(call=>call.options.credentials==='omit'),'Public cache must omit account cookies');
    for(const [url,method] of [['https://academy.invalid/api','POST'],['https://academy.invalid/pluginfile.php/private','GET'],['https://other.invalid/file','GET']]) {
        let intercepted=false; handlers.fetch({request:{url,method,mode:'cors'},respondWith:()=>{intercepted=true;}});
        assert.equal(intercepted,false,'Private media and API must stay on the network');
    }
    context.fetch = async () => {throw new Error('Offline');};
    let response; handlers.fetch({request:{url:'https://academy.invalid/home',method:'GET',mode:'navigate'},respondWith:promise=>{response=promise;}});
    assert.equal(await response,entries.get(publicAssets[0]));
    handlers.activate({waitUntil:promise=>{work=promise;}}); await work;
    assert.equal(calls.at(-1),'ustar-app-old'); assert(!calls.includes('unrelated'));
}

Promise.resolve().then(chat).then(worker).then(()=>console.log('MESSAGES_DOM_WORKER=PASS draft retry payload removal revoked-access public-only-cache'))
    .catch(error=>{console.error(error);process.exitCode=1;});
