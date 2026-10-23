# Dashboard, parte 1 (PR1)

Risco BAIXO (Dashboard, leitura). Desenho: `01 - Dashboard 1.2.2.dc.html` (claude-design-2026-10-05)
e o README do handoff (seções "Período padrão", "Troféu de melhor resultado", "Som do campeão",
"Botão direito no colaborador"). Itens do inventário: DASH-001, DASH-003, DASH-011, parte da DASH-019.

## Escopo deste PR

1. **Período padrão "Este mês"**: sem `data_de` e sem `data_ate` na URL, o painel abre de 1º do mês
   até hoje. "Limpar filtros" só aparece quando o filtro difere disso (outro período, datas manuais,
   responsável, cargo ou busca) e volta para Este mês (não para "sem período").
2. **Coluna extra "Relatório"** (chave `relatorio`, tipo soma, no "Adicionar coluna"): registros por
   AUTOR, criados no período, somando três tabelas do escritório:
   `pasta_mensagem` (Dados da pasta, só `sistema IS NULL`), `pasta_observacao_financeira` e
   `pasta_observacao_detalhes`. Resposta conta (é um registro); edição não conta de novo (a linha é a
   mesma). Pasta excluída (lápide) e pasta oculta pelo cadeado para quem olha ficam fora.
3. **Troféu** (`bi-trophy-fill`, 11.5px, `#c49a3a`, depois do nome; tooltip do desenho "Mais
   metas concluídas no período") para quem tem **mais metas concluídas** no período = Total metas −
   Metas ativas da pessoa, a mesma régua das colunas do painel (metas criadas no período). Desempate:
   **menos metas vencidas** (hoje), como no script do desenho. Universo: todos os colaboradores ativos
   do escritório, calculado antes de busca, responsável e cargo (o troféu não muda de dono ao filtrar
   a tabela). Ninguém recebe com zero.
4. **Ranking no cartão do colaborador** (botão direito no nome; janela flutuante do desenho):
   cabeçalho azul com avatar, nome e "cargo · Nº de M no período", seletor Mês · Trimestre ·
   Semestre · Ano e as linhas do desenho com as metas concluídas e os registros ("Comentários").
   Mesma regra do troféu, na janela escolhida. A nota mostra o total da equipe (meta com 2
   responsáveis conta 1 vez, como no card Meta global). Id fora do escritório: 404 (IDOR).
5. **Som do campeão**: fanfarra do desenho (Web Audio, sol 392 Hz + dó 523 Hz com terça), no hover do
   troféu do campeão, no máximo 2 vezes por carregamento e 1 a cada 1,4 s. Desliga pela preferência
   que já existe, "Sons" do menu ⋮ (`dashboard.sons`, README: "sons (calendário e campeão)"), e pelo
   "Sons de alerta" do Meu Perfil. Sem chave nova no catálogo.

## Regras registradas (decisão do dono)

- Troféu e ranking: "mais concluídas" (total − ativas), desempate menos vencidas.
- Relatório: por autor, pela data de criação; resposta conta.
- Setas e pílula de percentual comparam com o **período anterior equivalente** (comportamento atual,
  sem mudança neste PR). Desligar as setas em "Exibição" fica para o PR2.
- Zerar (PR3): qualquer coluna pode ser zerada; só o Master zera ou desfaz; quem não é Master vê
  "Pedir para zerar", que vai ao primário e à Controladoria.
- Pastas concluídas (PR3) = "Demandas encerradas no período" do desenho, lida como "arquivadas no
  mês": desarquivar tira da conta e pasta excluída não conta.

## Padrões PROVISÓRIOS (até o dono responder)

- **Empate total** (mesmas concluídas e mesmas vencidas): **ninguém recebe o troféu** e o ranking
  divide a posição (1º, 1º, 3º). O desenho é omisso.
- **Janelas do cartão**: calendário corrido até hoje. Mês = 1º do mês; Trimestre = 1º dia do
  trimestre civil; Semestre = 1º/jan ou 1º/jul; Ano = 1º/jan. Abre em Mês.
- **Período do troféu** = o período do painel; do cartão = a janela escolhida nele.
- "Relatório" sem link (a lista da pessoa é a DASH-012) e sem seta de tendência.

## Fora deste PR

Zerar (DASH-014), meta de pastas (DASH-016), pastas concluídas (DASH-013), setas × ontem, histórico
de "1º do mês" (`dashboard_campeao_mes`) e o bloco "Rendimento" do cartão (D-EQUIPE), lista do
Relatório (DASH-012).

## Fatias seguintes

- **PR2**: setas × ontem e a opção de desligar as setas em "Exibição".
- **PR3**: zerar e pastas concluídas, com a migration `Version20261023150900` com `pasta.arquivada_em`, `dashboard_zeramento` e
  `dashboard_meta_pastas`.
- **Contrato com o bluejus-bd**: o comando `app:dashboard:fotografar` é idempotente, `--data` tem
  padrão hoje, nenhuma opção é obrigatória, roda 1x por dia.
