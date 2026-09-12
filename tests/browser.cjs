const { chromium } = require('playwright');
const { spawn, spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const root = path.resolve(__dirname, '..');
const php = process.env.PHP_BINARY || path.join(root, '.runtime/php/php.exe');
const suffix = crypto.randomBytes(5).toString('hex');
const driverResult = spawnSync(php, ['tests/database.php','driver'], {cwd:root,env:process.env,encoding:'utf8'});
if (driverResult.status !== 0) throw new Error(driverResult.stderr || 'Cannot read test configuration.');
const maria = process.env.TEST_MARIADB === '1' || (process.env.TEST_MARIADB !== '0' && driverResult.stdout.trim() === 'mysql');
const database = maria ? 'jjtest_' + suffix : path.join(root, 'storage', 'browser-test-' + suffix + '.sqlite');
const password = crypto.randomBytes(15).toString('hex');
const uploadRoot=path.join(root,'storage','browser-uploads-'+suffix);
const env = { ...process.env, UPLOAD_ROOT:uploadRoot, SMTP_CONFIG_DIR:path.join(root,'storage','browser-smtp-'+suffix), APP_ENV: 'dev', DB_CONNECTION: maria ? 'mysql' : 'sqlite', DB_DATABASE: database, DEV_ADMIN_PASSWORD: password };
let testDatabaseCreated = false;
let server, browser;
let serverError = '';
const base = 'http://127.0.0.1:8081';
function command(args) {
    const result = spawnSync(php, args, { cwd: root, env, encoding: 'utf8' });
    if (result.status !== 0) throw new Error(result.stderr || result.stdout);
}
(async () => {
    if (maria) {
        const result = spawnSync(php, ['tests/database.php', 'create', database], {cwd:root,env:process.env,encoding:'utf8'});
        if (result.status !== 0) throw new Error(result.stderr || result.stdout);
        testDatabaseCreated = true;
    }
    command(['scripts/console.php', 'migrate']);
    command(['scripts/console.php', 'seed-admin']);
    server = spawn(php, ['-S', '127.0.0.1:8081', '-t', 'public'], { cwd: root, env, windowsHide: true, stdio: ['ignore','ignore','pipe'] });
    server.stderr.on('data', chunk => { serverError += chunk.toString(); });
    for (let i=0; i<40; i++) {
        if (server.exitCode !== null) throw new Error('Test server could not start: ' + serverError);
        try { await fetch(base); break; } catch { await new Promise(r=>setTimeout(r,100)); }
    }
    browser = await chromium.launch({ channel: 'msedge', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1080 }, colorScheme: 'light' });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error' && !message.text().includes('status of 403') && !message.text().includes('status of 404')) errors.push(message.text()); });
    await page.goto(base);
    await page.getByLabel('Username', {exact:true}).fill('admin');
    await page.getByLabel('Password', {exact:true}).fill('wrong-password');
    await page.getByRole('button', {name:'Sign in',exact:true}).click();
    assert(await page.getByRole('alert').isVisible());
    await page.getByLabel('Password', {exact:true}).fill(password);
    await page.getByRole('button', {name:'Sign in',exact:true}).click();
    await page.getByRole('heading', {name:'Project dashboard'}).waitFor();
    assert(await page.getByRole('link', {name:'Users',exact:true}).isVisible());
    await page.screenshot({path:path.join(root,'storage/dashboard-desktop.png'),fullPage:true});
    const csv = await context.request.get(base+'/index.php?page=report-export');
    assert.equal(csv.status(),200);
    assert((await csv.text()).includes('Sample data'));
    await page.getByRole('button',{name:'Switch to dark mode'}).click();
    await page.reload();
    assert.equal(await page.locator('html').getAttribute('data-theme'),'dark');
    await page.screenshot({path:path.join(root,'storage/dashboard-dark.png'),fullPage:true});
    await page.setViewportSize({width:390,height:844});
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth));
    await page.screenshot({path:path.join(root,'storage/dashboard-mobile.png'),fullPage:true});
    await page.getByRole('button',{name:'Open navigation',exact:true}).click();
    await page.getByRole('link',{name:'Users',exact:true}).click();
    await page.getByRole('button',{name:'Open navigation',exact:true}).click();
    await page.keyboard.press('Escape');
    await page.setViewportSize({width:1440,height:1080});
    await page.getByRole('link',{name:'Create user',exact:true}).click();
    await page.getByLabel('Display name',{exact:true}).fill('Test Contractor');
    await page.getByLabel('Username',{exact:true}).fill('worker');
    await page.getByLabel('Password',{exact:true}).fill(password);
    await page.getByRole('button',{name:'Create user',exact:true}).click();
    await page.getByRole('status').waitFor();
    await page.getByLabel('Filter users').fill('not-a-user');
    assert(await page.getByText('No users match your search.').isVisible());
    await page.getByLabel('Filter users').fill('');
    await page.screenshot({path:path.join(root,'storage/users-desktop.png'),fullPage:true});
    const contractorContext = await browser.newContext();
    const contractor = await contractorContext.newPage();
    await contractor.goto(base);
    await contractor.getByLabel('Username',{exact:true}).fill('worker');
    await contractor.getByLabel('Password',{exact:true}).fill(password);
    await contractor.getByRole('button',{name:'Sign in',exact:true}).click();
    await contractor.getByRole('heading',{name:'Your workspace is ready'}).waitFor();
    assert.equal(await contractor.getByRole('link',{name:'Users',exact:true}).count(),0);
    for (const route of ['users','user-edit','report-export']) {
        const response = await contractorContext.request.get(base+'/index.php?page='+route);
        assert.equal(response.status(),403);
    }
    const forbiddenPost = await contractorContext.request.post(base+'/index.php?page=user-edit', {form:{username:'intruder'}});
    assert.equal(forbiddenPost.status(),403);
    await page.getByRole('link',{name:'Edit Test Contractor',exact:true}).click();
    await page.getByLabel('Role',{exact:true}).selectOption('pm');
    await page.getByRole('button',{name:'Save changes',exact:true}).click();
    await contractor.reload();
    await contractor.getByRole('heading',{name:'Welcome back'}).waitFor();
    await contractor.getByLabel('Username',{exact:true}).fill('worker');
    await contractor.getByLabel('Password',{exact:true}).fill(password);
    await contractor.getByRole('button',{name:'Sign in',exact:true}).click();
    await contractor.getByRole('heading',{name:'Project dashboard'}).waitFor();
    assert.equal((await contractorContext.request.get(base+'/index.php?page=users')).status(),403);
    assert.equal((await contractorContext.request.get(base+'/index.php?page=report-export')).status(),200);
    await page.getByRole('link',{name:'Edit Test Contractor',exact:true}).click();
    await page.getByLabel('Active account',{exact:false}).uncheck();
    await page.getByRole('button',{name:'Save changes',exact:true}).click();
    await contractor.reload();
    await contractor.getByRole('heading',{name:'Welcome back'}).waitFor();
    await contractor.getByLabel('Username',{exact:true}).fill('worker');
    await contractor.getByLabel('Password',{exact:true}).fill(password);
    await contractor.getByRole('button',{name:'Sign in',exact:true}).click();
    assert(await contractor.getByRole('alert').isVisible());
    const csrfResponse = await context.request.post(base+'/index.php?page=user-edit',{form:{username:'badcsrf'}});
    assert.equal(csrfResponse.status(),403);
    for (const route of ['/.env','/storage/dev.sqlite','/src/app.php','/.git/config']) {
        assert.equal((await context.request.get(base+route)).status(),404);
    }
    await require('./workflows-browser.cjs')({page,context,browser,base,password,root});
    await page.getByRole('button',{name:'Sign out',exact:true}).click();
    await page.getByRole('heading',{name:'Welcome back'}).waitFor();
    assert.equal(errors.length,0,errors.join('\n'));
    console.log('PASS: sign-in, admin CRUD, role guards, session revocation, deactivation, CSRF, report export, theme persistence, responsive layout, and private paths.');
})().catch(error=>{console.error(error);process.exitCode=1;}).finally(async()=>{
    if(browser) await browser.close();
    if(server) { server.kill(); await new Promise(r=>server.exitCode!==null?r():server.once('exit',r)); }
    if(fs.existsSync(uploadRoot)){
        for(const file of fs.readdirSync(uploadRoot)){
            if(!/^[a-f0-9]{32}\.(jpg|png|webp)$/.test(file))throw new Error('Unexpected test upload filename.');
            fs.unlinkSync(path.join(uploadRoot,file));
        }
        fs.rmdirSync(uploadRoot);
    }
    if (maria && testDatabaseCreated) {
        const result = spawnSync(php, ['tests/database.php','drop',database], {cwd:root,env:process.env,encoding:'utf8'});
        if (result.status !== 0) { console.error(result.stderr); process.exitCode=1; }
    } else if (!maria && fs.existsSync(database)) fs.unlinkSync(database);
});
