# Runbook — limpar o banco de Cobrança e refazer o go-live dos encargos

> Substitui, para o cenário "banco limpo", o `GO_LIVE_ENCARGOS.md` (escrito para o modelo antigo de
> encargos materializados + cron, já superado). Modelo vigente: **cascata ao vivo sem snapshot**
> (spec `docs/specs/cobranca-encargos-taxa-por-obrigacao.md` + spec #9 da cascata por objeto).
>
> Contexto: o dado de Cobrança em dev **e em prod** é dado de teste. O dono decidiu zerar os dois e
> recomeçar sobre dado limpo. Tudo aqui é **execução humana** — Claude não roda DELETE/TRUNCATE em
> banco, nem publica.

## 1. Ordem de execução

1. Backup do banco (prod tem cron `02:30`; ainda assim tire um dump manual antes).
2. TRUNCATE das 19 tabelas `cobranca_*` (§2).
3. Garantir que as migrações do #9 estão aplicadas (§3) — em prod ainda **não** estão.
4. Recriar as carteiras e configurá-las (§4).
5. Importar/registrar o dado novo.
6. Conferir (§5).

**A1 (descongelar as abertas) não faz mais parte deste roteiro** — ver §6.

## 2. Limpeza (humano)

Nenhuma tabela fora do domínio tem FK para `cobranca_*` (verificado em dev), e as 19 listadas cobrem
todas as FKs internas — então **não se usa `CASCADE`**: se um dia surgir uma tabela nova apontando para
cobrança, o Postgres recusa o TRUNCATE em vez de truncá-la junto em silêncio. Validado em dev com
`BEGIN … TRUNCATE … ROLLBACK` (rodou sem erro e sem apagar nada).

```bash
# Execute manualmente no terminal externo

# --- DEV ---
docker exec jusprime_db_dev psql -U symfony -d saas -v ON_ERROR_STOP=1 -c "
TRUNCATE TABLE
  cobranca_acordo, cobranca_acordo_documento, cobranca_alocacao_pagamento,
  cobranca_carteira, cobranca_carteira_documento, cobranca_caso, cobranca_documento,
  cobranca_evento_historico, cobranca_liquidacao, cobranca_objeto, cobranca_obrigacao,
  cobranca_pagamento, cobranca_pessoa, cobranca_pessoa_email, cobranca_pessoa_endereco,
  cobranca_pessoa_telefone, cobranca_proxima_acao, cobranca_secao,
  cobranca_vinculo_pessoa_objeto
RESTART IDENTITY;"

# --- PROD (VPS; confira o dump antes) ---
# docker exec jusprime_db_prod psql -U jusprime -d prime -v ON_ERROR_STOP=1 -c "<mesmo TRUNCATE>"
```

Só toca `cobranca_*`: clientes, pastas, processos, usuários e escritórios ficam intactos.

Os arquivos anexados (documentos de carteira/caso/acordo) **não** são apagados pelo TRUNCATE: ficam
órfãos em `app/public/uploads/cobrancas/<tenantId>/`. Se quiser zerar também, limpe à mão.

## 3. Migrações

Estas quatro são do #9 e estão aplicadas **só em dev e em `saas_test`** — prod recebe quando a branch
`cobranca-ajustes-pos-taxa-exec` for publicada e o deploy rodar (o entrypoint aplica sozinho):

| Migração | O que faz |
|---|---|
| `Version20260722023029` | qualificação da pessoa (5 colunas) + listas endereço/telefone/e-mail + backfill (#1) |
| `Version20260722040000` | documentos da carteira (#5) e do acordo (#4) |
| `Version20260722060000` | `numero_externo` + `numero_parcelas_total` no acordo (identidade de importação, #7) |
| `Version20260722070000` | **override de encargos no objeto** (nível 2 da cascata ao vivo, #9-T1) |

Conferir o que já rodou:

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/console doctrine:migrations:status'
```

## 4. Configuração das carteiras (load-bearing)

Sem taxa configurada o cálculo ao vivo devolve **0** e os encargos "somem" da tela. Configure **antes**
de importar/registrar dívida. Pela tela: Carteira → *Editar configuração* → bloco "Encargos por atraso".

| Campo | TOPLIFE I | TOPLIFE II |
|---|---|---|
| Juros a.m. | 1,00 % | 1,00 % |
| Regime de juros | simples | simples |
| Multa | 2,00 % | 2,00 % |
| **Base da multa** | **Sobre o valor original (fixa)** | **Sobre o valor original (fixa)** |
| Correção | 0 | 0 |
| Base da correção | sobre o valor original | sobre o valor original |
| Base dos honorários | **sobre o valor com encargos (progressiva)** | idem |
| Carência de honorários | 30 dias | 30 dias |
| Tolerância juros/multa | 0 | 0 |
| Forma de honorários | acrescido à dívida | acrescido à dívida |
| Percentual de honorários | **20,00 %** | **15,00 %** |

Por que essas bases: a verificação contra 4.317 linhas reais provou que **multa = 2 % do principal
puro** (fixa, não cresce) e que quem incide sobre a base composta (principal + juros + multa +
correção) são os **honorários**. Base da multa em "composta" infla o valor — foi o desvio encontrado no
smoke do dev.

## 5. Conferência (depois de configurar)

```bash
docker exec jusprime_db_dev psql -U symfony -d saas -c "
SELECT nome, taxa_juros_mensal_bp AS juros_bp, regime_juros, taxa_multa_bp AS multa_bp,
       base_multa, taxa_correcao_bp AS corr_bp, base_honorarios,
       carencia_honorarios_dias AS carencia, tolerancia_juros_multa_dias AS tol,
       forma_honorarios, percentual_honorarios AS pct
FROM cobranca_carteira ORDER BY id;"
```

Esperado: `base_multa = principal` em **todas**, `base_honorarios = composta`, `juros_bp = 100`,
`multa_bp = 200`, `carencia = 30`, `tol = 0`.

Teste ao vivo (o que prova a cascata do #9): registre uma dívida vencida há ~60 dias, anote o total,
mude o percentual de honorários da carteira e recarregue a tela do objeto — o total tem de mudar **na
hora**, sem cron e sem clicar em nada.

## 6. Por que o A1 não volta

O A1 era a limpeza de `encargos_congelados_em` de ~3.262 obrigações abertas congeladas pelas migrações
do modelo antigo (`Version20260719140000`). Num banco limpo isso não se reproduz:

- nenhum caminho do código congela uma obrigação nova — `congelarEncargos()` só é chamado de dentro de
  `Obrigacao::liquidar()` (pagamento que quita) e da substituição por acordo;
- a importação do relatório da carteira **nasce viva** (decisão D6: "NÃO congela mais").

Ou seja: depois da limpeza, obrigação congelada só existe se tiver sido paga ou substituída — que é
exatamente o comportamento desejado. Se depois de importar aparecer obrigação **aberta** com
`encargos_congelados_em` preenchido, é bug — investigue antes de descongelar na mão:

```bash
docker exec jusprime_db_dev psql -U symfony -d saas -c "
SELECT count(*) FROM cobranca_obrigacao
WHERE encargos_congelados_em IS NOT NULL AND liquidada_em IS NULL;"
```

## 7. Pendências que continuam do humano

- Publicar a branch `cobranca-ajustes-pos-taxa-exec` (push/merge/deploy).
- Ratificar as decisões do #9 (§6 da spec): nível da cascata = **objeto**, caso vira sombra, honorários
  herdam do objeto/carteira.
- Follow-ups M-1 / M-2 registrados no ledger `.superpowers/sdd/ajustes-pos-taxa.md`.
