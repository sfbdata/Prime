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
| Twig `tem_jornada_no_escritorio()` | existe jornada | existe alguma versão |

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

## 8. Decisões pendentes

1. Padrão do escritório: etapa própria (recomendado) ou junto?
2. Data de migração: primeiro registro da pessoa no escritório (recomendado; com a regra 3.1.3 é só
   rótulo) ou uma data fixa ("desde sempre")?
3. "Limpar escala" apaga todas as versões (recomendado, é o que faz hoje) ou só a vigente?
