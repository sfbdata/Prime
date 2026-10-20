# Relatório mensal de pastas paradas (desenho 02, R226)

Origem: desenho `docs/design/redesign-19-10-26/02 - EXPEDIENTES - VS 18.10.26.dc.html` L4694-4831 e
`bluejus-central.js` L3238-3246; pedido R226 (`ESPECIFICACAO_ORIGINAL_FARLEI_BLUEJUS.md` L304).
Frente `pastas-paradas`, empilhada sobre o #75 (`relatorio-processos-controladoria`, da 0d), que traz
`GeradorDePdf`, `PastasVisiveisNoRelatorio`, a flag `UserTenant::controladoria` e `findUsuariosDaControladoria`.
Risco BAIXO. Decisões do coordenador em 20/10/2026.

## Estado da frente

- 20/10: implementado (d5d62580, ca4835f1). Página `/expediente/pastas-paradas` e PDF; comando
  `app:pastas:relatorio-pastas-paradas`; tabela `relatorio_pastas_paradas_envio` (FK ON DELETE CASCADE e linha na
  purga). Entrada só pelo link do aviso: no desenho, a abertura vem só de ações do chat da I.A, sem menu.
  No PDF o ⏸ é desenhado em CSS (as fontes do Dompdf não têm o U+23F8); ` · tribunal` sai quando não há sigla;
  a linha "Andamento" sai quando falta a data de entrada na fase.

## O que é

Uma vez por mês (primeira segunda-feira, a partir das 10h, como o #75), o sistema avisa no sino e na Caixa
da Central quais pastas ativas estão sem movimentação há mais de 30 dias, para cada responsável dizer se
a pasta vai receber movimentação ou ser arquivada. O aviso leva a uma página "Pastas paradas" com o
relatório e o botão de baixar o PDF (tarja azul, logo, como o #75).

## Cálculo (por pasta ativa, não excluída, do escritório)

- **Última movimentação** = a data mais recente entre:
  - `MovimentacaoProcesso.dataMovimentacao` dos processos vinculados (PastaProcesso);
  - `PublicacaoDjen.dataDisponibilizacao` pelos números dos processos vinculados (é o "Push" da aba da pasta);
  - `PastaMensagem.criadaEm` só dos registros de pessoas (`sistema IS NULL`, como o #75);
  - a data de entrada na fase atual do andamento (`PastaAndamento.marcos.em[atual]`).
- **Parada** = dias desde a última movimentação; sem nenhuma, dias desde a abertura (`dataAbertura`).
  Entra quem tem parada > 30 dias (`LIMITE_DIAS = 30`).
- **Sai da lista** a pasta cuja fase atual é a última da via (andamento encerrado). Sem andamento gravado,
  sai a pasta cujo processo principal tem situação que casa `/arquiv|baix|transit|extint/i` (a mesma regra do
  `AndamentoDaPasta::inferir`).
- **Nível**: > 90 dias "Crítico" (#a3232b), > 60 "Atenção" (#b06a00), senão "Acompanhar" (#0a6a96).
- Ordem: mais parada primeiro.
- Tudo em lote (consultas por lista de ids, com `tenant` explícito: o CLI roda sem TenantFilter). Sem N+1.

## Pré-análise (textos exatos do desenho)

- `Pasta aberta em dd/mm/aaaa, hoje faz N dias.` / `Data de abertura não informada.`
- `Última movimentação em dd/mm/aaaa (N dias parada).` / `Nenhuma movimentação registrada desde a abertura.`
- `Andamento: na fase "<nome>" há N dias (desde dd/mm/aaaa).` (sem andamento gravado, a linha sai)
- `Sem processo vinculado: não há número de processo cadastrado.` / `Processo <CNJ> consta como suspenso.`
  (situação casa `/suspens/i`; senão nenhuma linha de processo)
- `Contrato: pró-bono.` (`isProBono`) / `Contrato assinado.` (`situacaoContrato` REGULAR) / `Contrato não juntado.`
- `Falta juntar: X e Y.` / `Nenhum documento juntado. Falta: X e Y.`, com X e Y entre `Procuração` e
  `Contrato de honorários` (categorias PROCURACAO e CONTRATO de `PastaDocumento`, não excluídos; o catálogo
  `CatalogoDeDocumentos` também reconhece pelo título). Sem falta, a linha sai.
- `Financeiro: <resumo>.`, com o resumo de `PastaPagamento`: `nenhum lançamento`, `N pagamento(s) vencido(s)`
  ou `em dia`. **Só para quem pode ver o financeiro da pasta** (`VisibilidadeDoFinanceiroDaPasta`); para os
  demais, a linha e a sugestão do financeiro saem.

## Sugestões (textos exatos, nesta ordem, quando valem)

1. `Confirmar com o cliente a assinatura do contrato e juntar à pasta.` (contrato não juntado)
2. `Ver com o Financeiro se o pagamento já foi feito e dar a baixa no sistema.` (vencido ou nenhum lançamento; só quem vê o financeiro)
3. `Solicitar ao cliente os documentos faltantes.` (falta documento)
4. Uma de: `Verificar se a ação já foi distribuída e vincular o processo, ou decidir pelo arquivamento.` (sem processo) /
   `Conferir o motivo e o prazo da suspensão no PJe e registrar no expediente.` (suspenso) /
   `Consultar o andamento no PJe e registrar a providência.` (demais)

## Página e PDF

- Página no domínio Pasta ou Expediente (seguir o `RelatorioDeProcessosController` do #75: modos página e
  PDF numa folha só, `GeradorDePdf`), guarda `canAccessModule('expediente')` e só as pastas que o leitor vê
  (`PastasVisiveisNoRelatorio`).
- Folha: 4 cartões (`Pastas paradas`, `Mais de 90 dias`, `Sem contrato`, `Sem processo`); o texto
  `Relação das pastas sem movimentação há mais de 30 dias em dd/mm/aaaa. Para cada uma, informe ao responsável se a pasta deve **receber movimentação** ou ser **arquivada**.`;
  tabela `Pasta · Cliente · Responsável · Processo · Última mov. · Parada` (cabeçalho #0b5f86; `⚖ número` ou
  `sem processo`; `nenhuma`; chip `⏸ N dias`); um cartão por pasta (`⏸ Pasta N · Cliente`, chip do nível,
  `Responsável: … · ⚖ proc · tribunal`, seções `Pré-análise` e `Sugestão`, linha
  `☐ Movimentar  ☐ Arquivar  Responsável pela decisão: ____________________`).
- Título do PDF: `Pastas paradas há mais de 30 dias · dd/mm/aaaa`.
- **Rodapé (desvio pela regra 1, ligado ao item 8 do pacote do dono)**: o desenho diz "Pré-análise gerada pela
  BlueJus I.A a partir dos dados do sistema; …". A pré-análise é por regra, sem modelo, e a regra 1 da
  Inteligência proíbe atribuir à I.A. Texto usado, numa constante só:
  `Pré-análise gerada a partir dos dados do sistema; não substitui a conferência no PJe. Relatório enviado automaticamente na primeira segunda-feira do mês, às 10h.`

## Envio automático

- Comando `app:pastas:relatorio-pastas-paradas` (`--dry-run`, `--tenant`), lock próprio
  `jusprime-relatorio-pastas-paradas.lock`, mesmo molde do `RelatorioSemProcessoCommand`.
- Cron diário às 10h15 com `CRON_TZ=America/Sao_Paulo` (linha no DEPLOY.md deste PR); o UseCase só envia na
  primeira segunda-feira do mês, a partir das 10h, e uma vez por mês.
- **Uma vez por mês**: tabela `relatorio_pastas_paradas_envio` (tenant, `mes` MM/AAAA, contagens, `em`),
  UNIQUE (tenant_id, mes), migration `Version20261021190000` (reservada pelo coordenador). A gravação vem
  ANTES de enviar, com `INSERT ... ON CONFLICT DO NOTHING`: quem não inseriu não envia (dois crons não duplicam).
  Fora do audit_log, como a tabela do #75; entra no `PurgarEscritorioUseCase`.
- Sem pasta parada, não há aviso (o desenho mostraria "Nenhuma pasta parada há mais de 30 dias. 👍", que
  aparece na página).
- **Avisos** (tipo `relatorio_pastas_paradas`, registrado em `TiposDeAvisoDaCentral`; aviso do sistema, não fala da I.A):
  - para cada responsável com vínculo ativo e módulo Expediente: título `Pastas paradas: N sob sua responsabilidade`;
    texto `Pasta N (cliente), X dias. <primeira sugestão>` unidos por ` | `, terminando em
    ` Informe se vai movimentar ou arquivar.` (sem a sugestão do financeiro), cortado em 255;
  - para Controladoria e primários com módulo Expediente, sem repetir: título `Relatório mensal: N pasta(s) paradas`;
    texto `N · cliente · X dias · responsável` unidos por ` | `, cortado em 255;
  - link para a página.

## Fora

- Envio manual (o desenho só tem o automático e o baixar PDF).
- Endpoint `/api/pastas/paradas` do comentário do desenho (a página já serve).
