# HANDOFF — Importar "Receitas detalhadas" (etapa 2 de 3) — CÓDIGO PRONTO, AGUARDA O DONO

**Aberto em 2026-08-01, reescrito em 2026-08-03** ao fechar a etapa.
Risco **ALTO**. Spec: `docs/specs/cobranca-importar-receitas.md` (**é ela que manda**; este arquivo é só o estado).

---

## 1. Onde a etapa parou

Tudo o que era trabalho de código está feito: leitura, gravação, comando, tela (R5), dry-run contra as
planilhas reais, conferência contra a contabilidade e **duas revisões com correção entre elas**.

**Nada foi gravado. Nada foi publicado.** Todas as execuções contra as planilhas reais foram dry-run.

- **15 commits não publicados**, suíte **3169/3169**, sem migration.
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

## 2. 🔴 O que trava a importação de verdade: uma decisão sua

**Não é código. É a pendência da spec §9.1, e ela está aberta desde 01/08.**

Medido no dry-run: **37 recebimentos da TOP LIFE I não têm principal nenhum** — são só honorário e/ou
juros/multa, somando **R$ 11.179,36**. Destes, **10 só de honorário** (R$ 2.618,18) criam obrigação com
valor **R$ 0,00**, descrita como "Taxa MM/AAAA" sem taxa nenhuma.

**Nenhum centavo se perde em qualquer das opções** — o total recebido fecha ao centavo com a contabilidade
nas três. O que muda é a forma do que entra:

1. **aceitar como está** (o que o código faz hoje) — histórico completo, com 10 linhas de R$ 0,00;
2. **rejeitar** — o histórico perde R$ 11.179,36 de honorário efetivamente recebido;
3. **anexar ao boleto de taxa** do mesmo devedor/competência — a hipótese que você mandou medir em 01/08
   ("se o boleto é acessório de um de taxa"). ⏳ **Ainda não medi** se existe um boleto de taxa
   correspondente para cada um dos 37; é o próximo passo se você quiser essa opção.

O comando **não decide sozinho**: ele imprime quantidade, valor e os NNs **antes** de qualquer escrita.

### Duas pendências menores, também suas (spec §9.2 e §9.3)

- **Obrigação de R$ 0,00 reaberta some** em vez de voltar a cobrar. Se você apagar o recebimento (etapa 1),
  os encargos são recalculados sobre zero e ela continua aparecendo como paga. Desaparece junto se você
  escolher a opção 2 ou 3 acima.
- **3 dos 4 recebimentos que pousam em obrigação existente pagam a mais** (R$ 0,62 + R$ 0,20 + R$ 0,80):
  o excedente abate o saldo do caso, que fica negativo. É como o sistema já se comporta com pagamento
  digitado à mão. ⚠️ **Reconferir em produção** — o número de casos lá não é conhecido.

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

## 5. Se você aprovar a importação, é assim (nada disso foi feito)

```bash
# 1. DEV, para ver de novo antes (é dry-run, não grava):
docker exec jusprime_php_dev bash -c 'cd app && APP_DEBUG=0 php -d memory_limit=2G bin/console \
  app:cobranca:importar-receitas --tenant-id=1 --carteira-id=1 --usuario-id=1 \
  --arquivo="/var/www/docs/gestao-cobrancas/planilhas atualizadas/top_life_1_Receitas_..._09_51_26.xlsx"'

# 2. só então, com --confirmar. Em PROD: scp -> docker cp -> docker exec -w /var/www/app
```

Uma carteira por execução (`--carteira-id=1` e `=2`, com o arquivo correspondente).

## 6. Armadilhas medidas nesta frente — as que custaram tempo

- **Fato medido tem prazo de validade curto nesta fonte.** Quatro números da spec caíram ao serem
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

## 7. Pendências suas (não travam o código)

- ⏳ **Smoke da tela R5** (§4) e os smokes atrasados: caso 193 (desde 01/08) e o da etapa 1.
- ⏳ **15 commits não publicados.**
- ⏸️ **As três decisões da §2** — a de "sem principal" é a que trava a importação.

## 8. A etapa 3, que vem depois

**D6 — reativação por importação** (`docs/specs/cobranca-cancelar-acordo.md` §3.2). Só depois desta, e o
motivo está medido: os 106 acordos citados pela Receitas **não existem no sistema**, então é a criação em
R1 que dá a ela em que se apoiar.

## 9. Onde estão as planilhas

`docs/gestao-cobrancas/planilhas atualizadas/` — **gitignored (PII)**. Usar as de **03/08**
(`..._2026_08_03_...`), que são as da mesma data. Nunca commitar, nunca colar conteúdo.
