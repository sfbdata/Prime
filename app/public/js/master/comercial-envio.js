/* BlueJus · Painel do Master, Central de envios: a chave "Envio de campanhas".
   - Carrega o estado ao abrir (GET) e desenha o interruptor, o estado escrito e o aviso fixo (só com a chave desligada).
   - Clicar pede confirmação, faz o POST JSON com X-CSRF-Token e redesenha com a resposta do servidor.
   - Avisa o resto da tela pelo evento `mc:envio` ({ligada, esperando}); a outra instância (modo com e venda na mesma
     página) escuta o mesmo evento e se sincroniza. A linha de retorno é fixa: fica até a próxima alteração.
   Nenhuma regra mora só aqui: com a chave desligada o servidor não recusa nada, as campanhas esperam. */
(() => {
  'use strict';
  const T = (chave, params) => BJ_T.t('comercial.js.' + chave, params);

  function iniciar(raiz) {
    if (raiz.dataset.mcIniciada) return;
    raiz.dataset.mcIniciada = '1';
    const URL_ESTADO = raiz.dataset.urlEstado;
    const URL_ALTERAR = raiz.dataset.urlAlterar;
    const TOKEN = raiz.dataset.token || '';
    const chave = raiz.querySelector('[data-mc-envio-chave]');
    const estado = raiz.querySelector('[data-mc-envio-estado]');
    const aviso = raiz.querySelector('[data-mc-envio-aviso]');
    const avisoTexto = raiz.querySelector('[data-mc-envio-aviso-texto]');
    const retorno = raiz.querySelector('[data-mc-envio-retorno]');
    let atual = null;

    // Linha de retorno fixa (M5.14): `tipo` 'ok', 'erro' ou vazio (informação, ex.: "Salvando…").
    function falar(texto, tipo) {
      retorno.textContent = texto || '';
      retorno.hidden = !texto;
      retorno.classList.toggle('is-ok', tipo === 'ok');
      retorno.classList.toggle('is-erro', tipo === 'erro');
      retorno.setAttribute('role', tipo === 'erro' ? 'alert' : 'status');
    }

    function desenhar(d) {
      atual = { ligada: d.ligada === true, esperando: parseInt(d.esperando, 10) || 0 };
      chave.setAttribute('aria-checked', String(atual.ligada));
      chave.disabled = false;
      estado.textContent = T(atual.ligada ? 'envio_ligado' : 'envio_desligado');
      aviso.hidden = atual.ligada;
      avisoTexto.textContent = atual.ligada ? '' : T(atual.esperando > 0 ? 'envio_aviso_n' : 'envio_aviso_zero', { n: atual.esperando });
    }

    function pedir(url, opcoes) {
      return fetch(url, Object.assign({ credentials: 'same-origin', headers: { Accept: 'application/json' } }, opcoes))
        .then(r => r.json().catch(() => ({})).then(j => {
          if (!r.ok) throw new Error(j.erro || T('envio_erro_salvar'));
          return j;
        }));
    }

    function avisarTela() {
      document.dispatchEvent(new CustomEvent('mc:envio', { detail: { ligada: atual.ligada, esperando: atual.esperando } }));
    }

    chave.addEventListener('click', () => {
      if (!atual || chave.disabled) return;
      const ligar = !atual.ligada;
      const pergunta = ligar
        ? T(atual.esperando > 0 ? 'envio_confirmar_ligar' : 'envio_confirmar_ligar_zero', { n: atual.esperando })
        : T('envio_confirmar_desligar');
      if (!window.confirm(pergunta)) return;
      chave.disabled = true;
      falar(T('envio_salvando'));
      pedir(URL_ALTERAR, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': TOKEN },
        body: JSON.stringify({ ligada: ligar }),
      }).then(d => {
        desenhar(d);
        falar(T(atual.ligada ? 'envio_ok_ligado' : 'envio_ok_desligado'), 'ok');
        avisarTela();
      }).catch(e => {
        falar(e.message, 'erro');
        chave.disabled = false;
      });
    });

    // A outra instância (ou qualquer parte da tela) mudou o estado: só redesenha, sem reavisar.
    document.addEventListener('mc:envio', e => {
      if (e.detail && typeof e.detail.ligada === 'boolean') desenhar(e.detail);
    });

    pedir(URL_ESTADO).then(d => { desenhar(d); avisarTela(); })
      .catch(() => falar(T('envio_erro_carregar'), 'erro'));
  }

  document.querySelectorAll('[data-mc-envio]').forEach(iniciar);
})();
