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
