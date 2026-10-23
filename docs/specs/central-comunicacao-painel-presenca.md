# Central de Comunicação: Painel "Equipe · em aberto" e presença (F2)

Autorizado pelo dono em 16/10/2026: "primeiro cuide da aba PAINEL, está faltando mostrar os usuários e poder abrir
conversa com eles como está no desenho". Decisões tomadas por ele na mesma data:

- **"Em aberto" como no desenho, visível para todos** do escritório (mensagens não lidas na Central + notificações
  não lidas do sino de cada colega). Ele sabe que isso mostra a carga de cada colega a todos (HANDOFF §11.2 recomendava
  restringir; decisão dele foi seguir o desenho).
- **Presença junto (F2)**, com migration.

Merge no `master` só depois da aprovação dele na demo.

Risco: MÉDIO (tabela nova ligada a usuário × escritório, dado de atividade exposto à equipe). Não mexe em `User`,
`Tenant` nem `UserTenant`.

## 1. Fonte no desenho (`bluejus-central.js`, pacote de 13/10)

- Painel, `telaPrioridades` (L5632-5658): abaixo dos cartões, "Equipe · em aberto" + "N no total"; uma linha por
  usuário do escritório (inclusive "você"), ordenada por em aberto desc e nome; avatar 26 com ponto de status; nome;
  "função · status"; contador (≥10 vermelho `#fdeceb/#a3232b`, >0 azul `#e5f1f7/#0a7aad`, 0 cinza `#eef2f5/#9aabb6`);
  ícone de conversa. Clicar em outra pessoa abre a conversa com ela (aba Caixa de entrada); a própria linha não clica.
- Presença (L264-345): batimento a cada 20 s; on-line = último batimento há menos de 60 s; status manual
  Disponível `#3fd17f`, Ocupado `#e5484d`, Ausente `#f2a93b`; "Não perturbe" força Ocupado; off-line `#b7c4cd`, "Off-line",
  "visto por último hoje às HH:MM"; on-line com "desde HH:MM".
- Cabeçalho da lista (L6337): ponto do MEU status no avatar e pílula "N on-line" (eu incluído), com dica dos nomes;
  clicar mostra a lista "N on-line agora" / "N off-line" (L6380-6386).
- ⋮ "Meu status" (L6264): item "Status" com ponto e valor, alterna Disponível → Ocupado → Ausente.
- Conversa 1:1 (L6334): subtítulo "on-line · setor · função".

## 2. Regras

1. **Presença é por vínculo (usuário × escritório).** Tabela nova `central_presenca`: `tenant_id`, `usuario_id`,
   `ultima_atividade`, `status` (`disponivel|ocupado|ausente|inativo`; `inativo` desde 19/10, ver regra 3), `status_desde`; único `(tenant_id, usuario_id)`. Quem
   tem dois escritórios tem presença separada em cada um. Entra na purga de escritório.
2. **Batimento:** `POST /central/presenca {status}` (CSRF `ajax`) a cada 20 s enquanto houver algo do BlueJus **à
   vista** (a página com a Central ou a Central destacada), e na hora em que o status muda. Aba em segundo plano não
   bate (revisão de 16/10: senão a pessoa ficaria "on-line" a noite toda e a sessão nunca expiraria); o relógio é o da
   janela à vista, como o do polling. Exige vínculo ativo
   (`AcessoACentral::exigirVinculoAtivo`). Grava com `INSERT ... ON CONFLICT DO UPDATE` (duas abas ao mesmo tempo não
   duplicam). `status_desde` só muda quando o status muda. Status fora da lista = 422. Desde 19/10 (spec
   `presenca-e-ponto-no-cabecalho.md` §2) a resposta é `{status, online: [{id, status, semAtividadeMin}]}`: quem está
   on-line no escritório (colegas com vínculo ATIVO, mesmo cálculo de 60 s), só ids, status e minutos, para o selo do
   cabeçalho não pedir a equipe a cada 20 s. `semAtividadeMin` só no `inativo`.
3. **Status efetivo** vem da tela, nesta precedência (emenda de 19/10, spec `presenca-e-ponto-no-cabecalho.md` §2):
   `ocupado` com "Não perturbe" ligado; `inativo` sem atividade há mais de `Presenca::INATIVO_APOS_MINUTOS` (60 min);
   senão o manual (preferência deste navegador, como no desenho, lida do armazenamento a cada uso para que todas as
   abas concordem). A atividade é um instante só em `bj-central-ativ:<userId>` (todas as abas e a janela destacada);
   o `storage` dessa chave não bate; a volta da inatividade bate na hora. O ponto do meu cabeçalho e o ⋮ mostram o
   MANUAL (L6337); o batimento leva o efetivo. A Central mostra `inativo` como o Ausente dela.
4. **On-line** = `ultima_atividade` há menos de 60 s (relógio do servidor). Off-line não mostra o status manual.
   "Visto por último" = `ultima_atividade` (nunca deu batimento: "sem atividade recente").
5. **Equipe:** `GET /central/equipe` devolve todos os colegas com vínculo ATIVO no escritório (eu incluído, marcado):
   id, nome, cargo, foto, em aberto, presença. Ex-colega (vínculo inativo) não aparece.
6. **Em aberto (por colega)** = mensagens da Central não lidas por ele (mesma regra de não lida da Central: de outra
   pessoa, não de sistema, acima do cursor, participação ativa; silenciadas contam, como no desenho) + notificações do
   sino não lidas dele **neste escritório**, menos os avisos da própria Central (menção, entrou no grupo), que já contam
   como mensagem. Só números: nenhum título, texto ou conversa alheia sai.
7. **Tenant explícito** em toda consulta nova (regra 4 do `app/src/Central/CLAUDE.md`): presença, não lidas por
   colega e notificações por colega filtram `tenant = :tenant` além do `TenantFilter`; prova com o filtro desligado.
8. **Frequência:** com a janela aberta, a tela pede `/central/equipe` a cada 20 s (junto do batimento); com ela
   fechada, só o batimento. O polling de mensagens não muda.

## 3. Tela

- Painel: seção "Equipe · em aberto" (medidas e cores do desenho) abaixo dos cartões; clicar abre a conversa (cria a
  individual se não existir, o mesmo caminho da busca de colega).
- Cabeçalho da lista: ponto do meu status efetivo no avatar e pílula "N on-line"; clicar abre a lista "on-line agora /
  off-line" com "Voltar".
- ⋮ "Meu status": "Status" (ponto + valor) antes de "Não perturbe".
- Conversa 1:1: subtítulo "on-line · cargo" (ou só o cargo).

**Fora (não existe no BlueJus):** na lista de on-line, as linhas "Todos" e "BlueJus IA" do desenho; setor (só há
cargo); cartões Urgente/Atenção/Metas e a lista de notificações do Painel (F1, não autorizada).

## 4. Testes

- PHPUnit: batimento (cria, atualiza, `status_desde`, status inválido, sem vínculo, CSRF); equipe (só ativos, eu
  marcado, em aberto somando Central + sino, on-line/off-line pelo relógio); isolamento com o filtro desligado (presença,
  não lidas e sino de outro escritório não contam); purga.
- Playwright comparativo: linha da equipe, contador nas três faixas, ponto de status e pílula iguais ao protótipo;
  clicar abre a conversa; `/central/*` simulado.
