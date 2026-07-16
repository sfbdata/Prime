# Ajuste 10 — Redesenho de UX da página do objeto (`cobranca_objeto_show`)

> **Risco:** MÉDIO (dinheiro na UI — o pré-preenchimento do "Receber" decide quanto é pago;
> nenhuma regra de saldo, exigibilidade ou acordo muda).
> **Origem:** pedido do humano (2026-07-16): *"a interface não está boa para usuários comuns... menos abas
> possível, opção de pagamento e de acordo na própria obrigação, aba pessoa vira card, conceitos fortes de
> UX, iniciantes entendem o que precisa ser feito."*
> **Migration:** NENHUMA (sem mudança de schema).
> **Mockup aprovado:** validado em Bootstrap 5.3.3 com os tokens reais do módulo, nos dois temas.

## 1. Objetivo

A tela está organizada pelas **tabelas do banco**, não pelo **trabalho do usuário**: as 6 abas (Pessoas,
Obrigações, Pagamentos & Liquidações, Acordos, Documentos, Histórico) espelham as entidades Doctrine.

Reorganizar a página para responder, nessa ordem: **quanto deve** → **o que exige atenção** → **de quem** →
**o que fazer agora**. Sem tocar em regra de negócio.

## 2. Estado atual (confirmado no código — não repetir a investigação)

| Fato | Prova |
|---|---|
| 941 linhas de Twig; 1.601 com partials | `app/templates/cobranca/objeto/show.html.twig` |
| 6 abas | `show.html.twig:121-128` |
| **A aba default é Pessoas** — a menos acionável da tela | `show.html.twig:131` |
| Todo POST redireciona sem fragmento → o usuário **sempre cai em Pessoas** | controllers de Cobrança (PRG) |
| **Não existe "receber" na linha da obrigação** — só editar/excluir | `show.html.twig:235-263` |
| Pagar obrigação específica = trocar de aba + "Alocar manualmente" (6–7 cliques) | `_acoes_modais_financeiro.html.twig:20,34` |
| O modal de pagamento **não** pré-preenche data; o de contato pré-preenche | `RegistrarPagamentoInput.php:25` (null) + `MontadorModaisCaso.php:104` vs `:66-67` |
| Erro de validação vira flash e **perde o que foi digitado** | `AutorizacaoCobranca.php:106-115` (`flashErrosDoForm`) |
| 13–16 FormViews construídas em **todo** GET, independente da aba | `MontadorModaisCaso.php:69-88,103-107` |
| A página **perde a subnav** do módulo (Painel · Carteiras · Alertas) | `_subnav.html.twig` não é incluído aqui |
| "Novo acordo" só revela que não há elegível **depois** de 2 cliques | `_acoes_modais.html.twig:244-249` |
| Pagamentos não dizem **o que abateram** (o dado existe em `AlocacaoPagamento`) | `show.html.twig:288-310` |

### 2.1 Bug confirmado — a aba Documentos gruda para sempre

`pasta-arquivos.js:478-484` persiste a aba em `sessionStorage['fmTab_'+pastaId]` e a reabre no load
(`:493-495`). O listener que **limparia** a flag ao trocar de aba busca `#pastaTabs` (`:481`) — mas o
container aqui é `#objetoTabs` (`show.html.twig:121`), e **`id="pastaTabs"` não existe em nenhum template do
repo**. Efeito: depois de abrir Documentos uma vez, **todo reload força Documentos**, por caso, até fechar o
navegador.

### 2.2 O que o DTO já entrega (e o que não entrega)

`MontarDetalheCasoUseCase::agruparPorAcordo` (`MontarDetalheCasoUseCase.php:122-176`) **já** particiona em
`list<GrupoAcordoObrigacoesOutput>` + `list<ObrigacaoOutput>` avulsas, sem query extra. O redesenho **reusa**
essa partição — não cria lógica de agrupamento nova.

**Porém:** obrigações substituídas por acordo vigente são **descartadas** no `continue` da linha 136 — nunca
chegam ao template. Só sobrevive a contagem (`qtdSubstituidas`, `:169`). Expor as substituídas (§4.3) exige
mudança de DTO; **não é só Twig.**

### 2.3 Lacunas de dado nos DTOs (confirmadas por leitura, 2026-07-16)

| Falta | Onde | Consequência |
|---|---|---|
| `ObrigacaoOutput` **não expõe o alocado** | `ObrigacaoOutput.php:19-30` | **não existe "quanto falta" por obrigação** — e é esse o `D` do prefill do "Receber" (§5.1). Bloqueante. |
| `ObrigacaoOutput` tem `substituidaPorAcordo` (bool) mas **não** `acordoSubstitutoId` | `ObrigacaoOutput.php:26` | não dá para saber **por qual** acordo a obrigação foi trocada → §4.3 não agrupa |
| `PagamentoOutput` **não carrega alocação nenhuma** | `PagamentoOutput.php:17-24` | §4.4 ("o que abateu") não é renderizável hoje |
| `Obrigacao` **não tem** relação `alocacoes` nem `getValorAlocado()` | `Obrigacao.php` | **deliberado**: o ManyToOne é unidirecional (`AlocacaoPagamento.php:39-41`, sem `inversedBy`) justamente para impedir `$obrigacao.getAlocacoes()` num loop Twig e o N+1 acidental. **Não mapear o inverso.** |

## 3. Decisões fechadas com o humano (2026-07-16)

| # | Decisão |
|---|---|
| D1 | **3 abas**: Cobrança · Documentos · Histórico. Documentos e Histórico ficam intocados. |
| D2 | Pessoa deixa de ser aba e vira **card** acima das abas, com os vínculos em `collapse`. |
| D3 | "Acordar" usa **seleção múltipla** (checkbox + barra de ação) com atalho por linha; **não** é um acordo por obrigação. Motivo: acordo junta várias dívidas; um botão literal por linha induziria N acordos de 1 obrigação. |
| D4 | Escopo inclui o **lote de backend**: 4 correções pequenas + erros inline + formulários sob demanda. |
| D5 | Rótulos de domínio viram linguagem de usuário na UI: *Saldo exigível* → **"Total em aberto"**; *Saldo vencido* → **"Já vencido"**. O termo técnico fica no `title`/tooltip. |
| D6 | **A direção do tempo é declarada em cada lista** (§4.7). Feedback do humano na revisão do mockup: *"não consegui entender se começa por cima ou por baixo (a lógica do tempo)"*. |
| D7 | **Acordo sobre obrigação parcialmente paga**: **não bloquear** — sugerir o remanescente e avisar (§5.3). Renegociar o resto é fluxo legítimo; a causa real é o sistema sugerir o valor cheio e esconder o pago. |

## 4. Estrutura nova

```
┌ subnav do módulo (DEVOLVIDA)              Painel · Carteiras · Alertas
├ cabeçalho          ← identificação · badge status · [Registrar contato] · [⋯]
├ COCKPIT            ← Total em aberto · Já vencido · Honorários · Próxima ação
├ ALERTAS ACIONÁVEIS ← cada alerta ganha o botão que leva à ação
├ CARD DA PESSOA     ← quem estamos cobrando + [Trocar] + Envolvidos (collapse)
└ ABAS (3)
    ├ Cobrança   → Dívida em aberto · O que já entrou · Acordos encerrados
    ├ Documentos → INTOCADA
    └ Histórico  → INTOCADA
```

### 4.1 Cabeçalho

`Encerrar cobrança` sai da barra e vai para um menu `⋯` junto com `Judicializar`. Quando o saldo não é zero,
o item nasce **desabilitado com tooltip dizendo o quanto falta** — em vez de deixar o usuário clicar e tomar
`SaldoNaoResolvidoException` (`EncerrarCasoUseCase.php:56`) na cara como flash.

### 4.2 Alertas acionáveis

Hoje `caso.alertas` renderiza badge + texto, read-only (`show.html.twig:109-118`). Passam a ter ação:

| `TipoAlerta` | Ação |
|---|---|
| `obrigacao_vencida` | "Ver na dívida" → âncora na seção Dívida |
| `parcela_acordo_vencida` | "Abrir acordo" → `cobranca_acordo_show` |
| `acao_atrasada` | "Concluir" → `#modalConcluirAcao` |
| `pronto_para_encerrar` | "Encerrar cobrança" → `#modalEncerrarCaso` |

**Os alertas continuam derivados e read-only** (invariável 28: *o sistema alerta, o humano decide*). Ganham
só um atalho de navegação — nenhum alerta executa mutação sozinho.

### 4.3 Seção "Dívida em aberto" — a espinha da tela

Funde as antigas abas Obrigações e Acordos, porque separá-las mente sobre o domínio: **com acordo vigente,
as parcelas *são* a dívida e as originais saem do saldo** (`ObrigacaoRepository.php:103-118`).

- **Avulsas** (`obrigacoesAvulsas`) como linhas, vencidas com stripe de severidade (`.is-vencida`, o mesmo
  `rgba(var(--bs-danger-rgb), .05)` + `inset 3px 0 0` já usado em `cobrancas.css:52-53`).
- **Cada grupo de acordo vigente** (`GrupoAcordoObrigacoesOutput`) vira um card com as parcelas como linhas
  e uma barra de progresso "N de M parcelas pagas".
- **Substituídas** ficam recolhidas atrás de um toggle: *"Mostrar N obrigações trocadas pelo acordo #X — fora
  do total em aberto"*, riscadas, com a legenda **"volta ao total se o acordo for rompido"**. Isso torna a
  derivação visível, que hoje é invisível e é a maior fonte de confusão do módulo.

**Mudanças de DTO exigidas:**
- `ObrigacaoOutput` ganha `acordoSubstitutoId: ?int` (hoje só há o bool, §2.3).
- `GrupoAcordoObrigacoesOutput` ganha `substituidas: list<ObrigacaoOutput>`, e `agruparPorAcordo` passa a
  coletá-las no balde do acordo substituto em vez de só `continue`-ar (`:136-138`).

### 4.4 Seção "O que já entrou"

Pagamentos + liquidações numa lista só.

**Fatia OPCIONAL — "o que este pagamento abateu":** o dado existe em `AlocacaoPagamento` e nunca foi exibido,
mas `PagamentoOutput` não o carrega (§2.3) e `somasPorObrigacaoDosCasos` **não serve** (ele agrega por
obrigação, não por pagamento). É a **única query genuinamente nova** de todo o ajuste, e foi proposta pelo
redesenho, não pedida pelo humano. **Se o custo apertar, esta fatia cai primeiro** — o resto da spec não
depende dela. Se entrar: método novo em `AlocacaoPagamentoRepository` (é onde os dois irmãos já vivem),
em lote por caso, jamais por pagamento dentro de loop.

### 4.5 Seção "Acordos encerrados"

Rompidos/cancelados, read-only, com o motivo e a legenda de que **não afetam o total em aberto**.

### 4.6 Encanamento do "quanto falta" — reusar, não inventar

**Não precisa de query nova.** `AlocacaoPagamentoRepository::somasPorObrigacaoDosCasos(array $casoIds, Tenant)`
(`AlocacaoPagamentoRepository.php:74`) já devolve `obrigacaoId => Σ alocado` em **uma** query
(`groupBy('a.obrigacao')`, tenant nos dois lados).

**O precedente a copiar já está em produção:** `MontarDetalheAcordoUseCase.php:39` chama exatamente esse
método e alimenta `ParcelaAcordoResumoOutput` com `alocado`/`quitada`, renderizado em
`acordo/show.html.twig:90-93`. **A tela do acordo já mostra "quanto falta" por parcela; a do objeto não.**
Este ajuste só estende o mesmo padrão.

Três pontos, **+1 query** no total da página:

1. **`MontarDetalheCasoUseCase.php:60`** — injetar `AlocacaoPagamentoRepository`, chamar
   `somasPorObrigacaoDosCasos([$caso->getId()], $caso->getTenant())` **uma vez**, e passar
   `$alocado[$o->getId()] ?? 0` no `array_map`. Usar `$caso->getTenant()` (`CasoCobranca.php:118`) mantém a
   assinatura de `executar(CasoCobranca $caso)` intacta — logo `MontarDetalheObjetoUseCase.php:30` e
   `ObjetoController.php:91` **não mudam**. É o que `CalculadoraSaldo::saldoExigivel` já faz (`:56`).
2. **`ObrigacaoOutput`** — ganha `alocado: int`; derivar `restante = max(0, valorAtual - alocado)` e `quitada`,
   espelhando `ParcelaAcordoResumoOutput.php:19-20`. `fromEntity` (`:34`) ganha 2º parâmetro `int $alocado = 0`
   — **o default preserva os testes existentes** em `tests/Cobranca/Unit/ObrigacaoOutputTest.php`.
3. **Template** — como `show.html.twig` é reescrito por este ajuste, não há "linha a patchar": as linhas de
   obrigação (avulsas e parcelas) nascem já exibindo `restante`, no formato de `acordo/show.html.twig:90-93`.

> ⚠️ **Armadilha de aritmética (não cair nela):** a aba usa `doCaso` (`ObrigacaoRepository.php:78`), que traz
> **todas** as obrigações — inclusive substituídas e parcelas de acordo desfeito. O mapa cobre todas, então o
> lookup funciona em qualquer linha. **Mas somar os `restante` da tela NÃO reproduz `caso.saldoExigivel`**,
> porque `doCasoExigiveis` exclui justamente essas. **Nunca somar restantes para "conferir" o saldo.**
>
> Note também que `GrupoAcordoObrigacoesOutput.valorTotal` (`MontarDetalheCasoUseCase.php:158-161`) hoje soma
> `valorAtual` **bruto, sem abater alocado** — se o card do acordo exibir "restante", precisa de campo próprio,
> não do `valorTotal`.
>
> **Não mapear** o inverso `Obrigacao.alocacoes`: a unidirecionalidade é a defesa contra N+1 (§2.3).

### 4.7 A lógica do tempo — declarada, não adivinhada

Levantado pelo humano ao revisar o mockup (D6). Duas listas da mesma tela correm em **direções opostas**, e
nada dizia isso:

| Lista | Direção | Por quê |
|---|---|---|
| **Dívida em aberto** | mais **antiga** → mais nova | é uma **fila**: o FIFO abate sempre a mais velha primeiro (`AutoAlocadorFifo`) |
| **O que já entrou** | mais **recente** → mais antiga | é um **extrato**: o que importa é o que houve por último |
| **Histórico** (aba) | mais recente → mais antiga | já é assim hoje (`caso.historico|reverse`, `show.html.twig:394-406`) |

A divergência é **correta e permanece** — o erro era não comunicá-la. Três correções:

1. **A data vira coluna própria**, primeira depois do checkbox, `tabular-nums`, com o tempo relativo embaixo
   ("há 128 dias" / "em 25 dias"). Hoje a data está enterrada numa linha de metadados: **sem coluna não há
   eixo, e sem eixo não há como ver a ordem.**
2. **Cabeçalho de colunas** em cada lista ("Venceu em · O que é · Valor"), que ancora onde começar a ler.
3. **Rótulo de ordenação no cabeçalho da seção** ("Da mais antiga para a mais nova"), com tooltip que
   aproveita para **ensinar o FIFO de graça**: *"é nesta ordem que o pagamento abate a dívida"*.

**Ordenação é fixa e declarada — não há controle de sort.** Um toggle por coluna serviria ao usuário
avançado e confundiria o iniciante, que é o alvo declarado deste ajuste.

## 5. Comportamento das ações novas

### 5.1 "Receber" na linha — e o gross-up dos honorários (o ponto crítico)

**Semântica:** `Receber` numa linha = **alocação manual naquela obrigação**, não FIFO. Se o usuário clica em
Maio com Março em aberto, foi isso que ele pediu. O FIFO continua sendo o default do botão genérico
"Registrar pagamento" da seção (`AutoAlocadorFifo`).

**O alvo `D`:** o prefill mira o **restante da obrigação**, não o valor cheio:
`D = max(0, valorAtual − alocado)` — dado que só existe depois de §4.6. Uma obrigação com R$ 400 já
recebidos de R$ 1.200 tem `D = 800,00`.

**A armadilha:** na forma `acrescido_divida`, `CalculadoraHonorarios::ratearPagamento`
(`CalculadoraHonorarios.php:49-66`) rateia o valor **bruto** digitado:
`hon = round(T·pb/(10000+pb))`, `divida = T − hon`.

Logo, pré-preencher com o restante da obrigação (D) **não quita a obrigação**:

> Março deve R$ 1.200,00, honorários 10%. Prefill ingênuo = R$ 1.200,00 → dívida R$ 1.090,91 +
> honorários R$ 109,09. **Sobram R$ 109,09** e o usuário não entende por quê.

**Correto:** pré-preencher o **bruto** `T` cuja parte-dívida é exatamente `D`:
`T = arredondarFracao(D · (10000 + pb), 10000)`.

Conferido à mão: `D=120000, pb=1000 → T=132000 → hon=12000, dívida=120000` ✓;
`D=101, pb=1000 → T=111 → hon=10, dívida=101` ✓.

**Implementação obrigatória:** um método público novo em `CalculadoraHonorarios`, inverso de
`ratearPagamento` — p.ex. `brutoParaRecuperar(CasoCobranca $caso, int $dividaAlvoCentavos): int`. Nas formas
sem percentual e em `pb === 0`, retorna `D` (espelhando `CalculadoraHonorarios.php:51-59`).

**Teste de propriedade obrigatório (round-trip):** para uma faixa ampla de `D` e de percentuais,
`ratearPagamento($caso, brutoParaRecuperar($caso, $D))[0] === $D`. Dupla-arredondamento não se valida por
inspeção — **se o round-trip não fechar em algum ponto, o prefill está errado e a spec precisa voltar ao
humano**, não ser "ajustado" com um `+1`.

**Segurança:** o prefill é **sugestão**; o usuário pode editar. A prévia ao vivo
(`cobranca_pagamento_previa`) continua mandando, e os guards do domínio seguem intactos —
`AlocadorPagamento` (Σ alocações == parte-dívida, `:71`; obrigação do mesmo caso, `:55`).

### 5.2 "Acordar" — seleção múltipla

- **Checkbox só nas linhas acordáveis.** Parcela **nunca** tem checkbox nem botão: `acordoOrigem !== null`
  é barrado por INV-I (`CriarAcordoUseCase.php:108`), porque acordo sobre acordo **duplica dívida no saldo**
  pelo vetor de rompimento (ver `cobranca-ajuste9`).
- **Barra de seleção** (sticky) mostra quantidade + soma e oferece "Fazer acordo com estas".
- **Botão da linha** = atalho: marca só aquela e abre o mesmo modal.
- Ambos abrem `#modalCriarAcordo` com as escolhidas **pré-marcadas** — o modal e o `AcordoCriarType` não
  mudam de contrato; só chegam com checkboxes já marcados e o gerador de parcelas recalcula (`show.html.twig:730-732`).
- Se não há nenhuma acordável, o botão da seção nasce **desabilitado com tooltip**, em vez de revelar o vazio
  depois de 2 cliques.

> ⚠️ **A assimetria da spec §5.1.1 permanece intocada:** o render filtra **substituíveis**
> (`ObrigacaoRepository::doCasoSubstituiveis`, `:136`) e o POST valida contra **exigíveis**. Parece
> divergência e **é deliberada — NÃO igualar.**

### 5.3 Acordo sobre obrigação parcialmente paga — bug CONFIRMADO em prod

> **Achado desta investigação (2026-07-16).** Não é regressão do redesenho: é **pré-existente e alcançável
> hoje**. Veredito de revisão adversarial: **CONFIRMADO**, com prova em SQL no dev (caso 295) — com o acordo
> `cancelado`, `pago = 245.455`; simulando o **mesmo** acordo como `ativo`, **`pago` cai para 0**.

**Mecanismo.** `CalculadoraSaldo::saldoExigivel` (`:47-61`) coleta os IDs **só das exigíveis** e subtrai
`totalAlocadoEmObrigacoes($ids)` (`AlocacaoPagamentoRepository.php:49-62`, filtra `a.obrigacao IN (:ids)`).
Quando `CriarAcordoUseCase.php:113` marca `setAcordoSubstituto`, a original sai de `doCasoExigiveis`
(`ObrigacaoRepository.php:103-118`) — **e leva a alocação junto**. O comentário em `CalculadoraSaldo.php:57`
admite o design: *"as substituídas e suas alocações saem juntas"*.

**Cenário mínimo (fluxo canônico de cobrança — pagou parte, renegocia o resto):**

| Momento | Estado | Saldo |
|---|---|---|
| Abril `valorOriginal=120000`, alocado `40000` | exigível | **80.000** |
| Acordo substitui Abril por 3 × `40000` (= o que o form sugere) | Abril fora do exigível; alocação some da subtração; parcelas entram por 120000 | **120.000** |

**Os R$ 400 evaporam.** E o gestor não tem como saber: `MontadorModaisCaso.php:61-64` passa ao modal só
label + valor cheio; `alocado` só aparece em `acordo/show.html.twig:90-93`, para parcelas de acordo **já
criado** — nunca na lista de substituíveis.

**A causa raiz é dupla:**
1. `AcordoCriarType::opcoesObrigacoes` (`:86-96`) e `valoresObrigacoes` (`:105+`, que alimenta o
   `data-valor-centavos` do gerador JS) usam `valorOriginal + encargosReconhecidos` — **valor cheio, sem
   descontar alocação**.
2. `CriarAcordoUseCase` **nem injeta** `AlocacaoPagamentoRepository` (`:39-45`). Assimetria gritante contra
   `EditarAcordoUseCase.php:132-135,151-154` (INV-C, `ObrigacaoComPagamentoException`),
   `ExcluirObrigacaoUseCase.php:57-59` e `EditarObrigacaoUseCase.php:65`.

**Cobertura hoje: ZERO.** `CriarAcordoUseCaseTest` tem 11 testes (`:65-405`), nenhum toca alocação/pagamento.
Nenhuma invariável cobre o caso: `cobranca-ajuste7:205-206,257` define INV-C **só para editar parcela**.

**Decisão (D7): NÃO bloquear.** Renegociar o saldo remanescente é fluxo legítimo, e as partes podem acordar
qualquer valor — o saldo virar o total negociado é o design correto do domínio. O defeito é o sistema
**sugerir** um número que ignora pagamentos e **esconder** que eles existem. Bloquear (simétrico ao INV-C)
mataria um fluxo real sem oferecer alternativa.

**O que fazer:**
1. `AcordoCriarType::valoresObrigacoes` e `opcoesObrigacoes` passam a usar **`valorExigivel − alocado`**
   (remanescente) — a mesma fonte de §4.6, sem query nova, injetando o mapa já carregado.
2. O label da opção mostra o remanescente e, quando `alocado > 0`, sinaliza *"R$ X já recebidos"*.
3. O modal exibe **aviso** quando alguma obrigação marcada tem `alocado > 0`, explicitando que o valor
   sugerido é o **remanescente**, não o original.
4. A seção Dívida do redesenho já mostra `restante` por linha (§4.6) — **metade da mitigação sai de graça**.

**Testes obrigatórios:**
- **Integration** — o teste que hoje passaria verde documentando o furo: obrigação 120000 com alocação 40000
  → assertar `saldoExigivel == 80000`; criar acordo substituindo-a por parcelas somando 120000 → assertar o
  saldo. **Este teste documenta o comportamento do domínio; ele NÃO muda com D7** (não bloqueamos).
- **Unit** — `valoresObrigacoes` devolve o **remanescente**, não o valor cheio (é este que prova o conserto).
- **Functional** — o modal traz o aviso quando há obrigação com alocação; não traz quando não há.

> ⚠️ **Não "consertar" isto mexendo em `CalculadoraSaldo`.** A regra de saldo está correta e é a mesma dos
> dois lados (`derivarSaldos` alimenta o batch do Dashboard); mexer ali quebraria o módulo inteiro. **O
> conserto é de sugestão e informação, não de cálculo.**

## 6. Lote de backend

| # | Item | Onde |
|---|---|---|
| B1 | Data do pagamento pré-preenchida com hoje | `RegistrarPagamentoInput.php:25` / `MontadorModaisCaso.php:104` (espelhar `:66-67`) |
| B2 | Bug da aba grudada | `pasta-arquivos.js:481` — o seletor deve casar com o container real; conferir que a correção não quebra a página de Pasta, que compartilha o script |
| B3 | Subnav devolvida | incluir `cobranca/_partials/_subnav.html.twig` |
| B4 | Redirect volta para a seção certa (a aba Pessoas não existe mais) | controllers de mutação de Cobrança |
| B5 | **Erros de validação inline** — re-render com o modal reaberto e o erro no campo, em vez de flash que apaga o digitado | ~10 controllers + `AutorizacaoCobranca::flashErrosDoForm` |
| B6 | **Formulários sob demanda** — só os da aba aberta | `MontadorModaisCaso` + `ObjetoController.php:83-86` |

**B5 é a maior fatia e a de maior risco de regressão** (muda o contrato POST-only de vários controllers).
Deve ser fatia própria, com os functional tests existentes verdes antes e depois.

## 7. O que NÃO muda

- **Nenhuma regra de negócio.** Saldo, exigível/substituível, honorários, guards de acordo, FIFO: intactos.
- Abas **Documentos** e **Histórico**: intocadas.
- Contratos dos Form Types e dos UseCases: intactos (exceto o método novo de §5.1, **aditivo**).
- Permissões: `gerenciar` × `movimentacao_financeira` seguem separadas (`ObjetoController.php:79-80`) — o
  botão "Receber" exige `movimentacao_financeira`; "Acordar" exige `gerenciar`. **Não unificar.**

## 8. Invariantes de UI

| # | Invariante |
|---|---|
| INV-U1 | Parcela de acordo (`acordoOrigemId !== null`) **nunca** oferece checkbox nem "Acordar". |
| INV-U2 | O prefill do "Receber" é o **bruto** `T` cuja parte-dívida == `D` = **restante** da obrigação (§5.1), nunca `D` cru nem `valorAtual`. |
| INV-U3 | Alertas continuam **derivados e read-only**; ganham atalho, nunca executam mutação. |
| INV-U4 | Nenhuma cor hardcoded: só `var(--bs-*)` e o accent `--jp-accent`/`--jp-accent-rgb`; status como fundo usa `rgba(var(--...-rgb), α)`, nunca hex claro. |
| INV-U5 | Template recebe **Output DTOs**, nunca entidade Doctrine (`templates/CLAUDE.md`). |
| INV-U6 | Dados para JS via `data-*` + `json_encode|e('html_attr')`, nunca `|raw` em `<script>`. |
| INV-U7 | Valores monetários com `font-variant-numeric: tabular-nums`. |
| INV-U8 | Toda lista com eixo temporal **declara sua direção** no cabeçalho da seção e tem a data como **coluna própria** (§4.7). |
| INV-U9 | O valor de obrigação oferecido ao **gerador de acordo** é o **remanescente** (`valorExigivel − alocado`), nunca o cheio (§5.3). |

## 9. Testes

- **Unit** — `CalculadoraHonorarios::brutoParaRecuperar`: round-trip (§5.1), formas sem percentual, `pb === 0`,
  `D = 0`, D de 1 centavo.
- **Unit** — `agruparPorAcordo` com substituídas expostas: grupo carrega as substituídas certas; avulsas não
  regridem; acordo vigente sem parcela viva segue sem virar grupo (`:154-156`).
- **Unit** — `ObrigacaoOutput.restante`: piso 0 quando super-alocada; `alocado` default 0 mantém os testes
  atuais de `ObrigacaoOutputTest` verdes **sem editá-los** (se precisarem de edição, o default está errado).
- **Unit** — `MontarDetalheCasoUseCase` chama `somasPorObrigacaoDosCasos` **uma única vez** (mock com
  `expects(once())`) — é o teste que trava o N+1 por regressão.
- **Functional** — a página renderiza com 3 abas; "Receber" aparece na linha da obrigação; **não** aparece em
  linha de parcela (INV-U1); sem `movimentacao_financeira` o "Receber" some.
- **Functional** — B5: POST inválido re-renderiza com erro no campo e **preserva o digitado**.
- **Cross-tenant** — objeto de outro tenant segue 404 (`ObjetoController.php:62`).
- Suíte `tests/Cobranca` e global verdes ao fim de **cada fatia** (a contagem sobe com os testes novos; a
  baseline da última rodada foi 539 / 1854 — conferir a real antes de começar, não confiar neste número).
- **Smoke real obrigatório** antes do commit (a cadência do módulo exige MOSTRAR o smoke ao humano).
  Gotcha conhecido: o modal `#modalAlertaPonto` intercepta cliques no Playwright — remover via `browser_evaluate`.

## 10. Riscos e follow-ups

| Risco | Mitigação |
|---|---|
| B5 muda o contrato de ~10 controllers | fatia própria; functional tests verdes antes/depois |
| B2 mexe em script compartilhado com Pasta | conferir a página de Pasta no mesmo smoke |
| Prefill errado faz o usuário cobrar valor errado | round-trip test (§5.1) — falha = volta ao humano |
| Expor substituídas muda DTO consumido por outras telas | conferir consumidores de `GrupoAcordoObrigacoesOutput` |
| `ObrigacaoOutput.fromEntity` ganha parâmetro → quebra chamadores | default `int $alocado = 0`; grep dos chamadores antes |
| Alguém somar `restante` da tela e achar que bate com o saldo | armadilha documentada em §4.6; **não** exibir soma de restantes como se fosse saldo |

**Follow-up aberto (fora deste escopo):** alocação **manual** não tem teto por obrigação
(`AlocadorPagamento::montar` só valida Σ == parte-dívida, `:71`), então é possível gerar **saldo negativo**;
nesse estado o caso não alerta "pronto para encerrar" **nem pode ser encerrado** (`AlertasCobranca.php:203` e
`EncerrarCasoUseCase.php:56` usam `=== 0`). O FIFO bloqueia (`AutoAlocadorFifo.php:98`); o manual, não. Beco
estreito, real e **pré-existente** — não se mistura a este redesenho.

**Resíduo transversal conhecido:** N+1 de autorização `user_tenant` (pré-existente, MÉDIO).

## 11. Ordem das fatias

Sequencial — cada fatia fecha com suíte verde + smoke MOSTRADO ao humano antes do commit (cadência do módulo).
Nenhuma delas roda em paralelo com outra: todas tocam `show.html.twig` ou o DTO que ele consome, e escrita
concorrente no mesmo arquivo é exatamente o que o workflow proíbe.

| Fatia | Conteúdo | Por que nesta ordem |
|---|---|---|
| F1 | **Dado**: `somasPorObrigacaoDosCasos` no `MontarDetalheCasoUseCase` + `alocado`/`restante` no `ObrigacaoOutput` + `acordoSubstitutoId` + `substituidas` no grupo (§4.6, §4.3) | tudo depende disso; sem `restante` não existe prefill nem "quanto falta" |
| F2 | **`brutoParaRecuperar`** + round-trip test (§5.1) | é o núcleo de dinheiro; se o round-trip não fechar, o redesenho para aqui |
| F3 | **Redesenho do template** — 3 abas, card da pessoa, Dívida unificada, Movimentos, alertas acionáveis + CSS | consome F1/F2 |
| F4 | **Receber / Acordar** — prefill, seleção múltipla, barra sticky (§5.1, §5.2) | precisa do template de F3 |
| F4b | **Acordo sobre obrigação parcialmente paga** (§5.3) — remanescente no `AcordoCriarType` + aviso no modal + os 3 testes | usa o `alocado` da F1; é **dinheiro**, não cosmético — não adiar para o fim |
| F5 | **B1–B4** (correções pequenas) | independentes entre si, baratas; B4 só faz sentido com F3 pronta |
| F6 | **B5** erros inline (~10 controllers) | maior risco; isolada por último para não contaminar o diff do redesenho |
| F7 | **B6** formulários sob demanda | higiene; depende da estrutura final de abas |
| F8 | *(opcional)* "o que este pagamento abateu" (§4.4) | **primeira a cair** se o custo apertar |
