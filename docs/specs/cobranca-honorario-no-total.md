# Spec — o honorário entra no total da dívida

> Risco **ALTO** (muda o saldo exigível de todas as carteiras em produção). Frente do espelho da
> contabilidade, §3.2 do `docs/HANDOFF_ESPELHO_CONTABILIDADE.md`. **Já aprovada pelo dono.**
> Medido em produção em **2026-08-19**.

## 1. O defeito

A contabilidade soma `principal + juros + multa + honorários`. O rodapé do relatório de
inadimplência dela (emissão 17/08), nas 3 carteiras:

| principal | juros | multa | honorários | total dela |
|---:|---:|---:|---:|---:|
| 535.384,49 | 149.771,17 | 10.705,69 | **126.878,17** | **822.739,52** |

`Obrigacao::valorExigivel()` deixa o honorário de fora. A tela mostra ~R$ 126 mil **a menos** que o
documento dela.

**O honorário já está calculado e gravado** — só não é somado. Medido: R$ 126.362,85 de honorário
materializado nas obrigações exigíveis em aberto (111.535,80 TL1 · 14.047,74 TL2 · 779,31 AMLI),
contra os R$ 126.878,17 do rodapé dela. Diferença de R$ 515,32 (0,4%).

### 1.1 Isto é o sistema formando opinião

O código diz, com todas as letras (`Obrigacao.php:28` e `:225`):

> *Honorários ficam FORA do `valorExigivel()` (INV-E2/SPEC §18.5): honorário não é dívida do credor.*

A distinção é defensável em contabilidade — mas é **julgamento**, e a regra desta frente é que o
sistema copia, não julga (handoff §1.1). Ela põe no total; nós pomos no total.

🔑 **O conserto é TIRAR a exceção, não acrescentar regra.** Espere apagar código. Se a solução ficar
maior que o problema, ela entendeu a tarefa errado. A distinção "quanto disso é honorário" continua
existindo — como **detalhamento na tela**, que é do dono, nunca como subtração do total.

**INV-E2 é revogada por esta spec.** Registrar isso explicitamente, para que ninguém a "restaure"
depois lendo o comentário antigo.

## 2. 🔴 A fórmula do exigível está DUPLICADA em três lugares

Este é o achado que decide a fatia. Trocar só o método deixaria o sistema com duas definições de
dívida — o saldo diria uma coisa e a quitação diria outra, **em silêncio**.

| # | onde | o que faz |
|---|---|---|
| 1 | `Obrigacao::valorExigivel()` (`:231`) | a canônica; 15 pontos de chamada |
| 2 | `EncargosVivos::exigivelVivo()` (`:74`) | `getValorOriginal() + juros + multa + correcao` do cálculo ao vivo — **é por aqui que o `AutoAlocadorFifo` enxerga a dívida** |
| 3 | `ReconciliadorLiquidacao` (`:83-84`) | monta a soma inline para decidir se **QUITA ou REABRE** |

**As três mudam juntas ou nenhuma muda.** O próprio repositório já registra a lição em
`ObrigacaoRepository::aplicarExigibilidade`: *"Regra de dinheiro duplicada diverge em silêncio."*

Depois desta fatia, #2 e #3 devem **delegar** para a regra canônica em vez de repetir a soma — é o
mesmo conserto que a D10 fez com os critérios de exigibilidade.

## 3. Os pontos de chamada: 15, não 35

Chamadas reais de `->valorExigivel()`, em 10 arquivos (os 35 do handoff contavam menções em
comentário e docblock). **Dois decidem estado**, o resto lê:

| grupo | onde | efeito de somar honorário |
|---|---|---|
| **decide quitação** | `ReconciliadorLiquidacao:86` (via cópia #2), `ExcluirPagamentoUseCase:175` | a barra da quitação sobe |
| **saldo** | `CalculadoraSaldo:72,106,161` | o saldo exigível sobe |
| **alocação** | `AutoAlocadorFifo` (via `exigivelVivo`), `AcordoCriarType:114,159` | há mais dívida a abater/renegociar |
| **tela** | `ObrigacaoOutput:156`, `MontarDetalheCasoUseCase:133`, `MontarDetalheAcordoUseCase:62,94,99` | os números da tela sobem |
| **aviso** | `ImpactoDaReativacaoDeAcordo:130`, `AlertasCobranca:227` | idem |

`totalComHonorarios()` (`Obrigacao:237`) vira **redundante** — passa a ser igual a `valorExigivel()`.
Remover, atualizando os 3 usos em `_divida.html.twig` e os 2 em DTOs. É o "apagar código" da §1.1.

## 4. Risco medido — e por que ele é baixo

### 4.1 Dívidas hoje quitadas que deixariam de estar: **ZERO**

Medido em produção, obrigação a obrigação: nenhuma quitada reabre, porque **nenhuma quitada carrega
honorário**. As 1.014 quitadas com juros/multa > 0 e honorário R$ 0,00 se explicam por inteiro:

| causa | quantas |
|---|---:|
| carência de 30 dias — comportamento **correto** (juros/multa desde o 1º dia, honorário só após o 30º) | 601 |
| parcela de acordo com override `taxa_honorarios_bp = 0` — é a §3.4, não esta fatia | 343 |
| criadas-já-pagas pelo importador de receitas (`ImportarReceitasUseCase:564` grava `honorariosBp = 0`) | 70 |
| **sem explicação** | **0** |

O override zero existe em 1.906 obrigações e **100% delas são parcela de acordo**.

⚠️ Este zero é **frágil por dependência**: ele vale porque o honorário das quitadas é zero hoje. A
§3.4 (que conserta o honorário zerado da parcela de acordo) muda essa população. **Se a §3.4 entrar
antes desta fatia, remedir.**

### 4.2 O efeito é sobre dívida EM ABERTO

O saldo exigível sobe **R$ 126.362,85** nas 3 carteiras. Nenhuma dívida quitada volta a abrir.

## 5. A ordem de alocação — a pergunta se dissolve

O handoff manda *"não escolham uma ordem: descubram a dela"*. Medido, a resposta é que **não há
ordem a descobrir de nenhum dos dois lados**:

- **Do lado dela:** o relatório de receitas não tem ordem, tem **rateio declarado**. Cada recebimento
  aparece repartido por categoria (`1.1 Taxa de condomínio`, `1.4 Juros`, `1.5 Multas`,
  `1.15 Honorário advocatício`, `1.6 Descontos`), tudo na mesma data. Não é uma cascata; é um extrato.
- **Do nosso lado:** `cobranca_alocacao_pagamento` guarda **um único `valor` por obrigação** — não
  existe alocação por categoria. Logo não há "para onde o dinheiro vai" a decidir: o honorário
  simplesmente entra no número único que o pagamento abate.

E a separação que existe já está preenchida e conferida: `cobranca_pagamento` tem
`valor_divida`/`valor_encargos`/`valor_honorarios`, e o honorário recebido soma **R$ 54.253,67**
contra os R$ 54.135,16 da categoria `1.15` dela. Melhor ainda: **Σ pago = Σ alocado ao centavo nas
três carteiras** — todo o dinheiro de honorário já está abatendo dívida hoje, contra um exigível que
não o contava.

🔑 **Nada a construir aqui.** Nem ordem de alocação, nem leitura nova do relatório de receitas.
Registrado para que a fatia não invente trabalho que a medição já eliminou.

## 6. A coluna-sombra fica como está

`encargos_reconhecidos` = `juros + multa + correcao` (`sincronizarSombraDeEncargos`) existe para que
um **rollback do deploy** encontre o saldo intacto. Ela deve continuar **sem** honorário: é
exatamente o valor que a versão anterior espera ler. Mexer nela quebraria a rede de segurança que
ela é. Não é esquecimento — é decisão, e está registrada aqui.

## 7. Prova

**Prova por reintrodução, EXECUTADA** — apagar a correção, ver vermelho, restaurar, ver verde, e
dizer qual teste morreu. Nesta frente quatro correções já entraram declaradas como provadas sem
estar; leitura de código não conta como prova.

Testes obrigatórios:

1. **A duplicação (§2), um teste por cópia.** Um teste que só exercite o método deixaria #2 e #3
   passarem: precisa haver teste que quite/reabra uma dívida (caminho #3) e teste que aloque via FIFO
   (caminho #2) com honorário > 0. **É o teste que pega o defeito que esta spec existe para evitar.**
2. Saldo do caso sobe exatamente o honorário das obrigações em aberto.
3. Dívida com honorário > 0 e alocado = principal+juros+multa **não** é quitada.
4. Carência: dívida com ≤ 30 dias de atraso segue com honorário 0 e o total não muda.
5. Isolamento multi-tenant nos caminhos tocados.
6. Suíte completa verde na frente **e de novo no master depois do merge** — é o passo que todo mundo
   pula e que já salvou esta frente duas vezes.

## 8. O que esta fatia NÃO faz

- Não conserta o honorário zerado da parcela de acordo (**§3.4**) — população e mecanismo diferentes.
- Não muda arranjo, rótulo ou agrupamento de tela: isso é do dono.
- Não desmonta o cálculo ao vivo (`EncargosVivos`) — é projeção aceita.
- Não toca a coluna-sombra (§6).

## 9. Depois de pronto

⚠️ **O total na tela sobe ~R$ 126 mil.** O dono avisa a equipe de cobrança antes do deploy. Não é
dinheiro novo: é dinheiro que a contabilidade já cobrava e o sistema não mostrava.
