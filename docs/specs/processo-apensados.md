# Processos apensados, filtro por processo no Push e registro de fase administrativa (P4f)

Desenho de referência: `docs/design/redesign-19-10-26/02 - EXPEDIENTES - VS 18.10.26.dc.html` (checkout principal,
fora do git). Linhas citadas como N:. Botão N:1710, `procAbrirApenso` N:4517, modal N:289-354 e `pvVals`
N:3135-3151, salvar `procSalvarVinc` N:4535-4563, `apensosDe` N:4503, cartão N:1725 e N:6711, aba Dados
N:1431-1455 e N:7000, timeline `SYS.proc` N:6168 e N:6320, Push `pushDaPasta` N:5483, chips `procFiltros`
N:5683 e N:2546-2548, estados vazios N:5681-5682, `spMarcar` N:4761. Risco BAIXO.

## 1. Modelo

`pasta_processo` ganha `tipo_apenso` (varchar 40, nullable). O vínculo `principal = true` é o processo principal;
os demais são apensados, e `tipo_apenso` guarda o tipo escolhido (Embargos, Agravo de instrumento, Cumprimento de
sentença, Execução, Impugnação, Recurso, Cautelar, Outro; enum `TipoDeApenso` com o rótulo exato do desenho).
Vínculo antigo sem principal e sem tipo continua valendo (rótulo "Outro"). Não há processo pai: o vínculo é pela
pasta, como no desenho (N:2977-2979).

## 2. Aba Processo

- Botão "Vincular apensado" (`bi-paperclip`, dica "Vincular processo apensado (embargos, agravo, execução…)") entre
  "Vincular processo" e "PETICIONAR NA PASTA" (N:1710). Abre o MESMO modal de "Vincular processo"
  (`#modalVincularProcesso`) em modo apenso: título "Vincular apensado", select "Tipo de apenso *" (padrão
  Embargos), botão final "Vincular apensado". As duas abas "Buscar processo existente" / "Cadastrar novo (CNJ)"
  continuam as do sistema.
- Rota: a mesma `pasta_vincular_processo` com o campo `modo=apenso` e `tipo_apenso`, ou rota irmã; validações do
  desenho (N:4535-4553): "Selecione um processo para vincular.", "Este é o processo principal da pasta.",
  "Este apensado já está vinculado.". Vincular apensado sem principal é permitido (N:4555).
- Cartão do apensado (N:1725): etiqueta cinza `bi-link-45deg` "APENSADO · {TIPO}" no lugar de "PRINCIPAL", botão X
  "Desvincular este apensado" com confirmação vermelha "Desvincular apensado" / "Desvincular o apensado X da pasta?
  O registro fica no histórico." / "Desvincular". Corrigir o defeito do protótipo: o ⋮ "Desvincular da pasta" do
  cartão do apensado desvincula O APENSADO (no protótipo escondia o principal, N:3075).
- Contador "Processos vinculados N" conta principal + apensados (no protótipo era fixo "1", N:1705; a convenção
  manda contar).
- Registro na timeline de Dados (`SYS.proc`, faixa "PROCESSO"): "apensado X (Embargos) vinculado ao processo
  principal Y." com "→ X", e "apensado X desvinculado." com "X → —" (N:4555, N:6320). Use o mesmo mecanismo de
  registro de sistema que a pasta já usa para processo vinculado/desvinculado.
- Desvincular o principal quando a pasta tem apensados NÃO promove apensado a principal (decisão do
  orquestrador na revisão: é o desenho, e embargos não é a ação principal). A pasta fica sem principal e
  com os apensados intactos; só o vínculo antigo sem tipo de apenso continua elegível à promoção.
- Aviso após vincular: "Apensado vinculado · aparece em Processo, Dados da pasta e Push processual" (N:4556).

## 3. Aba Dados

Cartão "Processo vinculado" (N:1431-1455): pílula `bi-paperclip` "1 apensado" / "N apensados" com chevron
(dica "Ver processos apensados" / "Recolher processos apensados"); expandido, cada apensado com "APENSADO · TIPO",
número (link que abre a aba Processo), ação e "classe · tribunal · situação". Hoje o sistema já tem parte disso:
reaproveite e complete.

## 4. Push: filtro por processo

- `pushDaPasta` (N:5483): movimentações do principal sempre; dos apensados só os não pausados (monitoramento do
  #82; se esta branch não tiver o #82, considerar todos ativos e deixar o filtro de pausa para depois do merge).
  A fonte da movimentação de apensado ganha o sufixo " · Apensado · {Tipo} {número}" (N:2655).
- Chips (N:5683, N:2546-2548) no cabeçalho "Movimentações recebidas", antes de "Todas / Novas / Geram prazo":
  "Todos os processos · N", "Principal · N", depois um por apensado "{Tipo} · N". Dica "Movimentações de todos os
  processos da pasta" / "Somente {número}". Selecionado fundo #0a6fa0 e texto branco; demais #eef2f5. Só aparecem
  com mais de 2 opções. Aplicam antes de "Todas/Novas/Geram prazo"; o total do cabeçalho não é filtrado. Filtro
  no navegador (como os atuais). Estado vazio "Nenhuma movimentação neste filtro." (já existe). Corrigir o defeito
  do protótipo: o filtro de processo volta para "Todos" quando o processo filtrado sai da pasta.

- Atualização: depois de vincular ou desvincular, só a aba Processo é refeita na hora (XHR). A aba Push
  (chips e lista) e a pílula de apensados da aba Dados se atualizam ao recarregar a página; por isso o
  "volta para Todos" do protótipo não se aplica (a aba Push nasce sempre em "Todos os processos").

## 5. Registro de fase ao marcar administrativa (`spMarcar`, N:4761)

Ao marcar ou desmarcar "Administrativo sem processo", grava um registro de sistema na timeline de Dados do tipo
fase: marcar "pasta marcada como administrativa: o andamento passa para a via administrativa (Requerimento, Em
análise, Exigência, Parecer). O andamento judicial fica guardado."; desmarcar "pasta voltou para a via judicial: o
andamento judicial anterior foi restaurado.". Confira primeiro o que `AndamentoDaPasta` e o
`DefinirPastaAdministrativaUseCase` já fazem (o sistema já tem a via administrativa); não duplique, só acrescente o
registro se não existir. O toggle já usa a janela de confirmação do sistema; o texto ganha "O andamento passa para a
via administrativa." (N:7001).

## 6. Testes mínimos

Unit: enum de tipos, validações de vínculo de apensado, contagem dos chips. Functional: vincular apensado (CSRF,
EDIT, outro tenant, principal repetido, apensado repetido), desvincular apensado (não mexe no principal), cartão com
a etiqueta e o X, contador, aba Dados com a pílula, timeline com os dois registros, chips do Push com contagens e
sufixo da fonte, registro de fase ao marcar/desmarcar administrativa. JS: sem innerHTML.
