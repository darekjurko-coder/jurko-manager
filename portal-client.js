'use strict';
window.JurkoAPI = (() => {
  const initial = window.JurkoInitial;
  if (!initial || !initial.csrf) throw new Error('Brak bezpiecznej sesji.');
  let busy = false;
  const pendingWrites = new Map();
  async function request(url, options={}) {
    const response = await fetch(url, {credentials:'same-origin',cache:'no-store',...options});
    const data = await response.json().catch(()=>({error:'Serwer nie potwierdził operacji. Odśwież stronę.'}));
    if (response.status===401) { location.assign('/auth/'); throw new Error('Sesja wygasła.'); }
    if (!response.ok) throw new Error(data.error || 'Operacja nie została potwierdzona.');
    return data;
  }
  async function write(action, data, month) {
    if (busy) throw new Error('Poczekaj na zakończenie poprzedniego zapisu.');
    busy=true;
    const identity=JSON.stringify([action,data]);
    const requestId=pendingWrites.get(identity)||crypto.randomUUID();
    pendingWrites.set(identity,requestId);
    document.querySelectorAll('#view button, #view input, #view select, #company, #period').forEach(el=>el.disabled=true);
    try {
      await request('/portal-api.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':initial.csrf},body:JSON.stringify({action,data,request_id:requestId})});
      const state=await load(month);
      pendingWrites.delete(identity);
      return state;
    } finally {
      busy=false;
      document.querySelectorAll('#view button, #view input, #view select, #company, #period').forEach(el=>el.disabled=false);
    }
  }
  const load = month=>request('/portal-api.php?month='+encodeURIComponent(month));
  return {initial,write,load};
})();
