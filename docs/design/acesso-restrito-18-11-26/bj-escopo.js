/* BlueJus · Escopo de acesso por responsabilidade (protótipo)
   Perfil com "ACESSO RESTRITO ÀS PASTAS SOB RESPONSABILIDADE": o usuário só vê, abre, busca, baixa e movimenta
   as pastas em que é o responsável. Tudo o mais do escritório fica fora do alcance (painel, relatórios gerais,
   perfis, financeiro, pastas de outros).
   Protótipo: o Master simula o acesso em Perfis ("Simular acesso"). O escopo fica em localStorage:
     bj-perfis-escopo  { "<nome do perfil>": { restrito: true, rotulo: "Prospectador" } }
     bj-escopo-sim     { perfil, usuario, rotulo }   (simulação ativa)
     bj-pasta-resp     { "<nup>": "<nome do responsável>" }   (gravado pela lista de pastas)
   Sistema real: a regra é aplicada no SERVIDOR, em toda consulta (lista, busca, contagem, download, exportação,
   relatório, API, notificações): WHERE pasta.responsavel_id = :usuario_id quando o perfil tiver escopo 'responsavel'.
   A interface só reflete o que o servidor já filtrou. */
(function () {
  if (window.BJEscopo) return;
  const KP = 'bj-perfis-escopo', KS = 'bj-escopo-sim', KR = 'bj-pasta-resp';
  const ler = (k, d) => { try { return JSON.parse(localStorage.getItem(k)) || d; } catch (e) { return d; } };
  const norm = t => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().replace(/\s+/g, ' ').trim();
  const sim = () => ler(KS, null);
  const ativo = () => !!sim();
  const usuario = () => (sim() || {}).usuario || '';
  const perfis = () => ler(KP, {});
  const respDe = nup => ler(KR, {})[String(nup)];
  const compart = nup => { const R = window.BJRepublicar; return !!(R && R.compartilhadaCom && R.compartilhadaCom(nup, usuario())); };
  const podePasta = nup => { if (!ativo()) return true; const r = respDe(nup); return (!!r && norm(r) === norm(usuario())) || compart(nup); };
  const filtrar = (lista, respNome) => !ativo() ? lista : lista.filter(x => norm(respNome(x)) === norm(usuario()));
  const ehMinha = nome => !ativo() || norm(nome) === norm(usuario());
  /* Telas permitidas no acesso restrito: lista de pastas, a pasta (se for do usuário), Meu Perfil e Contribua. */
  const PERMITIDAS = ['03 - EXPEDIENTE', '02 - EXPEDIENTES', '07 - Meu Perfil', '08 - Contribua'];
  const PERFIS_PAG = './05 - Perfis 1.2 - VS 03.11.26.dc.html', LISTA = './03 - EXPEDIENTE - VS 30.10.26.dc.html';
  const tela = () => decodeURIComponent(location.pathname.split('/').pop() || '');
  const nupDaUrl = () => { const m = (location.hash + location.search).match(/pasta=(\d+)/); return m ? m[1] : ''; };
  function sair() { localStorage.removeItem(KS); location.href = PERFIS_PAG; }
  function iniciar(o) { localStorage.setItem(KS, JSON.stringify({ perfil: o.perfil, usuario: o.usuario, rotulo: o.rotulo || 'Acesso restrito', em: Date.now() })); location.href = LISTA; }
  function bloqueio(tit, txt) {
    if (document.getElementById('bje-ov') || !document.body) return;
    const o = document.createElement('div'); o.id = 'bje-ov';
    o.style.cssText = 'position:fixed;inset:0;z-index:2147483150;background:#f3f6f8;display:flex;align-items:center;justify-content:center;padding:20px;font-family:Arial,Helvetica,sans-serif;color:#12242f;';
    o.innerHTML = '<div style="max-width:470px;text-align:center;background:#fff;border:1px solid #dee2e6;border-top:4px solid #c0392b;border-radius:6px;padding:30px 26px;box-shadow:0 10px 30px rgba(18,36,47,.08);"><div style="width:62px;height:62px;margin:0 auto 12px;border-radius:50%;background:#fbe7e7;color:#a3232b;display:flex;align-items:center;justify-content:center;font-size:28px;">🔒</div><div style="font-size:19px;font-weight:bold;margin-bottom:6px;">' + tit + '</div><div style="font-size:14px;color:#4a6373;line-height:1.55;">' + txt + '</div><a href="' + LISTA + '" style="display:inline-flex;align-items:center;gap:7px;margin-top:18px;height:38px;padding:0 16px;border-radius:3px;background:#0a6fa0;color:#fff;font-weight:bold;font-size:14px;text-decoration:none;">Ir para minhas pastas</a></div>';
    document.body.appendChild(o);
  }
  function faixa() {
    if (!document.body || document.getElementById('bje-fx')) return; const S = sim(); if (!S) return;
    const f = document.createElement('div'); f.id = 'bje-fx'; f.setAttribute('data-print', 'hide');
    f.style.cssText = 'position:fixed;z-index:2147483160;left:50%;bottom:14px;transform:translateX(-50%);width:min(760px,calc(100vw - 24px));display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:9px 12px;background:#2b1a0c;color:#fff;border-left:4px solid #e67e22;border-radius:4px;box-shadow:0 10px 26px rgba(0,0,0,.3);font:13px Arial,Helvetica,sans-serif;';
    f.innerHTML = '<span style="font-size:16px;">👁</span><span style="flex:1;min-width:220px;line-height:1.4;"><b>Simulação:</b> você está vendo como <b>' + String(S.usuario).replace(/</g, '&lt;') + '</b> · ' + String(S.rotulo || '').replace(/</g, '&lt;') + ' · acesso restrito às pastas sob responsabilidade</span><button style="height:30px;padding:0 12px;border:none;border-radius:3px;background:#e67e22;color:#fff;font:bold 12.5px Arial,Helvetica,sans-serif;cursor:pointer;">Sair da simulação</button>';
    f.querySelector('button').onclick = sair; document.body.appendChild(f);
  }
  function guarda() {
    if (!ativo()) { const o = document.getElementById('bje-ov'); if (o) o.remove(); const f = document.getElementById('bje-fx'); if (f) f.remove(); return; }
    faixa(); const t = tela();
    if (!PERMITIDAS.some(p => t.indexOf(p) === 0)) return bloqueio('Fora do seu acesso', 'Seu perfil tem <b>acesso restrito às pastas sob sua responsabilidade</b>. Esta área do escritório não está disponível para você.');
    if (t.indexOf('02 - EXPEDIENTES') === 0) { const n = nupDaUrl(), o = document.getElementById('bje-ov'); if (o) o.remove(); if (n && !podePasta(n)) return bloqueio('Pasta fora da sua responsabilidade', 'A pasta ' + n + ' não está sob sua responsabilidade nem foi compartilhada com você.'); }
  }
  window.BJEscopo = { ativo, sim, usuario, perfis, podePasta, filtrar, ehMinha, respDe, iniciar, sair, norm,
    salvarPerfil: (nome, cfg) => { const P = perfis(); if (cfg && cfg.restrito) P[nome] = cfg; else delete P[nome]; localStorage.setItem(KP, JSON.stringify(P)); },
    renomearPerfil: (de, para) => { const P = perfis(); if (P[de]) { P[para] = P[de]; delete P[de]; localStorage.setItem(KP, JSON.stringify(P)); } },
    gravarResp: mapa => { try { localStorage.setItem(KR, JSON.stringify(mapa)); } catch (e) {} } };
  const ini = () => { guarda(); addEventListener('hashchange', guarda); };
  if (document.body) ini(); else addEventListener('DOMContentLoaded', ini);
  addEventListener('storage', e => { if (e.key === KS) location.reload(); });
})();
