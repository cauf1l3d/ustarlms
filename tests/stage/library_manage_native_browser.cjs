const assert=require('node:assert/strict');
module.exports=async(context,base)=>{
 const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto(base+'/local/ustar/library_manage.php');
 await page.locator('summary').filter({hasText:'Редактор проверок знаний'}).click();
 const form=()=>page.locator('form:has(input[name=action][value=savecheck])');
 const rows=()=>form().locator('[data-library-questions] [data-library-question]');
 await form().locator('[data-library-question-status]').filter({hasText:'из 100'}).waitFor();
 const title='Native library bank '+Date.now();await form().locator('[name=title]').fill(title);
 await form().locator('[name=topic]').fill('Native topic');await form().locator('[name=questioncount]').fill('6');await form().locator('[name=passpercent]').fill('80');
 while(await rows().count()<6)await form().locator('[data-library-add-question]').click();
 for(let i=0;i<6;i++){const row=rows().nth(i);await row.locator('textarea').fill('Native question '+(i+1));await row.locator('input[type=text]').nth(0).fill('Answer A '+i);await row.locator('input[type=text]').nth(1).fill('Answer B '+i);await row.locator('input[type=radio]').nth(i%2).check();}
 const submit=async()=>{await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),form().locator('button[type=submit]').click()]);};
 await submit();const id=new URL(page.url()).searchParams.get('check');assert(Number(id)>0);assert.equal(await rows().count(),6);assert.equal(await form().locator('[name=expectedrevision]').inputValue(),'1');
 await form().locator('[name=title]').fill(title+' pending');await form().locator('[name=passpercent]').fill('101');
 await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),form().evaluate(f=>{f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));HTMLFormElement.prototype.submit.call(f);})]);
 await page.locator('[data-library-error]').waitFor();assert.equal(await form().locator('[name=title]').inputValue(),title+' pending');assert.equal(await rows().count(),6);assert.equal(await form().locator('[name=expectedrevision]').inputValue(),'1');assert(await rows().nth(5).locator('input[type=radio]').nth(1).isChecked());
 const fresh=await context.newPage();await fresh.goto(base+'/local/ustar/library_manage.php?check='+id);assert.equal(await fresh.locator('form:has(input[value=savecheck]) [name=title]').inputValue(),title,'Invalid POST must not change stored bank');await fresh.close();
 await form().locator('[name=passpercent]').fill('80');await submit();assert.equal(await form().locator('[name=expectedrevision]').inputValue(),'2');
 await rows().nth(5).locator('[data-library-question-action=up]').click();await rows().nth(0).locator('[data-library-question-action=remove]').click();await form().locator('[name=questioncount]').fill('5');await submit();
 assert.equal(await rows().count(),5);assert.equal(await form().locator('[name=expectedrevision]').inputValue(),'3');assert.equal(await rows().nth(3).locator('textarea').inputValue(),'Native question 6');assert(await rows().nth(3).locator('input[type=radio]').nth(1).isChecked());
 await page.reload();assert.equal(await form().locator('[name=title]').inputValue(),title+' pending');assert.equal(await rows().count(),5);
 await form().locator('[data-library-question-status]').filter({hasText:'из 100'}).waitFor();assert.deepEqual(errors,[]);await page.close();return {status:'PASS',bankid:Number(id),created_questions:6,invalid_post_preserved:true,invalid_post_zero_bank_writes:true,reorder_remove_correct_preserved:true,revision:3,fresh_reload:true};
};
