# Ponto — edição de justificativa já analisada (C2-01)

**Risco:** ALTO (ponto eletrônico). **Frente:** `fix-edicao-justificativa-analisada`, base `91d0aef3`.
**Origem:** achado C2-01 (CRÍTICO) da auditoria de segurança de 04/09/2026, reconfirmado no pré-deploy
de 18/09. Vai para produção **junto** com `fix-troca-atestado-abonado` (§7).

## 1. O defeito

`PontoController::editarJustificativa` confere o dono e o CSRF e nunca o status. O colaborador altera,
numa justificativa **abonada** ou **rejeitada**:

| Campo (do POST) | Alcance | Efeito numa abonada (medido no `FolhaPontoBuilder`) |
|---|---|---|
| `tipo` (vazio ou inválido vira `null`, e `null` abona) | só o registro da rota | técnico/falta ↔ abona: o déficit do dia fica ou zera; muda qual justificativa vence no dia |
| `abonoParcial` + `horaInicioAbono`/`horaFimAbono` | só o registro | soma minutos sem teto, até +1439/dia — vira "hora extra" na folha |
| `tipoRegistroEsquecido`/`horaRegistroEsquecido` | só o registro | nenhum no cálculo (a folha lê a batida criada na aprovação), mas a justificativa diverge da batida |
| `anexo` | o **lote** inteiro | tratado em `docs/specs/ponto-troca-de-atestado-analisado.md` |

A rota não toca `status`, `dataAnalise`, `analisadoPor`, `observacaoAnalise`, `data` nem `batchId`: a
justificativa continua "analisada por X" com um conteúdo que X nunca viu. Numa **rejeitada** nada muda
no cálculo, mas a decisão do gestor fica apontando para dados diferentes dos que ele recusou.

**O roteiro da auditoria:** a `falta_nao_justificada` nasce `abonado` sem análise
(`PontoController::novaJustificativa`); editar o tipo para um que abona leva o dia de **−480** para **0**, e com
abono parcial 00:00–23:59 para **+959** (+1439 num domingo) — crédito no banco de horas sem nenhum
gestor. Vale também para a falta lançada pelo admin.

**Prova por execução (18/09):** os 20 casos de `tests/Ponto/Functional/EdicaoDeJustificativaAnalisadaControllerTest`
contra o código de produção da base `91d0aef3` — 15 falham: abonada e rejeitada aceitam troca de tipo,
tipo vazio, abono parcial 00:00–23:59 e hora de esquecimento; a requisição forjada passa; o dia abonado
de um lote misto muda; atestado novo numa analisada é gravado; a tela oferece editar o que já foi
analisado. Os 5 que passam são os que têm de continuar passando (pendente editável, status que a
edição não grava, admin aprovando, outro escritório, tela da pendente).

**Produção (18/09, só contagens):** 318 abonadas, 5 pendentes, 5 rejeitadas; 0 `falta_nao_justificada`;
a rota foi usada 4 vezes desde que existe, 2 delas em abonada (só `tipo`, de nulo para um tipo que
também abona — efeito numérico zero). A afirmação da auditoria de que o golpe "já ocorreu 17 vezes"
não se sustenta: as 17 linhas `ajuste_jornada` não têm nenhum registro no `audit_log` e batem com a
correção manual de meio período de `docs/specs/ponto-abono-nao-perdoa-jornada.md`.

## 2. A regra — determinada, não escolhida

| Fonte | O que diz |
|---|---|
| Auditoria C2-01, correção pedida (`docs/security-audit/`, fora do git) | "Em editarJustificativa, recusar a edição quando o status não for 'pendente'; ao editar um item pendente, não permitir que o próprio formulário grave um status diferente de 'pendente' sem passar pelo fluxo de aprovação do administrador." Recomendação P1. |
| `templates/ponto/index.html.twig:203`, desde o commit que criou a edição | `{# Modal: Editar justificativa pendente #}` |
| Análise do admin (`TenantController`) | aprovar, rejeitar e aprovar-todos só agem sobre `pendente`; a volta é o "reverter para pendente" |
| `fix-troca-atestado-abonado` | a mesma regra aplicada ao atestado |

- **R1.** O colaborador só edita — qualquer campo — a justificativa cujo registro está `pendente`.
  Abonada ou rejeitada: recusa, e nada muda.
- **R2.** A decisão que vale contra a análise simultânea do admin é lida **do banco, com a linha
  travada** (`SELECT … FOR UPDATE`), na mesma transação que grava. O admin não pega trava nenhuma:
  com a linha travada, o UPDATE de status dele espera o COMMIT do colaborador, e um que já comitou é
  visto pela leitura. Nunca pelo getter: a justificativa chega carregada antes, e o identity map não
  relê campos.
- **R3.** Antes disso, uma recusa rápida pelo estado carregado — sem abrir transação. É a que
  responde no caso comum (editar algo analisado há dias).
- **R4.** A recusa descarta a edição inteira: aviso, nunca a mensagem de sucesso.
- **R5.** A edição não grava status: uma pendente editada para "falta não justificada" continua
  pendente (na criação esse tipo nasce abonado). Já é o comportamento; fica provado por teste.
- **R6.** A tela não oferece "Editar justificativa" para o que não está pendente; mostra um cadeado
  com o caminho de volta. O servidor é a garantia.
- **Lote misto:** a edição de campos atinge só o registro da rota, então a regra é pelo status dele —
  o dia pendente de um lote misto continua editável, e os dias analisados nunca mudam. (O atestado,
  que atinge o lote inteiro, segue a regra do lote: `ponto-troca-de-atestado-analisado.md`, R1.)
- **Caminho de volta:** o administrador reverte o dia para pendente
  (`app_tenant_user_justificativa_reverter`), o colaborador edita, o administrador analisa de novo.

## 3. Mudança

- `JustificativaPontoRepository::statusNoBancoTravadoPorId()` — projeção escalar do status do
  registro com `LockMode::PESSIMISTIC_WRITE` e tenant explícito. Sem transação, o Doctrine lança.
- `ConfirmarEdicaoDeJustificativaUseCase::executar()` — R2: posse do tenant, `wrapInTransaction`,
  leitura travada, recusa ou flush. Grava o que a rota mudou na entidade (o mesmo desenho do
  `SubstituirAnexoDoLoteUseCase`, que grava os campos da rota junto com o anexo).
- `JustificativaJaAnalisadaException` (`App\Ponto\Exception`, mensagem única para o usuário final,
  também usada pela recusa rápida, que não lança).
- `PontoController::editarJustificativa` — R3 logo depois do CSRF; sem anexo, o antigo `flush()`
  passa a ser o UseCase; captura a recusa, avisa e redireciona.
- `_justificativas_list.html.twig` — R6.

Sem migration. Nada em E2, R2, Drive nem nas rotas do admin.

## 4. Garantias que a correção não pode quebrar

O fluxo do administrador (aprovar, rejeitar, aprovar-todos, reverter, criar) não muda. A edição de
pendente continua gravando todos os campos de antes. O caminho com atestado mantém as garantias
E1/E2 (C1–C11 de `ponto-troca-de-atestado-analisado.md` §5): esta frente não toca
`SubstituirAnexoDoLoteUseCase`.

## 5. Provas exigidas

Testes: pendente continua editável; abonada não muda tipo (inclusive vazio); abonada não muda
horários; rejeitada não muda; o roteiro da auditoria (falta não justificada); esquecimento abonado não
muda a hora; requisição forjada recusada; pendente não muda de status pela edição; lote misto; recusa
com atestado no POST não toca banco nem storage; admin continua aprovando (perfil com
`admin.users.manage`, sem bypass de sistema); reverter libera a edição; outro escritório não alcança;
tela; aprovação do admin depois de a entidade ser carregada (a decisão usa o banco); a leitura trava a
linha; posse de outro escritório no UseCase; falha do banco na leitura travada.

Provas por reintrodução do defeito, com diário por mutação.

## 6. Fora do escopo — achados do mesmo fluxo, registrados para o dono

Nenhum é CRÍTICO nem ALTO pela medição; nenhum foi corrigido aqui.

- **MÉDIO — tipo vazio numa pendente.** A edição aceita tipo vazio (vira `null`, e `null` abona o dia
  inteiro); a criação exige tipo. O gestor vê "—" e, se aprovar, abona o dia todo. Exige aprovação do
  gestor — não é contorno da análise. Em produção: 0 justificativas com tipo nulo.
- **MÉDIO — batida duplicada ao reaprovar esquecimento.** Reverter não remove a batida que a aprovação
  individual criou (o admin recebe um aviso), e aprovar de novo cria outra. Esta correção torna o
  "reverter" o caminho oficial de correção, então o cenário fica mais exercitado.
- **MÉDIO — gestor aprova a própria justificativa.** Nenhuma rota do admin compara o analista com o
  dono. Decisão de negócio.
- **MÉDIO — a aprovação não amarra o conteúdo que o gestor viu.** O "aprovar" lê sem trava e grava
  só status e análise. Editar uma pendente é legítimo (R1), então, entre o gestor abrir a aba e clicar,
  o colaborador pode mudá-la: fica "abonada por X com um conteúdo que X nunca viu". No esquecimento,
  numa corrida de milissegundos, a batida sai com a hora antiga que o admin tinha em memória. Não é
  regressão (sempre foi assim); fechar exige a aprovação conferir o que foi exibido (versão ou
  instantâneo no POST do admin). **Decisão do dono.**
- **Esta correção torna mais exercitado o item da batida duplicada:** a mensagem e o cadeado mandam
  pedir "reverter", e num esquecimento abonado reverter + reaprovar duplica a batida. **Decidir antes
  do deploy** se isso segue como está (o admin é avisado ao reverter) ou vira frente própria.
- **BAIXO — lote misto na tela:** o botão edita o dia mais recente exibido (`batch[0]`); os outros
  dias pendentes do lote não são alcançáveis pela tela (anterior a esta frente). A mensagem não diz
  qual dia reverter.
- **BAIXO** — `createFromFormat('H:i')` aceita "25:00"; `getMinutosAbonados()` ignora o sinal; a
  edição de tipo/horário de um lote de vários dias atinge só o primeiro dia exibido, mas a tela do
  admin mostra esse tipo como se fosse do lote; a criação pelo admin trata a data de hoje como futura.

## 7. Integração com `fix-troca-atestado-abonado` — esta frente NÃO vai para produção sozinha

As duas frentes saem de `91d0aef3` e mexem na mesma rota. **Nesta frente sozinha, o caminho com
atestado é protegido pela recusa rápida (R3), mas não contra a corrida com o admin** (janela curta:
validação e gravação do arquivo) — quem fecha a corrida desse caminho é a outra frente (lote todo
pendente, lido com `FOR UPDATE` dentro da transação do anexo, antes de gravar; lote todo pendente
implica o registro pendente). Por isso as duas vão juntas para produção, a do atestado primeiro.

**Medido em 18/09, sem mesclar nada** (`git merge-tree` + a árvore mesclada extraída por `git
archive` para um diretório de ensaio fora do git, com banco próprio):

- conflito textual: **nenhum** (o único trecho comum, `use Doctrine\DBAL\LockMode;` no repositório, é
  idêntico nos dois lados e sai uma vez só);
- conflito semântico: **4 testes da outra frente**, todos em `EditarJustificativaAnexoControllerTest`,
  todos por comportamento mais estrito, nenhum por regressão —
  `testLoteAnalisadoRecusaATrocaPelaRota` (casos "abonado" e "rejeitado": a recusa agora vem da R3,
  com a mensagem desta frente) e `testTelaSinalizaATrocaSoParaLotePendente` (casos "um dia abonado" e
  "rejeitado": o `batch[0]` analisado não tem mais botão);
- **ajuste de integração (só dados de teste, validado no ensaio):** os casos da rota passam a usar
  lotes cujo dia editado está pendente e outro dia não (`['pendente','abonado']`,
  `['pendente','rejeitado']`, `['pendente','pendente','abonado']`) — o cenário que só a regra do
  atestado recusa; os casos de tela passam a pôr o dia analisado ANTES do pendente
  (`['abonado','pendente']`, `['rejeitado','pendente']`), para haver botão e o sinal do atestado ser
  `0`. Com o ajuste: Ponto 427/427 na árvore mesclada.
