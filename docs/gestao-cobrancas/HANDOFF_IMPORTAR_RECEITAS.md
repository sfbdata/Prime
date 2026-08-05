# HANDOFF — Importar "Receitas detalhadas" — ETAPA 3 FECHADA. Importação DESTRAVADA.

**Aberto em 2026-08-01, reescrito em 2026-08-03** ao fechar a etapa 3.
Risco **ALTO**. Specs: `docs/specs/cobranca-importar-receitas.md` (etapas 1-2) e
`docs/specs/cobranca-receitas-parcela-de-acordo.md` (**etapa 3, é ela que manda agora**).

> ✅ **A TRAVA CAIU.** O NN da planilha é parcela de acordo, e o importador agora sabe disso: a coluna J
> decide dois caminhos, e os 187 recebimentos que são parcela nascem ligados ao acordo, que é criado se
> não existir. Rodado com `--confirmar` **no DEV** e provado (§10).
>
> ⛔ **Produção continua sua.** Nada foi enviado nem deployado.

---

## 1. Onde a frente parou

- **24 commits não publicados**, suíte **3200/3200**, sem migration.
- **DEV importado e conferido** (§10). **Produção intocada.**
- Confira sempre com os comandos, não com este arquivo:
  `git rev-list --count origin/master..HEAD` · `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit'`

Commits da etapa 3:

| | |
|---|---|
| `d4afb53b` | a spec |
| `8734814d` | a implementação: a coluna J decide dois caminhos |
| `150b7aa6` | correções da **1ª** revisão (9 achados, 1 bloqueante de dinheiro) |
| `ecc43a09` | correções da **2ª** revisão — que achou defeito nas correções da 1ª |
| `53b7f527` | correções da **3ª** revisão — que achou defeito nas correções da 2ª |

## 2. O que a etapa 3 fez

A coluna J (`"Acordo 348 - Parc. 1/40"`) decide dois caminhos na gravação:

- **vazia** → boleto avulso, exatamente como na etapa 2 (1.891 dos 2.078 recebimentos);
- **preenchida** → a obrigação nasce como **parcela** do acordo N, que é **criado** se não existir
  (decisão A1), com `numeroExterno`, `numeroParcelasTotal` e `dataAcordo` derivada da competência.

Mais **D6**: acordo rompido ou cancelado que a planilha traz volta a `Ativo`, com registro no histórico
e aviso do dinheiro que isso mexe.

## 3. O que foi gravado no DEV (medido, `saas_ux`)

| | antes | depois |
|---|---|---|
| obrigações | 3.431 | **5.504** |
| pagamentos | 0 | **2.077** |
| acordos | 10 | **116** (106 criados) |
| — `Cumprido` | 0 | **75** |
| parcelas (obrigação com `acordoOrigem`) | 51 | **238** |

**Dinheiro: R$ 379.912,02**, e os quatro baldes batem ao centavo com a contabilidade —
principal R$ 364.354,44 · juros e multa R$ 6.162,97 · honorários R$ 9.394,61.

**A §9.1 fechou na prática:** o NN `75124`, que a etapa 2 gravaria como "Taxa 03/2026" valendo
**R$ 0,00**, agora é `Acordo 348 - Parc. 1/40` valendo **R$ 242,11**, quitado ao centavo. Nenhuma parcela
de acordo ficou com valor original zero.

**Idempotência provada no dado real:** a 2ª rodada com `--confirmar` registrou **0 recebimentos**,
ignorou 2.077 e não criou acordo nenhum. Os totais não se moveram um centavo.

**Backup:** o banco de antes está em `saas_ux_antes_etapa3`. Para voltar:

```bash
# Execute manualmente no terminal externo
docker exec jusprime_db_dev psql -U symfony -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='saas_ux' AND pid <> pg_backend_pid();"
docker exec jusprime_db_dev psql -U symfony -d postgres -c 'DROP DATABASE saas_ux;'
docker exec jusprime_db_dev psql -U symfony -d postgres -c 'CREATE DATABASE saas_ux TEMPLATE saas_ux_antes_etapa3;'
```

## 4. O que precisa do seu olho na tela

Abra um devedor da **TOP LIFE** com recebimento de acordo e confira:

- a obrigação aparece como **"Acordo N - Parc. x/y"**, não como "Taxa MM/AAAA";
- ela está na seção **"Já pago"** (R5, etapa 2), quitada;
- o **acordo existe** na aba de acordos, com o número externo e o total de parcelas certos;
- um acordo de todas as parcelas pagas aparece **Cumprido**; um parcial, **Ativo**;
- ⚠️ **os smokes atrasados continuam de pé**: tela R5 (etapa 2), caso 193 (desde 01/08) e etapa 1.

## 5. ⚠️ Duas consequências que precisam da sua decisão

**(a) Os 106 acordos ficam INCANCELÁVEIS pela tela.** Toda parcela nasce com alocação, e o
`CancelarAcordoUseCase` recusa cancelar acordo com parcela paga. Para cancelar um deles será preciso
antes excluir os recebimentos um a um (etapa 1). Pega em cheio os **31 incompletos** e os **4 órfãos**.

**(b) ✅ DECIDIDA em 04/08 — o importe sempre manda:** *"quem manda é o importe sempre. o importe
sobrescreve o sistema."* A política vale para o sistema todo. O `ImportarAcordosDetalhadosUseCase` ficou
em contradição com ela (só reporta divergência de status) e precisa ser **alinhado numa frente própria**
— é código em produção e mexe em saldo.

## 5.1 ⏭️ Frentes que esta etapa deixou abertas (nenhuma iniciada)

1. **Alinhar o `ImportarAcordosDetalhadosUseCase`** à regra vigente. O dono decidiu em 04/08 que *"o
   importe sempre sobrescreve o sistema"*; aquele importador ainda só **reporta** divergência de status
   (`:598-631`) em vez de aplicá-la. É código em produção e mexe em saldo → spec + revisão própria.
2. **Automatizar o download dos relatórios** do groupcondominios. ✅ A **secretaria autorizou** a
   automação (04/08) e o download **está funcionando** (lote completo em `2026-08-04-completo/`).
   🔴 **MAS a resposta do fornecedor chegou em 05/08 e foi NÃO:** *"O Group Condomínios infelizmente não
   dispõe a API para terceiros"*. A automação usa as chamadas internas do site com o login da secretária
   — **não é integração oficial**. Riscos, medições e encaminhamento em
   `HANDOFF_AUTOMATIZAR_DOWNLOADS.md` §7.1. **Ler antes de mexer.**
   ✅ Independente disso: **validar a linha `Filtros:` do rodapé e recusar recorte inesperado** — é o que
   impede o erro dos acordos escondidos de se repetir, e **vale igual com download manual**. Provou-se em
   04/08: a Receitas vinha filtrada só por 2026 e ninguém tinha visto.
3. **Decisão (b) ainda ABERTA**: os 106 acordos ficam travados para cancelar pela tela.

## 6. A ordem para produção, quando você decidir

1. **Receitas** (`--carteira-id=1` com o arquivo `top_life_1`, `=2` com o `top_life_2`) — cria os 106
   acordos e liga as 187 parcelas;
2. **Acordos detalhados**, DEPOIS — completa as parcelas futuras dos 27 que têm aba;
3. os **4 órfãos** (acordos 212, 230, 237, 280): **não precisam de pedido à contábil** — ver abaixo.

### 6.1 🔑 O que falta é um FILTRO do export, não dado que não existe

O rodapé do relatório de Acordos detalhados diz: `Filtros: Situação do acordo: **Em andamento**`. As 74
abas são todas "Em andamento" — **acordo quitado ficou fora do export**. E 3 dos 4 órfãos terminaram de
ser pagos (212, 237 e 280 têm a última parcela paga), por isso não têm aba.

**Basta reexportar o "Acordos detalhados" com Situação = Todos.** Só o acordo **230** continua sendo uma
exceção real: está em andamento (parcela 19 de 28) e mesmo assim não tem aba.

O rodapé da Receitas diz `Período de vencimento: 01/01/2026 a 01/01/2027` — é a prova de por que faltam
parcelas **anteriores** a 2026. Alargar essa janela traz os anos anteriores, mas aumenta muito o volume:
é decisão do dono, não ajuste técnico.

Rodar o importador de Acordos detalhados ANTES não adianta: ele não cria acordo, por decisão de spec.

Em prod: `scp` → `docker cp` → `docker exec -w /var/www/app`, `APP_DEBUG=0`, `memory_limit` alto.
⚠️ **Conferir antes** se alguma importação anterior trouxe contas originais dos acordos (§3.5 da spec).


### 7.1 O que a etapa 3 acrescentou à lista

- 🔑 **Três rodadas de revisão, e as três acharam defeito NAS CORREÇÕES da rodada anterior.** A 1ª achou
  1 bloqueante de dinheiro; a correção dele criou o espelho do bloqueante, que a 2ª achou; a correção da
  2ª reintroduziu o assert vacuoso que ela mesma tinha vindo corrigir, e a 3ª achou. **Corrigir sem
  re-revisar teria fechado a etapa três vezes com a sensação de resolvido.**
- 🔑 **A prova por injeção de defeito pegou 5 problemas na primeira rodada**, três deles reais — incluindo
  `assertSame(0, (int) fetchOne(...))`, que passava com a coluna NULA porque `(int) null` é 0.
- 🔑 **Duas defesas em SÉRIE tornam o teste do caso negativo improvável.** Uma guarda redundante foi
  REMOVIDA por isso: uma defesa, uma prova.
- ⚠️ **`ResetDatabase` do Foundry DESTRÓI o `saas_test`** (que aqui vem de dump): 1.217 testes morreram
  numa rodada. Restaurado clonando de outra worktree + `migrations:execute` da migration que faltava.
- ⚠️ **O dev lê `saas_ux`, não `saas`** — e eu medi contra o banco errado mesmo tendo isso na memória.
- ⚠️ **Cache do container dev fica velho** depois de mudar assinatura de construtor: `cache:clear` com
  `memory_limit` alto (o default de 128M estoura).

## 7. Armadilhas medidas nesta frente — as que custaram tempo

- **Fato medido tem prazo de validade curto nesta fonte.** CINCO números da spec caíram ao serem
  remedidos (§10.1). Nenhum derrubou uma decisão; caíram só os números. E a causa de dois deles estava
  na **linha de filtros do próprio arquivo**, que ninguém tinha lido: o export passou a incluir a
  situação "Aberta" e filtra por **vencimento**, não por recebimento.
- **Minha primeira medição também errou** — contou o RODAPÉ do relatório como se fossem boletos em
  aberto. Só foi pega porque o relatório imprime o próprio total.
- 🔑 **Três dos quatro defeitos de teste desta etapa eram asserts que não podiam falhar**, e um deles foi
  escrito nesta mesma sessão para corrigir esse tipo de problema. A "prova por injeção" que o acompanhou
  falhou **por carona em outro assert**. Não basta ver vermelho: é preciso conferir que o vermelho veio
  do assert que se quer provar.
- **A 2ª revisão achou defeito nas correções da 1ª**, incluindo o bloqueante dela aplicado no lugar
  errado do fluxo (o aviso saía depois da gravação). Corrigir sem re-revisar teria fechado a etapa com o
  bloqueante intacto.
- **O comando não tinha teste**, e um erro fatal passou por uma suíte 3162/3162 verde. Só o dry-run
  manual pegou.
- **Prévia mente sem estado intra-execução** (já aconteceu duas vezes) — por isso prévia e confirmação
  compartilham o `EstadoDaImportacaoDeReceitas`.
- **Serviço só usado por teste é inlined pelo Symfony** e some do container.
- **`AcordoDoRelatorio` usa `parcelaIndice`/`parcelaTotal`**, não `parcela`/`totalParcelas`.

## 8. Pendências suas

- ⏳ **Smoke da tela R5** (§4) e os smokes atrasados: caso 193 (desde 01/08) e o da etapa 1.
- ⏳ **18 commits não publicados.**
- ⏸️ **§9.2 e §9.3 da spec** — duas pendências menores medidas na 2ª revisão (obrigação de R$ 0,00
  reaberta some; 3 dos 4 recebimentos que pousam em obrigação existente pagam a mais). Ambas podem
  desaparecer com a etapa 3, porque tratam de casos que viram parcela de acordo.

## 9. (histórico) A etapa 3 quando foi definida

Duas coisas, na mesma frente:

1. **Acordo a partir da Receitas** (§11 da spec, decisões A1–A3) — é o que destrava a importação.
2. **D6 — reativação por importação** (`docs/specs/cobranca-cancelar-acordo.md` §3.2).

A primeira pergunta de desenho já está posta: **os 79 acordos sem aba no relatório de Acordos** teriam de
nascer só com o que a Receitas dá (número, total de parcelas e as parcelas pagas), enquanto os 48
cobertos podem vir completos da outra fonte. Vale rodar o importador de Acordos detalhados antes.

## 10. Onde estão as planilhas

`docs/gestao-cobrancas/planilhas atualizadas/` — **gitignored (PII)**. Usar as de **03/08**
(`..._2026_08_03_...`), que são as da mesma data. Nunca commitar, nunca colar conteúdo.
