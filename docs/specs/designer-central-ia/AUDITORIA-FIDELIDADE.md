# Auditoria integral: Central de Comunicação × Claude Designer

Feita em 15/10/2026 (noite), só leitura, na worktree `designer-central-ia` @ `419af346`. Não refazer: as conclusões
abaixo valem até o protótipo mudar (conferir os `etag` no MCP `claude_design`: `bluejus-central.js` 1791844469628618,
`02 - EXPEDIENTES 1.2.3.dc.html` 1791844935928960; projeto "Redesign de pasta jurídica",
`4f6a5f61-fa47-40a0-9630-6744f1bb1cac`, idêntico ao pacote local de 13/10).

Três referências comparadas: protótipo servido localmente
(`/home/prime/projetos/bluejus-designer/REPOSITORIO_BLUEJUS/prototipo`), código da worktree e demo 8002 com dados
reais (escritas em `/central/*` bloqueadas no navegador durante a observação). Evidências (pasta ignorada pelo git):
`/home/prime/projetos/jusprime/.playwright-mcp/fid/` (`p-*` protótipo, `d-*` demo; `faseA/` pares dos testes;
`auditoria/` aberto, Painel e menu ⋮ reais × protótipo).

Linhas citadas como `C:` = `bluejus-central.js`, `K:` = `bj-contas.js`, `E:` = `02 - EXPEDIENTES 1.2.3.dc.html`.

---

## 1. Diagnóstico

1. **O Designer exibe atividade FICTÍCIA.** O "10 não lidas" vermelho e pulsando vem de notificações que o próprio
   protótipo gera: o Expediente cria `meta_atrasada` e cobranças de checklist 400 ms depois de montar
   (`centralSync`, E:4314-4377); a "BlueJus IA" gera a análise semanal ao abrir (`resumoSemanal`, C:6321); a presença
   é simulada (`DEMO_ONLINE`: os 3 primeiros usuários sempre on-line, C:274-275; `DEMO_ST` sorteia status).
2. **O sistema só conhece mensagens e menções da Central.** Notificações de metas, prazos, cobrança e alertas vivem no
   sino (`Notificacao`) e não entram na Caixa: por isso não existem os filtros Urgente/Atenção/Metas, os cartões do
   Painel, a lista de pendências e o contador vermelho. Na 8002 a conta do dono tem 0 não lidas: o ícone fica sempre
   calmo.
3. **As telas internas foram reinterpretadas** com componentes próprios do `central.css` em vez de portadas do código
   do protótipo (menu ⋮, confirmação, Informações, Novo grupo, avatares, ações da mensagem, Painel).
4. **Recursos ausentes por decisão ou infraestrutura** (não "simplificados"): presença, IA, anexos, ligações, Destacar.
5. **Os 7 testes da Fase A provam o lançador com dados simulados** (o sistema desenha igual quando recebe o mesmo
   número). Não provam que existe dado real para chegar aos mesmos estados (o estado urgente não tem fonte no sistema).

## 2. Regras extraídas do protótipo (fonte para B1/B2 e fases seguintes)

**Comandos da janela (C:6296-6345, 6423-6465).**
- Barra: Destacar, Minimizar, Maximizar/Restaurar, Fechar. Destacada: some Minimizar e Maximizar; Destacar vira
  "Voltar para dentro do BlueJus".
- Fechar: `ui.aberto=false`, limpa menu/contexto/confirmação; não grava prefs.
- Minimizar: anima o painel até o lançador (`translate(dx,dy) scale(.08)`, opacidade 0, 260 ms,
  `cubic-bezier(.4,0,.6,1)`), sem animação com reduced-motion; na prática é Fechar com animação.
- Maximizar/Restaurar: alterna `prefs.janela` 'max'/'normal' e salva; duplo clique no cabeçalho faz o mesmo e limpa
  `snap`. 'max' = 12 px de margem; `snap` = meia tela. O protótipo redesenha o elemento, então RESTAURAR É INSTANTÂNEO.
- Arrastar: ímã 14 px; soltar com o ponteiro em y≤3 maximiza; nas bordas laterais encaixa meia tela; setas movem
  20 px (Shift 80); 8 alças só em 'normal', mínimo 320×320; padrão 380×580; lançador arrastável (`prefs.pos`, 18/18).
- Persistência em localStorage: `janela`, `tam`, `win`, `snap`, `pos`. `ui.aberto` NÃO persiste: sempre recarrega
  fechado. Nada abre o chat sozinho.
- **Destacar (`abrirJanelaSeparada`, C:6296-6313):** pede W=max(420, tam.w‖440), H=max(560, tam.h‖660).
  1. Com `documentPictureInPicture`: `requestWindow({width,height})` (janela do sistema operacional, sempre por cima,
     pode ir a outro monitor). Aviso: "Chat em janela flutuante: arraste para qualquer lugar da tela. Ela fica por cima
     mesmo com o BlueJus minimizado."
  2. Sem ele: `window.open('', 'bluejus-chat', 'popup=yes,width=W,height=H,left=availWidth-W-40,top=60')`, título
     "BlueJus · Chat I.A". Bloqueado: "O navegador bloqueou a janela. Permita pop-ups para este site e tente de novo."
     Sucesso: "Chat em janela própria: arraste para qualquer lugar ou para outro monitor."
  3. Copia todos os `link[rel=stylesheet]` e `<style>` para a janela nova; body margin 0, fundo #eef2f5, Arial.
  4. O MESMO nó raiz é MOVIDO (não é cópia: estado único). Ao destacar: `prefs.janela='max'`, `ui.aberto=true`,
     `ui.separada=true`; painel em 100vw×100vh.
  5. Volta ao fechar a janela externa (`pagehide`/`beforeunload`): repõe o nó no lugar original e a `janela` anterior.
     `voltarJanela` = voltar + `w.close()`. Erro: "Não foi possível abrir a janela separada." Fica em auditoria.
  6. Observado no navegador (Chromium): abriu 1280×720 (decisão do navegador), 10 folhas de estilo copiadas, e ao
     fechar a janela o chat voltou para a página.
  7. Detalhe do protótipo, provavelmente não intencional: Fechar no modo destacado não chama `voltarJanela`.

**Lançador (C:6323, 6399-6413, 136-144).**
- Contador `naoLidas().length + msgsNaoLidas()` (silenciadas fora); "99+" acima de 99.
- VERMELHO #c0392f (rgb(192,57,47)) + `bjcUrg` 1,6 s infinito quando há notificação não lida de tipo nível ≥3
  (`meta_atrasada`, `alerta`, `toque`, `ligacao_perdida`, `cobranca`) e alertas ligados; senão laranja #e8590c.
  A marca manual "urgente" não pinta o contador.
- "@n" = menções não lidas, com o chat fechado. Salto `bjcSalto` 0,9 s ×2 por 2,4 s ao chegar notificação relevante
  (modo 'todas' ou nível ≥2). Som: senos 880/1320 Hz, ganho 0,12×volume (baixo .45, médio 1, alto 1,7), intervalo
  2,5 s; toque = 4 notas triangulares; tremer 650 ms ×2 só para 'toque'. Aviso escuro 2,6 s.

**Presença (C:264-345).** Contrato real: `window.BLUEJUS_ONLINE` ou WebSocket `{tipo:'presenca', online:[ids]}`
(`BLUEJUS_WS_URL`), `{tipo:'digitando'}` 4 s. Sem servidor: batimento em localStorage a cada 20 s, expira em 60 s.
Status manual Disponível #3fd17f, Ocupado #e5484d, Ausente #f2a93b (Não perturbe força 'ocupado'). Off-line #b7c4cd,
"visto por último hoje às HH:MM". Fictício: `DEMO_ONLINE`, `DEMO_ST`, presença por localStorage.

**Filtros da Caixa (C:5775-5790) e Painel (C:5632-5658).**
- 7 bolinhas de seleção única, sem contador: Tudo (#4f6878, ícone inbox), Conversas (#3b6e8f), Urgente (#c0392f),
  Atenção (#c8952a), Menções (#0a7aad), Metas (#1f9d61), Para revisar (#f2c200).
- Grupo = `n.urgente ? 'urgente' : TIPOS[tipo].grupo` (C:83). Urgente: meta_atrasada, alerta, toque,
  ligacao_perdida, cobranca. Atenção: demanda, conformidade, direcionada. Metas: meta_nova, meta_atualizada,
  meta_concluida. Mensagens: mensagem, documento_analisado.
- Tudo = conversas + notificações; Conversas = só conversas; Revisar = conversas marcadas; demais = notificações do
  grupo. Ordem: fixadas, não resolvidas, mais recentes; até 100.
- Painel: 5 cartões (Urgente, Atenção, Menções, Mensagens, Metas) com contagem; lista das não lidas por nível, até
  12; vazio "Tudo em dia / Nenhuma notificação pendente para você."; "Equipe · em aberto" soma pendências de CADA
  colega, visível para todos, sem filtro de permissão.

**Notificações na Caixa (C:122-144, 5432-5630).** Cartão com cor do tipo, selos URGENTE/RESOLVIDA, baixa rápida;
menu: Abrir, Resolver/Reabrir, Lida/Não lida, Urgente, Encaminhar, Abrir pasta, Copiar, Excluir. Resolvidas ficam
24 h (1 h/1 dia/1 semana). Baixas aceleradas em 3 níveis (avisa Controladoria, suspende 10 min).

**BlueJus IA (C:313-320, 391-781, 4765-5050).** Fixada no topo por padrão; avatar estrela em degradê; ~300 comandos
"/"; @IA em qualquer conversa; motores locais por regra e, no fim, `BLUEJUS_IA_URL` ou `window.claude.complete`;
ações de escrita com confirmação. Só o ramo final é IA real.

**Menu ⋮ (C:6244-6293), 7 seções.** Conversas (Central de Atenção, Nova conversa, Novo grupo, Arquivadas, Resolvidas
antigas, Prazo das resolvidas, Marcar todas como lidas, Governança e acessos [só gestores], Ocorrências, Ver todas
as notificações) · Meu status (Status, Não perturbe 1 h) · Notificações (Som, Volume, Receber toques, Alertas
visuais, Avisar todas/importantes) · Mensagens (Enter envia) · Ligações (Receber ligações, Tamanho do texto) ·
Limpeza (Limpar lidas, Excluir todas as conversas) · final (Restaurar posição, Restaurar tamanho, Abrir em janela
separada, Ajuda). Painel de 272 px, títulos 10,5 px .07em, chaves 30×17.

**bj-contas.js.** Simula conta e tenant no navegador (prefixo de localStorage), primário/secundário, `?como=`. A
Central só usa `BLUEJUS_CONTA.primario`. Tudo fictício como mecanismo; a regra de primário é do produto.

**Integrações do Expediente (E).** `despachar` (~14 menus "Encaminhar via Chat I.A"), `conversar`, `abrir`,
`notificar`, `alertar`, `presenca`, `timeline.abrir`, eventos `bj-evento`/`bj-navegar`; `bj-chat-anexar-texto` não
tem ouvinte nem no protótipo.

## 3. Matriz de fidelidade (15/10)

Legenda: ✅ correto · 🟡 parcial · ❌ ausente · 👁 existe, mas a demo não chega lá. Prioridade P0 a P3.

| Área | Componente | Designer | Symfony / demo 8002 | Situação | Causa / dependência | Prio |
|---|---|---|---|---|---|---|
| Lançador | SVG, 66×54, sombra, hover, transição | Sim | Idêntico (medido) | ✅ | | |
| Lançador | Visível fechado; começa fechado | Sim | Sim, nada por cima | ✅ | | |
| Lançador | Calmo / novas / "@n" / pontos | Sim | Iguais no teste simulado; na demo só calmo (0 não lidas) | 👁 | Dados | P0 |
| Lançador | Contador vermelho + `bjcUrg` | Nível ≥3 | Não existe | ❌ | Notificações na Caixa + regra de urgência (F1) | P1 |
| Lançador | Salto, som, aviso, balão | Sim | Iguais (por mensagem) | ✅ | | |
| Janela | Abrir, Fechar, Minimizar, Maximizar | Sim | Iguais | ✅ | | |
| Janela | Restaurar | Instantâneo | ~1 s no tamanho máximo, saindo da tela (transição width/height) | 🟡 defeito | Frontend (B1) | P1 |
| Janela | Arrastar, ímã, encaixe, setas, alças, prefs | Sim | Implementado (encaixe não confirmado no protótipo por automação) | 🟡 | | P2 |
| Janela | Destacar / "Abrir em janela separada" | Document PiP + pop-up | Não existe | ❌ | Frontend (B2) | P0 |
| Cabeçalho | Avatar com ponto, "Chat I.A", pílula "N on-line" | Sim | Sem avatar e pílula; título "Central de Comunicação" | 🟡/❌ | Avatar visual (B1); presença (F2) | P1 |
| Cabeçalho | Menu ⋮ em painel com seções e chaves | ~25 itens | Menu de contexto com 8 itens em texto | 🟡 | B1 (só itens reais) | P0 |
| Caixa | Busca nome/e-mail/setor/função | Sim | Só nome | 🟡 | Ampliar DTO de contatos (sem migration) | P2 |
| Caixa | Abas e contador | Notif + msgs | Só msgs | 🟡 | F1 | P1 |
| Caixa | 7 filtros coloridos | Sim | Tudo e Menções | 🟡 | Conversas/Revisar: C; demais: F1 | P1 |
| Caixa | Linha da conversa, avatar de grupo | Degradê + ícone; contador centralizado | Iniciais; contador na prévia | 🟡 | C | P1 |
| Caixa | Conversa BlueJus IA fixada | Sim | Não | ❌ | D1 | Decisão |
| Caixa | Cartões de notificação | Sim | Não | ❌ | F1 | P1 |
| Caixa | Estados vazios | Textos/ícones do desenho | Outros | 🟡 | C | P2 |
| Painel | 5 cartões | Sim | 2 | 🟡 | F1 | P1 |
| Painel | Lista de pendências / "Tudo em dia" | Sim | Área em branco | ❌ | C (vazio), F1 (lista) | P1 |
| Painel | Equipe · em aberto | Visível a todos | Não | ❌ | Decisão de privacidade | Decisão |
| Conversa | Cabeçalho clicável + Pesquisar | Sim | Estático, sem pesquisa | ❌ | D | P1 |
| Conversa | Ações no hover (Responder, ⋮) | Sim | Só botão direito | ❌ | D | P1 |
| Conversa | Responder, copiar, mencionar | Sim | Sim | ✅ | | |
| Conversa | Editar, excluir, fixar, favoritar, encaminhar, reações | Sim | Não | ❌ | Regra D5 + migration | P3 |
| Conversa | Recibo enviada/entregue/visualizada | Sim | Sempre "enviada" | 🟡 | Privacidade | Decisão |
| Conversa | Carregar anteriores (pílula), "Novas mensagens" | Sim | Link; sem botão | 🟡/❌ | D | P2 |
| Conversa | Compositor (sem anel de foco; anexo, emoji, toque, ligar) | Sim | Anel azul; só Enviar | 🟡/❌ | D; anexo = R2; ligar = infra | P2 |
| Conversa | Enter envia configurável; "digitando" | Sim | Não | ❌ | D; presença | P3 |
| Grupos | Criar, adicionar, remover, sair, renomear | Sim | Funcional com permissão e tenant | ✅ / 🟡 visual | E | P1 |
| Grupos | Informações e Novo grupo no desenho | Sim | Botões com borda, checkbox nativo | 🟡 | E | P1 |
| Grupos | Foto do grupo; grupo da pasta "Abrir pasta" | Sim | Não | ❌ | Armazenamento; vínculo pasta + migration | P3 |
| Geral | Confirmação interna | Diálogo na janela | `window.confirm` | ❌ | B1 | P1 |
| Geral | Avisos de erro | Toast | `window.alert` | ❌ | B1 | P1 |
| Geral | Balão de dica | bj-dica | Igual, só na Central | ✅ | | |
| Geral | API para o Expediente (`despachar`, `conversar`, `abrir`, `alertar`) | 30 pontos | Nenhuma | ❌ | Coordenar com fidelidade-designer | P2 |

**Comandos ausentes, incompletos ou inacessíveis.** Ausentes: Destacar e "Abrir em janela separada"; no ⋮ Nova
conversa, Marcar todas como lidas, Central de Atenção, Resolvidas, Status, Volume, Receber toques, Avisar,
Enter envia, Receber ligações, Limpar lidas, Excluir todas, Ajuda; Pesquisar na conversa; ações no hover; editar,
excluir, fixar, favoritar, encaminhar; reações; @Todos e @IA; anexo, emoji, toque, ligar; menu do usuário;
Governança. Incompletos: Restaurar, filtros, cartões, recibo, busca de colega. Inacessíveis na demo: estados com não
lidas, menção e salto (sem mensagens novas reais).

**Comprovado.** Lançador (geometria, SVGs, sombra, hover, salto, pontos, "@n", balão, celular, abrir, minimizar):
7 testes comparativos com prova por mutação. Conversas 1:1 e grupos com permissão e tenant, menções com aviso,
responder, lida/não lida, fixar/silenciar/arquivar por pessoa, polling: 82 testes PHPUnit. Maximizar, mover,
encaixar, redimensionar, preferências locais.

**Protótipo × testes × demo.** O protótipo simula dados, presença, conta e IA. Os testes provam aparência com dados
simulados. A demo mostra a verdade dos dados (pouca atividade, nenhuma fonte de urgência): parece mais vazia que o
desenho mesmo onde o código é fiel.

## 4. Backend: o que existe e o que falta (relatório só leitura, 15/10)

| Tema | Existe | Falta |
|---|---|---|
| Presença | Nada útil: `user_tenant.last_login_at` só no login com 1 vínculo (`UserAuthenticator.php:64`); `UserProfile.status` é texto livre por usuário; sessão em arquivo; sem Mercure/WS/SSE | Última atividade por vínculo (ex.: gravar no `/central/novidades`), enum de status, migration, regra "on-line = < 60 s" |
| Sino | `Notificacao` (`src/Entity/Notificacao.php`): tipo string, sem prioridade nem origem genérica; 18+ tipos; leituras filtram só por usuário (dependem do TenantFilter); `/notificacoes/lista-dropdown` devolve HTML | Endpoint JSON com cursor e tenant explícito; regra de urgência; rechecagem de acesso |
| Urgência | Calculável: `Tarefa.prazo/status` (`AgrupadorDeMetas`: atrasadas, próximas ≤7 dias), `ProximaAcao.prazo`, `KanbanCard.dataVencimento`, `Chamado.prioridade`; não há aviso "vence hoje" nem notificação de cobrança | Uma regra única para Urgente/Atenção; sem entidade nova |
| IA | `ProvedorAnthropic` dormente (`IA_HABILITADA=false`, chave/modelo vazios); 3 camadas de disponibilidade | D1-a a D1-f |
| Anexos | `ArmazenamentoDeArquivos` + `CategoriaDeArquivo` (enum fechado, nenhum caso da Central); modelo de endpoint no Kanban | Categoria `CENTRAL_ANEXO`, entidade, migration, endpoints com `AcessoACentral::participacao`, purga; R2 mora na frente `r2-gravacao-dupla` |
| Permissões | `AcessoACentral` (vínculo ativo, participação, sem bypass) | Nada, para B1/B2 |
| Cadastro | Cargo e lotação no vínculo; foto no perfil; `findCargoPorColaboradores`/`findFotoPorColaboradores` | Ampliar `ContatoOutput` e a busca (sem migration). Nomes em MAIÚSCULAS são dado de cadastro (16 de 20 em prod), não setter |
| Pasta | Nenhum vínculo conversa↔pasta; acesso por `resource_access` + papel + bypass de super admin | `pasta_id` + migration + regra de entrada e de perda de acesso |

## 5. Riscos de segurança e privacidade

1. **Prévia de notificação após revogação de acesso.** O sino grava a notificação com a checagem do momento e depois
   só filtra por usuário: se o acesso à pasta for revogado, título e prévia continuam visíveis. Trazer isso para a
   Central exige rechecar acesso na leitura e tenant explícito em toda consulta.
2. **Isolamento por escritório.** Presença, contadores e notificações da Central precisam de `tenant` explícito (regra
   4 do `app/src/Central/CLAUDE.md`); o sino hoje depende do TenantFilter.
3. **Notificações de gestão** (`TIPOS_GESTAO`) têm aba própria com `podeVerGestao`: misturar na Caixa precisa respeitar.
4. **Bypass do PermissionChecker** (super admin, papel de sistema) conflita com "posse, sem bypass" da Central se for
   usado para grupo de pasta.
5. **"Equipe · em aberto"** expõe a carga de cada colega a todos; **recibo "Visualizada"** revela quando cada um leu;
   **presença** revela atividade.
6. **IA** envia dado de cliente à Anthropic: ZDR por escrito e base legal LGPD art. 33 (D1).
7. **Destacar:** mesma origem e mesma sessão; sem dado novo exposto. A janela fica por cima de tudo, inclusive numa
   tela compartilhada: avisar o usuário.
8. **Simulação ≠ produção:** nada de presença, notificação ou IA fictícia no sistema (D5).

## 6. Plano incremental

| Etapa | Escopo | Tipo | Aceite |
|---|---|---|---|
| **B1 Moldura** | Restaurar sem pulo; avatar no cabeçalho (sem ponto); menu ⋮ em painel de 272 px com seções e chaves, só itens funcionais; confirmação interna e avisos no lugar de `window.confirm`/`alert` | Visual, só frontend | Ver HANDOFF §11 |
| **B2 Destacar** | Document PiP + pop-up `window.open`; ícone e textos do desenho; estado e polling preservados; voltar ao fechar | Frontend | Ver HANDOFF §11 |
| C Lista e Painel | Avatar de grupo, linha, filtros Conversas/Revisar, vazios, Painel vazio, `bjcIn` do `central.css` | Visual + pouca função | Comparação visual por estado |
| D Conversa | Hover, cabeçalho clicável, Pesquisar (servidor, tenant), compositor, pílula, "Novas mensagens", Enter envia, Marcar todas como lidas | Visual + 1-2 endpoints | Visual + PHPUnit de isolamento |
| E Grupo | Informações e Novo grupo no desenho | Visual | Comparação visual |
| F1 Notificações na Caixa | Endpoint JSON com tenant e rechecagem; urgência derivada; contador vermelho; filtros; cartões; Painel | Backend + tela | Isolamento, revogação, contador com dado real |
| F2 Presença | Última atividade pelo polling; status manual; pílula, pontos, "visto por último" | Migration | Isolamento, expiração |
| F3+ | Equipe em aberto; recibo; ações de mensagem; grupo de pasta; anexos (após R2); IA (D1); ligações | Novas funções | Spec própria cada |
