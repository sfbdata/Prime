# HANDOFF — Importar "Receitas detalhadas" (etapa 2 de 3) — FECHADA. Importação TRAVADA até a etapa 3

**Aberto em 2026-08-01, reescrito em 2026-08-03** ao fechar a etapa.
Risco **ALTO**. Spec: `docs/specs/cobranca-importar-receitas.md` (**é ela que manda**; este arquivo é só o estado).

> ⛔ **NÃO RODE A IMPORTAÇÃO.** No fim de 03/08 o dono descobriu que o NN da planilha não é um boleto
> avulso: é **parcela de acordo**. Rodar agora criaria **187 obrigações avulsas** que a etapa 3 teria de
> desfazer. Ver §2 deste arquivo e §11 da spec.

---

## 1. Onde a etapa parou

Tudo o que era trabalho de código está feito: leitura, gravação, comando, tela (R5), dry-run contra as
planilhas reais, conferência contra a contabilidade e **duas revisões com correção entre elas**.

**Nada foi gravado. Nada foi publicado.** Todas as execuções contra as planilhas reais foram dry-run.

- **17 commits não publicados**, suíte **3169/3169**, sem migration.
- Confira sempre com os comandos, não com este arquivo:
  `git rev-list --count origin/master..HEAD` · `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit'`

Commits desta etapa (a etapa 1, `40c3e05a`, está FECHADA — não reabrir):

| | |
|---|---|
| `81aa3166` | leitura da planilha |
| `edacfacc` | gravação + comando |
| `9f8b8df4` | **R5 — a tela**: "Já pago" separado do em aberto |
| `fd76b8d8` | terceiro balde (juros e multa) no resumo |
| `5f4e58bf` | spec: os três números que a remedição derrubou |
| `cdc9021d` | correções da 1ª revisão |
| `a9733865` | correções da 2ª revisão |
| `e5ef30f4` | gitignore: fecha a pasta das planilhas inteira, não só os `.xlsx` |
| `aea26965` | **a estrutura de acordo da fonte** (§11 da spec) — o que define a etapa 3 |

## 2. 🔑 A descoberta que fecha a etapa 2 e define a etapa 3

**O dono leu a coluna J e mudou o diagnóstico.** O NN não é um boleto avulso — é uma **parcela de acordo**:

```
Acordo 348                       ← coluna J: "Acordo 348 - Parc. 1/40"
  ├─ Parc. 1/40 = NN 75124       ← o boleto DAQUELA parcela
  │    └─ 4 linhas de 1.15       ← a COMPOSIÇÃO: as dívidas que entraram nela (= R$ 242,11)
  ├─ Parc. 7/40 = NN 75130       → energia + honorário
  └─ Parc. 8/40 = NN 75131       → 5 linhas de taxa + 1 de energia
```

**Isso encerra a pendência dos "37 sem principal"** (aberta desde 01/08): **37 de 37 são parcela de
acordo**. Numa parcela de acordo, não ter taxa é normal — o acordo distribui as dívidas ao longo das
parcelas. A pergunta *"é acessório de um boleto de taxa?"* tinha resposta na coluna que o adapter já lia
e o UseCase descartava. Nenhuma das três opções que estavam no handoff anterior era a certa.

### Por que a importação está travada

O `TopLifeReceitasAdapter` **já lê** a coluna J (`AcordoDoRelatorio`). O `ImportarReceitasUseCase`
**ignora**. Se rodar hoje, **187 recebimentos** que são parcela de acordo (160 na TL I + 27 na TL II)
viram obrigações avulsas "Taxa MM/AAAA", soltas, sem vínculo com o acordo.

### O que foi medido

| | TL I | TL II |
|---|---|---|
| recebidos que são parcela de acordo | **160** | **27** |
| acordos citados | 93 | 34 |
| — já existem no sistema (`numero_externo`) | 0 | **8** |
| — têm aba no relatório "Acordos detalhados" | **40** | **8** |

Total: **127 acordos citados**, **48 cobertos** pelo relatório de Acordos, **79 sem fonte completa**.
E duas propriedades que sustentam a chave: **nenhum NN em dois acordos**, **nenhum acordo cruza unidades**.

⚠️ A spec §1 dizia "106 acordos citados, **zero** existem". Errado nos dois números — corrigido.

### Suas decisões (03/08), que são o contrato da etapa 3

| # | Decisão |
|---|---|
| **A1** | Parcela paga ⇒ **o acordo existe e tem de ser criado**. Outra planilha pode ter os dados faltantes — medido: o relatório de Acordos cobre 48 dos 127. |
| **A2** | Status **`Ativo`**; só não é ativo se já terminou de ser pago (aí `Cumprido`). |
| **A3** | **A etapa 2 fecha como está**; isto vira a etapa 3, junto com D6. A importação espera. |

### O desenho, em uma frase

A coluna J decide dois caminhos: **vazia** → boleto avulso, como hoje (1.891 dos 2.077); **`Acordo N -
Parc. x/y`** → obrigação nasce como **parcela do acordo N**, criando o acordo (`numeroExterno = N`,
`numeroParcelasTotal = y`) quando não existir, ou ligando ao que já existe.

A infraestrutura já está pronta: `Acordo` tem `numeroExterno` e `numeroParcelasTotal` (com índice por
tenant), `Obrigacao` tem `acordoOrigem`.

## 3. A conferência contra a contabilidade — feita, e fecha ao centavo

Foi a primeira vez que deu para fazer (os quatro relatórios são da mesma data). 🔑 **O relatório imprime o
próprio gabarito**: depois da última linha vem o total e um quadro de recebido **por classe de conta**.

| | TOP LIFE I | TOP LIFE II |
|---|---|---|
| **Total recebido** | R$ 243.013,53 ✓ | R$ 136.898,49 ✓ |
| — principal | R$ 228.867,89 ✓ | R$ 135.486,55 ✓ |
| — juros e multa | R$ 5.610,14 ✓ | R$ 552,83 ✓ |
| — honorários | R$ 8.535,50 ✓ | R$ 859,11 ✓ |

**Os oito batem.** Total que entraria: **R$ 379.912,02** · **2.073 obrigações criadas** · 220 unidades,
pessoas e casos novos · 1 rejeição (NN `60082`, líquido zero).

## 4. O que precisa do seu olho na tela (R5)

Abra um devedor da TOP LIFE com recebimento e confira a aba **Dívida**:

- a fila de cima só tem o que está **em aberto**;
- abaixo dela, a seção **"Já pago"** recolhida, com `N obrigações · R$ X` sempre visível;
- clicar abre as linhas (pago em · o que é · recebido);
- num devedor sem nada pago a seção **não aparece**;
- num devedor com tudo pago, a fila diz "Nada em aberto" em vez do vazio genérico.

⚠️ **Muda a tela mesmo antes de importar**: obrigação quitada por pagamento **digitado à mão** também
desce para a seção nova. E o botão "Novo acordo" passou a olhar só o que está em aberto.

## 5. ⛔ Como rodar — SÓ DEPOIS DA ETAPA 3

⛔ **Não rode com `--confirmar` antes da etapa 3** (decisão A3). O dry-run abaixo é seguro e pode ser
repetido à vontade — ele não grava nada.

```bash
# 1. DEV, dry-run (não grava):
docker exec jusprime_php_dev bash -c 'cd app && APP_DEBUG=0 php -d memory_limit=2G bin/console \
  app:cobranca:importar-receitas --tenant-id=1 --carteira-id=1 --usuario-id=1 \
  --arquivo="/var/www/docs/gestao-cobrancas/planilhas atualizadas/top_life_1_Receitas_..._09_51_26.xlsx"'

# 2. DEPOIS DA ETAPA 3, com --confirmar. Em PROD: scp -> docker cp -> docker exec -w /var/www/app
```

Uma carteira por execução (`--carteira-id=1` e `=2`, com o arquivo correspondente).

## 6. Armadilhas medidas nesta frente — as que custaram tempo

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

## 7. Pendências suas

- ⏳ **Smoke da tela R5** (§4) e os smokes atrasados: caso 193 (desde 01/08) e o da etapa 1.
- ⏳ **17 commits não publicados.**
- ⏸️ **§9.2 e §9.3 da spec** — duas pendências menores medidas na 2ª revisão (obrigação de R$ 0,00
  reaberta some; 3 dos 4 recebimentos que pousam em obrigação existente pagam a mais). Ambas podem
  desaparecer com a etapa 3, porque tratam de casos que viram parcela de acordo.

## 8. A etapa 3 — agora com escopo definido

Duas coisas, na mesma frente:

1. **Acordo a partir da Receitas** (§11 da spec, decisões A1–A3) — é o que destrava a importação.
2. **D6 — reativação por importação** (`docs/specs/cobranca-cancelar-acordo.md` §3.2).

A primeira pergunta de desenho já está posta: **os 79 acordos sem aba no relatório de Acordos** teriam de
nascer só com o que a Receitas dá (número, total de parcelas e as parcelas pagas), enquanto os 48
cobertos podem vir completos da outra fonte. Vale rodar o importador de Acordos detalhados antes.

## 9. Onde estão as planilhas

`docs/gestao-cobrancas/planilhas atualizadas/` — **gitignored (PII)**. Usar as de **03/08**
(`..._2026_08_03_...`), que são as da mesma data. Nunca commitar, nunca colar conteúdo.
