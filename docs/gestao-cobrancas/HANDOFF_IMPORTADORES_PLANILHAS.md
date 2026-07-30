# Handoff — 3 importadores de planilha da contábil L.G

> Estado em **2026-07-30**. Frente `cobranca-importar-cadastro-acordos`.
> **Nada publicado, nada deployado.** 2 de 3 pendências entregues.

## Como retomar em 30 segundos

```bash
cd /home/prime/projetos/jusprime/.claude/worktrees/cobranca-importar-cadastro-acordos
git log --oneline -5
scripts/frente-testar.sh cobranca-importar-cadastro-acordos      # último verde: 2938/2938
```

A worktree tem banco de teste próprio (`saas_testcobranca-importar-cadastro-acordos`).
**Nunca** rodar `cd app && php bin/phpunit` — isso testa o repositório principal e dá verde falso.

## De onde veio

O dono baixou 3 planilhas da contábil L.G em `docs/gestao-cobrancas/planilhas atualizadas/`
(**gitignored — contêm CPF de 229 pessoas**; a regra `docs/gestao-cobrancas/*.xlsx` não cobria
subpasta e foi corrigida para `**/`).

| Planilha | Situação |
|---|---|
| Inadimplências detalhadas | **Já era suportada.** O dono importa pela tela (`/cobrancas/carteiras/{id}/importar`) |
| Dados cadastrais dos condôminos | ✅ importador entregue nesta frente |
| Acordos detalhados | ⏳ **pendência 3, não iniciada** |

## O achado que reorientou tudo: o NN não é único

`ObrigacaoRepository` casava a obrigação por **(caso, NN)**. A contábil **reaproveita o Nosso Número**:
o NN 61457 é uma taxa de 08/2022 na carteira TOP LIFE I *e* outra de 07/2026 na TOP LIFE 2.

Medido contra **produção**: 69 colisões aparentes, **todas entre carteiras diferentes** → como a busca é
escopada por caso, **colisões reais hoje = 0**. Defeito real no código, sem ocorrência no dado.

⚠️ **Durante a investigação eu soei alarme falso** ("69 boletos em risco, não importe nada") antes de
cruzar a carteira. Sob suspeita de perda de dinheiro, medir até o fim **antes** de mandar parar.

**A chave NÃO pode ser o vencimento** — o dono derrubou com um caso concreto: dívida antiga reemitida
para a pessoa pagar mantém o NN e **muda a data**; chave por data duplicaria a dívida e cobraria duas
vezes. Competência não muda → chave = **(caso, NN, competência)**.

## O que está feito

### 1. Chave NN + competência — `08766716`
- Coluna `competencia` (`MM/AAAA`) em `cobranca_obrigacao`, migration **`Version20260730120000`**
- Backfill por regex sobre `descricao`; cobre **3270/3300 (99,1%)** em produção
- `findOnePorReferenciaECompetenciaNoCaso` com **fallback** para dado legado (competência nula casa só
  pelo NN — sem isso a 1ª reimportação pós-deploy duplicaria tudo que o backfill não alcançou)
- Dois avisos novos no `ResultadoImportacao`: `referenciasReutilizadas` e `vencimentosAlterados`
- 🔑 **O teste revelou um índice ÚNICO PARCIAL** `(caso_id, referencia_externa)` criado por SQL cru na
  `Version20260710160000`, **invisível ao mapeamento Doctrine**, que impunha a suposição errada no banco.
  Trocado por `(caso_id, referencia_externa, competencia) NULLS NOT DISTINCT` — a cláusula é obrigatória
  (PostgreSQL 15+, medido 15.17): sem ela dois NULL seriam distintos e as obrigações mais antigas
  poderiam duplicar

### 2. Importador do cadastro — `b6751f8d` + `981b213d`
- Comando `app:cobranca:importar-cadastro`, dry-run por padrão, `--confirmar` persiste
- Orquestra os UseCases existentes; pessoa importada é indistinguível de pessoa da tela
- Aditivo: contato novo entra na lista e vira o atual, o anterior fica no histórico
- Leitura da planilha **real**: 242 pessoas (229 proprietários + 13 relacionadas), 229 unidades,
  331 telefones, 3 rejeições (todas de telefone; nenhuma pessoa perdida)

**Três correções que a medição impôs à spec de 29/07:**
1. O papel **vem da coluna C** (`Proprietário` / `Pessoa relacionada`), não é "a primeira é dona".
   `Pessoa relacionada` → `TipoVinculo::Outro`, **nunca Coproprietario** (a fonte não afirma isso).
2. Endereço parseado **de trás para frente** — as 13 linhas de relacionada têm 6 segmentos em vez de 7,
   e contar da esquerda faz o bairro escorregar.
3. **A regra "não casar por nome" estava errada** e o teste provou: pessoa sem CPF nasceria de novo a
   cada rodada mensal. Corrigido para casar por nome **dentro do objeto** (a "decisão A" que o importador
   de inadimplência já usa), e só entre pessoas que também não têm documento.

## O que falta

### Pendência 3 — importador dos acordos (risco **ALTO**, não iniciada)
Spec pronta: `docs/specs/cobranca-importar-acordos-detalhados.md`. Três operações:
1. completar as **5 parcelas futuras** (R$ 1.399,49 que nenhum relatório enxerga);
2. **reconciliar as contas originais** — é o que corrige **R$ 680,00 cobrados a mais do Gessi Pereira
   dos Santos** (QUADRA 05 CHACARA 03/04), único sacado afetado;
3. situação do acordo. **Baixa de pagamento fica FORA** (decisão do dono).

⚠️ **Casar por NN + competência** aqui é obrigatório. Casar só por NN marcaria 3 dívidas de 2022 da
TOP LIFE I como substituídas por acordos de 2026 da TOP LIFE 2 — apagaria R$ 435,00 de cobrança legítima.

⚠️ Decisão do dono já fechada: **criar as 21 contas originais ausentes**, nascendo já com
`acordoSubstituto` (nunca entram no saldo). Risco aceito e registrado na spec §3.2.1.

### Outras pendências
- **Avisos na tela.** `referenciasReutilizadas` e `vencimentosAlterados` existem no resultado mas **não
  aparecem no Twig** — quem importa pelo navegador não os vê. É o caminho que o dono usa de verdade.
- **`/review` da frente inteira** antes de integrar. Risco ALTO exige revisão dupla.
- **Migration não aplicada** em dev (`saas_ux`) nem em produção — só no banco de teste da frente.
- **Deploy** exige `deploy-prod-tls.sh` (tem migration). Lembrar: `bcmath` da frente do BCB também só
  entra em prod no próximo deploy.

## Estado do repositório

- Frente commitada e limpa; **8 commits** à frente de `origin/master`
- No **checkout principal** o `.gitignore` está modificado de propósito: a correção da regra de PII vive
  na frente, mas as planilhas estão fisicamente na pasta principal e ficariam desprotegidas até o merge
- Outra sessão mexeu na branch `polimento-objeto-show-cabecalho` durante o trabalho — **um piloto de git
  por vez**; conferir `git log` antes de integrar qualquer coisa

## Consultas de produção usadas (todas SELECT)

Guardadas porque a próxima rodada vai querer repetir:

```sql
-- dívidas antigas na faixa de NN do relatório novo (candidatas a colisão)
SELECT count(*) FROM cobranca_obrigacao
WHERE vencimento_original < '2025-01-01' AND referencia_externa ~ '^[0-9]+$'
  AND referencia_externa::int BETWEEN 60005 AND 61600;

-- cobertura do backfill de competência
SELECT count(*) AS total, count(*) FILTER (WHERE descricao ~ 'compet.ncia [0-9]{2}/[0-9]{4}') AS com
FROM cobranca_obrigacao;
```

## Lições desta frente

- **Um número igual não prova que é a mesma coisa.** Casar por NN sozinho me fez afirmar "R$ 1.115,00
  duplicados em 3 sacados"; o real é **R$ 680,00 em 1**. Antes de agir sobre dinheiro a partir de um
  casamento, verifique um segundo campo independente.
- **`leftJoin` é buscar, não excluir.** Afirmei que marcar `acordoSubstituto` tira do saldo tendo visto
  só o join; a prova está em `doCasoExigiveis`.
- **As perguntas do dono acharam 2 furos que eu não vi**: a reemissão de boleto e a desconfiança sobre a
  numeração repetida. Quando ele desconfia de um número, medir de novo.
- **Conferir o adapter contra a fonte REAL, não só contra a fixture.** Foi assim que apareceu o telefone
  descartado em silêncio (331 telefones × 3 lixos × 2 rejeições reportadas).
- **Provar que o teste pega o defeito**, reintroduzindo-o. Foi o que mostrou que 2 dos 5 testes da chave
  guardavam defeitos diferentes do que eu supunha.
