# Handoff — 3 importadores de planilha da contábil L.G

> Estado em **2026-07-30**. Frente `cobranca-importar-cadastro-acordos`.
> **Nada publicado, nada deployado.** As **3 pendências de importador estão entregues.**

## Como retomar em 30 segundos

```bash
cd /home/prime/projetos/jusprime                                  # o script roda do checkout PRINCIPAL
scripts/frente-testar.sh cobranca-importar-cadastro-acordos       # último verde: 2972/2972
cd .claude/worktrees/cobranca-importar-cadastro-acordos && git log --oneline -5
```

⚠️ `frente-testar.sh` usa `git rev-parse --show-toplevel`: rodado de DENTRO da worktree ele procura uma
worktree dentro da worktree e **recusa**. Recusa não é teste vermelho — é teste que não rodou. Já custou
uma bateria inteira de "provas" que não provaram nada.

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
| Acordos detalhados | ✅ importador entregue nesta frente |

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

### 3. Importador dos acordos — `37e122ce` (risco **ALTO**)
Comando `app:cobranca:importar-acordos`, dry-run por padrão, `--confirmar` persiste. 11 arquivos
**todos novos** — nenhum comportamento existente foi alterado.

- **Parcelas futuras** (§3.1): as 5 ausentes, R$ 1.399,49. Valor = soma da coluna "Valor acordado" do NN,
  honorários **zero por TAXA** (`honorariosBp = 0`, não só cache — senão a hidratação ao vivo os traria de
  volta na leitura seguinte).
- **Reconciliação** (§3.2): marca com `acordoSubstituto`, tirando do saldo. É o que corrige o Gessi
  Pereira dos Santos.
- **Reconstrução** (§3.2.1): a conta ausente nasce já substituída, com procedência na descrição
  (`Reconstruída da planilha de acordos (emissão dd/mm/aaaa)`) — a `Obrigacao` não tem campo de
  observação, e o importador de inadimplência já usa essa convenção.
- Ao marcar, os encargos são **materializados na data do acordo**, como o `CriarAcordoUseCase` faz pela
  tela. Não muda saldo (substituída sai do exigível); muda o número que a tela exibe, que passa a ser o
  valor na data da renegociação em vez do cache da última vez que alguém abriu a página.

**Cinco recusas deliberadas, todas reportadas — nenhuma silenciosa:**

| Situação | O que faz |
|---|---|
| acordo não existe na carteira | aba ignorada (quem cria acordo é a inadimplência) |
| valor da planilha ≠ valor lançado | reporta, **não sobrescreve** |
| a "conta original" é parcela de acordo | recusa (INV-I: duplicaria a dívida ao romper) |
| mesmo NN de parcela com outra competência | **não cria** (criar é a direção que cobra) |
| planilha diz "Em andamento", sistema diz Rompido | mantém o sistema, reporta |

🔑 **§3.3 nunca escreve status, de propósito.** A única situação da fonte (`Em andamento` → `Ativo`) já é o
status de todo acordo importado — escrever seria no-op. E em todo caso em que **não** seria no-op, o status
do sistema é decisão manual do escritório que move dinheiro: ressuscitar um acordo rompido a partir de uma
planilha tiraria as originais do saldo de novo, desfazendo em silêncio o que uma pessoa decidiu.

🔑 **`prever()` e `confirmar()` percorrem o MESMO método** (`$usuario === null` é o dry-run). O irmão
`ImportarRelatorioCarteiraUseCase` tem as duas escritas separadas e elas já divergiram uma vez; aqui a spec
exige conferir a projeção contra o efeito antes de mexer em dinheiro de produção, e duas implementações da
mesma decisão não garantem isso.

**Adapter conferido contra a planilha REAL** (não só contra a fixture): 7 abas, 25 contas originais, 12
parcelas, 0 rejeições, e a soma de cada aba batendo com o cabeçalho **da própria aba** (14 conferências
independentes). As 5 parcelas ausentes somam exatamente R$ 1.399,49; o acordo 37 soma exatamente R$ 680,00.

Duas armadilhas medidas na fonte, cada uma capaz de derrubar uma parcela inteira:
1. **toda célula é string**, inclusive dinheiro e data — "170,00" nunca chega como float;
2. **o desconto vem `-\u{00A0}3,04`**, com espaço NÃO-QUEBRÁVEL entre o sinal e o número. `str_replace(' ', '')`
   não limpa isso e a linha vira "não numérica", levando junto a parcela de R$ 400,68.
   *(A defesa real é o modificador `/u` na regex, que liga UCP e faz `\s` cobrir o U+00A0 — o `\x{00A0}`
   explícito é redundante. Descoberto tentando provar o teste: ele passava com e sem o `\x{00A0}`.)*

## O que falta

### Outras pendências
- **Avisos na tela.** `referenciasReutilizadas` e `vencimentosAlterados` existem no resultado mas **não
  aparecem no Twig** — quem importa pelo navegador não os vê. É o caminho que o dono usa de verdade.
  O importador de acordos é **só CLI** e tem um resumo bem mais rico (tabela por acordo + bloco
  "A CONFERIR"); ao levar os avisos para a tela, considerar o mesmo tratamento aqui.
- **`/review` da frente inteira** antes de integrar. Risco ALTO exige revisão dupla.
- **Migration não aplicada** em dev (`saas_ux`) nem em produção — só no banco de teste da frente.
  ⚠️ **Isto bloqueia o dry-run ponta-a-ponta**: `cobranca_obrigacao.competencia` não existe no `saas_ux`
  (conferido em 30/07), então rodar `app:cobranca:importar-acordos` contra o dev falha antes de começar.
  Ordem certa: aplicar a migration em dev → dry-run com a planilha real → conferir a tabela contra o §1 da
  spec → só então pensar em produção.
- **Deploy** exige `deploy-prod-tls.sh` (tem migration). Lembrar: `bcmath` da frente do BCB também só
  entra em prod no próximo deploy.

## Divergências conscientes em relação à spec dos acordos

Registradas para a revisão bater nelas de propósito, não por descuido:

1. **§9 contradiz §3.2.1.** A lista de testes ainda pede "conta original **inexistente não é criada**",
   linha remanescente da versão de 29/07; o §3.2.1 (decisão do dono de 30/07) manda criá-la. Seguido o
   §3.2.1. A linha do §9 deveria sair da spec.
2. **§3.3 não escreve status** — ver o porquê acima. A spec dizia "`Situação:` → `StatusAcordo`".
3. **Materialização dos encargos na data do acordo** ao marcar: a spec só diz "o mecanismo é o mesmo do
   `CriarAcordoUseCase`"; isso é o que aquele UseCase faz. Reescreve campos de encargo de 4 linhas reais de
   produção, **sem alterar saldo nenhum**.
4. **Parcela com NN ambíguo não é criada.** §7 diz "idempotência da parcela: por NN" e §3.2 exige
   NN+competência; quando as duas chaves discordam, a implementação recusa e reporta — a interseção
   conservadora das duas leituras.

## Estado do repositório

- Frente commitada e limpa; **10 commits** à frente de `origin/master`
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
- **Uma "prova" também precisa ser provada.** Na pendência 3, a primeira bateria de 7 provas rodava o
  PHPUnit com o cwd errado; o script recusava, e eu li a recusa como "teste vermelho, defeito pego". Sete
  ✅ que não valiam nada. O conserto: exigir que a saída mostre o PHPUnit tendo **executado**, e checar que
  o teste está VERDE antes de aplicar o defeito. É o mesmo modo de falha do "verde falso" do `cd app`,
  invertido — **vermelho falso**.
- **Dois testes passaram COM o defeito** e precisaram de cenário refeito: o do NN reutilizado entre
  carteiras (a busca cega acertava por acaso, porque o acordo certo era o de id menor — a correção foi
  importar na carteira B, para o erro ficar visível) e o do rompimento (meu filtro mexia na planilha e não
  no sistema, então o cenário nunca chegava a ter conta reconstruída, que é justamente o risco aceito).
  **Teste verde sobre cenário errado não guarda nada.**
