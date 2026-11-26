/* BlueJus · Master, "Agendar" artigo dos blogs (spec docs/specs/painel-artigos-blogs.md, P5).
   Janela na própria página (componentes mp-conf do painel), com data e hora no horário de Brasília e só no futuro.
   Usada pela lista (blog-artigos.js) e pelo editor (blog-editor.js).
   BJBlogAgendar.abrir({ url, token, titulo, aoAgendar(json) }): POST {publicarEm: "AAAA-MM-DDTHH:MM"} em `url`
   com o X-CSRF-Token. Dando certo, fecha e chama aoAgendar; recusa do servidor (422 por campo ou 409) aparece na janela.
   O servidor confere tudo de novo: o `min` do campo é só ajuda. */
(() => {
  'use strict';
  const ERRO_PADRAO = 'Não foi possível concluir agora. Tente de novo.';
  const FUSO = 'America/Sao_Paulo';

  // "Agora" em Brasília no formato do campo (AAAA-MM-DDTHH:MM), qualquer que seja o fuso do navegador.
  function agoraEmBrasilia() {
    return new Date().toLocaleString('sv-SE', { timeZone: FUSO }).replace(' ', 'T').slice(0, 16);
  }

  // Junta as mensagens de uma recusa 422 ({erros: {campo: [texto]}}) ou 409 ({erro}).
  function mensagemDaRecusa(j) {
    if (j && j.erros) return Object.values(j.erros).reduce((a, l) => a.concat(l), []).join(' ');
    return (j && j.erro) || ERRO_PADRAO;
  }

  let aberta = null;

  function fechar() {
    if (!aberta) return;
    document.removeEventListener('keydown', aberta.tecla);
    aberta.fundo.remove();
    aberta = null;
  }

  function abrir(op) {
    fechar();
    const fundo = document.createElement('div');
    fundo.className = 'mp-conf-fundo';
    fundo.innerHTML = '<div class="mp-conf" role="dialog" aria-modal="true" aria-labelledby="bjAgendarTitulo" style="--cor:#0a6fa0">'
      + '<div class="mp-conf-cab"><i class="bi bi-calendar-event"></i><span id="bjAgendarTitulo"></span></div>'
      + '<div class="mp-conf-texto">Escolha o dia e a hora (horário de Brasília) em que o artigo vai ao ar. O sistema confere de 5 em 5 minutos, então a publicação pode levar alguns minutos a mais que a hora marcada.</div>'
      + '<div class="mp-conf-campo"><label for="bjAgendarQuando">Data e hora</label>'
      + '<input type="datetime-local" id="bjAgendarQuando" style="letter-spacing:normal"></div>'
      + '<div class="mp-conf-texto" data-agendar-erro role="alert" style="color:#a3232b;padding-top:0" hidden></div>'
      + '<div class="mp-conf-rodape"><button type="button" class="mp-conf-cancelar" data-agendar-cancelar>Cancelar</button>'
      + '<button type="button" class="mp-conf-ok" data-agendar-ok>Agendar</button></div></div>';
    fundo.querySelector('#bjAgendarTitulo').textContent = op.titulo || 'Agendar artigo';
    const campo = fundo.querySelector('#bjAgendarQuando');
    const ok = fundo.querySelector('[data-agendar-ok]');
    const aviso = fundo.querySelector('[data-agendar-erro]');
    campo.min = agoraEmBrasilia();
    const mostrar = t => { aviso.textContent = t; aviso.hidden = !t; };

    const enviar = () => {
      if (!campo.value) { mostrar('Informe a data e a hora.'); return; }
      if (campo.value <= agoraEmBrasilia()) { mostrar('Escolha uma data e uma hora no futuro.'); return; }
      mostrar('');
      ok.disabled = true;
      fetch(op.url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': op.token },
        body: JSON.stringify({ publicarEm: campo.value }),
      })
        .then(r => r.json().catch(() => null).then(j => ({ ok: r.ok && !r.redirected && j !== null, j: j })))
        .then(res => {
          if (res.ok) { fechar(); if (op.aoAgendar) op.aoAgendar(res.j); return; }
          mostrar(mensagemDaRecusa(res.j));
          ok.disabled = false;
        })
        .catch(() => { mostrar(ERRO_PADRAO); ok.disabled = false; });
    };

    const tecla = e => { if (e.key === 'Escape') fechar(); };
    fundo.querySelector('[data-agendar-cancelar]').addEventListener('click', fechar);
    fundo.addEventListener('click', e => { if (e.target === fundo) fechar(); });
    ok.addEventListener('click', enviar);
    document.addEventListener('keydown', tecla);
    (document.querySelector('.mp') || document.body).appendChild(fundo); // os botões do mp-conf são estilizados dentro de .mp
    aberta = { fundo, tecla };
    campo.focus();
  }

  window.BJBlogAgendar = { abrir, fechar };
})();
