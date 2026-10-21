/* BlueJus · Lembretes da agenda (pop-up + som), em qualquer tela do sistema.
   Lê os compromissos da Agenda (bj-agenda-v1) com lembrete, e prazos/audiências das pastas (bj-prazos-pasta-v1).
   Sistema real: o mesmo aviso chega por push (Service Worker) e o servidor dispara no horário mesmo com o navegador fechado. */
(function () {
  if (window.BJLembretes) return;
  const KD = 'bj-lembretes-disp', AZ = '#0a6fa0';
  const PERF = { aud: { r: 'AUDIÊNCIA', ic: 'bi-bank', cor: '#5b3f9e', antes: 60, notas: [523, 659, 784, 1047, 784, 1047], dica: 'Confira a pauta, os documentos e o link da sala virtual.' }, reun: { r: 'REUNIÃO DE EQUIPE', ic: 'bi-people-fill', cor: '#1e7b45', antes: 10, notas: [784, 988], dica: 'A equipe vai se reunir. Leve as pendências da semana.' }, prazo: { r: 'PRAZO', ic: 'bi-hourglass-split', cor: '#c0392b', antes: 15, notas: [880, 660, 880, 660], dica: 'Prazo com horário: confirme o protocolo antes do limite.' }, meus: { r: 'COMPROMISSO', ic: 'bi-calendar-event', cor: AZ, antes: 15, notas: [660, 880, 1100], dica: '' } };
  const iso = d => d.toLocaleDateString('sv-SE');
  const disp = () => { try { return JSON.parse(localStorage.getItem(KD) || '{}'); } catch (e) { return {}; } };
  const marca = (k, v) => { const D = disp(); D[k] = v; Object.keys(D).forEach(x => { if (typeof D[x] === 'number' && Date.now() - D[x] > 7 * 864e5) delete D[x]; }); localStorage.setItem(KD, JSON.stringify(D)); };
  function eventos() {
    const L = []; let E = [], S = {};
    try { E = JSON.parse(localStorage.getItem('bj-agenda-v1') || '[]'); } catch (e) {}
    try { S = JSON.parse(localStorage.getItem('bj-prazos-pasta-v1') || '{}'); } catch (e) {}
    E.forEach(e => { if (!e.d || e.dia || !e.h1) return; const tp = e.tipo || 'meus', lem = e.lem === '0' || e.lem === 0 ? 0 : (+e.lem || PERF[tp].antes), ini = new Date(e.d + 'T' + e.h1); L.push({ k: 'ag:' + e.id + ':' + e.d + (e.h1 || ''), t: e.t, quando: ini, aviso: new Date(ini - lem * 6e4), tipo: tp, pasta: e.pasta || '', obs: e.obs || '', id: e.id, dia: false });
    });
    const hj = iso(new Date());
    Object.entries(S).forEach(([nup, arr]) => (Array.isArray(arr) && !nup.startsWith('_') ? arr : []).forEach((p, i) => { if (!p || typeof p !== 'object' || !p.data || !p.hora) return; const d = String(p.data).slice(0, 10); if (d !== hj) return; const aud = /audi/i.test(p.tipo || p.titulo || ''), ini = new Date(d + 'T' + (p.hora || '09:00')); L.push({ k: 'pz:' + nup + ':' + i + ':' + d, t: (aud ? 'Audiência' : (p.titulo || 'Prazo')) + ' · Pasta ' + nup, quando: ini, aviso: new Date(ini - PERF[aud ? 'aud' : 'prazo'].antes * 6e4), tipo: aud ? 'aud' : 'prazo', pasta: nup, obs: p.resp ? 'Responsável: ' + p.resp : '', dia: false }); }));
    return L;
  }
  let ctx = null;
  function som(tipo) {
    try { ctx = ctx || new (window.AudioContext || window.webkitAudioContext)(); if (ctx.state === 'suspended') ctx.resume(); const t0 = ctx.currentTime, notas = (PERF[tipo] || PERF.meus).notas;
      notas.forEach((f, i) => { const o = ctx.createOscillator(), g = ctx.createGain(); o.type = 'sine'; o.frequency.value = f; g.gain.setValueAtTime(0.0001, t0 + i * .18); g.gain.exponentialRampToValueAtTime(0.22, t0 + i * .18 + .02); g.gain.exponentialRampToValueAtTime(0.0001, t0 + i * .18 + .32); o.connect(g).connect(ctx.destination); o.start(t0 + i * .18); o.stop(t0 + i * .18 + .34); }); } catch (e) {}
  }
  addEventListener('pointerdown', () => { try { ctx = ctx || new (window.AudioContext || window.webkitAudioContext)(); ctx.resume(); } catch (e) {} }, { once: true });
  const COR = { meus: AZ, aud: '#5b3f9e', reun: '#1e7b45', prazo: '#c0392b' };
  const fila = [];
  function mostrar(ev) {
    if (document.getElementById('bjl-' + btoa(unescape(encodeURIComponent(ev.k))).replace(/=/g, ''))) return;
    fila.push(ev); desenhar(); som(ev.tipo);
    try { if ('Notification' in window && Notification.permission === 'granted') new Notification('BlueJus · ' + ev.t, { body: rotulo(ev), icon: './bluejus-favicons/bluejus-simbolo-petroleo.png', tag: ev.k }); } catch (e) {}
  }
  function rotulo(ev) { const min = Math.round((ev.quando - Date.now()) / 6e4), h = ev.dia ? 'hoje' : ev.quando.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }); return min > 59 ? 'Em ' + Math.round(min / 60) + 'h · ' + h : min > 0 ? 'Em ' + min + ' min · às ' + h : min > -5 ? 'Agora · ' + h : 'Começou às ' + h; }
  function desenhar() {
    let box = document.getElementById('bjl-box'); if (!fila.length) { if (box) box.remove(); return; }
    if (!box) { box = document.createElement('div'); box.id = 'bjl-box'; box.style.cssText = 'position:fixed;z-index:2147483400;right:18px;top:76px;display:flex;flex-direction:column;gap:10px;width:min(360px,calc(100vw - 24px));font-family:Arial,Helvetica,sans-serif;color:#12242f;'; document.body.appendChild(box);
      if (!document.getElementById('bjl-css')) { const st = document.createElement('style'); st.id = 'bjl-css'; st.textContent = '@keyframes bjlIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:none}}@keyframes bjlSino{0%,100%{transform:rotate(0)}15%{transform:rotate(16deg)}30%{transform:rotate(-14deg)}45%{transform:rotate(10deg)}60%{transform:rotate(-6deg)}}@keyframes bjlBar{from{transform:scaleX(1)}to{transform:scaleX(0)}}'; document.head.appendChild(st); } }
    box.innerHTML = '';
    fila.forEach((ev, i) => { const P = PERF[ev.tipo] || PERF.meus, cor = P.cor, id = 'bjl-' + btoa(unescape(encodeURIComponent(ev.k))).replace(/=/g, ''), el = document.createElement('div'); el.id = id;
      el.style.cssText = 'background:#fff;border:1px solid #d3dde4;border-left:4px solid ' + cor + ';border-radius:4px;box-shadow:0 14px 34px rgba(18,36,47,.22);overflow:hidden;animation:bjlIn .3s ease both;';
      el.innerHTML = '<div style="display:flex;gap:12px;padding:12px 12px 10px 14px;"><span style="width:38px;height:38px;flex:none;border-radius:50%;background:' + cor + ';color:#fff;display:flex;align-items:center;justify-content:center;font-size:18px;animation:bjlSino 1s ease 2;"><i class="bi ' + P.ic + '"></i></span><div style="flex:1;min-width:0;"><div style="font-size:11px;font-weight:bold;letter-spacing:.6px;color:' + cor + ';">' + P.r + ' · ' + rotulo(ev) + '</div><b style="display:block;font-size:14.5px;margin-top:2px;line-height:1.3;">' + ev.t.replace(/</g, '&lt;') + '</b>' + (ev.obs || P.dica ? '<div style="font-size:12.5px;color:#5b7383;margin-top:3px;line-height:1.4;">' + (ev.obs || P.dica).replace(/</g, '&lt;') + '</div>' : '') + '</div><button data-x title="Dispensar" style="width:28px;height:28px;border:none;border-radius:3px;background:transparent;color:#7a8e9b;cursor:pointer;font-size:16px;">×</button></div>'
        + '<div style="display:flex;gap:6px;padding:0 12px 12px 14px;flex-wrap:wrap;"><button data-abrir style="height:30px;padding:0 12px;border:none;border-radius:3px;background:' + AZ + ';color:#fff;font:bold 12.5px Arial,Helvetica,sans-serif;cursor:pointer;">' + (ev.pasta && ev.tipo !== 'meus' ? 'Abrir pasta' : 'Ver na agenda') + '</button><button data-adiar style="height:30px;padding:0 10px;border:1px solid #c5d2db;border-radius:3px;background:#fff;color:#34505f;font:12.5px Arial,Helvetica,sans-serif;cursor:pointer;">Lembrar em 10 min</button></div>'
        + '<div style="height:3px;background:' + cor + ';opacity:.35;transform-origin:left;animation:bjlBar 60s linear both;"></div>';
      const fim = ok => { fila.splice(fila.indexOf(ev), 1); desenhar(); if (ok) marca(ev.k, Date.now()); };
      el.querySelector('[data-x]').onclick = () => fim(true);
      el.querySelector('[data-adiar]').onclick = () => { marca(ev.k, 'adiar:' + (Date.now() + 10 * 6e4)); fila.splice(fila.indexOf(ev), 1); desenhar(); };
      el.querySelector('[data-abrir]').onclick = () => { fim(true); location.href = ev.pasta && ev.tipo !== 'meus' ? './02 - EXPEDIENTES - VS 18.10.26.dc.html#pasta=' + encodeURIComponent(ev.pasta) : './09 - Agenda - VS 18.10.26.dc.html#ev=' + encodeURIComponent(ev.id || ''); };
      box.appendChild(el); });
  }
  function checar() {
    const agora = Date.now(), D = disp();
    eventos().forEach(ev => { const st = D[ev.k]; if (typeof st === 'number') return; if (typeof st === 'string' && st.startsWith('adiar:') && agora < +st.slice(6)) return; if (agora >= +ev.aviso && agora - +ev.quando < 2 * 36e5) mostrar(ev); });
  }
  window.BJLembretes = { checar, testar: tipo => mostrar({ k: 'teste:' + Date.now(), t: { aud: 'Audiência de conciliação · Pasta 1180', reun: 'Reunião de equipe semanal', prazo: 'Prazo de contestação · Pasta 1180', meus: 'Atendimento ao cliente' }[tipo || 'aud'], quando: new Date(Date.now() + (PERF[tipo || 'aud'].antes) * 6e4), tipo: tipo || 'aud', pasta: tipo === 'reun' || tipo === 'meus' ? '' : '1180', obs: '', dia: false }), permitir: () => { try { if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission(); } catch (e) {} } };
  const ini = () => { checar(); setInterval(checar, 30000); addEventListener('storage', e => { if (e.key === 'bj-agenda-v1' || e.key === 'bj-prazos-pasta-v1') checar(); }); };
  if (document.readyState === 'loading') addEventListener('DOMContentLoaded', () => setTimeout(ini, 1200)); else setTimeout(ini, 1200);
})();
