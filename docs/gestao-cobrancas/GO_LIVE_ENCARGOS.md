# Checklist de go-live — Encargos separados e configuráveis em cascata

> ⚠️ **SUPERADO pelo modelo "ENCARGOS AO VIVO" (2026-07-20).** Este checklist descreve o modelo antigo
> (encargos **materializados + cron** de crescimento). A feature pivotou: o encargo agora é **calculado ao
> vivo** na leitura (vencimento → hoje × taxa), **sem cron** e **sem congelamento manual**. O cron
> `app:cobranca:atualizar-encargos` foi **REMOVIDO** (F4) — **NÃO agende o crontab** descrito na seção 3
> (o comando não existe mais e a linha noturna falharia). Fonte de verdade atual do *o quê/porquê*:
> `docs/specs/cobranca-encargos-ao-vivo.md`; execução: `docs/superpowers/plans/2026-07-20-encargos-ao-vivo.md`.
> Pré-requisito de go-live que PERMANECE (e fica mais crítico): **configurar as taxas das carteiras** —
> sem taxa, o cálculo ao vivo recomputa 0 e os valores "somem" (config load-bearing, §10.1 da spec nova).
> **Pendência de dados (do humano):** migrar os `encargos_congelados_em` legados das obrigações ABERTAS
> (o modelo antigo congelou ~3.262 dívidas em aberto; no modelo ao vivo elas devem voltar a crescer).

> Feature **completa e provada** na branch local `cobranca-encargos-cascata` (F1→F6). Risco **ALTO** (dinheiro).
> **Nada publicado.** push/merge/deploy = **humano**. Este documento é a lista do que conferir e executar.
> Fonte de verdade do *o quê/porquê*: `docs/specs/cobranca-encargos-configuraveis-cascata.md`.
> Estado detalhado da execução: `.superpowers/sdd/progress-encargos.md` (ledger, gitignored).

## 1. O que a feature entrega

Separa o campo único `encargosReconhecidos` da obrigação em **juros / multa / correção** (+ **honorários**),
todos materializados, com:
- **Cálculo** (`CalculadoraEncargos`, puro, aritmética 100% inteira) provado ao centavo contra dados reais.
- **Cascata de config em 3 níveis** Carteira → Caso (snapshot no nascimento) → Obrigação (override).
- **Crescimento no tempo** via cron diário `app:cobranca:atualizar-encargos` (só as **não congeladas**).
- **UI**: a linha da obrigação mostra as colunas do PDF da contabilidade
  (Original · Juros · Multa · Correção · Honorários · Total); modais de criar/editar com **% ↔ R$**.
- **Edição de obrigação paga + reconciliação do recebido**, com histórico.

## 2. Estado de prova (medido, não afirmado)

| Item | Resultado |
|---|---|
| Suíte `tests/Cobranca` | **764/764** |
| Suíte global | **2128/2128** |
| Verificação independente da fórmula (F6, subagente sem viés) | **4.317/4.317 linhas reais TOPLIFE ao centavo** (I: 3.865/3.865 · II: 452/452) |
| Half-down do juros | confirmado com **555 linhas de empate exato de .5 centavo** — todas arredondam para baixo |
| `doctrine:schema:validate` (mapping) | correto |
| Backfill da migração de dados | **diferença 0 ao centavo em 3.294 obrigações reais** |
| Bomba do cron (legado não congelado) | **desarmada**: migração congelou 3.271 obrigações com R$ 155.209,73 |

## 3. ⚠️ Duas correções de premissa que o dono precisa saber (não esconder)

A verificação independente contra 4.413 linhas reais **contrariou o brainstorm**:
1. **Multa = 2% do principal PURO** (fixa, não cresce) — não sobre principal+juros. Quem incide sobre a base
   composta (P+juros+multa) são os **honorários**.
2. **Correção = 0** em 100% das linhas (fica configurável, default 0).
3. A carência de ~30 dias é **só de honorários**; juros e multa valem desde o **1º dia**.

A opção "Fixa vs Progressiva" continua configurável (é SaaS); mudou só o **default TOPLIFE**.

## 4. Duas pendências que EXIGEM decisão/ação humana ANTES do deploy

### 4.1 Confirmar o corte exato da carência de honorários (regra de negócio)
O código usa **`d > 30`** (honorário a partir do 31º dia de atraso). Isso **reproduz 100%** das 4.317 linhas
reais — mas o dado **não distingue** o boundary exato porque **nenhuma obrigação real caiu em `d ∈ {29,30,31,32}`**
(o maior `d` sem honorário é 28; o menor com honorário é 33). Só afeta obrigações **futuras** que pousem nessa
janela. **Confirmar com a contabilidade** se o corte é "a partir do 31º dia" ou "mês seguinte ao vencimento".
Se mudar, é uma linha em `CalculadoraEncargos` + revalidar.

### 4.2 Configurar as carteiras TOPLIFE reais em produção
A fórmula certa com config errada ainda erra o valor. Em **produção**, cada carteira precisa carregar (via a
tela "Editar configuração" da carteira, que a F2 entregou):

| Carteira | Juros a.m. | Multa | Correção | Carência honor. | Tolerância juros/multa | Honorários |
|---|---|---|---|---|---|---|
| TOPLIFE I | 1,00% | 2,00% | 0 | 30 dias | 0 | **20,00%** |
| TOPLIFE II | 1,00% | 2,00% | 0 | 30 dias | 0 | **15,00%** |

> Atenuante: as obrigações **já importadas** nascem **congeladas** (`encargosCongeladosEm`), então o cron não
> as recalcula — a config errada **não** altera o histórico da contabilidade. O risco é **prospectivo**: novas
> obrigações e o crescimento das não-congeladas. Ainda assim, configure antes de ligar o cron.
>
> (No banco de **dev** ambas já foram configuradas para este smoke — dev é dado de teste.)

## 5. Passos de deploy (o humano executa)

```bash
# Execute manualmente no terminal externo

# 1) Publicar a branch para revisão (NÃO mexe no master)
cd /home/prime/projetos/jusprime
git log --oneline d19f652..cobranca-encargos-cascata   # confira os 14 commits da feature
git push -u origin cobranca-encargos-cascata

# 2) Depois de revisar/aprovar, integrar no master (decisão do humano) e:
#    - deploy em prod = REBUILD via script (prod é imagem baked, não bind-mount):
#      ./scripts/deploy-prod-tls.sh   (na VPS; o entrypoint roda as migrations sozinho)
#    Migrations que serão aplicadas: Version20260719120000 (colunas+backfill) e
#    Version20260719140000 (congela o legado com encargos > 0).

# 3) Na PRIMEIRA vez, RODE O CRON EM DRY-RUN e leia o relatório ANTES de agendar:
docker exec jusprime_php_prod php bin/console app:cobranca:atualizar-encargos --dry-run
#    Confira: "Reduções bloqueadas" = 0 (se > 0, NÃO force --permitir-reducao; confira a config das
#    carteiras primeiro — o normal é alguém ter zerado uma taxa sem querer). "Falhas" = 0.

# 4) Agendar o cron (crontab na VPS) — DEPOIS do backup (02:00) e da purga (03:00):
#    30 3 * * * docker exec jusprime_php_prod php bin/console app:cobranca:atualizar-encargos >> /var/log/jusprime-encargos.log 2>&1
#    (a seção já está documentada em DEPLOY.md; exit code 1 = alguma obrigação falhou, leia o log)
```

## 6. Migrações (aditivas, não-breaking)

- `Version20260719120000` — adiciona as colunas de encargos separados + config nos 3 níveis; backfill
  conservador `juros := encargos_reconhecidos` (preserva o saldo ao centavo). **Não remove**
  `encargos_reconhecidos` (coluna-sombra por 1 release, para rollback).
- `Version20260719140000` — congela (`encargos_congelados_em = NOW()`) as obrigações não congeladas com
  encargos > 0, para o cron não as zerar. Verificado em dev: 3.271 linhas, 0 em risco depois.

## 7. Follow-ups (fora do go-live, decisão do humano)

- **§11 configurabilidade fina** (enhancement, não bloqueia): % e taxa configurada **ao lado de cada R$ na
  linha**; forma de honorários + bases + carência/tolerância **por obrigação** nos modais; bloco de config
  herdada da carteira no cabeçalho do objeto com override nível-2. O núcleo (colunas do PDF + %↔R$ dos 3
  encargos + congelamento) está entregue.
- **Dívida transversal (não é desta feature):** 3 índices **parciais/funcionais**
  (`uniq_cobranca_obrigacao_ref_externa`, `idx_cobranca_pessoa_tenant_cpf_digitos`,
  `idx_cobranca_pessoa_tenant_cnpj_digitos`) vivem só nas migrations; `doctrine:schema:create` não os cria,
  então recriar o banco de **teste** exige rodar o `CREATE UNIQUE INDEX` à mão. Vale um hook no bootstrap de
  teste, em tarefa própria. (É a única linha do `schema:validate` fora de sincronia — **nenhuma coluna da
  feature diverge**.)
- **UI de descongelar** (`descongelarEncargos()` existe na entidade mas não tem chamador): hoje editar uma
  obrigação à mão a congela para sempre. A spec §8 marca isso como YAGNI inicial; se o dono quiser reabrir,
  é uma ação explícita a construir.
- `TipoEventoHistorico::ValorAtualizadoReconhecido` permanece ocioso (o histórico usa `ObrigacaoEditada`,
  já testado). Decisão consciente de não trocar.
