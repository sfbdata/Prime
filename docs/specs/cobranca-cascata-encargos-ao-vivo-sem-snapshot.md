# Cobrança — Cascata de encargos 100% ao vivo (sem snapshot), nível OBJETO

> Ponto **#9** dos ajustes pós-taxa (decisão do dono após estudo com a gerência, 2026-07-21). Risco **ALTO**
> (muda comportamento de dinheiro **já em produção**). **Base de implementação: a master atual** (`0ef1a8e`, já com
> encargos/taxa deployados). REVERTE parcialmente a decisão §18.2/§18.3 (snapshot no caso) da feature de encargos.

## 1. Objetivo (na voz do dono)

A config de encargos deve valer **ao vivo, em cascata de 3 níveis**, sem congelar nada por tempo:
- **Mudar a carteira** → muda **todas as obrigações de todos os objetos** daquela carteira.
- **Mudar o objeto** → muda **todas as obrigações daquele objeto**.
- **Mudar a obrigação** → muda **só ela**.

**Congelar (snapshot) só acontece em 2 casos:** quando a obrigação é **recebida** (pagamento) e quando **vira
acordo**. Fora disso, tudo é recalculado na leitura (vencimento → hoje × taxa vigente).

## 2. Estado atual (o que muda)

Hoje o `AbrirCasoUseCase` **fotografa** a config da carteira no **caso** ao nascer (spec §18.2/§18.3: "mudar a
carteira depois NÃO recalcula casos antigos"). O `ResolvedorConfigEncargos::resolverDoCaso` lê a config do **caso**
(nível do meio) e os honorários **sempre** do snapshot do caso (`forma_honorarios`/`percentual_honorarios`).

Consequência medida em prod (2026-07-21): 194 casos com honorários **fotografado em 10%**; configurar a carteira
para 20%/15% **não** os corrige (cada caso usa seu snapshot). Já os juros/multa/correção **herdam** (as colunas do
caso estão NULL nos casos antigos), então esses já reagem à carteira.

## 3. A mudança

### 3.1. Nível do meio passa a ser o OBJETO (não o caso)
- `ObjetoCobranca` ganha as colunas de override (todas **nullable** = herda a carteira), espelhando as da obrigação:
  `taxaJurosMensalBp`, `regimeJuros`, `taxaMultaBp`, `baseMulta`, `taxaCorrecaoBp`, `baseCorrecao`,
  `taxaHonorariosBp`, `baseHonorarios`, `carenciaHonorariosDias`, `toleranciaJurosMultaDias`.
- A cascata efetiva vira **Carteira → Objeto → Obrigação** (o caso deixa de participar da resolução de config).

### 3.2. Sai o snapshot do caso
- `AbrirCasoUseCase`: **remove** a cópia da config da carteira para o caso (os `set*` de taxa/honorários/base).
- `ResolvedorConfigEncargos`: novo caminho `resolverDoObjeto($objeto)` (Carteira base + overlay do objeto), e
  `aplicarObrigacao($configObjeto, $obrigacao)` no topo. `resolverDoCaso` passa a delegar ao objeto do caso.
- **Honorários herdam ao vivo:** a alíquota vem de `objeto.taxaHonorariosBp ?? carteira (forma+percentual)`, **não**
  do snapshot do caso. Com isso, os 194 casos antigos passam a exibir os **20%/15%** da carteira automaticamente
  (sem UPDATE manual) assim que esta mudança subir + a carteira estiver configurada.

### 3.3. Congelamento permanece só onde já é (AO VIVO)
- **Recebimento** (pagamento total): `liquidada_em` + snapshot (via `ReconciliadorLiquidacao`) — **inalterado**.
- **Acordo**: substituídas congelam na `dataAcordo`; a parcela nasce com o valor total (honorário embutido) e
  honorários 0 — **inalterado** (ver `#7`/`#8`).
- Nenhum outro caminho congela. Editar carteira/objeto/obrigação **nunca** congela.

## 4. UI

- **Config no OBJETO:** uma tela/modal "Editar configuração de encargos" no objeto (espelha a config da carteira e a
  da obrigação): as 4 taxas com %↔R$ + bases/carência/tolerância, todas opcionais (vazio = herda a carteira), com
  indicador "herda da carteira" e ação de voltar a herdar. Reusa `TaxaBpType`/`CentavosType` e o `ConversorTaxaEncargo`.
- A tela do objeto (`objeto/show.html.twig`) ganha o acesso a essa config.

## 5. Migração e dados

- `Version<AAAAMMDDHHMMSS>`: `ADD COLUMN` das colunas de override no `cobranca_objeto` (todas nullable). Sem backfill
  (null = herda). Conferir colisão de número.
- **Config do caso vira MORTA:** as colunas de config no `cobranca_caso` deixam de ser lidas. **Não dropar no mesmo
  deploy** (coluna-sombra por 1 release, rollback seguro); dropar em follow-up.
- **Efeito nos dados legados:** casos antigos param de usar o snapshot → passam a herdar a carteira. Isso **corrige
  os honorários de 10% → 20%/15%** por herança (pré-requisito: carteiras configuradas). É o resultado desejado.

## 6. ⚠️ Decisões defaultadas (o dono ratifica de manhã)

1. **Nível do meio = OBJETO** (não caso). *(Alinha ao "mudar o objeto muda as obrigações dele".)*
2. **Config do caso: manter as colunas por 1 release** (sombra), dropar depois. *(Rollback seguro.)*
3. **Honorários herdam ao vivo** — casos antigos passam de 10% para a carteira. *(É o fix desejado; muda o "total com
   honorários" exibido dos 194 casos — fora do saldo.)*
4. Se algum caso/objeto tinha um encargo **legitimamente diferente** da carteira, ele **perde** isso ao remover o
   snapshot (passa a herdar). Medido: os 194 estão uniformes em 10% (default, não negociação) → seguro. Confirmar.

## 7. Invariantes / segurança
- **INV-V1** preservado (Viva não persiste valor, só taxa). Motor `CalculadoraEncargos` **inalterado**.
- Congela só em recebimento e acordo (INV-V2). Multi-tenant em toda query.
- O overlay por-obrigação e o `ReconciliadorLiquidacao`/`CriarAcordoUseCase` (que a taxa por-obrigação corrigiu)
  passam a operar sobre `resolverDoObjeto` — garantir que TODOS os caminhos que chamam o motor usem a nova base
  (lição do chat 2: revisão da branch inteira obrigatória quando a base de config muda de lugar).

## 8. Testes
- Unit do resolvedor: Carteira → Objeto → Obrigação (override em cada nível vence; null herda; honorários herda).
- Unit: mudar a carteira reflete em obrigação de caso ANTIGO (sem snapshot); mudar o objeto reflete nas obrigações
  dele; mudar a obrigação só nela.
- Unit: recebimento e acordo continuam congelando (snapshot intacto).
- Functional: config no objeto (%↔R$) persiste e o saldo/linha refletem; cross-tenant negado.
- **Re-prova das suítes sensíveis** (saldo, FIFO, acordo, dashboard, pagamento, ObjetoShow) + **revisão da branch
  inteira** (opus) — obrigatória (risco ALTO, base de config mudou de lugar).
- Alvo: `tests/Cobranca` verde + global verde (container `-d memory_limit=512M`).

## 9. Fora de escopo
- Dropar as colunas de config do caso (follow-up, após 1 release).
- UI de override no nível do CASO (o meio agora é o objeto).
