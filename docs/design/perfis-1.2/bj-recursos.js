/* BlueJus · Telas e recursos (liga/desliga pelo Master)
   Protótipo: estado em localStorage 'bj-recursos'. Sistema real: tabela recurso_estado (id, ativo, publico, motivo, ate, por, em)
   lida pelo backend; rotas de telas desativadas retornam a página "em manutenção" e os itens de menu nem são renderizados. */
(function () {
  if (window.BJRecursos) return;
  const K = 'bj-recursos';
  const REG = [
    { g: 'Telas', id: 'dashboard', t: 'Dashboard', ic: 'bi-speedometer2', arq: '01 - Dashboard' },
    { g: 'Telas', id: 'expedientes', t: 'Pasta (Expedientes)', ic: 'bi-folder2-open', arq: '02 - EXPEDIENTES' },
    { g: 'Telas', id: 'lista', t: 'Expediente · lista de pastas', ic: 'bi-list-ul', arq: '03 - EXPEDIENTE Lista', base: 1 },
    { g: 'Telas', id: 'metas', t: 'Minhas Metas', ic: 'bi-bullseye', arq: '04 - Minhas Metas' },
    { g: 'Telas', id: 'trabalho', t: 'Trabalho Externo', ic: 'bi-briefcase', arq: '06 - Trabalho Externo' },
    { g: 'Telas', id: 'peticionar', t: 'Peticionar', ic: 'bi-file-earmark-text', arq: '07 - Peticionar' },
    { g: 'Telas', id: 'meuperfil', t: 'Meu Perfil', ic: 'bi-person', arq: '07 - Meu Perfil', base: 1 },
    { g: 'Telas', id: 'contribua', t: 'Contribua', ic: 'bi-heart', arq: '08 - Contribua' },
    { g: 'Telas', id: 'pushpub', t: 'Push compartilhado (link público)', ic: 'bi-link-45deg', arq: 'Push Compartilhado' },
    { g: 'Telas', id: 'vagaspub', t: 'Vagas públicas (link)', ic: 'bi-megaphone', arq: 'Vagas Publicas' },
    { g: 'Menu do usuário', id: 'instalar', t: 'Instalar aplicativo', ic: 'bi-download' },
    { g: 'Menu do usuário', id: 'idioma', t: 'Idioma', ic: 'bi-translate' },
    { g: 'Menu do usuário', id: 'tema', t: 'Tema escuro', ic: 'bi-moon-stars' },
    { g: 'Menu do usuário', id: 'novidades', t: 'Novidades', ic: 'bi-stars' },
    { g: 'Menu do usuário', id: 'ajuda', t: 'Ajuda e atalhos', ic: 'bi-question-circle' },
    { g: 'Menu do usuário', id: 'privacidade', t: 'Política de Privacidade', ic: 'bi-shield-lock' },
    { g: 'Menu do usuário', id: 'contribua', t: 'Contribua (atalho)', ic: 'bi-heart', so: 1 },
    { g: 'Funções', id: 'chat', t: 'Chat I.A', ic: 'bi-chat-dots', d: 'Botão flutuante de conversas e assistente' },
    { g: 'Funções', id: 'assistente', t: 'Assistente de pastas', ic: 'bi-stars', d: 'Agente que lê e organiza pastas' }
  ];
  /* Cópia parcial do protótipo (catálogo e cabeçalho). O resto do arquivo original (estado em localStorage,
     marcação vermelha, faixa do Master e página "em preparação") está descrito na spec
     docs/specs/perfis-1.2-telas-e-recursos.md §2 e §3, com os textos exatos. */
  window.BJRecursos = { REG };
})();
