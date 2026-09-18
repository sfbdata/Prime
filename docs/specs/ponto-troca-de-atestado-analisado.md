# Ponto — troca de atestado em justificativa já analisada

**Risco:** ALTO (ponto eletrônico). **Frente:** `fix-troca-atestado-abonado`, base `91d0aef3`.
**Origem:** revisão adversarial do pré-deploy de 18/09/2026 (risco 12.1), que segurou o deploy de
E1 + DT-8 + E2.

## 1. O defeito

`PontoController::editarJustificativa` só confere o dono e o CSRF — nunca o status — e entrega o
arquivo a `SubstituirAnexoDoLoteUseCase`, que desde a E1 (`c4f349f4`) troca o anexo do **lote
inteiro** e apaga o arquivo antigo depois do COMMIT quando ninguém mais o referencia. Nenhum dos
dois olha o status. Resultado: o colaborador edita um lote **já abonado** (ou rejeitado), anexa
outro arquivo, e o atestado que o gestor analisou some do disco; todos os dias passam a apontar para
um arquivo que ninguém viu, e o status continua `abonado`.

Antes da E1 o arquivo novo ia para um registro só e o antigo ficava no disco — a E1 criou o
primeiro caminho que apaga um atestado fora da purga do escritório.

Agravantes medidos:
- o lote lançado pelo **admin** já nasce `abonado` e aparece na lista do colaborador, que pode
  trocar (e apagar) o atestado que o admin anexou;
- **lote misto** existe em produção (26 abonados + 1 rejeitado; 5 abonados + 2 pendentes): a análise
  é por dia, mas a troca atinge o lote inteiro. Na tela de setembro, o lote de 27 dias aparece como
  "Rejeitado" pelo `batch[0]` — trocar o atestado ali, a reação natural à rejeição, apagaria o
  arquivo dos 26 dias abonados de agosto;
- corrida com o admin: as ações de análise (`TenantController`) não pegam a trava do lote; uma
  aprovação que comita entre a leitura e o UPDATE do colaborador reabriria o defeito.

Alcance em produção (18/09): 24 atestados, 24 lotes, 0 lotes com dois anexos. A rota de edição foi
usada 4 vezes desde abril, **nenhuma** trocando anexo.

## 2. Evidência da regra

| Fonte | O que diz |
|---|---|
| `templates/ponto/index.html.twig:203` (desde `a27c67f0`, o commit que criou a edição) | `{# Modal: Editar justificativa pendente #}` — a intenção era editar só pendente; a trava nunca foi escrita |
| Testes da edição (`EditarJustificativaAnexoControllerTest`, `SubstituirAnexoDoLoteUseCaseTest`) | só criam justificativa `pendente`; edição de analisada não tem teste |
| Auditoria de segurança de 04/09 (C2-01, CRÍTICO, `docs/security-audit/`, fora do git) | correção pedida: "recusar a edição quando o status não for 'pendente'", com teste que espera recusa ao editar abonada |
| Análise do admin (`TenantController:1087`, `:1181`, `:1250`) | aprovar, rejeitar e aprovar-todos só agem sobre `pendente`; a volta é explícita: "reverter para pendente" (`:1276-1345`), feito pelo admin |
| Convenção em outros domínios | `EditarAcordoUseCase` (INV-D: só acordo ativo é editável, o resto é histórico congelado); `AccessRequestController` (`isPending()` antes de decidir) |
| Spec da E1 | invariante "um `batchId` → um anexo" (C7 abaixo): a troca é do lote, não do dia |

## 3. As alternativas, comparadas

| | O que faz | A favor | Contra |
|---|---|---|---|
| **(a) proibir** a troca fora de `pendente` | recusa; o caminho de volta é o "reverter" que o admin já tem | intenção original; C2-01; convenção do Ponto; sem migration; não mexe em nenhuma garantia E1/E2 | rejeitado deixa de ser "corrigível" pela edição — mas a edição de um rejeitado nunca o reabria, então nenhum fluxo funcional se perde |
| (b) trocar e **voltar a pendente** | desfaz a análise do gestor por ação do colaborador | precedente de reabrir em Cobrança | o colaborador desfaz sozinho uma decisão do gestor; o atestado analisado continua sendo apagado; a batida criada pelo esquecimento aprovado fica; exige notificação e trava nas 4 ações do admin |
| (c) manter abonado e **preservar** o arquivo | nunca apagar, ou guardar histórico | nada se perde | abonado passa a apontar para arquivo que ninguém analisou; (c1) deixa órfão permanente que a purga não acha (dado de saúde sobrevive à purga); (c2) exige migration e um 4º produtor de `anexo_path` |
| (d) **ação específica do admin** | troca só pelo admin, ou anexo novo pendente de aprovação | quem analisa decide | (d1) já existe como "reverter" + (a); (d2) exige coluna nova (migration) e expõe C1/C2/C8 |

**Decisão: (a)**, com o "reverter para pendente" que o admin já tem como caminho de volta. É a única
alternativa sustentada pela documentação e pelo comportamento existentes, e a única que não mexe em
garantia nenhuma da E1/E2.

## 4. A regra

- **R1.** O atestado de uma justificativa só pode ser trocado se **todos** os registros do lote
  estiverem `pendente`. Um único dia abonado ou rejeitado recusa a troca inteira.
  *Por que o lote, e não o dia:* a troca grava o `anexo_path` de todos os registros do lote (C7).
  Aplicar "só pendente é editável" a cada registro que a operação grava dá exatamente R1. Trocar só
  nos dias pendentes partiria o lote em dois anexos (viola C7), e não é opção.
- **R2.** A decisão lê o status **do banco, sob a trava do lote e com trava de linha**
  (`SELECT … FOR UPDATE`), nunca pelo getter: a justificativa chega carregada pelo
  EntityValueResolver muito antes da trava, e o identity map não relê campos
  (`feedback_doctrine_nao_rele_entidade_carregada`). A trava de linha é o que serializa com a análise
  do admin, que não pega a trava advisory: um UPDATE de status do admin espera o COMMIT do
  colaborador, e um que já comitou é visto pela leitura.
- **R3.** A recusa acontece **antes** de gravar o arquivo novo: nada vai ao disco, nada sai do disco.
- **R4.** Recusa descarta a **edição inteira** (tipo, abono, horas) — mesma semântica de anexo inválido
  (C4). O colaborador vê um aviso, nunca uma mensagem de sucesso.
- **R5.** A tela não oferece o campo "Substituir atestado" quando algum dia visível do lote não está
  pendente (o campo fica `disabled`, não só escondido, para um arquivo escolhido antes não ir junto).
  O servidor é a garantia; a tela é cortesia.

Lote todo `pendente` continua exatamente como a E1 deixou: troca no lote inteiro e remoção do antigo
depois do COMMIT se ninguém mais o referenciar. Isso inclui o lote que o admin reverteu para
pendente — reverter é o ato explícito que desfaz a análise.

## 5. Garantias que a correção NÃO pode desfazer (E1/E2)

C1 remoção física só depois do COMMIT · C2 arquivo novo só sai com ausência de COMMIT provada ·
C3 posse do tenant antes de tudo, lote vazio recusado · C4 anexo recusado descarta a edição inteira ·
C5 trava do lote, releitura sob a trava, anexo antigo por projeção · C6 fase 2 sob a trava do arquivo,
contagem com tenant explícito · C7 um `batchId` → um anexo · C8 ninguém copia `anexo_path` existente ·
C9 escopo da chave sai da entidade dona · C10 falha física pós-COMMIT vira log, nunca 500 ·
C11 `excluir()` só via `RemocaoAposTransacao`.

## 6. Mudança

- `JustificativaPontoRepository::statusDoLoteTravado()` — projeção escalar dos status do lote (ou do
  registro avulso) com `LockMode::PESSIMISTIC_WRITE`, tenant explícito. Sem transação, o Doctrine
  lança (falha fechada).
- `SubstituirAnexoDoLoteUseCase` — depois da trava do lote e antes de gravar o arquivo novo, recusa
  com `TrocaDeAnexoRecusadaException` se algum status não for `pendente`. Lote vazio sob a trava é
  premissa quebrada (a posse já foi conferida) e lança `LogicException`, como o `loteDe()` já faz —
  nunca "nada a recusar".
- `TrocaDeAnexoRecusadaException` (`App\Ponto\Exception`, `\DomainException`, mensagem para o
  usuário final).
- `PontoController::editarJustificativa` — captura a exceção: aviso + redirect, sem sucesso.
- `_justificativas_list.html.twig` + modal/JS de `index.html.twig` — R5.

Sem migration. Nada em E2, R2 ou Drive.

## 7. Provas exigidas

Testes: lote pendente (troca, antigo some); lote abonado, lote rejeitado e lote misto (recusa, banco e
disco intactos, nenhum arquivo novo, nenhuma exclusão); status mudado no banco depois de a entidade
ser carregada (a decisão usa o banco); a consulta leva `FOR UPDATE`; falha do banco na leitura do
status (nada gravado, nada apagado); falha do storage (recusa não toca o storage; os caminhos de falha
da troca pendente seguem cobertos pela E1/E2); arquivo ainda referenciado nunca é apagado; a rota
recusa sem mensagem de sucesso e sem gravar os outros campos; a tela oferece o campo só para lote
pendente.

Provas por reintrodução (diário por mutação): sem a checagem; checagem só do registro `{id}`;
checagem pelo getter; sem `FOR UPDATE`; checagem depois de gravar o arquivo; `rejeitado` aceito;
controller sem capturar a exceção; tela sempre oferecendo a troca; lote vazio seguindo adiante; sem
filtro de tenant; filtro por usuário no lugar do tenant. Todas derrubam ao menos um teste.

**O que o PHPUnit não prova — vai para o smoke do dono.** O JS do modal (`value = ''` e
`disabled` a cada abertura) não roda no PHPUnit. Roteiro: abrir o modal num lote pendente, escolher
um arquivo, fechar; abrir num lote analisado e salvar — o POST não pode levar `anexo`, e a tela tem
de mostrar o aviso no lugar do campo.

**Sob o DAMA, "o diretório terminou igual" não prova R3:** a transação da troca é a de fora (nível 0
para o DBAL), e um arquivo gravado antes de uma recusa seria apagado na hora. A prova de R3 é o
espião do storage (`gravadas === []`), no teste unitário e no funcional.

## 8. Fora do escopo (continua aberto, registrado para o dono)

- **C2-01 (CRÍTICO, anterior à E1):** a mesma rota deixa o colaborador mudar `tipo`, abono parcial e
  horário de uma justificativa **já abonada**, sem reanálise — e isso muda horas já aprovadas na folha
  e no banco de horas (`FolhaPontoBuilder:178-196`). Ex.: `falta_nao_justificada` nasce abonada
  (`PontoController:337-338`) e pode virar um tipo que abona. Em produção houve 2 edições de `tipo`
  depois do abono (ids 1 e 2, preenchimento de tipo nulo). Fechar exige a mesma regra na edição
  inteira, com a edição movida para um UseCase sob trava. Decisão do dono: esta frente trata só o
  atestado, que é o bloqueio do deploy.
- **Corrida humana com o admin:** com a trava de linha, nenhum estado comitado tem dia analisado
  apontando para arquivo trocado. Mas o admin que abriu a tela antes de uma troca num lote pendente
  pode aprovar depois o arquivo novo sem tê-lo visto. Fechar exige a análise conferir o anexo que foi
  exibido — mudança na tela e na rota do admin.
- **R5 enxerga só o mês exibido:** um lote que atravessa o mês pode ter a parte visível toda pendente
  e a outra não; a tela oferece o campo e o servidor recusa com o aviso.
- **Destravar custa reverter o lote inteiro.** O "reverter" do admin é por dia; R1 exige todos os
  dias pendentes. No lote misto 26+1 de produção, reverter só o dia rejeitado não destrava a troca —
  seria preciso desfazer os 26 abonos, e a folha muda enquanto isso (e, em esquecimento de registro,
  a batida criada no abono fica). A mensagem diz exatamente isso ("todos os dias precisam voltar a
  pendente"). A alternativa operacional é o colaborador lançar uma justificativa nova. Um "substituir
  atestado" do lado do admin, se o dono quiser, é frente própria.
- **`falta_nao_justificada` nasce `abonado`** (`PontoController:337-338`): depois desta correção ela
  não aceita mais atestado pela edição (antes aceitava, e a troca apagava o anterior, se houvesse).
- **A trava de linha dura o `gravarEm`.** Com disco local é desprezível; quando o storage for remoto
  (R2), uma aprovação do admin naquele lote espera o upload terminar. Há também um deadlock teórico
  com o "aprovar todos" (UPDATEs na ordem do UnitOfWork × `FOR UPDATE` na ordem da varredura): o
  PostgreSQL aborta um dos lados (500), sem destruir nada — a recusa ou a falha vêm antes de qualquer
  exclusão física, e o arquivo novo de uma transação abortada sai pela `TransacaoComArquivoNovo`.
