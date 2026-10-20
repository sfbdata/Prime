/* BlueJus · Excluir conta do escritório em 2 etapas (PR 5, docs/specs/conta-pedido-exclusao.md; desenho
   07 L142-161 e L258-269). Etapa 1: motivo (+ observação) → o servidor manda o código ao e-mail. Etapa 2:
   código + EXCLUIR → o pedido é criado e a conta fica "suspensa a pedido". "Desistir e reativar" volta.
   Tudo é conferido no servidor; aqui só a ordem das telas e as mensagens do desenho. Sem innerHTML. */
(function () {
  'use strict';

  const card = document.getElementById('mpExclusao');
  if (!card) return;
  const aviso = t => (window.bjAviso ? window.bjAviso(t) : null);

  function postar(url, dados) {
    const corpo = new FormData();
    corpo.append('_token', card.dataset.token);
    Object.keys(dados).forEach(k => corpo.append(k, dados[k]));

    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
      body: corpo,
    }).then(r => r.json().catch(() => ({})).then(d => ({ ok: r.ok && d.ok === true, dados: d || {} })));
  }

  /* ---------- Desistir e reativar ---------- */
  const desistir = card.querySelector('[data-mp-ex-desistir]');
  if (desistir) {
    desistir.addEventListener('click', () => {
      if (!window.confirm('Desistir da exclusão e reativar a conta para a equipe?')) return;
      desistir.disabled = true;
      postar(card.dataset.urlDesistir, {}).then(r => {
        if (!r.ok) { desistir.disabled = false; window.alert(r.dados.erro || 'Não foi possível desistir agora.'); return; }
        aviso(r.dados.aviso);
        setTimeout(() => window.location.reload(), 900);
      }, () => { desistir.disabled = false; window.alert('Erro de comunicação. Tente de novo.'); });
    });
  }

  /* ---------- Modal ---------- */
  const modal = document.getElementById('modalExclusaoConta');
  if (!modal) return;
  const el = sel => modal.querySelector(sel);
  const e1 = el('[data-mp-ex-e1]'), e2 = el('[data-mp-ex-e2]'), erro = el('[data-mp-ex-erro]');
  const etapaTxt = el('[data-mp-ex-etapa]'), avancar = el('[data-mp-ex-avancar]');
  const obs = el('[data-mp-ex-obs]'), codigo = el('[data-mp-ex-codigo]'), palavra = el('[data-mp-ex-palavra]');
  let etapa = 1, motivo = '';

  function mostrarErro(texto) {
    erro.querySelector('span').textContent = texto || '';
    erro.hidden = !texto;
  }
  function irPara(n) {
    etapa = n;
    etapaTxt.textContent = String(n);
    e1.hidden = n !== 1;
    e2.hidden = n !== 2;
    avancar.textContent = n === 2 ? 'Suspender e pedir exclusão' : 'Continuar';
    mostrarErro('');
    (n === 2 ? codigo : modal.querySelector('[data-mp-ex-motivo]')).focus();
  }
  function zerar() {
    motivo = '';
    obs.value = ''; codigo.value = ''; palavra.value = '';
    modal.querySelectorAll('[data-mp-ex-motivo]').forEach(b => { b.setAttribute('aria-pressed', 'false'); b.classList.remove('is-on'); });
    avancar.disabled = false;
    irPara(1);
  }
  modal.addEventListener('show.bs.modal', zerar);
  modal.addEventListener('shown.bs.modal', () => irPara(1));

  modal.querySelectorAll('[data-mp-ex-motivo]').forEach(b => b.addEventListener('click', () => {
    motivo = b.dataset.mpExMotivo;
    modal.querySelectorAll('[data-mp-ex-motivo]').forEach(o => {
      const on = o === b;
      o.setAttribute('aria-pressed', on ? 'true' : 'false');
      o.classList.toggle('is-on', on);
    });
    mostrarErro('');
  }));
  codigo.addEventListener('input', () => { codigo.value = codigo.value.replace(/\D/g, '').slice(0, 6); mostrarErro(''); });
  palavra.addEventListener('input', () => mostrarErro(''));

  avancar.addEventListener('click', () => {
    if (etapa === 1) {
      if (!motivo) { mostrarErro('Escolha um motivo.'); return; }
      avancar.disabled = true;
      postar(card.dataset.urlCodigo, { motivo, observacao: obs.value }).then(r => {
        avancar.disabled = false;
        if (!r.ok) { mostrarErro(r.dados.erro || 'Não foi possível enviar o código.'); return; }
        if (r.dados.email) el('[data-mp-ex-email]').textContent = r.dados.email;
        irPara(2);
      }, () => { avancar.disabled = false; mostrarErro('Erro de comunicação. Tente de novo.'); });
      return;
    }

    if (!/^\d{6}$/.test(codigo.value)) { mostrarErro('Código inválido.'); return; }
    if (palavra.value.trim().toUpperCase() !== 'EXCLUIR') { mostrarErro('Digite EXCLUIR para confirmar.'); return; }
    avancar.disabled = true;
    postar(card.dataset.urlPedir, { motivo, observacao: obs.value, codigo: codigo.value, palavra: palavra.value }).then(r => {
      if (!r.ok) { avancar.disabled = false; mostrarErro(r.dados.erro || 'Não foi possível concluir o pedido.'); return; }
      if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(modal).hide();
      aviso(r.dados.aviso);
      setTimeout(() => window.location.reload(), 900);
    }, () => { avancar.disabled = false; mostrarErro('Erro de comunicação. Tente de novo.'); });
  });
}());
