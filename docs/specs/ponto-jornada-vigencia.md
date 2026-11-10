# Jornada com data de início (vigência)

Risco: **ALTO** (ponto eletrônico, saldo de banco de horas). Pedido do Samuel, 06/11/2026.
Migration reservada: `Version20261107100000`.

## 1. O problema

Hoje cada pessoa tem **uma** jornada por escritório (`jornada_colaborador`, única por `user_id` +
`tenant_id`). A folha, o saldo e os alertas aplicam essa jornada a **todos os dias**, inclusive os
anteriores à última troca. Trocar a jornada reescreve o passado.

Casos reais em produção (consulta somente leitura, 06/11/2026):

- **Samuel (user 2, escritório 1).** Até 22/04/2026 12:48 trabalhava 40h (8h de segunda a sexta).
  Nesse dia ganhou a jornada personalizada de 44h (9h de segunda a quinta, 8h na sexta). A folha de
  abril inteiro cobra 9h de segunda a quinta, e o saldo de 01 a 21/04 sai negativo sem motivo.
- **user 11 (jornada 8).** A jornada mudou 4 vezes (25h, 44h, 30h, 25h entre 20/05 e 10/10). Cada
  troca recalculou todos os meses anteriores com a carga nova.
- **user 12 (jornada 9).** Mudou o bloco em 31/08: o passado passou a cobrar o horário novo.

Hoje são 10 jornadas pessoais (9 com blocos) e 1 jornada padrão do escritório, todas no escritório 1.

## 2. Storytelling

> A Ana trabalha 40h. Em 10/03 o escritório passa a jornada dela para 44h. O gestor abre
> "Jornada Personalizada", ajusta os blocos e, no campo **"Vale a partir de"** (que já vem com a data
> de hoje), deixa 10/03. Salva.
>
> A folha de fevereiro continua cobrando 8h por dia, como antes. A de março cobra 8h de 01 a 09/03 e
> 9h a partir de 10/03. O saldo acumulado de fevereiro não muda nem um minuto.
>
> Embaixo do formulário aparece o **histórico**: "44h, a partir de 10/03/2026 (atual)" e "40h, até
> 09/03/2026". O gestor percebe que a data certa era 03/03: clica em "Editar" na versão de 44h,
> troca a data e salva. Os dias de 03 a 09/03 passam a cobrar 9h.
>
> No caso do Samuel, o gestor edita a versão atual (44h) para valer a partir de 22/04/2026 e cria
> uma versão de 40h a partir do primeiro registro. Abril passa a cobrar 8h até 21/04.

## 3. Regras

### 3.1 Versões

1. Uma pessoa pode ter **várias versões** de jornada no mesmo escritório. Cada uma tem a data
   **"Vale a partir de"** (`vale_a_partir_de`, só a data, sem hora).
2. Cada versão vale da sua data até o dia anterior à próxima versão. A mais recente vale
   indefinidamente.
3. **A versão mais antiga vale também para os dias anteriores à sua data.** Assim nenhum dia que
   hoje tem jornada fica sem jornada (garantia do §5).
4. Duas versões da mesma pessoa no mesmo escritório não podem começar no mesmo dia (índice único
   `user_id, tenant_id, vale_a_partir_de`).
5. A data pode ser passada, de hoje ou futura. Uma versão futura fica agendada. A tela e o alerta
   de hoje usam a vigente hoje; a futura só passa a valer no dia dela.
6. A versão leva tudo o que mede o dia: blocos, carga semanal e os campos legados (`entrada1`...,
   `diasSemana`, `cargaHorariaDiaria`, sábado). O **"Habilitar alertas de ponto"** não é medida do
   passado: vale o da versão vigente hoje.

### 3.2 Quem lê qual versão

| Consumidor | Hoje | Depois |
|---|---|---|
| `FolhaPontoBuilder::buildRows`, `calcularSaldoAteMes`, `calcularSaldoAnual` | uma jornada para todo o período | a versão vigente **em cada dia** |
| `CalculadoraJornada::calcularSaldoDiaDaEscolha` | recebe a jornada | sem mudança: quem chama passa a do dia |
| Cabeçalho da folha (PDF/XLSX: horários e "horas semanais") | a jornada atual | a versão vigente no **último dia** do mês da folha; se o mês tiver troca, uma linha "Jornada alterada em dd/mm" |
| `VerificadorAlertaPonto`, tela do ponto (meta do dia, excedente), `IntervalosDeTrabalho`, `ChoqueDeJornadaEntreEscritorios` | a jornada | a vigente **hoje** (ou na data consultada) |
| `ListarPendenciasDeRegistroUseCase`, `SaidaDeEsquecimento`, `EscolhaDasBatidasDoDia` | a jornada | a vigente no dia da batida |
| Saldo no Meu Escritório / Dashboard (`TenantController`, `PontoController`) | via `FolhaPontoBuilder` | idem, sem código próprio |
| Twig `tem_jornada_no_escritorio()` | existe jornada | existe versão valendo hoje (§10.4) |

Peça central: `HistoricoDeJornadas` (objeto de valor, `final`) carregado uma vez por pessoa e
escritório, com `vigenteEm(\DateTimeInterface $dia): ?JornadaColaborador`. O repositório ganha
`historicoDe(User, Tenant)` (todas as versões com os blocos, numa consulta) e
`findByUserETenant()` passa a devolver **a vigente hoje** (mesmo nome, todos os chamadores de
"hoje" ficam certos sem mudança). Quem varre dias recebe o histórico, não uma jornada.

### 3.3 Padrão do escritório (`jornada_tenant`)

Ela também muda o passado de quem não tem blocos próprios (hoje só 1 pessoa, user 5). Proposta:
**fora deste pacote**, numa etapa própria com a mesma regra, porque mexe em outra tela (modal
"Configurar Jornada do Escritório") e nos mínimos de repouso e interjornada. Até lá, a padrão
continua uma só e vale para todos os dias. **Decisão pendente do Coordenador (§8).**

### 3.4 Editar e excluir versões

- **Salvar com data nova** (diferente de todas): cria uma versão. As outras ficam como estão.
- **Salvar com a data de uma versão existente**: atualiza aquela versão.
- **Editar uma versão do histórico**: abre o mesmo formulário com os dados dela; pode trocar a data
  (desde que não colida com outra).
- **Excluir uma versão**: só se houver mais de uma. Os dias dela passam para a versão anterior
  (ou, se era a mais antiga, para a seguinte, pela regra 3.1.3).
- **"Limpar escala"** (botão que existe hoje): continua apagando a jornada pessoal **inteira**
  (todas as versões), com a confirmação dizendo isso. A pessoa volta a seguir o padrão do escritório.
  Se o gestor quer "a partir de tal dia seguir o padrão", isso fica para a etapa do §3.3.
- Toda gravação passa pelo `Auditavel` como hoje (a trilha `audit_log` continua).
- Permissão: a mesma de hoje na rota `app_jornada_colaborador_save` (sem mudança de camada).

## 4. Dados e migration (`Version20261107100000`)

1. `ALTER TABLE jornada_colaborador ADD vale_a_partir_de DATE`.
2. Preenche cada linha com **a data do primeiro registro de ponto da pessoa naquele escritório**;
   sem registro, a data de hoje. (Pela regra 3.1.3 a data exata não muda nenhum cálculo: com uma
   versão só, ela vale para todos os dias.)
3. `SET NOT NULL`.
4. Troca o único `uq_jornada_colaborador_user_tenant (user_id, tenant_id)` por
   `uq_jornada_colaborador_vigencia (user_id, tenant_id, vale_a_partir_de)`.
5. `down()`: só desfaz se cada pessoa tiver uma versão por escritório (senão falha com mensagem),
   para não apagar histórico em silêncio.

**Sem mudar saldo de ninguém:** depois da migration cada pessoa tem exatamente uma versão, que vale
para todos os dias. Prova: teste de integração que monta folha e saldo anual antes e depois de virar
o histórico (mesmo número) e um teste da regra 3.1.3. Correções do passado (Samuel, users 11 e 12)
são feitas **pela tela**, por quem administra o ponto, depois do deploy.

## 5. Tela (aba "Jornada Personalizada", `tenant/_jornada_colaborador_tab.html.twig`)

Não há desenho do Claude Design para esta aba: segue o estilo dela (Bootstrap do sistema).

1. Acima de "Carga horária semanal": campo **"Vale a partir de"** (`input type="date"`), vem com a
   data de hoje ao abrir uma versão nova; com a data da versão ao editar uma.
   Ajuda abaixo: "A folha usa esta jornada a partir desta data. Os dias anteriores seguem a versão
   anterior."
2. Abaixo do formulário: **"Histórico da jornada"**, uma linha por versão, da mais nova para a mais
   antiga: carga semanal, resumo dos blocos ("Seg a Qui 09:00 às 19:00"), período ("a partir de
   22/04/2026 (atual)", "de 01/04/2026 a 21/04/2026", "a partir de 01/12/2026 (agendada)"),
   botões "Editar" e "Excluir" (este some quando só há uma versão).
3. Ao salvar uma data no passado, confirmação: "A folha e o saldo serão recalculados desde
   dd/mm/aaaa. Continuar?"
4. API: o GET `app_jornada_colaborador_get` passa a devolver `versoes[]` (id, valeAPartirDe,
   carga, blocos) e a vigente; o POST aceita `valeAPartirDe` e `versaoId` opcional; DELETE de versão
   em rota nova `app_jornada_colaborador_versao_delete` (`/jornada-colaborador/versao/{versaoId}`,
   CSRF como as outras).

## 6. Fora do escopo

- Versionar a padrão do escritório (§3.3, se o Coordenador decidir por etapa própria).
- Reconstruir o histórico a partir do `audit_log` (só existe desde 22/04/2026 e não cobre a criação
  das jornadas 1 a 7). Possível como comando avulso, se o Samuel quiser.
- Fechamento de folha (não existe hoje).

## 7. Plano de PRs

1. **PR 1 (motor):** migration, `vale_a_partir_de` na entidade, `HistoricoDeJornadas`,
   repositório, folha/saldo/alerta/pendências usando a versão do dia. Sem tela: comportamento igual
   ao de hoje (uma versão por pessoa). Testes: unit do histórico, unit da folha com duas versões,
   functional do saldo antes/depois.
2. **PR 2 (tela):** campo "Vale a partir de", histórico, editar/excluir versão, UseCases
   `SalvarVersaoDeJornadaUseCase` e `ExcluirVersaoDeJornadaUseCase` com unit e functional.
3. **PR 3 (opcional):** padrão do escritório com vigência.

## 8. Decisões do Coordenador (06/11/2026)

1. Padrão do escritório com vigência: **etapa própria, depois** (PR 3).
2. Data da migração: **primeiro registro da pessoa no escritório**; sem registro, a data de criação
   da jornada. A tabela não tem coluna de criação: usar a data do `create` no `audit_log` quando houver,
   senão a data da migration. Em produção as 10 jornadas têm registro, então o caso não ocorre lá.
3. "Limpar escala" **apaga todas as versões**. A confirmação avisa, em texto simples, que a folha
   passada volta a usar o padrão do escritório.
4. PR 1 não muda nenhum saldo: **teste prova que a folha antes e depois da migração é igual**.

## 9. Estado (07/11/2026)

**PR 1 (motor): pronto.** Migration `Version20261107100000`, `JornadaColaborador::valeAPartirDe`,
`HistoricoDeJornadas`, `JornadaColaboradorRepository::historicoDe()` (`findByUserETenant()` = vigente hoje),
`FolhaPontoBuilder` com a versão de cada dia, chamadores (`PontoController` folha/PDF/XLSX, `TenantController`,
`ObterEstadoDoPontoDoDiaUseCase`). Provas: `HistoricoDeJornadasTest`, `FolhaComVersoesDaJornadaTest` (caso do
Samuel: 20/04 cobra 8h, 23/04 cobra 9h) e `MigracaoDaJornadaComVigenciaTest` (folha de fev a abr e saldo do ano
iguais antes e depois; fevereiro contado antes da data da versão; data pelo `create` do `audit_log`; down recusa
com versões). `MigracaoDaJornadaPorEscritorioTest` passa a desfazer e refazer esta migration em volta da dela.

Mapa: pendências (`ListarPendenciasDeRegistroUseCase`), `SaidaDeEsquecimento` e `EscolhaDasBatidasDoDia` não leem
jornada. `IntervalosDeTrabalho`/choque, alerta e tela do ponto usam a de hoje. `PurgarEscritorioUseCase` apaga por
`tenant_id`.

### Para o PR 2 (tela), vindos da revisão do PR 1

1. **Primeira versão com data futura.** Pela regra 3.1.3 uma versão única vale para trás, então criar a PRIMEIRA
   jornada pessoal "a partir de 01/12" mudaria a folha passada de quem segue o padrão. Decidir: ou a primeira
   versão nunca vale antes da sua data quando a pessoa seguia o padrão (o histórico precisa saber disso), ou a
   tela não aceita data futura na primeira versão.
2. **Salvar e "Limpar escala" com versões.** Hoje o POST edita a vigente no lugar (reescreve desde a data dela) e
   o DELETE só limpa a vigente. No PR 2: POST com `valeAPartirDe`/`versaoId` (§3.4) e DELETE apagando todas as
   versões (§8.3). O GET (`app_jornada_colaborador_get`) passa a devolver `versoes[]`.
3. **Concorrência:** o 409 de `JornadaSalvaAoMesmoTempoTest` sai porque as duas criações recebem a mesma data;
   com a data vinda do formulário, conferir a corrida de novo.
4. **Relógio:** `findByUserETenant()` usa `new \DateTimeImmutable('today')`; com versão futura, injetar
   `ClockInterface` para testar "vigente hoje" com relógio fixo.
5. **Cabeçalho do PDF/XLSX:** falta a linha "Jornada alterada em dd/mm" quando o mês tem troca (§3.2).
6. **Consultas:** `/ponto` carrega o histórico 3 vezes (tela, folha, saldo do ano). Só desempenho; juntar se pesar.

## 10. PR 2 (tela): desenho decidido em 07/11/2026

### 10.1 O achado que muda a regra 3.1.3

Hoje quem não tem linha em `jornada_colaborador` não tem saldo calculado (`calcularSaldoAnual` devolve só as horas
pagas), e o "Limpar escala" **não apaga** a linha: tira os blocos e a carga, e a pessoa passa a seguir o padrão do
escritório, com saldo. Se a primeira versão criada pela tela valesse para trás (3.1.3), criar a jornada de alguém
"a partir de 01/12" mudaria todo o passado dela. Por isso:

- Coluna nova `jornada_colaborador.cobre_dias_anteriores` (boolean, padrão `false`). A migration do PR 2 marca
  `true` em todas as linhas existentes (as versões que vieram do PR 1, uma por pessoa).
- `HistoricoDeJornadas::vigenteEm()` passa a devolver `?JornadaColaborador`: antes da versão mais antiga, devolve a
  mais antiga **só se alguma versão do histórico tiver `cobre_dias_anteriores`**; senão `null` (sem jornada pessoal
  naquele dia, como era antes de ela existir).
- Versão criada pela tela nasce com `false`. Assim a primeira jornada de alguém vale só da data dela em diante,
  futura ou passada.

### 10.2 Regras da tela

1. Ao abrir a aba, o formulário vem no modo **nova versão**: os blocos e a carga da versão vigente hoje (ou da
   padrão, se não houver), e "Vale a partir de" com a data de hoje.
2. "Editar" no histórico carrega aquela versão no formulário (com a data dela). Salvar atualiza aquela versão; a
   data pode mudar, desde que não colida com outra (422 "Já existe uma versão da jornada que começa em dd/mm/aaaa.").
3. Salvar no modo nova versão com a data de uma versão existente atualiza aquela versão (§3.4).
4. "Excluir" no histórico: só com mais de uma versão. Se a excluída tinha `cobre_dias_anteriores`, a mais antiga
   que sobrar herda a marca (os dias de antes continuam cobertos).
5. "Limpar escala": se alguma versão tem `cobre_dias_anteriores`, apaga todas menos a mais antiga, que fica sem
   blocos, sem carga e com a marca (a pessoa segue o padrão do escritório em todos os dias, como o botão faz hoje).
   Se nenhuma tem a marca (só versões criadas pela tela), apaga todas: a pessoa volta a não ter linha, como antes
   de a jornada existir, e o saldo dela volta a ser o de antes.
   Confirmação: "A jornada personalizada e todo o histórico dela serão apagados. A folha de todos os meses,
   inclusive os passados, volta a usar a jornada padrão do escritório. Continuar?"
6. Data no passado (salvar ou editar): confirmação "A folha e o saldo serão recalculados desde dd/mm/aaaa.
   Continuar?".
7. "Habilitar alertas de ponto" não mede o passado: ao salvar, vale para todas as versões da pessoa.
8. O choque entre escritórios continua conferindo os blocos propostos, em qualquer versão.

### 10.3 Código

- UseCases: `SalvarVersaoDaJornadaUseCase`, `ExcluirVersaoDaJornadaUseCase`, `LimparJornadaPessoalUseCase`, com
  unit e functional. O controller guarda as validações de bloco e o choque, como hoje.
- API: GET devolve `versoes[]` (id, valeAPartirDe, ate, vigente, agendada, carga, blocos); POST aceita
  `valeAPartirDe` (`Y-m-d`; sem ele, hoje) e `versaoId` (opcional); DELETE
  `/jornada-colaborador/versao/{versaoId}` (`app_jornada_colaborador_versao_delete`, CSRF como as outras).
- `JornadaColaboradorRepository::findByUserETenant()` usa `ClockInterface` (pendência 4 do §9).
- Cabeçalho do PDF/XLSX com "Jornada alterada em dd/mm" (pendência 5): fica para um PR seguinte, para este não
  crescer.

### 10.4 Ajustes da revisão do PR 2 (07/11/2026)

- **Tela /ponto do colaborador.** Quem não tem linha em `jornada_colaborador` tem o dia calculado ali com uma
  jornada vazia (a padrão do escritório). Para agendar a primeira jornada não tirar esse saldo dos dias de antes,
  o `PontoController::index` usa `HistoricoDeJornadas::comJornadaAntesDaPrimeira()` com a mesma jornada vazia. A
  ficha do admin, o PDF, o XLSX e o banco do ano seguem sem saldo nesses dias, como antes de a pessoa ter jornada.

  > **Substituído pela E17** (decisão do Coordenador, 09/11/2026; spec ponto-desenho-0811). A ficha, o PDF, o XLSX e
  > o banco de horas contam pela mesma jornada da folha da /ponto (`FolhaPontoBuilder::jornadaDaFolha`): quem não tem
  > jornada pessoal e os dias antes da primeira versão contam pela jornada do escritório. O banco também não é mais
  > "do ano": não zera em janeiro.
- **Ajuda do campo** "Vale a partir de": o texto literal do §5.1, sem acréscimo.
- **"Limpar escala"**: o texto da confirmação fica o do §10.2.5 (decisão 3 do §8). Quando só há versões da tela, as
  linhas saem e a pessoa volta ao estado de antes de ter jornada: desde a E17, todas as telas contam pela jornada
  do escritório (nota acima).
- **`tem_jornada_no_escritorio()`** olha a versão que vale hoje: quem só tem versão agendada fica como quem não tem
  jornada até a data dela.
- **Choque entre escritórios**: além dos blocos propostos, quando o Editar leva a versão de hoje para uma data
  futura, a versão que passa a valer hoje também é conferida (o Excluir já fazia isso).
- **Data**: entre 2000 e 2099; editar uma versão exige a data (sem ela, 422), para a versão não mudar de data sem
  aviso.
- **`down` da `Version20261107110000`** recusa quando há jornada sem a marca (criada pela tela): o `up` seguinte a
  faria cobrir os dias anteriores e mudaria o saldo.
- **Histórico**: abaixo do formulário, da mais nova para a mais antiga, período no formato do §5.2 ("a partir de
  dd/mm/aaaa (atual)", "de ... a ...", "(agendada)"). Sem versão valendo hoje, a nova versão abre com os blocos da
  padrão.
- **Pendente (PR seguinte)**: duas gravações ao mesmo tempo nas versões da mesma pessoa não têm trava além do
  índice único (dois admins excluindo as duas últimas versões no mesmo instante; editar uma versão enquanto outro a
  exclui dá 500). Caminho: `SELECT ... FOR UPDATE` nas versões da pessoa dentro de uma transação nos três UseCases.
- **Pendente (PR seguinte)**: cabeçalho do PDF/XLSX com "Jornada alterada em dd/mm" (§10.3).
