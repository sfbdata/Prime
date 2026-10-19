# Ponto por escritório

Risco **ALTO** (ponto eletrônico). Frente `ponto-por-escritorio`, a partir do master. Decisão do dono (19/10, pelo
coordenador): cada batida é do escritório onde foi feita; cada escritório vê e imprime só as suas; a interjornada é
contada dentro de cada escritório; e, regra nova, quem está com a entrada aberta no escritório X não bate a entrada no
Y. Variante fixada pelo dono: a trava vale **só para entrada aberta no mesmo dia**.

Base: o modelo POR VÍNCULO já decidido em `docs/specs/ponto-isolamento-tenant.md` (cada escritório vê só o ponto batido
no contexto dele; quem tem N vínculos bate N vezes). Esta spec não muda esse modelo: torna explícito o escritório nas
duas consultas que decidem regra na hora da batida (interjornada e repouso) e acrescenta a trava. As consultas de
leitura que seguem só pelo filtro (`findBatidasDoDia` do alerta, `findByUserAndCompetencia` da folha) ficam como estão
nesta frente: não decidem regra e só rodam na web, com o filtro ligado.

**Ordem com o PR #67** (atalho de ponto do cabeçalho, aberto): esta frente entra **depois** dele. Ele mexe nos mesmos
arquivos (`PontoController`, `RegistroPontoRepository`, `PontoIsolamentoRepositoryTest`) e traz
`batidasDoDiaNoEscritorio(User, Tenant, dia)`, com o escritório explícito, que esta frente reaproveita. A implementação
corre numa branch empilhada sobre a do #67 (`ponto-escritorio`, decisão do coordenador, 19/10); quando o #61 e o #67
entrarem, traz-se o master para ela. Versão 3 (19/10): v1 não aprovada (B1, I1 a
I4, M1 a M5); v2 aprovada com ressalvas (N1 a N7, tratadas). P-A decidida pelo dono
(19/10): a liberação pelo esquecimento não existe.

## 1. O que já é verdade hoje (levantamento, 19/10)

- `RegistroPonto` é `TenantAware`, com `tenant_id NOT NULL` (`Version20260626103111`); a batida grava o tenant da sessão
  (`PontoController::batida`, `setTenant`) e o lançamento do admin, o da URL (`TenantController`).
- Toda leitura de batida na web fica no escritório: explícita (`findDataPrimeiraBatida`, competências, `desvincularSede`)
  ou pelo `TenantFilter`, ligado no `kernel.request` com o tenant da sessão (ou o da URL nas rotas `{tenantId}`).
  Folha, quadro de hoje, PDF, XLSX, saldo anual, ficha do admin, justificativas, alerta de horário: todos no escritório
  atual. Nada soma batidas de dois escritórios.
- **Ponto frágil:** `findUltimaSaida` (interjornada), `findRepousoDoDia` (repouso mínimo), `findBatidasDoDia` (alerta) e
  `findByUserAndCompetencia` (folha) filtram só por usuário e dependem do filtro estar ligado. Na web ele está; fora dela
  (comando, worker, super admin sem tenant fora de rota `{tenantId}`) somariam os escritórios. Hoje nenhum caminho faz
  isso, mas a interjornada e o repouso são regra trabalhista na hora da batida e não podem depender disso.
- Não existe hoje nenhuma consulta que procure a batida da pessoa em OUTRO escritório.

## 2. Mudanças

### 2.1 Interjornada e repouso dentro do escritório, explícitos

`RegistroPontoRepository::findUltimaSaida(User, Tenant)` e `findRepousoDoDia(User, Tenant, dia)` passam a receber o
tenant e filtrar `r.tenant = :tenant` (além do filtro). `PontoController::batida` passa o tenant da sessão. Na web o
resultado é o mesmo de hoje; a diferença é não depender do filtro. Os outros chamadores (se houver) passam o tenant que
já têm. **Não muda** o valor de `minimoMinutosInterjornada`, `minimoMinutosRepouso` nem as mensagens.

### 2.2 Trava nova: entrada aberta em outro escritório no mesmo dia

Na batida do tipo **entrada** no escritório X, antes de qualquer outra validação de posição (logo depois de validar o
tipo, para a pessoa ver o motivo certo e não um "fora da área"):

- **Entrada aberta** (v2, I1) = num escritório Y ≠ X em que a pessoa tem vínculo ativo (`user_tenant.is_active`) e que
  está ativo (`tenant.is_active`, que a exclusão desliga junto com `excluido_em`), a **última batida de hoje** (por
  `data_hora` e depois id, decrescente, até o `agora`) **não é saída**, havendo pelo menos uma entrada hoje até o
  `agora`. Batida com hora depois do `agora` (lançamento do admin para mais tarde) não conta: uma saída lançada para as
  18:00 não fecha Y às 10:00. Cobre a reentrada (entrada 08:00,
  saída 12:00, entrada 14:00: aberto), que a escolha da folha não cobre (ela fica com a primeira entrada e a última
  saída e diria "fechado"). Repouso sem retorno conta como aberto. Contam todas as batidas gravadas em Y, inclusive
  lançamento manual do admin e esquecimento aprovado (viram `RegistroPonto` igual). Ver P-B a P-D na seção 6.
- **Dia** = hoje no relógio do servidor (America/Sao_Paulo no container), o mesmo da hora gravada e da folha. O fuso da
  sede (que o `HomeOfficeResolver` usa para o home office) é ignorado de propósito: a trava fala do mesmo "hoje" que a
  folha mostra. A trava calcula o seu `agora` uma vez, logo antes de validar; a hora gravada continua sendo a de sempre
  (`new \DateTime()` no fim), porque mudar de onde vem a hora da batida não foi pedido. A diferença entre os dois é de
  microssegundos; só uma batida a 23:59:59,999 poderia cair dos dois lados, e isso é aceito.
- Com entrada aberta: **422** `{success: false, message: <MENSAGEM_TRAVA>}` e nada é gravado. A frase do dono mora
  numa constante do serviço (N7): `MENSAGEM_TRAVA = 'Você está com o ponto aberto em %s. Registre a saída lá antes.'`. O nome é o do escritório (`Tenant::getName()`). Mais de um Y aberto: cita o primeiro em
  ordem alfabética (P-E), depois do filtro do módulo (P-C, N6).
- **Onde a mensagem aparece (v2, I2).** No servidor a trava vem logo depois de validar o tipo, antes do home office e do
  GPS. Mas a tela do ponto e o atalho do cabeçalho recusam por localização **antes** de enviar (cerca do navegador): quem
  está fora da área de X vê o aviso da cerca, não o da trava. A mensagem da trava aparece para quem está dentro da área
  de X ou em home office em X.
- **Só a própria pessoa vê**: é a resposta à batida dela; nenhuma tela, folha ou ficha de X mostra o ponto de Y.
- **Variante fixada (mesmo dia).** Entrada esquecida de dias anteriores em Y não trava X. Ela já aparece em Y como
  registro incompleto na folha (pendência), e se regulariza pela justificativa de esquecimento que já existe (e pelo
  "Regularizar ponto de ontem" do atalho do cabeçalho, PR #67). A virada da meia-noite solta a trava: entrada em Y às
  23:50 e entrada em X às 00:10 do dia seguinte passam.
- **Isolada para trocar uma linha:** a janela da trava mora numa constante do serviço (`JanelaDaTrava::MESMO_DIA`); a
  variante "qualquer entrada aberta" (descartada pelo dono) seria trocar a janela de "hoje" para "qualquer dia desde a
  última saída", sem mexer no resto. Fica escrita aqui só como registro.
- **Fora da trava:** repouso, retorno e saída em X (a decisão é sobre entrada); o lançamento manual do admin
  (`TenantController`, é correção, não batida); o home office não muda nada (a trava vem antes).

**Onde mora.** Serviço `EntradaAbertaEmOutroEscritorio` (domínio Ponto), método `onde(User, Tenant $atual,
\DateTimeImmutable $agora): ?string` (o nome do escritório ou null). Quem **não tem vínculo ativo em outro escritório
ativo** não paga a consulta das batidas (M4, N5): a condição é essa, não "um vínculo só" (o super admin pode estar em X
sem vínculo em X e ter vínculo em Y). A consulta é **cross-tenant de propósito** e controlada: SQL nativo no `RegistroPontoRepository` (padrão de
`findCompetenciasComRegistroPorUsuario`, que não passa pelo filtro), com o escopo escrito à mão:
`rp.user_id = :u AND rp.tenant_id <> :atual AND rp.data_hora BETWEEN :inicio AND :agora`, `JOIN user_tenant ut ON
ut.user_id = rp.user_id AND ut.tenant_id = rp.tenant_id AND ut.is_active`, `JOIN tenant t ON t.id = rp.tenant_id AND
t.is_active`; devolve, por escritório, o nome, o tipo da última batida (`DISTINCT ON (rp.tenant_id) ... ORDER BY
rp.tenant_id, rp.data_hora DESC, rp.id DESC`) e se houve entrada (`bool_or(rp.tipo = 'entrada')` numa subconsulta
agregada), N1. O serviço decide com isso e não monta entidades (I1). Depois, P-C: para cada Y aberto,
`PermissionChecker::canAccessModule(user, Y, 'ponto')` (lê `UserTenant`, `TenantRole` e permissões, que não são
TenantAware: funciona com o filtro de X ligado). Para o super admin o `canAccessModule` é sempre verdadeiro, então o
P-C não filtra nada para ele: com vínculo ativo em Y aberto, trava (N6). Usa o índice de `registro_ponto(user_id)`. Nenhum dado de Y sai além do nome, e só na resposta da
própria pessoa.

**Corrida.** Duas entradas simultâneas em X e Y (dois aparelhos no mesmo segundo) podem passar as duas: o servidor não
tranca a pessoa entre escritórios. Proposto aceitar (P-F): é raro, fica visível nas duas folhas e não muda a regra de
nenhuma delas.

**Docblock.** `EscolhaDasBatidasDoDia.php:13-14` cita `findRepousoDoDia` e `findUltimaSaida` com a assinatura antiga:
atualizar junto (M5).

### 2.3 Cada escritório vê e imprime só as suas

Já é verdade (seção 1). A frente acrescenta os testes que faltam (seção 4) e nenhuma mudança de tela.

## 3. O que não muda

Regras trabalhistas, mínimos de repouso e interjornada, raio das sedes, `CalculadoraJornada`, `EscolhaDasBatidasDoDia`;
saldo e folha do passado; o modelo por vínculo; a jornada (`JornadaColaborador` é por pessoa, não por vínculo: follow-up
já registrado em `ponto-isolamento-tenant.md`, fora desta frente); a cerca; sem migration.

## 4. Testes

- Functional, batida (`PontoController::batida`):
  - mesmo dia: entrada aberta em Y trava a entrada em X com 422 e a mensagem com o nome de Y; nada gravado;
  - com a saída de Y batida, a entrada em X passa;
  - repouso sem retorno em Y ainda trava;
  - dia anterior: entrada sem saída em Y ontem não trava;
  - dia anterior com a entrada de Y às 23:50 de ontem (a virada da meia-noite em si é provada no unitário do serviço,
    que recebe o `agora`: a batida usa o relógio do sistema e não há relógio injetável, I3);
  - vínculo inativo em Y (ou escritório excluído) não trava;
  - repouso, retorno e saída em X não são travados por Y aberto;
  - a batida grava o tenant da sessão (lacuna de hoje);
  - interjornada: a saída de ontem em Y não conta para a entrada em X; a de X conta (isolamento explícito).
- Integração com o filtro DESLIGADO (`PontoIsolamentoRepositoryTest`): `findUltimaSaida` e `findRepousoDoDia` não
  enxergam outro escritório; a consulta nova só traz o próprio usuário, só outros escritórios com vínculo ativo, só hoje.
- Unit do serviço: última batida por escritório (só entrada, entrada e saída, reentrada depois da saída, repouso sem
  retorno), primeiro em ordem alfabética, janela do dia com `agora` às 23:59 e às 00:00, um vínculo só sem consulta.
- Decisões (N4): Y com vínculo ativo e **sem** o módulo de ponto não trava (P-C); Y só com repouso ou retorno, sem
  entrada, não trava (N1); saída lançada para depois do `agora` não fecha Y; super admin com vínculo em Y aberto trava
  (N6); P-A: o esquecimento da saída de hoje em Y, pendente, não solta. O teste confere o texto pela constante (N7).
- Mais casos (revisão): home office liberado em X; Y excluído e vínculo inativo em Y, separados; trava e interjornada
  falhando juntas (a trava vem antes); o JSON do 422 só com `success` e `message`; lançamento manual do admin em X não
  travado; lançamento manual e esquecimento aprovado em Y contando como entrada; super admin sem vínculo.
- A mensagem não aparece em nenhum HTML de X (a folha de X segue sem dado de Y).

## 5. Para o dono olhar na tela

Pessoa com vínculo em dois escritórios, **dentro da área de B** (ou com home office liberado em B): bater entrada em A,
trocar para B e tentar a entrada (vê a mensagem); bater a saída em A e a entrada em B passa; no dia seguinte, com a
entrada de A esquecida, a entrada em B passa e A mostra a pendência na folha.

## 6. Decisões (19/10)

Decididas pelo coordenador, por delegação do dono:
- **P-B.** A última batida do dia manda: a reentrada conta como aberta.
- **P-C.** Só trava por escritório Y onde a pessoa tem vínculo ativo **e** o módulo de ponto (`canAccessModule` em Y).
  Sem o módulo ela não bateria a saída lá.
- **P-D.** Repouso sem retorno em Y conta como aberto.
- **P-E.** Dois ou mais Y abertos: cita o primeiro em ordem alfabética.
- **P-F.** Corrida de duas entradas no mesmo segundo em X e Y: limite conhecido, aceito.
- Decisões técnicas da v2 (antes do GPS no servidor, "hoje" do servidor, sem consulta para quem tem um vínculo só,
  meia-noite no unitário): aceitas.

**P-A, decidida pelo dono (19/10): vale a regra dele.** Só a saída batida em Y, ou a aprovação do esquecimento pelo
gestor de Y (que vira a batida de saída em Y), solta a trava. O pedido de esquecimento ainda pendente não solta: seria
uma liberação que a própria pessoa aciona. A liberação proposta (constante, frase alternativa e a consulta da
justificativa em Y) não foi implementada; nenhuma leitura de justificativa de outro escritório existe.
