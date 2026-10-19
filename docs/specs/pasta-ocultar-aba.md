# Ocultar aba da pasta (PR 2 de 3 da frente "Expediente: Processo e Push")

## Estado da frente (sessão bluejus-0d; atualizado 19/10/2026)

Releia esta seção antes de continuar se a sessão for compactada.

**Pedido do dono (19/10):** implementar na íntegra o desenho das abas Processo e Push Processual (02 - EXPEDIENTES),
mesmo que precise de frente nova.

**PRs abertos e ordem de merge (fila de migrations do coordenador):**
1. #55 `push-link-externo`: link externo do Push. Migration Version20261019183000. Entra depois do #52 (de outra
   sessão); o #77 (trusted proxies, b5) também entra antes por mexer no framework.yaml.
2. #68 `pasta-ocultar-aba`: EMPILHADO sobre o #55 (base push-link-externo). Migration Version20261019200000.
3. #75 `relatorio-processos-controladoria`: base master. Migration Version20261019233000.
4. #82 `push-painel-monitoramento` (P4a): base master. Migration Version20261019234500.
5. P4b `push-avisar-quando-chegar`: em implementação, EMPILHADO sobre o #82.
Depois de cada merge: trazer o origin/master para as branches que ficaram, testes, push. Renomear a migration para
o timestamp do momento se o coordenador pedir.

**Decisões do dono (19/10/2026):**
- Controladoria = pessoas marcadas no escritório (só Master/primário marca).
- Ocultar aba vale só na pasta; é para ORGANIZAR a pasta, não restringir acesso no sistema (módulo Push global,
  links já criados e metas em /tarefas continuam).
- Renovação do link externo por aviso no sino; link curto já ativo (sem "ativa com o domínio").
- Envios automáticos ao cliente nascem DESLIGADOS (P4d).
- Pausar monitoramento para só a busca por número; OAB continua; legenda "A busca pelo número do processo fica
  parada; intimações pela OAB continuam chegando.".
- (coordenador) Prazo da previsão conta da publicação (1º útil após a disponibilização), Lei 11.419 art. 4º e CPC
  art. 224; e-mail com "não quero mais receber"; sem Controladoria envia e avisa o primário; remetente nao-responda@
  com reply-to do responsável; P4e com OCR.

**Contratos com outras sessões:**
- security.yaml: cada rota pública em linha própria (f6 e a7 também acrescentam).
- framework.yaml: limitadores em trechos próprios (#77, #55, #68, #82).
- `email_descadastro` (Shared, P4b): interface pública reaproveitada pela a7 (vagas) e pela frente "Comercial e
  e-mails".
- user_tenant: a 7d põe `situacao`; o #75 põe `controladoria`. Colunas independentes.

**Pendências com o dono:** chave "Análise da BlueJus IA" do P4b (padrão desligado, a confirmar); MAILER_DSN de
produção pela Hostinger (SPF/DKIM); cron mensal do relatório (DEPLOY.md); SYMFONY_TRUSTED_PROXIES (achado ALTO,
tratado no #77).

**Próximo passo:** fechar o P4b (revisão, visual, PR); depois specs de P4c (e-mails do cliente + envio manual da
Análise IA com CC), P4d (envio automático), P4e (arquivos + OCR + análise completa; trazer antes o PR da 4b por
causa do composer). "Vincular apensado" e o filtro por processo esperam o zip completo do desenho de 18/10.


Desenho: `docs/design/claude-design-2026-10-05 (1)/02 - EXPEDIENTES 1.2.3.dc.html` (no checkout principal):
menu da aba `abaMenu` L4409-4419, regras `abaOcultaPara`/visibilidade L4372-4387 e L6075, ícone na aba L1243 e
L6088, modal "Ocultar para…" L262-279 (lógica `abaVisVals` L4420-4427). Empilhada sobre `push-link-externo` (#55):
usa o menu da aba Push e o link externo.

Risco MÉDIO (decide quem vê o quê). Revisão de permissão obrigatória.

## Decisões

- D1 (dono, 19/10): a regra vale **só nesta pasta** (o protótipo gravava por aba em todas as pastas).
- D2 (dono, regra geral): Master (`ROLE_SUPER_ADMIN`) pode tudo. Quem configura = **Master ou primário ativo
  do escritório** (`RegraDosPrimarios::ehMaster` / `ehPrimario`; o desenho diz "usuário primário"). Quem configura
  **nunca** é ocultado (desenho L4377: o primário nunca é ocultado).
- D3 (orquestrador): ocultar é barreira de servidor, não só CSS. Para quem está oculto, a aba e o painel não são
  renderizados e as rotas daquela aba respondem 403 (§4).

## 1. Modelo

`pasta_aba_oculta` (`App\Pasta\Entity\AbaOcultaDaPasta`, `TenantAware`, **`Auditavel`**: quem escondeu o quê de
quem é dado de governança): id, tenant, pasta (FK cascade), aba (varchar 20, enum `AbaDaPasta`: dados, tarefas,
processo, financeiro, detalhes, documentos, push), para_todos (bool), usuarios (json list<int> de user ids),
atualizado_por (FK user, SET NULL), atualizado_em. UNIQUE (pasta_id, aba). Sem linha = visível para todos.

Regra (`VisibilidadeDasAbasDaPasta::ocultaPara(Pasta, AbaDaPasta, User): bool`): falso para Master e primário;
verdadeiro se `para_todos` ou se o id do usuário está em `usuarios`. Um método que devolve o conjunto de abas ocultas
para o usuário numa pasta (uma consulta só) para o show.

## 2. Casos de uso

1. `OcultarAbaParaTodosUseCase(pasta, aba, quem)`: grava `para_todos=true, usuarios=[]`.
2. `DefinirQuemNaoVeAbaUseCase(pasta, aba, quem, int[] userIds)`: ids filtrados para vínculos ATIVOS deste tenant e
   que não sejam primários nem Master (ignorados em silêncio). Nenhum → apaga a regra; todos os elegíveis → `para_todos`;
   alguns → lista.
3. `MostrarAbaParaTodosUseCase(pasta, aba, quem)`: apaga a regra.
Os três exigem `quem` Master ou primário (senão exceção → 403) e pasta do tenant da sessão.
4. `ListarEquipeParaOcultarAbaUseCase(pasta, aba)`: vínculos ativos do tenant menos primários/Master, com nome,
   "função · setor" (`UserTenant.cargo`/`lotacao`, sem N+1) e `oculta` (bool) pela regra atual; mais `total`.

## 3. Rotas (`App\Pasta\Controller\PastaAbaOcultaController`, prefixo `/pasta/{id}/abas/{aba}`)

`GET  /equipe` `pasta_aba_equipe` (JSON da lista); `POST /ocultar-todos`, `POST /ocultar-para` (`usuarios[]`),
`POST /mostrar`. CSRF `pasta_aba_oculta_{pastaId}`. Guarda: tenant da pasta (404), VIEW na pasta (403), quem
configura (403 com `{erro: 'Só o usuário primário pode ocultar abas'}`), aba válida (404). Resposta dos POST:
`{sucesso, aba, rotulo, paraTodos, quantidade, mensagem}` com a mensagem do toast do desenho:
"<Rótulo> oculta para toda a equipe" / "<Rótulo> oculta para N pessoa(s)" / "<Rótulo> visível para todos".

## 4. Barreira nas rotas (listener `BarreiraDeAbaOcultaListener`, kernel.controller)

Mapa nome de rota → aba, para rotas com `{id}` = pasta:
- push: `pasta_push_teor`, `pasta_push_texto`, `pasta_push_lida`, `pasta_push_links_*`, `inteligencia_push_*`
- financeiro: `pasta_financeiro_*`, `pasta_pagamento_*`, `pasta_alternar_situacao_contrato`,
  `pasta_atualizar_pro_bono`, `pasta_atualizar_valor_causa`
- documentos: `pasta_documento*`, `pasta_secao*`, `pasta_secoes_*`, `pasta_peticionar*`
- detalhes: `pasta_detalhes_*`
- processo: `pasta_vincular_processo`, `pasta_desvincular_processo`, `pasta_definir_processo_principal`,
  `pasta_administrativa_definir`
- tarefas: `pasta_prazo_*` (as rotas `tarefa_*` são da meta, não da pasta: fora)
Confira os nomes com `debug:router`; rota que não existir sai do mapa. Oculta para o usuário → 403. O mapa é testado
contra o roteador (todo nome/prefixo do mapa casa com pelo menos uma rota).

Também: `VisibilidadeDoFinanceiroDaPasta::podeVer` fica falso com a aba Financeiro oculta (pendência, timeline,
impressão, extrato, menção de origem financeiro seguem a regra única). A impressão/resumo omite as seções de abas
ocultas que ela já separa (financeiro; processo/movimentações se a aba Processo/Push estiver oculta, se a impressão
tiver a seção).

## 5. Tela

- Para quem está oculto: botão da aba e painel não são renderizados; se a aba Dados estiver oculta, a primeira
  visível fica ativa; `#push` na URL com push oculto cai na primeira visível. O item "Link externo do Push" do ⋮ não
  aparece com push oculto. Nenhuma aba visível: aviso "Nenhuma aba disponível para você nesta pasta."
- Para quem configura: aba com regra ganha o ícone `bi-eye-slash` vermelho no canto (L1243), tooltip
  "Oculta para toda a equipe" / "Oculta para N pessoa(s)" (L6088).
- Botão direito em QUALQUER aba, para quem configura: menu com título "<RÓTULO>" + " · oculta para N" (ou
  " · oculta para toda a equipe") e itens "Ocultar para toda a equipe" (`bi-eye-slash`), "Ocultar para…" (`bi-people`),
  "Mostrar para todos" (`bi-eye`, só com regra). Na aba Push os itens do link externo (#55) vêm depois, no mesmo menu.
- Botão direito para quem NÃO configura: na aba Push, só o menu do link (#55); nas outras, aviso
  "Só o usuário primário pode ocultar abas".
- Modal "Ocultar aba <Rótulo>" / "Quem estiver marcado deixa de ver esta aba": linha "Toda a equipe (N)" com
  checkbox de três estados (clicar alterna todos/nenhum); lista com nome, "função · setor", checkbox e etiqueta
  vermelha "OCULTA" para marcados; "Cancelar" e "Salvar". Depois de salvar, recarrega a página (o cabeçalho é
  renderizado no servidor) e mostra o toast.
- JS em `public/js/pasta-abas-ocultas.js` (vanilla, IIFE, só textContent), CSS `public/css/pasta-abas-ocultas.css`.
  O menu da aba Push do #55 (`pasta-push-link.js`) passa a compor os itens de ocultar quando quem abre configura:
  definir no JS um ponto de extensão simples (ex.: o `pasta-abas-ocultas.js` injeta os itens no topo do
  `#pushLinkMenu`), sem duplicar menus.

## 6. Testes mínimos

Unit: regra `ocultaPara` (todos, lista, Master e primário nunca), os 3 UseCases de escrita (filtra ids inelegíveis,
nenhum → apaga, todos → para_todos, quem não configura → exceção), listagem. Functional: guarda das rotas (outro
tenant 404, sem VIEW 403, não primário 403, CSRF, aba inválida 404); show para oculto não tem `#push-tab` nem `#push`
(e idem financeiro); show para primário tem a aba e o ícone; barreira 403 em ao menos uma rota de cada aba do mapa;
mapa casa com o roteador; financeiro oculto some a pendência de pagamentos; push oculto tira o item do ⋮ e bloqueia
`pasta_push_links_gerar`. JS: sem innerHTML, contrato de classes com o template.

## 7. Decisões da revisão de permissão (19/10)

Alcance decidido pelo dono (19/10/2026): **ocultar a aba é para ORGANIZAR a pasta, não para restringir o acesso
no sistema todo.** Quem não pode ver um conteúdo de jeito nenhum fica sem a permissão do módulo no perfil. Por isso:

- O módulo global `/push-processual` continua governado pela permissão do módulo. A regra de aba oculta vale
  dentro da pasta. (decidido pelo dono)
- Os links externos do Push já criados continuam ativos quando o Push é ocultado para alguém. (decidido pelo dono)
- As metas fora da pasta (`/tarefas`) continuam visíveis para quem tem a aba Metas oculta. (decidido pelo dono)
- O número do processo continua no cabeçalho da pasta (e no cabeçalho da folha impressa) com a aba Processo oculta.
  (decidido pelo coordenador: é a identificação da pasta)
- "Todos marcados" no "Ocultar para…" grava `para_todos`, como no desenho (vale também para quem entrar depois).
  (decidido pelo coordenador: comportamento do desenho)

Já decididas e implementadas nesta revisão:

- Análise de IA da pasta só aparece para quem lê se nenhuma aba de onde ela tirou conteúdo estiver oculta para
  essa pessoa (`VisibilidadeDaAnaliseDaPasta::abasDaAnalise`): resumo do Push → Push; agente → as abas das seções
  que ele lê, menos as que estavam ocultas para quem pediu (`contexto_resumo.abasOcultas`); quem leu documentos com
  o Financeiro visível depende também do Financeiro (contratos). Bloqueada = 404 por id.
- O contrato de honorários (`CATEGORIA_CONTRATO`) é dado do Financeiro: com ele oculto, sai do explorador, do
  cartão do trilho, da contagem da aba, do Histórico, do contexto da IA e dos documentos do Agente de Pastas, e por
  id dá 404 (`VisibilidadeDasAbasDaPasta::documentoVisivel`).
- Checklist de documentação é da aba Documentos (mora no explorador dela); os réus (janela Cadastro) não são de aba.
- Aceito na re-revisão: quem tem aba oculta e pede uma análise de agente pode receber o id da análise PENDENTE de
  outra pessoa (idempotência por agente). Não vaza conteúdo: a pendente não tem texto, e por id ela segue a regra
  de leitura (404 se tirou conteúdo de aba oculta para quem lê).
- Ações em lote com contrato de honorários e o Financeiro oculto: o id do contrato responde como id inexistente
  (404 "Documento não encontrado.", o mesmo do id que não existe), e a seção baixada em .zip não o leva.
