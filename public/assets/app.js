(() => {
    const root = document.documentElement;
    const system = window.matchMedia('(prefers-color-scheme: dark)');
    let preference;
    try { preference = localStorage.getItem('jj-theme'); } catch {}
    const apply = (theme) => {
        root.dataset.theme = theme;
        document.querySelectorAll('.theme-toggle').forEach(button => {
            button.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
            button.title = button.getAttribute('aria-label');
        });
    };
    apply(preference === 'dark' || preference === 'light' ? preference : system.matches ? 'dark' : 'light');
    system.addEventListener('change', event => { if (!preference) apply(event.matches ? 'dark' : 'light'); });
    document.querySelectorAll('.theme-toggle').forEach(button => button.addEventListener('click', () => {
        preference = root.dataset.theme === 'dark' ? 'light' : 'dark';
        apply(preference);
        try { localStorage.setItem('jj-theme', preference); } catch {}
    }));
    const menu = document.querySelector('.menu-toggle');
    const close = () => { document.body.classList.remove('nav-open'); menu?.setAttribute('aria-expanded', 'false'); };
    menu?.addEventListener('click', () => {
        const open = document.body.classList.toggle('nav-open');
        menu.setAttribute('aria-expanded', String(open));
    });
    document.querySelector('.nav-overlay')?.addEventListener('click', close);
    document.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
    document.querySelector('#user-filter')?.addEventListener('input', event => {
        const query = event.target.value.trim().toLowerCase();
        let count = 0;
        document.querySelectorAll('[data-user-row]').forEach(row => {
            row.hidden = !row.textContent.toLowerCase().includes(query);
            if (!row.hidden) count++;
        });
        document.querySelector('#no-users').hidden = count > 0;
    });
})();

(() => {
    const company=document.querySelector('#store-company');
    const filter=()=>{
        if(!company)return;
        let visible=0;
        document.querySelectorAll('.contractor-option').forEach(option=>{
            const show=!!company.value&&option.dataset.company===company.value;
            option.hidden=!show;
            const input=option.querySelector('input');
            input.disabled=!show;
            if(!show)input.checked=false;
            if(show)visible++;
        });
        const hint=document.querySelector('.contractor-hint');
        if(hint){hint.hidden=visible>0;hint.textContent=company.value?'No active contractors in this company.':'Select a company to show its available contractors.';}
    };
    company?.addEventListener('change',filter);filter();
    document.querySelectorAll('.sortable').forEach(list=>{
        let dragged;
        list.addEventListener('dragstart',event=>{
            dragged=event.target.closest('[data-order-item]');
            if(!dragged)return;
            dragged.classList.add('dragging');
            event.dataTransfer.effectAllowed='move';
            event.dataTransfer.setData('text/plain','reorder');
        });
        list.addEventListener('dragover',event=>{
            if(!dragged||dragged.parentElement!==list)return;
            event.preventDefault();
            const target=event.target.closest('[data-order-item]');
            if(target&&target!==dragged){
                const box=target.getBoundingClientRect();
                list.insertBefore(dragged,event.clientY<box.top+box.height/2?target:target.nextSibling);
            }
        });
        list.addEventListener('drop',event=>event.preventDefault());
        list.addEventListener('dragend',()=>{dragged?.classList.remove('dragging');dragged=null;});
        list.addEventListener('click',event=>{
            const button=event.target.closest('.order-up,.order-down');
            if(!button)return;
            const item=button.closest('[data-order-item]');
            if(button.classList.contains('order-up')&&item.previousElementSibling)list.insertBefore(item,item.previousElementSibling);
            if(button.classList.contains('order-down')&&item.nextElementSibling)list.insertBefore(item.nextElementSibling,item);
            button.focus();
        });
    });
    document.querySelector('.print-report')?.addEventListener('click',()=>window.print());
})();

document.querySelectorAll('.file-picker-input').forEach(input=>input.addEventListener('change',()=>{
    const text=input.closest('.photo-picker').querySelector('.selected-files');
    const pending=input.files.length>0;
    input.form.querySelector('.photo-save-reminder').hidden=!pending;
    input.form.classList.toggle('photos-pending',pending);
    text.textContent=input.files.length?Array.from(input.files).map(file=>file.name).join(', '):'No photos selected';
}));

(() => {
    document.querySelectorAll('[data-email-submit]').forEach(form=>form.addEventListener('submit',()=>{
        const button=form.querySelector('button');
        button.disabled=true;button.textContent='Sending…';
    }));
    const dialog=document.querySelector('.photo-lightbox');
    const links=Array.from(document.querySelectorAll('[data-photo-lightbox]'));
    if(!dialog||!links.length)return;
    const image=dialog.querySelector('.lightbox-image');
    const previous=dialog.querySelector('.lightbox-prev');
    const next=dialog.querySelector('.lightbox-next');
    const error=dialog.querySelector('.lightbox-error');
    let index=0,opener=null;
    const show=position=>{
        index=(position+links.length)%links.length;
        const link=links[index],name=link.querySelector('img')?.alt||'Store photo';
        error.hidden=true;
        image.alt=name;image.src=link.href;
        dialog.querySelector('.lightbox-filename').textContent=name;
        dialog.querySelector('.lightbox-count').textContent=(index+1)+' of '+links.length;
        previous.disabled=next.disabled=links.length<2;
    };
    links.forEach((link,position)=>link.addEventListener('click',event=>{
        event.preventDefault();opener=link;show(position);
        dialog.showModal();document.body.classList.add('lightbox-open');
    }));
    dialog.querySelector('.lightbox-close').addEventListener('click',()=>dialog.close());
    previous.addEventListener('click',()=>show(index-1));
    next.addEventListener('click',()=>show(index+1));
    dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close();});
    dialog.addEventListener('keydown',event=>{
        if(event.key==='ArrowLeft'){event.preventDefault();show(index-1);}
        if(event.key==='ArrowRight'){event.preventDefault();show(index+1);}
    });
    dialog.addEventListener('close',()=>{document.body.classList.remove('lightbox-open');image.removeAttribute('src');opener?.focus();});
    image.addEventListener('error',()=>{if(dialog.open)error.hidden=false;});
    let touchX=null;
    image.addEventListener('touchstart',event=>{touchX=event.touches.length===1?event.touches[0].clientX:null;},{passive:true});
    image.addEventListener('touchend',event=>{
        if(touchX===null)return;
        const delta=event.changedTouches[0].clientX-touchX;
        if(Math.abs(delta)>60)show(index+(delta<0?1:-1));
        touchX=null;
    },{passive:true});
})();

(() => {
    const search=document.querySelector('.store-search-form');
    if(!search)return;
    const input=search.querySelector('[name=q]'),preference=document.querySelector('.store-preference-form');
    const checkbox=preference.querySelector('[name=show_all]'),results=document.querySelector('#store-results');
    const feedback=document.querySelector('.stores-feedback');
    const setFeedback=(message,isError=false)=>{
        feedback.classList.toggle('sr-only',!isError);
        feedback.textContent=message;
    };
    let timer,controller,revision=0;
    const refresh=async()=>{
        clearTimeout(timer);
        const current=++revision;
        controller?.abort();controller=new AbortController();
        const url=new URL(search.action,location.href);
        url.search=new URLSearchParams({page:'stores',q:input.value,fragment:'1'});
        results.setAttribute('aria-busy','true');
        setFeedback('Searching…');
        try{
            const response=await fetch(url,{signal:controller.signal});
            const html=await response.text();
            if(!response.ok||response.redirected||!html.includes('table-scroll'))throw new Error('Search failed');
            if(current!==revision)return;
            results.innerHTML=html;
            setFeedback(results.querySelector('h2').textContent+' shown.');
            url.searchParams.delete('fragment');history.replaceState(null,'',url);
        }catch(error){
            if(error.name!=='AbortError'&&current===revision)setFeedback('Could not update stores. Refresh the page and try again.',true);
        }finally{if(current===revision)results.removeAttribute('aria-busy');}
    };
    input.addEventListener('input',()=>{
        clearTimeout(timer);++revision;controller?.abort();
        if(!input.matches(':invalid'))timer=setTimeout(refresh,750);
    });
    search.addEventListener('submit',event=>{event.preventDefault();refresh();});
    checkbox.addEventListener('change',async()=>{
        const selected=checkbox.checked;
        checkbox.disabled=true;
        const data=new FormData(preference);
        // Disabled controls are excluded from FormData.
        if(selected)data.set('show_all','1');else data.delete('show_all');
        setFeedback('Saving preference…');
        try{
            const url=new URL(preference.getAttribute('action'),location.href);url.searchParams.set('fragment','1');
            const response=await fetch(url,{method:'POST',body:data});
            if(!response.ok||!(await response.json()).saved)throw new Error('Save failed');
            await refresh();
        }catch{
            checkbox.checked=!selected;
            setFeedback('Could not save your preference. Please try again.',true);
        }finally{checkbox.disabled=false;}
    });
})();

document.querySelectorAll('.pm-note').forEach(note=>{
    const form=note.querySelector('.pm-note-report-form'),checkbox=form.querySelector('[type=checkbox]');
    const deletion=note.querySelector('.pm-note-delete-form'),button=deletion.querySelector('button');
    const feedback=note.querySelector('.pm-note-feedback');
    button.disabled=false;
    const send=async(data,source)=>{
        const url=new URL(source.getAttribute('action'),location.href);url.searchParams.set('note_ajax','1');
        const response=await fetch(url,{method:'POST',body:data});
        if(!response.ok||response.redirected||!(await response.json()).saved)throw new Error('Note action failed');
    };
    form.addEventListener('submit',event=>event.preventDefault());
    checkbox.addEventListener('change',async()=>{
        const selected=checkbox.checked,data=new FormData(form);
        checkbox.disabled=button.disabled=true;feedback.textContent='Saving…';
        try{await send(data,form);feedback.textContent='Saved';}
        catch{checkbox.checked=!selected;feedback.textContent='Could not save. Please try again or reload the page.';}
        finally{checkbox.disabled=button.disabled=false;}
    });
    deletion.addEventListener('submit',async event=>{
        event.preventDefault();
        if(!window.confirm('Delete this Project Manager note permanently? This cannot be undone.'))return;
        const data=new FormData(deletion);data.set('confirmed','1');
        checkbox.disabled=button.disabled=true;feedback.textContent='Deleting…';
        try{
            await send(data,deletion);note.remove();
            if(!document.querySelector('.pm-note')){
                const empty=document.createElement('p');empty.className='field-help';empty.textContent='No Project Manager notes yet.';
                document.querySelector('.pm-notes-panel .step-content').append(empty);
            }
        }catch{feedback.textContent='Could not delete. Please reload the page and try again.';checkbox.disabled=button.disabled=false;}
    });
});

document.querySelector('.activity-page-size select')?.addEventListener('change',event=>event.target.form.requestSubmit());

document.querySelectorAll('.unifi-order-form').forEach(form=>{
    const select=form.querySelector('select'),feedback=form.querySelector('.unifi-feedback');
    let saved=select.value;
    select.addEventListener('change',async()=>{
        const data=new FormData(form);select.disabled=true;feedback.textContent='Saving…';
        try{
            const url=new URL(form.getAttribute('action'),location.href);url.searchParams.set('order_ajax','1');
            const response=await fetch(url,{method:'POST',body:data});
            if(!response.ok||response.redirected||!(await response.json()).saved)throw new Error('Save failed');
            saved=select.value;feedback.textContent='Saved';
        }catch{select.value=saved;feedback.textContent='Could not save. Please reload and try again.';}
        finally{select.disabled=false;}
    });
});

document.querySelectorAll('.step-form input[name=complete]').forEach(checkbox=>{
    const form=checkbox.form,card=form.closest('.step-card'),feedback=form.querySelector('.completion-feedback');
    let busy=false;
    form.addEventListener('submit',event=>{if(busy)event.preventDefault();});
    checkbox.addEventListener('change',async()=>{
        const selected=checkbox.checked,data=new FormData();
        for(const name of ['csrf','version'])data.set(name,form.querySelector('[name='+name+']').value);
        data.set('action','completion');if(selected)data.set('complete','1');
        busy=true;checkbox.disabled=true;
        const buttons=Array.from(card.querySelectorAll('button'));buttons.forEach(button=>button.disabled=true);
        feedback.textContent='Saving completion…';
        try{
            const response=await fetch(form.getAttribute('action'),{method:'POST',body:data});
            const result=await response.json();
            if(!response.ok||!result.saved)throw new Error(result.error||'Could not save completion.');
            card.querySelectorAll('[name=version]').forEach(input=>input.value=result.version);
            checkbox.checked=result.complete;
            const badge=card.querySelector('.badge');badge.textContent=result.status;badge.className='badge '+(result.complete?'warning':'neutral');
            const details=card.querySelector('.completion-details');details.hidden=!result.complete;
            details.textContent=result.complete?'Completed by '+result.completed_name+' · '+result.completed_at:'';
            const signoff=card.querySelector('.signoff-form');if(signoff)signoff.hidden=!result.complete;
            feedback.textContent='Completion saved';
        }catch(error){checkbox.checked=!selected;feedback.textContent=(error instanceof SyntaxError?'Could not save completion. Refresh the page and try again.':error.message);}
        finally{busy=false;checkbox.disabled=false;buttons.forEach(button=>button.disabled=false);}
    });
});

document.querySelectorAll('.photo-delete-form').forEach(form=>{
    const button=form.querySelector('button');button.disabled=false;
    form.addEventListener('submit',event=>{
        if(!window.confirm('Delete this photo permanently? This cannot be undone.')){event.preventDefault();return;}
        const confirmed=document.createElement('input');confirmed.type='hidden';confirmed.name='confirmed';confirmed.value='1';form.append(confirmed);
        button.disabled=true;button.textContent='Deleting…';
    });
});
