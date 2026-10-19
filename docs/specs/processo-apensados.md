# Processos apensados, filtro por processo no Push e registro de fase administrativa (P4f)

## Estado da frente (sessão bluejus-0d; atualizado 19/10/2026, noite)

Releia esta seção antes de continuar se a sessão for compactada.

**Pedido do dono (19/10):** implementar na íntegra o desenho das abas Processo e Push Processual (02 - EXPEDIENTES,
versão completa de 18/10 em `docs/design/redesign-19-10-26/`, fora do git), mesmo que precise de frente nova.

**PRs e ordem de merge (fila de migrations do coordenador):**
- #55 link externo do Push: MERGEADO.
- #68 `pasta-ocultar-aba`: base master. Migration Version20261019200000 (renomear na vez: depois de #56, #60, #65,
  #69, #64, #74, #62, #83, #84).
- #75 `relatorio-processos-controladoria`: base master. Migration Version20261019233000.
- #82 `push-painel-monitoramento` (P4a): base master. Migration Version20261019234500.
- #87 `push-avisar-quando-chegar` (P4b): EMPILHADO sobre o #82, pronto. Migration Version20261020090000. A 5d
  (comercial-1a) empilha sobre ele (descadastro em Shared).
- P4f `processo-apensados`: base master. Migration Version20261020103000 (renomeada: a 5d tem a 20261020100000).
- P4c `push-avisar-cliente`: em implementação, EMPILHADO sobre o #87. Controladoria e envio isolados em interfaces.
- P4d (envio automático ao cliente, pelo Carteiro da 5d) e P4e (arquivos, OCR, análise completa): specs no
  scratchpad; P4e espera o #76 (composer) e aviso de Dockerfile.
Depois de cada merge: trazer o origin/master, migrar o banco de teste da frente (APP_ENV=test, TEST_TOKEN da frente),
suíte, push. Renomear a migration na vez dela.

**Decisões do dono (19/10/2026):** Controladoria = pessoas marcadas (só Master/primário marca); ocultar aba organiza a
pasta e não restringe o sistema; renovação do link por aviso no sino e link curto ativo; envios automáticos ao cliente
nascem desligados; pausar monitoramento para só a busca por número (legenda com OAB); chave "Análise da BlueJus IA"
do P4b nasce desligada. Coordenador: prazo da previsão pela lei (Lei 11.419 art. 4º, CPC art. 224); e-mail com
descadastro; sem Controladoria envia e avisa o primário; remetente nao-responda@ com reply-to do responsável; OCR no
P4e; "Apensado · Outro" para as 4 pastas antigas com 2 processos.

**Contratos com outras sessões:** security.yaml com uma rota pública por linha; framework.yaml com limitadores em
trechos próprios; descadastro (`App\Shared\Email\Descadastro`, ?Tenant, todos, reativar, listar) usado pela 5d e
pela a7; Carteiro da 5d (`App\Comercial\Envio`, commit 80c4c7de) para P4c/P4d; user_tenant: 7d põe `situacao`, #75
põe `controladoria`.

**Para o dono:** merges na ordem da fila; confirmar que o MAILER_DSN de produção sai pela Hostinger (SPF/DKIM do
nao-responda@); configurar o cron mensal do relatório (DEPLOY.md, #75); reconstruir a imagem de produção quando o P4e
entrar (tesseract/poppler); trocar o comando do worker para consumir a fila 'comercial' (PR da 5d) antes do P4c/P4d
enviarem pelo Carteiro.

**Próximo passo:** abrir o PR do P4f; fechar o P4c (revisão, visual, PR); depois P4d; P4e quando o #76 entrar.


Desenho de referência: `docs/design/redesign-19-10-26/02 - EXPEDIENTES - VS 18.10.26.dc.html` (checkout principal,
fora do git). Linhas citadas como N:. Botão N:1710, `procAbrirApenso` N:4517, modal N:289-354 e `pvVals`
N:3135-3151, salvar `procSalvarVinc` N:4535-4563, `apensosDe` N:4503, cartão N:1725 e N:6711, aba Dados
N:1431-1455 e N:7000, timeline `SYS.proc` N:6168 e N:6320, Push `pushDaPasta` N:5483, chips `procFiltros`
N:5683 e N:2546-2548, estados vazios N:5681-5682, `spMarcar` N:4761. Risco BAIXO.

## 1. Modelo

`pasta_processo` ganha `tipo_apenso` (varchar 40, nullable). O vínculo `principal = true` é o processo principal;
os demais são apensados, e `tipo_apenso` guarda o tipo escolhido (Embargos, Agravo de instrumento, Cumprimento de
sentença, Execução, Impugnação, Recurso, Cautelar, Outro; enum `TipoDeApenso` com o rótulo exato do desenho).
Vínculo antigo sem principal e sem tipo continua valendo (rótulo "Outro"). Dado legado: na produção só 4 pastas de
173 têm 2 processos, todas com principal; o segundo passa a aparecer como "Apensado · Outro" (aceito pelo coordenador). Não há processo pai: o vínculo é pela
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
