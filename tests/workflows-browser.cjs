const assert=require('node:assert/strict');
const path=require('node:path');
module.exports=async({page,context,browser,base,password,root})=>{
    const route=(name,id)=>base+'/index.php?page='+name+(id?'&id='+encodeURIComponent(id):'');
    const token=async(ctx)=>{const response=await ctx.request.get(route('dashboard'));const body=await response.text();return body.match(/name="csrf" value="([^"]+)"/)[1];};
    const post=async(ctx,name,data,id)=>ctx.request.post(route(name,id),{form:{csrf:await token(ctx),...data}});
    const login=async(username)=>{
        const ctx=await browser.newContext({viewport:{width:1440,height:1000}});
        const tab=await ctx.newPage();await tab.goto(base);
        await tab.getByLabel('Username',{exact:true}).fill(username);
        await tab.getByLabel('Password',{exact:true}).fill(password);
        await tab.getByRole('button',{name:'Sign in',exact:true}).click();
        return {ctx,tab};
    };
    for(const name of ['Alpha Installations','Beta Installations']){
        await page.goto(route('company-edit'));
        await page.getByLabel('Company name',{exact:true}).fill(name);
        await page.getByRole('button',{name:'Save company',exact:true}).click();
        await page.getByRole('status').waitFor();
    }
    await page.goto(route('store-edit'));
    const alpha=await page.getByLabel('Contracting company',{exact:true}).locator('option').filter({hasText:'Alpha Installations'}).getAttribute('value');
    const beta=await page.getByLabel('Contracting company',{exact:true}).locator('option').filter({hasText:'Beta Installations'}).getAttribute('value');
    for(const [username,name,role,company] of [
        ['installer2','Alex Installer','contractor',alpha],['lead2','Company Lead','contractor_admin',alpha],
        ['other2','Other Company','contractor',beta],['manager2','Project Manager','pm','']]){
        const response=await post(context,'user-edit',{username,display_name:name,role,company_id:company,password,active:'1'});
        assert((await response.text()).includes('User created.'));
    }
    await page.goto(route('template-edit'));
    await page.getByLabel('Subtask title',{exact:true}).fill('Photograph the completed rack');
    await page.getByLabel('Workstream',{exact:true}).selectOption('rack');
    await page.getByLabel('Instructions',{exact:true}).fill('Capture the cabinet front and cable routing.');
    await page.getByRole('button',{name:'Save template',exact:true}).click();
    await page.getByRole('heading',{name:'Task templates',exact:true}).waitFor();
    const interfaceList=page.getByRole('list',{name:'Interface order',exact:true});
    const reportList=page.getByRole('list',{name:'Report order',exact:true});
    const interfaceFirst=await interfaceList.locator('strong').first().textContent();
    // Exercise drag-and-drop, then save its independent order.
    await reportList.locator('li').first().dragTo(reportList.locator('li').nth(2));
    let reportFirst=await reportList.locator('strong').first().textContent();
    if(reportFirst===interfaceFirst){
        await reportList.locator('.order-down').first().click();
        reportFirst=await reportList.locator('strong').first().textContent();
    }
    assert.notEqual(reportFirst,interfaceFirst);
    await page.getByRole('button',{name:'Save report order',exact:true}).click();
    assert.equal(await page.getByRole('list',{name:'Interface order',exact:true}).locator('strong').first().textContent(),interfaceFirst);
    await page.screenshot({path:path.join(root,'storage/templates-workflow.png'),fullPage:true});
    await page.goto(route('store-edit'));
    await page.getByLabel('Store code',{exact:true}).fill('ST-101');
    await page.getByLabel('Store name',{exact:true}).fill('Harbour Point');
    await page.getByLabel('City',{exact:true}).fill('Copenhagen');
    await page.getByLabel('Installation date',{exact:true}).fill('2026-10-20');
    await page.getByLabel('Contracting company',{exact:true}).selectOption(alpha);
    await page.getByLabel('Alex Installer',{exact:true}).check();
    assert.equal(await page.getByLabel('Other Company',{exact:true}).isVisible(),false);
    await page.getByLabel('Owner name',{exact:true}).fill('Store Owner');
    await page.getByLabel('Owner phone',{exact:true}).fill('+45 12345678');
    await page.getByLabel('Owner email',{exact:true}).fill('owner@example.test');
    await page.getByLabel('Contact name',{exact:true}).fill('Store Contact');
    await page.getByLabel('Contact email',{exact:true}).fill('contact@example.test');
    await page.getByLabel('Reminder date override',{exact:true}).fill('2026-10-15');
    await page.getByRole('button',{name:'Save store',exact:true}).click();
    await page.getByRole('heading',{name:'Harbour Point',exact:true}).waitFor();
    const storeId=new URL(page.url()).searchParams.get('id');
    assert.equal(await page.locator('.step-card').count(),5);
    assert((await page.textContent('body')).includes('2026-10-15'));
    const worker=await login('installer2'),lead=await login('lead2'),outsider=await login('other2'),pm=await login('manager2');
    await worker.tab.goto(route('store',storeId));
    await worker.tab.getByRole('heading',{name:'Harbour Point',exact:true}).waitFor();
    await lead.tab.goto(route('stores'));assert(await lead.tab.getByRole('link',{name:'Harbour Point',exact:true}).isVisible());
    await lead.tab.goto(route('team'));assert((await lead.tab.textContent('body')).includes('Alex Installer'));assert(!(await lead.tab.textContent('body')).includes('Other Company'));
    assert.equal((await outsider.ctx.request.get(route('store',storeId))).status(),403);
    assert.equal((await lead.ctx.request.get(route('templates'))).status(),403);
    assert.equal((await pm.ctx.request.get(route('smtp'))).status(),403);
    assert.equal((await context.request.get(route('smtp'))).status(),200);
    const card=worker.tab.locator('.step-card').first();
    const stepId=(await card.getAttribute('id')).slice(5);
    await card.locator('textarea[name=note]').fill('Equipment replaced, cabling labeled, and connectivity verified.');
    await card.locator('input[name=complete]').check();
    await card.locator('.completion-feedback').filter({hasText:'Completion saved'}).waitFor();
    await worker.tab.reload();
    assert(await card.locator('input[name=complete]').isChecked());
    await card.locator('textarea').fill('Draft text stays here');
    await card.locator('input[name=complete]').uncheck();
    await card.locator('.completion-feedback').filter({hasText:'Completion saved'}).waitFor();
    assert.equal(await card.locator('textarea').inputValue(),'Draft text stays here');
    await worker.tab.reload();
    assert.equal(await card.locator('input[name=complete]').isChecked(),false);
    await card.locator('input[name=complete]').check();
    await card.locator('.completion-feedback').filter({hasText:'Completion saved'}).waitFor();

    const photo=Buffer.from(await worker.tab.evaluate(()=>{
        const c=document.createElement('canvas');c.width=640;c.height=400;
        const x=c.getContext('2d');x.fillStyle='#e5ecec';x.fillRect(0,0,640,400);
        x.fillStyle='#29464d';x.fillRect(210,40,220,320);
        for(let y=65;y<330;y+=48){x.fillStyle='#49636b';x.fillRect(230,y,180,30);x.fillStyle='#52c3a0';x.fillRect(385,y+9,8,8);}
        x.fillStyle='#19343d';x.font='18px sans-serif';x.fillText('Synthetic installation test photo',175,385);
        return c.toDataURL('image/png').split(',')[1];
    }), 'base64');
    await card.locator('input[type=file]').setInputFiles([{name:'rack-documentation.png',mimeType:'image/png',buffer:photo},{name:'rack-side.png',mimeType:'image/png',buffer:photo}]);
    await card.getByRole('button',{name:'Save step',exact:true}).click();
    await worker.tab.getByRole('status').waitFor();
    assert.equal(await worker.tab.locator('.photo-grid img').count(),2);
    const photoUrl=await worker.tab.locator('.photo-grid img').first().getAttribute('src');
    assert.equal((await outsider.ctx.request.get(base+photoUrl)).status(),403);
    assert.equal((await worker.ctx.request.get(base+photoUrl)).headers()['content-type'],'image/png');

    const pageCount=worker.ctx.pages().length;
    await worker.tab.locator('[data-photo-lightbox]').first().click();
    await worker.tab.getByRole('dialog',{name:'Photo preview'}).waitFor();
    assert.equal(worker.ctx.pages().length,pageCount);
    assert(await worker.tab.locator('.lightbox-image').evaluate(img=>img.complete&&img.naturalWidth>0));
    await worker.tab.keyboard.press('ArrowRight');
    assert.equal(await worker.tab.locator('.lightbox-count').textContent(),'2 of 2');
    await worker.tab.getByRole('button',{name:'Previous photo',exact:true}).click();
    assert.equal(await worker.tab.locator('.lightbox-count').textContent(),'1 of 2');
    await worker.tab.setViewportSize({width:390,height:844});
    await worker.tab.screenshot({path:path.join(root,'storage/photo-lightbox-mobile.png')});
    await worker.tab.setViewportSize({width:1440,height:1000});
    await worker.tab.keyboard.press('Escape');
    assert.equal(await worker.tab.getByRole('dialog',{name:'Photo preview'}).isVisible(),false);
    assert.equal(await worker.tab.locator('[data-photo-lightbox]').first().evaluate(link=>document.activeElement===link),true);
    await worker.tab.locator('[data-photo-lightbox]').first().click();
    await worker.tab.getByRole('button',{name:'Close',exact:true}).click();
    assert.equal(await worker.tab.getByRole('button',{name:'Send reminder now',exact:true}).count(),0);
    const manualDenied=await post(lead.ctx,'store',{action:'send-reminder',request_id:'a'.repeat(32)},storeId);
    assert.equal(manualDenied.status(),403);
    // SMTP storage is isolated and empty in this test, so neither action can send mail.
    await page.goto(route('smtp'));
    await page.getByLabel('Test recipient',{exact:true}).fill('test@example.test');
    await page.getByRole('button',{name:'Test SMTP',exact:true}).click();
    assert((await page.getByRole('alert').textContent()).includes('Save the SMTP'));
    await page.goto(route('store',storeId));
    await page.getByRole('button',{name:'Send reminder now',exact:true}).click();
    assert((await page.getByRole('alert').textContent()).includes('Save the SMTP'));
    const version=await worker.tab.locator('#step-'+stepId+' input[name=version]').first().getAttribute('value');
    assert.equal((await post(lead.ctx,'step-update',{action:'signoff',version},stepId)).status(),403);
    assert.equal((await post(outsider.ctx,'step-update',{action:'save',version,note:'forbidden'},stepId)).status(),403);
    await pm.tab.goto(route('store',storeId));
    await pm.tab.locator('#step-'+stepId).getByRole('button',{name:'Sign off step',exact:true}).click();
    await pm.tab.getByRole('status').waitFor();
    assert((await pm.tab.locator('#step-'+stepId).textContent()).includes('Signed off by Project Manager'));
    await worker.tab.reload();
    assert.equal(await worker.tab.locator('#step-'+stepId+' textarea').count(),0);
    await page.goto(route('store',storeId));
    await page.screenshot({path:path.join(root,'storage/store-workflow-desktop.png'),fullPage:true});
    await page.setViewportSize({width:390,height:844});
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth));
    await page.screenshot({path:path.join(root,'storage/store-workflow-mobile.png'),fullPage:true});
    await page.goto(route('store-report',storeId));
    assert.equal(await page.locator('.report-step h2').first().textContent(),reportFirst);
    assert((await page.textContent('body')).includes('Project Manager'));
    await page.setViewportSize({width:1100,height:1000});
    await page.screenshot({path:path.join(root,'storage/store-report-preview.png'),fullPage:true});
    await page.goto(route('store',storeId));
    await page.locator('#step-'+stepId).getByRole('button',{name:'Reopen step',exact:true}).click();
    await page.getByRole('status').waitFor();
    await worker.tab.reload();
    const upload=worker.tab.locator('#step-'+stepId+' input[type=file]');
    await upload.setInputFiles({name:'fake.jpg',mimeType:'image/jpeg',buffer:Buffer.from('<?php echo "not a photo"; ?>')});
    await worker.tab.locator('#step-'+stepId).getByRole('button',{name:'Save step',exact:true}).click();
    assert((await worker.tab.textContent('body')).includes('valid JPEG, PNG, or WebP'));


    await pm.tab.goto(route('store',storeId));
    const notes=pm.tab.locator('.pm-notes-panel');
    assert(await notes.locator('form').first().getByLabel('Include in PDF report').isChecked());
    await notes.getByLabel('New Project Manager note').fill('PM stakeholder note <safe>');
    await notes.getByRole('button',{name:'Add note',exact:true}).click();
    await pm.tab.locator('.notice.success').waitFor();
    const saved=pm.tab.locator('.pm-note').first();
    assert((await saved.textContent()).includes('Project Manager'));
    assert(await saved.locator('time').getAttribute('datetime'));
    await pm.tab.goto(route('store-report',storeId));
    assert((await pm.tab.locator('.report-pm-notes').textContent()).includes('PM stakeholder note <safe>'));
    await pm.tab.goto(route('store',storeId));
    await pm.tab.locator('.pm-note').first().getByLabel('Include in PDF report').uncheck();
    await pm.tab.locator('.pm-note-feedback').filter({hasText:'Saved'}).waitFor();
    assert((await pm.tab.locator('.pm-note').textContent()).includes('PM stakeholder note <safe>'));
    await pm.tab.goto(route('store-report',storeId));
    assert(!(await pm.tab.textContent('body')).includes('PM stakeholder note'));
    await worker.tab.goto(route('store',storeId));
    assert.equal(await worker.tab.locator('.pm-notes-panel').count(),0);
    assert.equal((await post(worker.ctx,'store',{action:'add-pm-note',pm_note:'Forbidden'},storeId)).status(),403);

    await pm.tab.goto(route('store',storeId));
    const deleteId=await pm.tab.locator('.pm-note-delete-form [name=note_id]').inputValue();
    assert.equal((await post(worker.ctx,'store',{action:'delete-pm-note',note_id:deleteId,confirmed:'1'},storeId)).status(),403);
    pm.tab.once('dialog',dialog=>dialog.dismiss());
    await pm.tab.getByRole('button',{name:'Delete note',exact:true}).click();
    assert.equal(await pm.tab.locator('.pm-note').count(),1);
    pm.tab.once('dialog',dialog=>dialog.accept());
    await pm.tab.getByRole('button',{name:'Delete note',exact:true}).click();
    await pm.tab.locator('.pm-note').waitFor({state:'detached'});
    await pm.tab.reload();
    assert.equal(await pm.tab.locator('.pm-note').count(),0);
    const eventually=async(check)=>{
        const deadline=Date.now()+6000;
        while(Date.now()<deadline){if(await check())return;await new Promise(resolve=>setTimeout(resolve,100));}
        assert(await check(),'Store UI did not reach the expected state');
    };
    const expect=locator=>({
        toHaveCount:n=>eventually(async()=>await locator.count()===n),
        toContainText:text=>eventually(async()=>(await locator.textContent())?.includes(text)),
        toHaveText:text=>eventually(async()=>(await locator.textContent())===text),
        toBeVisible:()=>eventually(()=>locator.isVisible()),
        toBeChecked:()=>eventually(()=>locator.isChecked()),
        not:{toBeChecked:()=>eventually(async()=>!await locator.isChecked())}
    });
    const today=new Intl.DateTimeFormat('en-CA',{timeZone:'Europe/Copenhagen',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
    const past='2020-01-01';
    for(const [code,name,date] of [['F-TODAY','Filter Today',today],['F-PAST','Filter Overdue',past],['F-LATER','Filter Upcoming','2099-01-01'],['F-NONE','Filter Unscheduled','']]){
        const response=await post(context,'store-edit',{code,name,city:'Test City',owner_name:'Owner',target_date:date});
        assert(response.ok());
    }
    await page.goto(route('stores'));
    const search=page.getByRole('searchbox',{name:'Search stores'});
    await search.fill('Filter');
    await expect(page.locator('#store-results tbody tr')).toHaveCount(4);
    assert.deepEqual(await page.locator('#store-results .store-name').allTextContents(),['Filter Overdue','Filter Today','Filter Upcoming','Filter Unscheduled']);
    await expect(page.locator('.store-today')).toContainText('Due today');
    await expect(page.locator('.store-overdue')).toContainText('Overdue');
    await search.fill('f-today');
    await expect(page.locator('#store-results tbody tr')).toHaveCount(1);
    await expect(page.locator('#store-results')).toContainText('Filter Today');
    await search.fill('no matching store');
    await expect(page.getByRole('heading',{name:'No matching stores'})).toBeVisible();
    await search.fill('Filter');
    await expect(page.locator('#store-results tbody tr')).toHaveCount(4);
    await page.getByLabel('Show all stores',{exact:true}).check();
    await expect(page.locator('.stores-feedback')).toHaveText('4 stores shown.');
    await page.reload();
    await expect(page.getByLabel('Show all stores',{exact:true})).toBeChecked();
    const another=await login('admin');
    await another.tab.goto(route('stores'));
    await expect(another.tab.getByLabel('Show all stores',{exact:true})).toBeChecked();
    await another.tab.getByLabel('Show all stores',{exact:true}).uncheck();
    await expect(another.tab.locator('.stores-feedback')).toContainText('stores shown.');
    await page.reload();
    await expect(page.getByLabel('Show all stores',{exact:true})).not.toBeChecked();
    await another.ctx.close();
    await worker.tab.goto(route('stores')+'&q=Filter');
    await expect(worker.tab.locator('#store-results tbody tr')).toHaveCount(0);
    assert.equal((await context.request.post(route('stores')+'&fragment=1',{form:{action:'store-preference',show_all:'1'}})).status(),403);
    await page.setViewportSize({width:1440,height:1080});
    await page.screenshot({path:path.join(root,'storage/stores-filter-desktop.png'),fullPage:true});
    await page.setViewportSize({width:390,height:844});
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    await page.screenshot({path:path.join(root,'storage/stores-filter-mobile.png'),fullPage:true});


    await page.goto(route('dashboard'));
    assert(!(await page.textContent('body')).includes('Sample data'));
    assert((await page.locator('.attention-list').textContent()).includes('Filter Unscheduled'));
    assert((await page.locator('.attention-list').textContent()).includes('Filter Overdue'));
    for(const href of await page.locator('.attention-list a').evaluateAll(links=>links.map(a=>a.href)))assert(href.includes('page=store-edit'));
    assert((await page.locator('.visits').textContent()).includes('Filter Today'));
    await page.goto(route('store',storeId));
    assert(await page.evaluate(()=>Boolean(document.querySelector('.step-list').compareDocumentPosition(document.querySelector('.pm-notes-panel')) & Node.DOCUMENT_POSITION_FOLLOWING)));
    const popupEvent=page.waitForEvent('popup');
    await page.getByRole('link',{name:'Report preview',exact:true}).click();
    const preview=await popupEvent;await preview.waitForLoadState();
    assert(preview.url().includes('page=store-report'));await preview.close();

    await page.goto(route('dashboard'));
    for(let i=0;i<3;i++)await post(context,'store-edit',{code:'ACT-'+i,name:'Activity fixture '+i,city:'Test City',owner_name:'Owner'});
    assert.equal(await page.locator('.activity-list .activity').count(),5);
    await page.getByRole('link',{name:'Recent activity',exact:false}).click();
    await page.getByRole('heading',{name:'Recent activity',exact:true}).waitFor();
    assert.equal(await page.locator('.activity-list .activity').count(),10);
    await page.getByRole('link',{name:'Next',exact:true}).click();
    assert(page.url().includes('p=2'));
    await page.getByLabel('Activities per page').selectOption('50');
    await page.waitForURL('**per_page=50');
    assert((await page.locator('.activity-pagination').textContent()).includes('Page 1'));
    await page.getByLabel('Activities per page').selectOption('100');
    await page.waitForURL('**per_page=100');
    assert.equal((await worker.ctx.request.get(route('activity'))).status(),403);

    await pm.tab.goto(route('store',storeId));
    await pm.tab.getByLabel('UniFi order',{exact:true}).selectOption('ordered');
    await pm.tab.locator('.unifi-feedback').filter({hasText:'Saved'}).waitFor();
    await pm.tab.reload();
    assert.equal(await pm.tab.getByLabel('UniFi order',{exact:true}).inputValue(),'ordered');
    assert.equal((await post(worker.ctx,'store',{action:'unifi-order',unifi_order:'delivered'},storeId)).status(),403);
    await worker.tab.goto(route('store',storeId));
    assert.equal(await worker.tab.locator('.unifi-order-form').count(),0);
    await worker.tab.getByRole('heading',{name:'Prerequisites',exact:true}).waitFor();
    assert.equal(await worker.tab.locator('.prerequisite-dot.good-to-go').count(),1);
    await pm.tab.goto(route('store-edit',storeId));
    await pm.tab.getByLabel('Installation date',{exact:true}).fill(today);
    await pm.tab.getByLabel('Reminder date override',{exact:true}).fill('');
    await pm.tab.getByRole('button',{name:'Save store',exact:true}).click();
    await worker.tab.reload();
    assert.equal(await worker.tab.locator('.prerequisite-dot.needs-attention').count(),1);
    assert((await worker.tab.locator('.prerequisites-panel').textContent()).includes('Needs attention'));
    await pm.tab.goto(route('store',storeId));
    await pm.tab.getByLabel('UniFi order',{exact:true}).selectOption('delivered');
    await pm.tab.locator('.unifi-feedback').filter({hasText:'Saved'}).waitFor();
    await worker.tab.reload();
    assert.equal(await worker.tab.locator('.prerequisite-dot.good-to-go').count(),1);
    assert(await pm.tab.evaluate(()=>{
        const steps=document.querySelector('.step-list').getBoundingClientRect();
        const notes=document.querySelector('.pm-notes-panel').getBoundingClientRect();
        return notes.top-steps.bottom>=23;
    }));



    await pm.tab.goto(route('store-edit',storeId));
    await pm.tab.getByLabel('Installation date',{exact:true}).fill('');
    await pm.tab.getByRole('button',{name:'Save store',exact:true}).click();
    for(const account of [worker,lead]){
        assert.equal((await account.ctx.request.get(route('store',storeId))).status(),403);
        assert.equal((await account.ctx.request.get(route('store-report',storeId))).status(),403);
        assert.equal((await account.ctx.request.get(base+photoUrl)).status(),403);
        const listing=await account.ctx.request.get(route('stores')+'&q=ST-101');
        assert(!(await listing.text()).includes('Harbour Point'));
    }
    assert.equal((await context.request.get(route('store',storeId))).status(),200);
    await page.goto(route('users'));
    for(const account of [worker,lead,outsider,pm])await account.ctx.close();
    console.log('PASS: workflow UI, company isolation, ordering, store creation, photo validation/authorization, PM sign-off, and responsive report preview.');
};
