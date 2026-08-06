# HANDOFF — Automatizar o download dos relatórios da contábil

**Estado em 2026-08-06 (fim do dia).** **58 commits não publicados, nada em produção.**

🔴 **A fila de "dinheiro faltando" está VAZIA.** Os dois itens que restavam caíram **ao serem
medidos**, nenhum por defeito de código — e nenhum virou linha de código:

- **item 3** (§8) — a diferença não tem **um centavo** de principal: é encargo que o sistema calcula
  ao vivo. Sobrescrever cobraria juros sobre juros. Dono mandou para o **fim da fila**;
- **item 2** (§9) — os R$ 4.396,07 são a **coluna Total** de 13 boletos **sem principal nenhum**, e os
  13 entram por **outra planilha** (Acordos). A guarda culpada pelo handoff não é a causa. **O item 2
  não tem trabalho próprio: virou um pedaço do item 5.**

✅ **O item 6 (validador do rodapé) FECHOU no fim do dia** — 3 commits, 2 revisões, 7 injeções, suíte
3325 verde, ⏳ falta o smoke do dono. Detalhe na **§10**.

**O que sobra é completude, não dinheiro perdido:** ~~6~~ → **5** (o importador de Acordos criar o
acordo — **é o próximo**) → **8** (teste do zero) → **7** (AMLI) → **3** (o aviso).

⚠️ **Outra sessão commita neste mesmo master.** O commit `b6bed3a2` (ponto eletrônico, "abono técnico
não perdoa jornada") entrou por cima dos desta frente em 06/08. Os 4 commits do dia seguem na história
— conferido com `git branch --contains`, que é o comando autoritativo; **o log linear engana** quando
outra frente empilha por cima. A frente "esqueci a senha / cadastro público" continua **não commitada**
no working tree (41 arquivos). `git add` sempre por caminho explícito.

**Estado em 2026-08-05 (fim do dia).** **50 commits não publicados, nada em produção.**

✅ **Fecharam hoje:** o **item 4** (§7.2 — os encargos de TL1/TL2 estão certos, e não precisou reemitir
nada) e o **item 1** (dívida sem número de boleto — spec própria, 2 revisões com correção entre elas,
prova com `--confirmar` contra as planilhas reais). **Faltam R$ 7.109,07** dos R$ 24.553,73.

**Feito:** a sobrescrita de situação do acordo (2 revisões) · a correção do hífen (2 revisões,
R$ 49.038,17 que a leitura descartava) · a emissão dos relatórios pela API, com o lote completo em
`planilhas atualizadas/2026-08-04-completo/` · a Receitas **sem filtro de data**, que sozinha destravou
5 anos de histórico (TL1: 1.203 → 7.411 pagamentos, R$ 239 mil → R$ 1,27 milhão) · a AMLI incluída.

**Falta, medido:** **R$ 24.553,73** ainda ficam de fora de uma importação do zero (§6.1), mais uma
verificação de encargos que pode invalidar a inadimplência de TL1 e TL2 (§7.1).

⚠️ **A automação de download NÃO é oficial** — ver §7.1 antes de mexer nela.

---

## 1. Por que esta frente existe

Os relatórios da contábil vinham do **https://app.groupcondominios.com.br/** baixados à mão. O relatório de
Acordos saía com `Situação: Em andamento` e escondia a maior parte dos acordos. Ninguém via, porque o único
lugar que registra o recorte é a linha `Filtros:` do rodapé do próprio arquivo — e ninguém lia.

**O dano do download manual não é o tempo, é o filtro errado passar despercebido.**

## 2. O que foi descoberto (medido, não suposto)

O sistema deles é um **JHipster** (Spring Boot + React). Autenticação por **JWT no header
`Authorization: Bearer`** — sem cookie de sessão. A cadeia inteira foi reproduzida **fora do navegador**,
com `curl`, do login ao arquivo `.xlsx` na mão.

### Mapa das requisições

| Operação | Requisição |
|---|---|
| **Login** | `POST /orquestrador/api/authenticate` · `{"username","password","remember":false}` → `{"token"}` |
| **Trocar condomínio** | `POST /orquestrador/api/authenticate/alterar-condominio-contexto/{id}` → **novo** `{"token"}` |
| **Emitir** | `POST /backend/api/relatorio/...` → **200 com corpo vazio** (fire-and-forget) |
| **Histórico** | `GET /backend/api/historico-visualizacao-relatorio?tipoRelatorio={ENUM}&page=0&size=10&sort=id,desc` |
| **Download (1/2)** | `GET .../historico-visualizacao-relatorio/baixar-arquivo-historico/{uuid}` → **URL S3 pré-assinada em `text/plain`** |
| **Download (2/2)** | `GET <URL do S3>` → o `.xlsx` |

**IDs dos condomínios:** `1` = APLC - TOP LIFE 1 · `3` = AMLI BR 060 · `4` = APLC - TOP LIFE 2.

**Rotas e enums dos 4 relatórios:**

| Relatório | rota (`POST /backend/api/`) | `tipoRelatorio` |
|---|---|---|
| Inadimplências detalhadas | `relatorio/inadimplencia-detalhada` | `INADIMPLENCIAS_DETALHADAS` |
| Acordos detalhados | `relatorio/acordo/detalhado/assincrono` | `ACORDOS_DETALHADOS` |
| Receitas det. por unidade/cliente | `relatorio/contas-receber-detalhadas` | `RECEITAS_DETALHADAS_UNIDADE_CLIENTE` |
| Dados cadastrais dos condôminos | `relatorio/condominio/dados-condominos` | `DADOS_CADASTRAIS_CONDOMINOS` |

**Valores internos dos filtros:** situação do acordo `TODOS·EM_ANDAMENTO·LIQUIDADO·CANCELADO` · situação das
contas `TODAS·ABERTA·BAIXADA·ABERTA_BAIXADA·EXCLUIDA·SUBJUDICE` · unidade do cadastro
`UNIDADE·GRUPO_UNIDADE·TODAS_UNIDADES·UNIDADES_VAZIAS` · competência `TODAS·COMPETENCIA` · sacado
`TODOS·PROPRIETARIO·INQUILINO` · contas `TODOS·CONTA_BANCARIA_CAIXINHA`. Formato `tipo: "PDF"|"XLSX"`,
datas ISO `YYYY-MM-DD`.

Status do job: **`EM_PROCESSAMENTO` → `FINALIZADO`**. Medido: 15–20 s por relatório.

O script que fez tudo isso está em `docs/gestao-cobrancas/planilhas atualizadas/` (pasta gitignored) —
mas é **descartável**: refaz login a cada execução, sem tratamento de erro, sem idempotência.

## 3. ⚠️ Armadilhas medidas — não repita

1. 🔴 **`condominioContextoId` no payload é IGNORADO.** Mandei `1`, recebi `200`, e o relatório saiu do
   condomínio **4**. Falha silenciosa que importaria a carteira errada. O contexto real vem do **JWT**.
   → **Sempre conferir `condominioId`/`condominioNome` do registro do histórico antes de importar.**
2. 🔴 **`tipoSituacaoAcordo: "TODOS"` devolve HTTP 500**, determinístico. `EM_ANDAMENTO`, `LIQUIDADO` e
   `CANCELADO` funcionam. O recorte que resolveria o problema original quebra o servidor deles.
   → Contorno: emitir uma vez por situação e juntar.
3. 🔴 **`tipoCompetencia: "TODAS"` também dá 500** se o payload não mandar `competencia` (ISO, não-nulo) +
   `anoCompetencia` + `mesCompetencia` junto. A UI manda esses campos mesmo quando o filtro é "todas".
4. 🟠 **O contexto de condomínio persiste no servidor entre logins.** Uma sessão nova retomou o condomínio
   trocado na véspera. A automação **nunca pode assumir** o contexto — troca sempre, e confere.
5. 🟠 **O histórico NÃO guarda os filtros usados.** Só data, nome, status, condomínio e bucket.
   → **A validação da linha `Filtros:` do rodapé continua indispensável mesmo com a API.**
6. 🟠 **O enum enviado não é o texto do rodapé.** Mandei `BAIXADA`, o rodapé escreve **`Baixadas`** (plural).
   Minha primeira conferência passou por sorte, porque `contains("Baixadas", "Baixada")` é verdadeiro.
   → **Comparação exata por campo, nunca `contains`**, e o mapa enum→texto tem de ser tabelado à mão.
7. 🟠 **Os encargos vão no payload** (`juros`, `multa`, `honorario`). Quem chama decide o dinheiro do
   relatório de inadimplência. Confirmado na prática: TL1 saiu com 20%, TL2 com 15%, conforme enviado.
8. 🟡 **Sessão de 8 h, sem refresh token.** Renovar = novo login. A troca de contexto também renova.
9. 🟡 **A URL do S3 expira em ~5 h** (`X-Amz-Expires=17999`) — baixar logo após obter.
10. 🟡 **A Inadimplência não se identifica** — nenhuma menção ao condomínio no conteúdo. Só o histórico diz.
11. 🟡 **A UI de Acordos não emite** — o clique em Excel não dispara requisição (a tela nasce com `TODOS`,
    que é justamente o que dá 500). Não dá para depender da interface.
12. 🟡 **O widget de data é DD/MM/YYYY** apesar do placeholder dizer `MM/DD/YYYY`. Irrelevante para a API
    (que recebe ISO), mas explica erro de quem preenche na tela.

## 4. O lote baixado (2026-08-04)

`docs/gestao-cobrancas/planilhas atualizadas/2026-08-04-api/` — **gitignored (PII)**. 12 arquivos,
6 por carteira, todos conferidos: condomínio certo e rodapé batendo com o recorte pedido.

| carteira | em andamento | liquidado | cancelado | **total** |
|---|---:|---:|---:|---:|
| TOP LIFE 1 | 66 | **259** | **99** | **424** |
| TOP LIFE 2 | 8 | 26 | 5 | **39** |

Sem sobreposição: cada acordo aparece em exatamente uma situação. **O export manual mostrava 66 e 8.**

🔑 **A etapa 3 criou 106 acordos** a partir da Receitas filtrada por vencimento em 2026. A fonte real tem
**463**. A diferença são anos de acordos liquidados e cancelados que nunca apareceram.

**Recortes usados:** Inadimplência `até hoje, Competência Todas, vencimento Todos, unidade Todas` ·
Acordos `uma emissão por situação` · Cadastro `Unidades: Todas` · Receitas `Baixada, Competência Todas,
recebimento 01/01/2026 a 04/08/2026, vencimento Todos`.

## 5. Decisões do dono (tomadas em 04/08)

- ✅ **Importar em andamento + liquidado** (325 na TL1, 34 na TL2). **Cancelados ficam de fora.**
- ✅ **Receitas fica com `Baixada`** (só pago). Motivo: linha em aberto obrigaria o importador a adivinhar
  se é dívida ou pagamento, e quebraria a idempotência da sincronização incremental (a mesma parcela
  voltaria em toda sincronização até ser paga). As parcelas **futuras** vêm do Acordos detalhados.
- ✅ **O importe sobrescreve a situação do acordo — entra NESTA frente** (era a frente separada nº 1 do
  `HANDOFF_IMPORTAR_RECEITAS.md` §5.1).
- ⏸️ **AMLI BR 060** existe no cadastro e ainda não foi decidido se entra.

## 6. O que falta fazer

**Agora:**
1. **Conferir o estado do banco de dev** (`saas_ux`) — ele ainda tem a importação da etapa 3 por cima
   (106 acordos). Importar sem limpar mistura os dois e o número não significa nada.
2. ✅ **FECHADA (04/08) — `ImportarAcordosDetalhadosUseCase` alinhado.** Spec:
   `docs/specs/cobranca-importar-acordos-situacao.md`. **5 commits, 2 revisões, suíte 3219/3219, nada
   publicado, nada rodado contra dado real.**

   | | |
   |---|---|
   | `1f5d9a5e` | o importe passa a escrever o status do acordo |
   | `24c61ae2` | parcela paga barra o cancelamento, com aviso |
   | `24b21171` | correções da 1ª revisão (2 asserts vacuosos, 1 recusa que faltava) |
   | `61c0aff4` | correções da 2ª revisão (o aviso mandava apagar pagamento à toa) |

   **O que faz:** a linha `Situação:` da planilha manda no status — `Em andamento`→`Ativo`,
   `Liquidado`→`Cumprido`, `Cancelado`→`Cancelado`. Situação fora do mapa continua reportada, nunca
   adivinhada. **Escopo: só o tenant 1** se baseia no importe (decisão do dono); os outros trabalham pelo
   sistema e nunca passam por aqui.

   **Duas exceções**, que espelham as recusas do cancelamento manual — o status é MANTIDO e sai aviso
   acionável: **parcela paga** e **parcelas renegociadas por outro acordo vigente**. Com os dois, o aviso
   mostra os dois: resolver só um não destrava, e mandar apagar um recebimento à toa destrói dado
   irreversível.

   ⏸️ **PENDÊNCIA que trava junto com o `*_CANCELADO.xlsx`** (spec §5.4): a 2ª recusa decide por query ao
   vivo dentro do laço, não por foto antes dele — prévia e confirmação podem barrar conjuntos diferentes
   de abas. Hoje o ramo **roda zero vez** (só dispara em `Cancelado`, que não é importado). Correção é
   mecânica. Mais 4 menores na §5.5.

   ⏳ **Falta o smoke do dono** e rodar o dry-run contra o arquivo real de acordos.

3. ✅ **FEITO (04/08) — dry-run dos Acordos contra o dado real.** Banco descartável `saas_ux_dryrun`,
   clone de `saas_ux_pos_etapa3`, sem `--confirmar`, 4 combinações.

   **A sobrescrita de situação está correta**: só **4** acordos mudam de status, e são exatamente os
   **4 órfãos** (212, 230, 237, 280) que o §6.1 previu. Os demais já estavam certos —
   **49 + 26 = 75 `Cumprido` batem 100% com o que a planilha chama de `Liquidado`**, duas fontes
   independentes concordando. ⚠️ A direção `Cumprido → Ativo`, que a spec §5.1 apostava ser *o caso mais
   comum*, acontece **zero vez** no dado real: nasce não-exercitada, como o ramo de `Cancelado`.

4. ✅ **FECHADA (04/08) — o hífen da fonte é ZERO.** Spec:
   `docs/specs/cobranca-adapter-acordos-hifen-zero.md`. **3 commits, 2 revisões, suíte 3224/3224.**

   O dry-run achou o defeito: a fonte escreve `-` onde o valor é zero (`1.4 - Juros`, `1.5 - Multas`,
   `1.6 - Descontos`), e o adapter lia isso como *"não numérico"*, descartando a **parcela inteira**.
   Medido: **172 parcelas (R$ 49.038,17) + 2 contas originais**. Preexistente — o arquivo manual de
   03/08 dá resultado idêntico ao da API, o que de quebra **valida a API**.

   ⚠️ **O efeito no saldo é ZERO** — e a primeira leitura deste achado dizia o contrário. As parcelas
   recuperadas já existiam (a Receitas criou), ou o importe recusa criá-las, ou estão em aba de acordo
   inexistente. O que a correção destrava: **R$ 21.413,72 hoje em abas ignoradas**, que viram dívida
   real quando esses acordos existirem, e **33 divergências de valor** que a rejeição escondia.

   | | |
   |---|---|
   | `05067aaf` | a régua: `-` vale 0, casando o token inteiro (`-\u{00A0}3,04` continua desconto) |
   | `e32dbf16` | 1ª revisão: a coluna E também tinha hífen e não fora medida nem testada |
   | `f2df754d` | 2ª revisão: prende os testes à régua, não ao texto da mensagem |

   ⏳ **Falta o smoke do dono.** Nada rodado com `--confirmar`.

5. **Rodar Receitas → Acordos no dev com `--confirmar`** e medir — quando o dono mandar.

## 6.0 ✅ LOTE COMPLETO EMITIDO PELO CLAUDE (04/08) — `planilhas atualizadas/2026-08-04-completo/`

**O dono transferiu a emissão dos relatórios para o Claude em 04/08** (*"não sou mais eu que emito os
relatórios, é você"*). Este lote foi emitido e baixado pela API, sem navegador — os payloads saíram da
leitura do **`main.js` do próprio sistema deles**, não de tentativa e erro no servidor.

| arquivo | conferido no histórico |
|---|---|
| `top_life_1_Receitas_detalhadas_TODOS.xlsx` | APLC - TOP LIFE 1 |
| `top_life_2_Receitas_detalhadas_TODOS.xlsx` | APLC - TOP LIFE 2 |
| `amli_br_060_Receitas_detalhadas_TODOS.xlsx` | AMLI BR 060 |
| `amli_br_060_Acordos_detalhados_EM_ANDAMENTO.xlsx` · `_LIQUIDADO.xlsx` | AMLI BR 060 |
| `amli_br_060_Inadimplencias_detalhadas.xlsx` | AMLI BR 060 |
| ✅ `amli_br_060_Dados_cadastrais.xlsx` | ficou preso em `EM_PROCESSAMENTO` em 04/08; **reemitido e baixado em 08/08** (item 7 — ver §17). O lote da AMLI está completo |

### 6.0.1 🔑 Duas armadilhas do §3 estavam ERRADAS — medido

1. **`tipoSituacaoAcordo: "TODOS"` não precisa de contorno.** A §3.2 mandava *"emitir uma vez por situação
   e juntar"*. O `beforeSubmit` do form no bundle faz
   `tipoSituacaoAcordo !== 'TODOS' ? valor : undefined` — a UI **omite** o campo. Testado nos dois modos
   contra a API: **omitido → HTTP 200**, `"TODOS"` explícito → **HTTP 500** (reproduzido). O 500 era
   causado por mandar a string, não por pedir todas as situações.
2. **`Período de recebimento: Todos` não é um valor** — é a **ausência** de `recebimentoInicio` e
   `recebimentoFim`. A validação do front só acusa erro quando **um** dos dois está preenchido.

Também medido: **o Cloudflare bloqueia `urllib` do Python (Error 1010)** e aceita `curl`. E o path de
login é `/orquestrador/api/authenticate` — `/orquestradorcloud/` devolve 404 (o servidor cita esse nome
na mensagem de erro, mas ele não é rota).

### 6.0.2 🔑 O RECORTE era o problema principal, não o desenho — e isso corrige a §6.0 anterior

Medido com o `TopLifeReceitasAdapter` real, TL1, antigo × novo:

| | janela 2026 | recebimento `Todos` |
|---|---:|---:|
| recebimentos | 1.203 | **7.411** |
| dinheiro | R$ 239.157,88 | **R$ 1.272.816,33** |
| **acordos criados** | 78 | **304** |
| anos cobertos | só 2026 | **2021–2026** |

Lote completo: **8.588 recebimentos · 354 acordos · R$ 1.464.408,36** (TL1 7.411/304/R$ 1.272.816,33 ·
TL2 858/26/R$ 137.148,49 · AMLI 319/24/R$ 54.443,54), com **6 rejeições** (5 de *valor líquido não
positivo*, 1 de *mesmo NN com mais de uma competência*).

⚠️ **Isto revoga a estimativa de "357 acordos não nascem".** Com a Receitas completa, a criação por
Receitas cobre **330 dos 359** acordos (TL1 304/325, TL2 26/34). **Faltam 29, não 357.** A correção de
desenho (o importe de Acordos criar acordo) continua **certa** — é a fonte canônica —, mas passa de
*bloqueante de 357* para *fecha os últimos 29*. **O recorte era o problema; o desenho é o acabamento.**

### 6.0.3 🔑 Os encargos NÃO precisam ir no payload — a §3.7 está incompleta

A §3.7 registrava que *"os encargos vão no payload e quem chama decide o dinheiro do relatório"* (TL1 com
20%, TL2 com 15%, "conforme enviado"). **Isso é verdade, mas é só metade.** O formulário tem
`personalizarAcrescimos: false` por padrão, com `juros`/`multa`/`honorario` **vazios** — e nesse modo o
sistema usa **os percentuais cadastrados do próprio condomínio**. Mandar os campos é que **sobrescreve**
o cadastro.

A Inadimplência da AMLI foi emitida **no modo padrão**, e o resultado foi conferido contra o print que o
dono mandou da tela de configuração dela:

| encargo | medido no arquivo emitido | configuração do condomínio |
|---|---|---|
| multa | **2,00%** exato sobre o principal | 2,00 ✅ |
| juros | R$ 3,12 sobre R$ 170,00 em 55 dias = **1,00% ao mês pró-rata** | 1,00 ✅ |
| honorário | R$ 26,48 sobre R$ 176,52 (principal + encargos) = **15,0%** | 15,00 ✅ |

**Os três batem.** Conclusão operacional: **emitir sempre no modo padrão** — o condomínio é a autoridade
sobre os próprios percentuais, e assim não há como o emissor errar o dinheiro por descuido. Personalizar
só quando houver decisão explícita para isso.

## 6.1 🔴 BALANÇO DE COBERTURA — o que NÃO entra numa importação do zero (medido 04/08)

**A pergunta do dono:** *"quando eu limpar tudo e fazer as importações, vai estar tudo certo, sem valor
faltando em nenhuma unidade de nenhum condomínio?"*

**A resposta hoje é NÃO.** Faltariam **R$ 21.840,73**, em pelo menos **10 unidades identificadas**.
Isto aqui não é estimativa: cada linha foi medida chamando os adapters reais contra os 6 arquivos de
`2026-08-04-api/`, e o valor é a soma de principal + juros + multa + correção + honorários da linha.

| # | fonte | o que fica de fora | valor | estado |
|---|---|---|---:|---|
| 1 | Acordos — **hífen** | 172 parcelas + 2 contas | R$ 49.038,17 | ✅ **corrigido** em 04/08 |
| 2 | Inadimplência — **boleto sem NN** | 73 boletos | **R$ 10.694,66** | 🔴 aberto |
| 3 | Acordos — **linhas sem NN** | 165 contas originais | **R$ 6.750,00** \* | 🔴 aberto |
| 4 | Inadimplência — **só encargos/honorário** | 13 boletos (20 linhas) | **R$ 4.396,07** | 🔴 aberto (conhecido desde 01/08) |
| 5 | Receitas — líquido não positivo | 1 recebimento (NN 60082) | ~0 | 🟡 menor |

\* Só os arquivos importáveis. Contando o `CANCELADO`, que não é importado, são R$ 13.310,00 + 1 parcela
de R$ 196,98.

### A causa nº 1 é a mesma nas duas fontes: **dívida antiga sem NN**

⚠️ **Correção da primeira versão desta seção** (feita em 04/08, ao investigar antes de escrever a spec —
exatamente o que o dono pediu: conferir no dado, não na spec). Eu havia escrito que *"a fonte não repete
o NN nas linhas seguintes de um grupo"*, generalizando a partir do acordo 12. **Medido nas 409 linhas sem
NN dos dois arquivos importáveis: só 4 seguem esse padrão. 405 não têm NN nenhum na seção inteira.**

O caso dominante é o **acordo 151**: `Relação das contas originais` com **73 linhas e ZERO NN** —
taxa de condomínio de R$ 100,00 por mês, competência a competência, desde 09/2019:

```
· | 1.1 - Taxa de condomínio | 09/2019 | 10/09/2019 | 100,00
· | 1.1 - Taxa de condomínio | 10/2019 | 10/10/2019 | 100,00
· | 1.1 - Taxa de condomínio | 11/2019 | 11/11/2019 | 100,00
                              (… 73 linhas, nenhuma com Nosso Número)
```

Não é ruído de formatação: são **dívidas antigas que nunca tiveram boleto emitido**. O cabeçalho da aba
soma todas elas. Os adapters descartam toda linha sem NN (`ctype_digit`) e a conta simplesmente some.

O mesmo aparece na inadimplência: **73 de 86 rejeições** são `Boleto sem número (NN)` — e a amostra
mostra taxas mensais de 2022 da mesma unidade (17-01/1-2, MARCELO ANTONIO SILVA), com `Acordo: -`.

🔑 **Isso é uma boa notícia para o desenho:** cada linha é uma dívida **mensal distinta**, com
competência e vencimento próprios. A chave substituta (**caso + competência + classe**) identifica cada
uma sem ambiguidade — não é preciso inventar heurística.

Na inadimplência o mesmo padrão vira rejeição explícita: **73 de 86 rejeições são `Boleto sem número
(NN)`**. Não é lixo de rodapé — é dívida real, com unidade, sacado, classe e vencimento preenchidos.

⚠️ **Por que o NN não é um detalhe:** ele é a chave de deduplicação (`uniq_cobranca_obrigacao_ref_competencia`).
Aceitar linha sem NN sem uma chave substituta faz a **segunda importação duplicar a dívida** — trocaria
dinheiro faltando por dinheiro dobrado. Foi por isso que a rejeição existe. A correção precisa de uma
chave alternativa (unidade + competência + vencimento + classe é a candidata natural), não de remover a
guarda.

### As 10 unidades afetadas (inadimplência)

| valor | unidade / sacado | motivo |
|---:|---|---|
| R$ 9.718,35 | 20-03C · JOSE CARLOS DA SILVA PER… | sem NN |
| R$ 1.115,14 | 20-03 · MANOEL MARGARIDO DA SILV… | só encargos |
| R$ 976,31 | 17-01/1-2 · MARCELO ANTONIO SILVA | sem NN |
| R$ 855,11 | 01-09 · CRISTIANO ARAUJO CAMPOS | só encargos |
| R$ 649,82 | 03-04A · MARIA GENIRA DE ARAUJO G… | só encargos |
| R$ 425,57 | 08-03A · ISMAEL RODRIGUES SOUSA | só encargos |
| R$ 417,22 | 11-04B · CLEBER MAGALHAES ALVES | só encargos |
| R$ 398,42 | 08-02B · IGOR JOSE DE SOUSA | só encargos |
| R$ 319,10 | 04-03 · FERNANDO PEREIRA NERY | só encargos |
| R$ 215,69 | 08-04A · TADEU HENRIQUE QUEIROZ D… | só encargos |

**Quase metade do buraco está numa unidade só** (20-03C, R$ 9.718,35).

### O que a leitura JÁ acerta

Nos acordos que hoje são processados, a leitura **fecha 100%** com o cabeçalho da planilha. Das 12
divergências restantes, 10 são de **centavos** (R$ 0,01–0,05, arredondamento da contábil) e 2 são o caso
das linhas sem NN — e **todas as 12 estão em abas ignoradas**, nenhuma nos acordos que entram.

⚠️ **Fato remedido:** a pendência de 01/08 registrava R$ 4.390,86 para o "só encargos"; nos arquivos de
04/08 são **R$ 4.396,07**. Mais um caso de *fato medido tem prazo de validade curto nesta fonte* — o
número muda a cada emissão, a decisão não.

### 6.2 Decisão do dono reiterada em 04/08

> *"O IMPORTE É A FONTE DA VERDADE, ELE QUEM MANDA EM TUDO, O QUE VEM DA CONTABILIDADE (PLANILHA DO
> IMPORTE) É O QUE VALE."*

Isso vale para **todos os itens acima** e mais um: as **33 divergências de valor** (planilha maior que o
sistema em 33 de 33, somando **R$ 2.713,00**) hoje são apenas **reportadas** — o `ImportarAcordosDetalhados`
não sobrescreve valor lançado, por decisão de 30/07 registrada na §4 da spec-mãe (*"a planilha não é
autoridade sobre dinheiro já lançado"*). **Essa decisão está revogada pela diretriz acima e precisa de
frente própria** (risco ALTO: mexe em saldo de devedor).

**Depois (a automação em si):**
6. **Validador da linha `Filtros:`** — recusar arquivo cujo recorte não seja o esperado. É o que impede o
   erro original de voltar, e **vale mesmo com download manual**. Comparação exata por campo (ver §3.6).
7. **Conferência da carteira** contra o `condominioNome` do histórico (ver §3.1).
8. Comando Symfony + armazenamento + agendamento, com a busca do arquivo atrás de uma interface
   (`PastaLocal` hoje, `GroupCondominiosApi` depois). Precedentes no repo: **DJEN**, **índices do BCB**,
   **sync do Drive**.

## 6.3 ☑️ CHECKLIST — o que falta para os números baterem 100% com a contabilidade

Estado em **05/08/2026**. Cada valor abaixo foi **medido** contra as planilhas reais, não estimado.

### ✅ Dinheiro que ainda fica de fora — **R$ 0,00** (eram R$ 24.553,73)

> Caiu a zero em 06/08 **sem uma linha de código**, em duas medições: o item 3 saiu da conta de manhã
> (§8) e o item 2 à tarde (§9). Nenhum dos dois era dinheiro faltando — os dois eram **encargo somado
> ao principal na planilha**, comparado com um sistema que guarda as duas coisas em campos separados.
> ⚠️ **Isto não quer dizer que tudo bate na tela:** o item 5 (completude) ainda segura **6 parcelas /
> R$ 1.679,86** e 29 acordos, por falta de criação de acordo — ver §9.3.

| # | O quê | Valor | Onde está o diagnóstico |
|---|---|---:|---|
| 1 | ✅ **FECHADO — dívidas sem número de boleto (NN)** | ~~R$ 17.444,66~~ | `docs/specs/cobranca-divida-sem-numero-de-boleto.md`. Chave = `SNN:<vencimento>`, agrupando por **caso + competência + vencimento** (a classe NÃO entra — ver §7.3). **Provado no dado real: 99 obrigações, R$ 12.510,00 de principal, idempotente.** ⚠️ Só R$ 8.912,21 viram dívida na tela; os R$ 6.750,00 dos acordos nascem substituídos |
| 2 | ~~**Boletos só de encargos/honorário**~~ | ~~R$ 4.396,07~~ → **R$ 0,00** | 🔴 **NÃO ERA DINHEIRO FALTANDO — medido em 06/08, ver §9.** Os 13 boletos têm **R$ 0,00 de principal**, e os 13 entram pela planilha de **Acordos**, com valor idêntico ao centavo. A guarda do `TopLifeInadimplenciaAdapter:201` não é a causa. **Virou pedaço do item 5**: 6 das 13 parcelas ficam de fora (R$ 1.679,86) porque o *acordo* não nasce |
| 3 | ~~**Valores divergentes** (planilha ≠ sistema)~~ | ~~R$ 2.713,00~~ → **R$ 0,00** | 🔴 **NÃO ERA DINHEIRO FALTANDO — medido em 06/08, ver §8.** A diferença tem **R$ 0,00 de principal**: é encargo que o sistema calcula ao vivo, desconto concedido no pagamento e honorário. **Dono mandou deixar por ÚLTIMO** (06/08); vira correção do *aviso*, não do dado |
| 4 | ✅ **FECHADO — encargos de TL1/TL2 NÃO estão sobrescritos** | — | **§7.2**. Não precisou reemitir: o arquivo imprime os encargos na L4, e 4 emissões manuais anteriores à automação (08/07, 22/07, 29/07, 03/08) trazem os mesmos percentuais da emissão pela API |

Os itens 1–3 são **risco ALTO**: spec + teste provado por reintrodução + **duas** revisões cada.

### 🟡 Completude e proteção (não é dinheiro perdido)

| # | O quê | Por quê |
|---|---|---|
| 5 | Mover **criação de acordo** para o importador de Acordos | fecha os últimos **29** de 359 acordos (§6.0.2) **e absorve o antigo item 2**: 6 parcelas / R$ 1.679,86 travadas por acordo inexistente (§9.3). 🔴 Virou o único item com dinheiro atrás dele — **risco ALTO** |
| 6 | ✅ **FECHADO — validador da linha `Filtros:`** | Spec: `docs/specs/cobranca-validador-rodape-filtros.md`. **3 commits, 2 revisões, 7 injeções, suíte 3325 verde.** Recusa nas **5 portas** (4 comandos + a tela), 15/15 contra arquivos reais. ⏳ falta smoke do dono. Detalhe na §10 |
| 7 | ✅ **FECHADO (08/08) — cadastro da AMLI reemitido e baixado** | **§17.** O lote da AMLI ficou completo (5/5). 🔑 A causa provável do travamento era **payload com o nome de campo errado**, que o servidor aceita com **HTTP 200** e deixa o job preso para sempre — provado nos dois sentidos (§17.1). ⛔ **Trava adiante: a carteira da AMLI não existe em banco nenhum** (§17.2) — é cadastro de tela, do dono |
| 8 | **Teste do zero** | limpar banco → importar tudo → bater com a contabilidade. **É a prova final.** O dono autorizou `--confirmar` **em banco descartável** para isto. ⚠️ **Duas restrições novas, medidas em 06/08:** usar o lote `2026-08-04-completo/` (o `-api/` é recusado pelo validador, §10.4) e **fatiar a importação** — a Receitas completa numa transação só derrubou a máquina duas vezes (§9.4) |

### ⏸️ Do dono

| # | O quê |
|---|---|
| 9 | Smokes na tela: "Já pago", caso 193, excluir recebimento, acordos sobrescritos, **e o do item 6** (§10.6) |
| 10 | Publicar os **58 commits** |
| 11 | Deploy em produção (lembrar: prod é imagem baked, exige `./scripts/deploy-prod-tls.sh`) |
| 12 | **Confirmar com a contábil** se a automação de download é permitida — §7.1 |

### Ordem recomendada (atualizada em 06/08 pelo dono)

~~**4** → **1**~~ (feitos) → ~~**3**~~ (medido, saiu da conta) → ~~**2**~~ (medido, dissolveu-se no 5)
→ **6** (trava o recorte) → **5** (o que sobrou de dinheiro) → **8** (a prova final) → **7** (AMLI) →
**3** (o aviso, por último).

**Decisão do dono em 06/08, textual:** *"sim, deixe o aviso por último. pode ir direto para o item 2."*
E, na sessão 2, depois de o item 2 cair: **item 6 primeiro, depois o 5** — e, para a spec do 5,
*"sim, cria, a planilha manda"*, inclusive acordo cujas parcelas são só honorário e juros (§9.5).
Até lá o aviso de divergência continua exatamente como está — **não mexer**.

### 🔑 O que a próxima sessão precisa saber antes de começar o item 3

⚠️ **Meça primeiro DE QUE é feita a diferença.** A decisão do dono é *"o importe sobrescreve sempre,
reabre o saldo"* (05/08), mas sobrescrever o **principal** com um número que já embute encargos
contaria encargo duas vezes. As 33 divergências precisam ser decompostas antes de virar spec.

⚠️ **O valor não pode entrar na chave de dedup.** É o que faz o item 1 e o item 3 conviverem — está
provado por teste (`ImportarDividaSemBoletoTest::testValorCorrigidoNaoCriaSegundaDivida`), e o docblock
desse teste avisa que ele é justamente a trava contra a tentação de quem for mexer no item 3.

### 🧪 Bancos descartáveis deste dia (podem ser apagados)

- **`saas_ux_semnn`** — tem a prova do item 1: 45 obrigações `SNN:` da inadimplência + 54 dos acordos
  (99 · R$ 12.510,00), com os 5 acordos criados a partir de um recorte real da Receitas.
- **`saas_ux_ac`** — clone limpo de `saas_ux_pos_etapa3`, sem nada gravado.
- ⛔ **`saas_ux`, `saas_ux_pos_etapa3` e `saas_ux_antes_etapa3` não foram tocados.**

## 7. Segurança

### 7.1 🔴 A automação NÃO é oficial — o fornecedor disse que não fornece acesso a terceiros

**Resposta do suporte do Group Condomínios, trazida pelo dono em 05/08:**
> *"O Group Condomínios infelizmente não dispõe a API para terceiros :("*

Isto **fecha** a pendência que a §5.1 do `HANDOFF_IMPORTAR_RECEITAS.md` registrava como *"aguardando a
equipe de desenvolvimento deles sobre API"*. A resposta chegou, e foi **não**.

**O que a automação realmente é, dito sem eufemismo:** ela repete as **mesmas chamadas internas que o
navegador da secretária faz**, com o login dela. Não existe API pública; o que existe é o funcionamento
interno do site, descoberto lendo o **`main.js` que o próprio site entrega ao navegador** (arquivo
público, 29 MB). Nenhuma trava de autenticação foi contornada para entrar — é a conta do cliente,
acessando os dados do cliente.

**Riscos que ficam, e que o dono precisa pesar:**

1. 🔴 **Eles barram acesso automatizado, e isso foi medido.** O cliente HTTP do Python levou
   **Cloudflare Error 1010** (*"acesso negado com base na assinatura do seu navegador"*). O `curl` passa
   hoje. A proteção existe e pode apertar sem aviso.
2. 🔴 **Os termos de uso não foram lidos.** É comum haver cláusula proibindo acesso automatizado mesmo
   com conta legítima. **Verificação pendente.**
3. 🟠 **Sem compromisso de compatibilidade:** não é serviço contratado; mudaram o site, quebrou.
4. 🟠 **A conta é de pessoa física** (a secretária) — o uso automatizado fica registrado no nome dela, e
   é ela que seria bloqueada se considerarem indevido.

**Encaminhamento recomendado (não executado):** perguntar formalmente **à contábil** — não ao suporte
técnico —, já que a secretaria autorizou internamente em 04/08: *"vamos automatizar o download dos
nossos próprios relatórios com o nosso login; há restrição?"* Autorizam → seguir, e pedir conta de
serviço. Não autorizam → parar e voltar ao download manual.

✅ **O plano não depende disso.** O **validador do rodapé** (§6, item 6) — a peça que teria pego o filtro
de 2026 sozinho — **funciona igual com download manual**, e os arquivos já baixados seguem válidos.

### 7.2 ✅ RESOLVIDA (05/08) — os encargos de TL1 e TL2 estão certos

**Não precisou reemitir nada.** O próprio arquivo imprime os encargos usados na **linha 4** (célula A4),
logo abaixo do título. Comparando as inadimplências emitidas pela API (04/08, com 20%/15% no payload)
com as que a secretária baixou **à mão pela tela**, semanas antes de existir qualquer automação:

| carteira | 08/07 · 22/07 · 29/07 · 31/07 · 03/08 (manuais) | 04/08 (API, encargos no payload) |
|---|---|---|
| TL1 | juros 1,00%/mês · multa 2,00% · **honorários 20,00%** | idem |
| TL2 | juros 1,00%/mês · multa 2,00% · **honorários 15,00%** | idem |

Quatro datas, duas carteiras, emissão manual em modo padrão: sempre os mesmos números. Como a §6.0.3
provou que o modo padrão usa o cadastro do condomínio (validado contra o print da AMLI), **o cadastro
de TL1 é 1/2/20 e o de TL2 é 1/2/15 — exatamente o que foi enviado. A sobrescrita gravou o mesmo
valor. Nenhum centavo está errado na origem.**

Ressalva honesta: isto prova que API e emissão manual produzem os mesmos encargos, e que são os do
cadastro *pelo elo do teste da AMLI*. Não é leitura direta da tela de configuração de TL1/TL2 — se o
dono tiver os prints das duas, fecha sem elo intermediário.

### 7.3 (histórico) O texto original desta pendência

Achado em 05/08, ao revisar o handoff. Decorre direto da §6.0.3.

Os arquivos de **Inadimplência de TL1 e TL2** que estão em `2026-08-04-api/` foram emitidos **com os
encargos enviados no payload** (TL1 20%, TL2 15% — a §3.7 registra *"conforme enviado"*). A §6.0.3 provou
depois que o modo padrão usa **os percentuais cadastrados do próprio condomínio**, e que mandar os campos
**sobrescreve** o cadastro.

**Ninguém conferiu se 20% e 15% são os percentuais reais de TL1 e TL2.** Se não forem, a dívida das duas
carteiras está errada **na origem** — antes de qualquer código do JusPrime tocar nela.

**Como resolver (barato):** reemitir as duas inadimplências no **modo padrão** (`personalizarAcrescimos:
false`, encargos omitidos) e comparar com os arquivos atuais. Foi assim que a AMLI foi validada. Fazer
**antes** de qualquer importação com `--confirmar`.

### 7.3 Credenciais e segredos

- A credencial está em `docs/gestao-cobrancas/credencial.txt`, **no `.gitignore`** (arquivo nunca foi
  rastreado — não precisou reescrever histórico). Varredura confirmou: nenhum segredo em arquivo versionado.
- **Nenhum token, cookie ou `Authorization` foi gravado em disco.** Na automação de verdade, a credencial
  tem de sair do `.txt` e ir para variável de ambiente/secret.
- ⚠️ A conta é de **pessoa física** (a secretária). Se ela trocar a senha ou sair, a automação para.
  Vale pedir conta de serviço à contábil.
- ℹ️ `.claude/settings.json` perdeu o `permissions.ask: ["mcp__playwright"]`. **Foi decisão do dono**, e está
  commitada: com a trava, cada ação do Playwright pedia autorização e a investigação ficava inviável.
  Continua valendo a regra do `CLAUDE.md`: **o smoke no navegador é do dono** — o Playwright só é aberto
  quando ele pede, com essas palavras. O que caiu foi o prompt por ação, não a regra.

---

## 8. 🔴 06/08 — O ITEM 3 FOI MEDIDO E A PREMISSA CAIU

**Nenhuma linha de código de produção foi tocada.** O dia foi medição, e a medição desfez o item.

### 8.1 O que a §6.3 mandava fazer, e por quê

> *"⚠️ Meça primeiro DE QUE é feita a diferença. A decisão do dono é 'o importe sobrescreve sempre,
> reabre o saldo', mas sobrescrever o principal com um número que já embute encargos contaria encargo
> duas vezes."*

Feito. E o resultado é mais forte do que a ressalva previa: **não há principal nenhum a sobrescrever.**

### 8.2 Como foi medido (reprodutível)

1. Banco descartável **`saas_ux_div`** = `CREATE DATABASE … TEMPLATE saas_ux_pos_etapa3`.
2. Dry-run (sem `--confirmar`) de `app:cobranca:importar-acordos` nas 4 combinações
   (TL1/TL2 × EM_ANDAMENTO/LIQUIDADO) dos arquivos de `2026-08-04-api/`, saída capturada em arquivo.
3. Extração das linhas `NN: sistema R$ x, planilha R$ y` → lista dos NNs divergentes.
4. Leitura **crua** dos `.xlsx` de **Acordos** *e* de **Receitas**, decompondo cada NN em
   **P** (principal: classes 1.1/1.14) · **J** (juros+multa: 1.4/1.5) · **D** (descontos: 1.6) ·
   **H** (honorário: 1.15).
5. Confronto das duas fontes contra o `valor_original` gravado.

Scripts descartáveis na pasta gitignored: `_item3_decompor.php`, `_item3_classificar.php`,
`_item3_duasfontes.php`, `_item3_lado.php`, `_item3_receitas.php`, `_item3_nns.txt`.

### 8.3 O resultado — a decomposição fecha ao centavo, sem resíduo

| componente | valor | % |
|---|---:|---:|
| **principal** | **R$ 0,00** | **0%** |
| juros + multa | R$ 6.087,10 | 87,9% |
| descontos concedidos no pagamento | R$ 440,00 | 6,4% |
| honorário | R$ 396,39 | 5,7% |
| **total** | **R$ 6.923,49** | 100% |

**A divergência existe por construção, não por defeito.** O "Valor acordado" da planilha é a soma de
**todas** as linhas de classe do NN — o docblock de `AcordosDetalhadosAdapter::montarParcela` (`:353-356`)
diz isso explicitamente. O `valor_original` do sistema **nunca** guarda juros/multa, porque
`EncargosVivos::exigivelVivo` (`:59`) os calcula ao vivo a partir dele. `divergenciaDeValor`
(`ImportarAcordosDetalhadosUseCase.php:900`) compara essas duas grandezas e chama a diferença de
divergência.

🔑 **Sobrescrever seria pior do que não fazer nada, e o erro cresce.** Os R$ 6.087,10 de juros já
corridos entrariam na base sobre a qual o sistema calcula *mais* juros (1%/mês), multa (2%) e
honorário (20% TL1 / 15% TL2). Não é erro de uma vez — é juro sobre juro, todo mês.

### 8.4 O caso do desconto — o que quase me enganou, e o que ensina

Os R$ 440,00 são linhas `1.6 - Descontos` que existem **só no relatório de Receitas** e **não existem
no de Acordos**. Exemplo medido, NN **61365** (TL2):

| fonte | conteúdo | total |
|---|---|---:|
| **Acordos** (o que foi combinado) | 6 × `1.1 - Taxa de condomínio` R$ 170,00 | R$ 1.020,00 |
| **Receitas** (o que foi recebido) | as mesmas 6 linhas **+ `1.6 - Descontos` −R$ 20,00** | **R$ 1.000,00** |
| **sistema** (obrigação 4883) | `valor_original` | **R$ 1.000,00** |

Conferido no banco: a obrigação **4883 está paga e encerrada** — alocação de R$ 1.000,00
(`cobranca_alocacao_pagamento` id 1453) e `encargos_congelados_em` preenchido.

**O sistema sabe do desconto** — foi alimentado pelo relatório de Receitas, que é onde o desconto está
escrito. O relatório de Acordos não sabe, porque na época do acordo o desconto ainda não existia.
Sobrescrever reabriria R$ 20,00 de dívida que a própria contábil perdoou, numa parcela quitada.

⚠️ **Objeção do dono que derrubou minha primeira explicação:** *"como que a contabilidade dá 20 reais
de desconto, mas a planilha mostra 20 reais a mais? pela lógica o sistema tinha que estar cobrando 20
reais a mais pois ele não sabe do desconto."* Ele estava certo em estranhar: **eu tinha escolhido um
caso de DESCONTO para ilustrar uma história de JUROS**, e nunca disse de onde vinha o número do
sistema. São dois fenômenos distintos e a explicação só fecha quando se diz **qual das duas planilhas
alimentou cada campo**.

### 8.5 Três afirmações do handoff caíram na remedição (de novo)

| dizia | mede |
|---|---|
| "33 casos" | **75** |
| "R$ 2.713,00" | **R$ 6.923,49** |
| implícito: os dois lados (parcelas *e* contas originais) | **74 das 75 saem só do laço de parcelas**; só o NN 77099 aparece nas duas seções |

⚠️ **Ressalva honesta sobre a contagem:** 75 e R$ 6.923,49 são medidos contra `saas_ux_pos_etapa3` —
um estado que **não vai existir de novo** (import antigo e estreito). Numa importação do zero a
contagem muda. **O que não muda é a composição**, que é propriedade da fonte, não do banco.

### 8.6 Dois defeitos da minha própria medição, achados antes de concluir

1. **Somei o mesmo NN nas duas seções.** O NN aparece em `contas originais` *e* em `parcelas` (o acordo
   renegocia a conta e emite a parcela com o mesmo boleto). 12 casos "batiam em exatamente o dobro" —
   o mesmo sintoma de "metade" que a frente do hífen já tinha registrado. Corrigido pondo a **seção na
   chave** antes de tirar qualquer conclusão.
2. **Li o contador errado do relatório.** Achei uma contradição ("22 divergências com 0 parcelas
   existentes") que não existia: eu tinha grepado *"Parcelas existentes ligadas ao acordo"*
   (= `$vinculadas`, o vínculo criado) em vez de *"Parcelas que já existiam (nada a fazer)"*
   (= `$existentes`, que é onde a divergência é reportada). Eram 27, e batem.

### 8.7 Achado extra que já vale para o item 2

**22 das 75 parcelas têm principal ZERO na fonte** — são só honorário e juros (ex.: NN 76575, com
`P 0,00 · J 97,81 · H 988,25`). **É exatamente a forma do item 2.** A guarda que rejeita
(`TopLifeInadimplenciaAdapter.php:201`, *"Boleto sem principal de dívida"*) tem irmã aqui.

🔴 **Alerta de desenho para quem for escrever a spec do item 2:** o sistema calcula encargo **ao vivo a
partir do `valor_original`** (`EncargosVivos::exigivelVivo`). Uma obrigação criada com principal ZERO
gera encargo ZERO — os R$ 4.396,07 **não apareceriam na tela** só por remover a guarda. Ou o valor
entra como principal (e muda de natureza jurídica: encargo vira dívida, e passa a render encargo
novo), ou precisa de outro caminho. **Isso é decisão do dono e ainda não foi feita a ele** — meça
antes de perguntar, como foi feito aqui.

### 8.8 O que o item 3 virou

**Não é mais correção de dado — é correção do AVISO.** Proposta apresentada ao dono e **adiada para o
fim da fila por decisão dele**: o relatório passar a comparar **principal com principal**, ignorando
juros, desconto e honorário, que o sistema guarda em outros campos.

- hoje: **75 avisos, nenhum é problema** — e aviso que sempre dispara ninguém lê;
- depois: **0 avisos**, e um aviso futuro passaria a significar divergência real de principal;
- **nenhum centavo se move** — a dívida já bate em 75 de 75.

⛔ **Até o dono mandar, o aviso fica exatamente como está.**

### 8.9 Bancos descartáveis deste dia

- **`saas_ux_div`** — clone de `saas_ux_pos_etapa3`, usado só para dry-run (nada gravado: nenhuma
  execução teve `--confirmar`). Pode ser apagado.
- ⛔ `saas_ux`, `saas_ux_pos_etapa3` e `saas_ux_antes_etapa3` **não foram tocados**.

### 8.10 A outra sessão continua escrevendo no mesmo repositório

A frente "esqueci a senha / cadastro público" avançou durante o dia: além dos arquivos de 05/08,
apareceram `app/src/Auth/UseCase/ConfirmarCadastroUseCase.php`,
`IniciarCadastroPublicoUseCase.php` e `app/src/Command/PurgarDadosExpiradosCommand.php` (com testes).
**Nada disso é da cobrança.** `git add` sempre por caminho explícito.

---

## 9. 🔴 06/08 (sessão 2) — O ITEM 2 FOI MEDIDO E TAMBÉM SE DISSOLVEU

**Nenhuma linha de código de produção foi tocada.** Segundo dia seguido em que a medição desfaz o item
em vez de virar spec. **A fila de dinheiro faltando ficou VAZIA** — o que resta é completude (item 5).

### 9.1 De onde vêm os R$ 4.396,07 — a conta fecha ao centavo

Fonte, dita por extenso: **`2026-08-04-api/top_life_1_Inadimplencias_detalhadas.xlsx`** — e só ela. O
número do handoff é a soma da **coluna M (Total)** dos 13 boletos rejeitados por `principal <= 0`:

| coluna da planilha | soma dos 13 boletos |
|---|---:|
| H — Valor | R$ 3.361,79 |
| I — Juros | R$ 360,47 |
| J — Multa | R$ 67,24 |
| K — Correção | R$ 0,00 |
| L — Honorários | R$ 606,57 |
| **M — Total** | **R$ 4.396,07** ✅ (H+I+J+K+L fecha exato) |

**13 boletos, 8 unidades, só na TL1** — TL2 e AMLI dão **zero**. Isso o handoff acertou.

🔑 **E a coluna Valor desses 13 não tem principal nenhum.** Decomposta por classe de conta:
**honorário (1.15) R$ 2.463,92 · juros lançado (1.4) R$ 876,53 · multa lançada (1.5) R$ 21,34 ·
principal (1.1/1.14) R$ 0,00.** É a mesma forma do item 3 — a §8.7 previu certo.

### 9.2 O achado que dissolve o item: eles entram por OUTRA planilha

**Os 13 de 13 são parcelas de acordo, e os 13 aparecem na planilha de ACORDOS**
(`top_life_1_Acordos_detalhados_EM_ANDAMENTO.xlsx`) como parcela, com valor idêntico **ao centavo**
(R$ 3.361,79 no total). Nenhum consta como pago na Receitas, então nenhum cai na recusa de
"parcela já liquidada" (`ImportarAcordosDetalhadosUseCase.php:465`).

O importador de Acordos grava a parcela com `valorOriginal = $parcela->valorCentavos` (`:921`) — o
"Valor acordado" da planilha de Acordos. **Logo: a guarda da inadimplência
(`TopLifeInadimplenciaAdapter.php:201`) NÃO é o que segura esse dinheiro.** Ela rejeita uma linha que
tem outra porta de entrada. Mexer nela não acrescentaria um centavo — acrescentaria uma **segunda**
obrigação para o mesmo boleto.

⚠️ **Isto corrige a §6.1 e a §6.3:** o item 2 nunca foi "R$ 4.396,07 que ficam de fora". Era um total
com encargos, de boletos sem principal, que entram por outro relatório.

### 9.3 O que REALMENTE fica de fora, e é o item 5

O que decide se a parcela nasce é **o acordo existir**. Hoje o acordo nasce pela **Receitas**
(o importador de Acordos nunca cria acordo — §3.1). Medido contra
`2026-08-04-completo/top_life_1_Receitas_detalhadas_TODOS.xlsx` (7.411 recebimentos, 304 acordos):

| acordo | nasce pela Receitas? | parcelas | valor |
|---|---|---:|---:|
| 225, 339, 348, 369 | ✅ sim | 7 | R$ 1.681,93 |
| **155, 374, 394, 414** | ❌ **não** | **6** | **R$ 1.679,86** |
| | | **13** | **R$ 3.361,79** ✅ |

**Os 6 que ficam de fora são o item 5**, não o item 2 — o mesmo conserto que fecha os outros 29
acordos de 359 (§6.0.2). O item 2 não tem trabalho próprio: ele é um pedaço do item 5.

### 9.4 ⏳ A prova em banco NÃO foi concluída — e o motivo importa para o item 8

O plano era: banco descartável `saas_ux_item2` (clone de `saas_ux_antes_etapa3` com as tabelas
`cobranca_*` truncadas) → importar a Receitas TL1 completa com `--confirmar` → dry-run de Acordos →
ver as 13 parcelas nascerem.

🔴 **A importação da Receitas completa travou a máquina do dono DUAS vezes** (7.411 recebimentos numa
transação única, `memory_limit=3G`). Nas duas o rollback foi limpo — **nada gravado, banco em zero**,
conferido. `saas_ux_item2` pode ser apagado.

⚠️ **Isto é um achado operacional para o item 8 (teste do zero):** a prova final **não pode** ser uma
transação única sobre o lote inteiro nesta máquina. Vai precisar ser fatiada (por carteira, ou por
faixa de linhas), ou rodar com o processo reduzido. Descobrir isso no item 8, com o relógio correndo,
seria pior.

**O que a falta dessa prova custa:** nada da §9.1–9.3, que é **medido contra as planilhas** — e
composição de fonte não depende do estado do banco (a ressalva da §8.5 vale ao contrário aqui). O que
fica sem confirmação empírica é só o elo já lido no código: que a parcela em aberto, com acordo
existente e sem NN ambíguo, é criada (`:485-496`).

### 9.5 Decisões do dono nesta sessão

1. **Ordem:** item **6 primeiro** (validador do rodapé), **depois o 5**. Motivo aceito: o validador é
   pequeno, independente, e impede o item 8 de rodar sobre arquivo com recorte errado — que é o erro
   que originou esta frente.
2. **Regra para a spec do item 5:** quando a planilha de Acordos traz acordo que não existe no
   sistema, **cria** — *"sim, cria, a planilha manda"* —, **inclusive quando as parcelas são só
   honorário e juros, sem principal** (é o caso dos 4 acordos que faltam). Coerente com a diretriz da
   §6.2 e com as ~350 parcelas de acordo que já entram assim.

### 9.6 A fila depois desta sessão

~~4~~ → ~~1~~ → ~~3~~ (medido, saiu) → ~~**2**~~ (medido, dissolveu-se no 5) →
**6** → **5** → **8** → **7** → **3** (o aviso, por último).

⛔ **O aviso de divergência continua exatamente como está.** Decisão do dono, não mexer.

---

## 10. ✅ 06/08 (sessão 2) — ITEM 6 FECHADO: o validador do rodapé

Spec: `docs/specs/cobranca-validador-rodape-filtros.md`. **3 commits, 2 revisões com correção entre
elas, 7 injeções de prova, suíte 3325/3325 verde. Nada publicado, nada em produção.**

| | |
|---|---|
| `22d5f93d` | o validador + a ligação nos 4 comandos |
| `9504c73b` | correções da 1ª revisão (a **tela** era a 5ª porta, e estava aberta) |
| *(este)* | correções da 2ª revisão (o **lado rígido** não tinha um único teste) |

### 10.1 O que ele faz

Lê a linha `Filtros:` do rodapé e **recusa o arquivo cujo recorte não seja o exigido** — nas 5 portas:
os 4 comandos de importação e a tela (`ImportacaoController::prever`). Vale no dry-run também: um
dry-run sobre arquivo errado imprime um relatório convincente e falso.

🔒 **Vira trava técnica a decisão "cancelados ficam de fora":** apontar qualquer comando para o
`*_CANCELADO.xlsx` passa a ser **barrado**, não avisado. Era só uma frase repetida a cada sessão.

**Provado contra 15 arquivos reais: zero falso-aceite, zero falso-recusa.** O arquivo que a secretária
baixou à mão em 03/08 é recusado por dois campos (`Aberta e baixada` + vencimento de 2026) — é o caso
concreto que motivou o item.

### 10.2 🔑 As duas revisões, e o que cada uma pegou

**Continua valendo o padrão desta frente: a 2ª revisão achou defeito nas correções da 1ª.** Nona vez.

| revisão | achado que mais importou |
|---|---|
| 1ª | **a TELA não passava pelo validador** — regra que fecha 4 de 5 portas não fecha porta nenhuma |
| 2ª | **o lado RÍGIDO não tinha UM teste**: tudo exercitava a recusa, nada provava que um recorte correto é ACEITO |

⚠️ **A armadilha que o teste de aceite pega é real e está no dado:** a inadimplência escreve
`Unidade: Todas` e a receitas escreve `Unidade: Todos` — **uma letra**. Com `RecorteEsperado` errado, os
testes de recusa ficariam todos verdes e o comando nasceria **travado em produção**. A spec abria
dizendo que "errar para o lado rígido trava a importação inteira", e era esse lado que estava sem
cobertura nenhuma.

Outros achados corrigidos: a leitura dita "magra" carregava 21.150 linhas × 10 colunas para ler uma
célula (faltava `IReadFilter`); `catch (\Throwable)` transformaria bug nosso em "confira o download";
mensagem que dizia "recorte errado" para arquivo truncado; `recorteConfere()` duplicado nas 4 classes.

### 10.3 🔑 O teste da ORDEM nasceu errado TRÊS vezes

Vale registrar porque é uma armadilha genérica, não deste item:

1. *"a planilha vazia prova que o adapter não rodou"* — **não prova**: os 4 adapters leem planilha vazia
   sem exceção, devolvendo zero itens;
2. *"a seção `Leitura:` não pode sair"* — **também não**: mover a *leitura* para antes não move a
   *impressão*, que fica depois da conferência. Injetei a troca e seguiu verde;
3. *"basta provar num comando"* — prendia a ordem só na Receitas; nos outros três a inversão continuava
   verde.

O que prova: arquivo com **assinatura de ZIP seguida de lixo** (`PK\x03\x04` + zeros) — o validador o
transforma em recusa com motivo, o adapter estoura. Texto puro **não serve**: o `IOFactory` o aceita
como CSV e o caso cai noutro ramo.

### 10.4 ⚠️ Consequência operacional para o item 8

O validador **recusa o lote de Receitas de `2026-08-04-api/`** (janela `Período de recebimento:
01/01/2026 a 04/08/2026`) — e está certo em recusar, é o recorte que cortou 5 anos para 7 meses. O
**teste do zero (item 8) tem de usar o lote `2026-08-04-completo/`**, que é o correto e passa. Os
arquivos de Acordos e Inadimplência de `2026-08-04-api/` continuam válidos.

### 10.5 Fixtures corrigidas (não foi acomodar o código)

As fixtures `.xlsx` da tela tinham o rodapé **truncado pela anonimização** — só 2 dos 5 campos.
Completá-las foi corrigir a fixture. A `toplife_amostra_zip64.xlsx` foi reescrita preservando o
`compress_type` de cada entrada, porque o que ela testa é o mime indetectável que só o Zip64/store
produz — conferido: continua `application/octet-stream`, e nenhum dado de planilha mudou.

### 10.6 ⏳ O smoke do item 6 (do dono — 3 minutos)

Na tela de importação de uma carteira (`/cobrancas/carteiras/<id>/importar`):

1. **Suba uma inadimplência com recorte errado** — serve qualquer emissão com `Competência` diferente
   de `Todas`, ou o arquivo manual antigo. **Esperado:** volta para a tela de upload com a mensagem
   *"O recorte deste arquivo não serve"*, o motivo campo a campo, e o rodapé lido. Nada é gravado, e
   **não** aparece a prévia.
2. **Suba a mesma planilha com o recorte certo.** **Esperado:** a prévia abre normalmente, como sempre.
   *(Este é o passo que importa: prova que a trava não fecha o que é válido.)*
3. Opcional, se quiser ver a mensagem de arquivo quebrado: interrompa um download pela metade e suba o
   arquivo. **Esperado:** *"Não foi possível abrir o arquivo"* — e **não** "o recorte não serve",
   porque o problema não é o recorte.

Na CLI, o mesmo vale para os 4 comandos; o mais fácil de ver é apontar qualquer um para o
`*_CANCELADO.xlsx`: ele agora é **barrado**.

## 11. 🧪 Bancos descartáveis de 06/08 (podem ser apagados)

- **`saas_ux_item2`** — clone de `saas_ux_antes_etapa3` com as tabelas `cobranca_*` truncadas. As duas
  tentativas de importar a Receitas completa travaram a máquina; o rollback foi limpo nas duas
  (**nada gravado**, conferido em zero). Pode ser apagado.
- **`saas_ux_div`**, **`saas_ux_semnn`**, **`saas_ux_ac`**, **`saas_ux_dryrun`** — dos dias anteriores.
- ⛔ **`saas_ux`, `saas_ux_pos_etapa3` e `saas_ux_antes_etapa3` não foram tocados.**

Scripts de medição descartáveis desta sessão, na pasta gitignored `planilhas atualizadas/`:
`_item2_decompor.php`, `_item2_cruzar.php`, `_item2_colunas.php`, `_item2_acordos_nascem.php`,
`_item6_rodapes.php`, `_item6_provar.php` (este último é o que confere o validador contra os 15
arquivos reais — vale manter à mão enquanto a frente estiver viva).

---

## 12. ✅ 07/08 — ITEM 5 ENTREGUE: o importador de Acordos passa a CRIAR o acordo

Spec: `docs/specs/cobranca-importar-acordos-criar-acordo.md`. **2 revisões com correção entre elas,
24 injeções de prova, suíte 3390/3390 verde. Nada publicado, nada em produção, nada gravado com
`--confirmar`.**

Revoga a §3.1 da spec-mãe (*"o acordo nunca é criado aqui"*). A spec-mãe foi corrigida no mesmo commit —
ela ainda dizia o contrário do que o código faz.

### 12.1 O que muda, em uma frase

A aba cujo acordo não existe **cria o acordo** e é processada inteira, em vez de ser reportada e ignorada.

**Qual planilha alimenta o quê** (é a pergunta que travou uma conversa antes): a de **Acordos** declara o
acordo, a unidade, o sacado, a situação, as contas originais e as parcelas · a de **Receitas** era a única
porta de criação, e só cria quando alguém **pagou** uma parcela · a de **Inadimplência** cria a unidade e o
caso de cobrança. Acordo fechado há semanas, sem pagamento, não nascia em lugar nenhum.

### 12.2 Os números — e a armadilha que a 2ª revisão pegou neles

🔴 **A medição planilha×planilha responde a pergunta do ITEM 8, não a de rodar contra um banco qualquer.**

| | |
|---|---:|
| acordos declarados pela contábil (TL1+TL2+AMLI) | 392 |
| nascem hoje (Receitas) | 354 |
| **passariam a nascer** | **38** |
| parcelas que viram dinheiro a receber | **R$ 28.926,43** (119) |
| contas originais reconstruídas (nascem fora do saldo) | R$ 41.975,83 (267) |
| **dívida contada em dobro** | **R$ 0,00** |

⚠️ **O handoff dizia 29 e não estava errado — estava incompleto:** 29 = TL1 (21) + TL2 (8), bate exato.
Faltavam os **9 da AMLI**, que o dono mandou incluir. Os 4 acordos do antigo item 2 (155, 374, 394, 414)
estão entre os 21 da TL1. ✅

🔑 **Contra BANCO os números são outros, e isso é o achado mais importante do dia.** Dry-run real
(read-only, sem `--confirmar`) contra `saas_ux`, que só tem a importação estreita da etapa 3:

| arquivo | acordos criados | parcelas | abas recusadas |
|---|---:|---:|---:|
| TL1 `EM_ANDAMENTO` | 45 | 608 — **R$ 129.811,51** | 21 |
| TL1 `LIQUIDADO` | 74 | **0** | 185 |
| TL2 `EM_ANDAMENTO` | 0 | 0 | 0 |
| TL2 `LIQUIDADO` | 15 | 0 | 11 |

**O número que o dono autorizar tem de sair do dry-run do banco em que a importação vai rodar**, nunca da
spec. E a **ordem virou requisito**: Inadimplência → Receitas → Acordos. Rodar Acordos antes recusa as abas
(sem gravar nada) — foi o que produziu as 217 recusas acima.

### 12.3 🔑 O zero que eu não acreditei, e conferi

**0 de 267 contas originais casam** com a Inadimplência ou a Receitas. Se fosse chave errada minha, o item
contaria a **mesma dívida duas vezes** na tela do dono. Três conferências: formato de competência idêntico
nas 3 fontes · régua frouxa (só o NN) também dá 0 · a mesma régua casa **837 parcelas** dos acordos que já
funcionam. É propriedade da fonte: **a contábil remove a conta renegociada da inadimplência ao fechar o
acordo**. Risco de dobrar: zero.

### 12.4 As duas revisões — e a 2ª achou defeito nas correções da 1ª pela DÉCIMA vez

| revisão | achado que mais importou |
|---|---|
| 1ª | **o `Liquidado` real não tinha teste**: 627 de 627 parcelas dessas abas vêm pagas, e parcela paga não é criada — o acordo nasce com ZERO linhas, e gravar `numeroParcelasTotal` deixaria **12 acordos** com "⚠ Faltam N parcelas" **permanente e falso** na tela |
| 2ª | **o evento de histórico que a 1ª pediu polui a produção**: `AcordoCriado` é *exatamente* o que a **Central de Acompanhamento** conta como a coluna "Acordos" do trabalho humano de cobrança, e alimenta a "Última ação". Importar creditaria dezenas de acordos "fechados" num dia a quem rodou o comando |

O evento **saiu**, e a decisão está travada por teste. A procedência não se perde: `numeroExterno` só é
preenchido por importação, e as contas reconstruídas dizem *"Reconstruída da planilha de acordos"*.

Outros achados corrigidos: o dry-run não mostrava **situação nem data base** do acordo que vai nascer (os
dois campos que as decisões do dono governam) · o T3 passava com o acordo **nascendo `Ativo`** — a
sobrescrita o corrigia depois, status certo pelo caminho errado · uma **quarta cópia** da régua da unidade
no `CadastroCondominosAdapter` · assert que não distinguia "leu o cabeçalho" de "somou as parcelas" ·
o T9 era tautológico e passou a provar o **efeito** (o snapshot dos encargos da renegociada).

### 12.5 As 4 recusas (o acordo NÃO nasce)

Situação fora do mapa · situação não vigente (`Cancelado`) · aba sem `Data base` · **unidade sem cobrança
ativa na carteira**. Nenhuma lança — uma aba estranha não derruba o lote. As três primeiras nascem mortas
no dado de hoje e estão provadas só por teste; a quarta dispara muito (as 217 recusas do §12.2).

### 12.6 Decisões do dono nesta sessão

1. **Unidade que não existe: RECUSA a aba e avisa.** Este relatório não abre cobrança nova (unidade,
   devedor, caso) — quem faz isso é a inadimplência.
2. **`Data base` vira a data do acordo**, não `Criado em`. Divergem em 4 das 38 abas, e é a data que para o
   relógio dos juros das dívidas renegociadas.

### 12.7 ⏳ O que falta

- **Smoke do dono** (junto com o do item 6, §10.6): abrir um dos acordos criados e conferir que ele não
  mostra "faltam parcelas" e que a dívida renegociada aparece fora do saldo.
- O **item 8** (teste do zero) é quem prova os 38 / R$ 28.926,43 de verdade — é ele que produz o estado em
  que essa medição vale.

### 12.8 A fila depois desta sessão

~~4~~ → ~~1~~ → ~~3~~ → ~~2~~ → ~~6~~ → ~~**5**~~ → **8** (a prova final) → **7** (AMLI) → **3** (o aviso,
por último).

⛔ **O aviso de divergência continua exatamente como está.** Decisão do dono, não mexer.

---

## 13. ✅ 07/08 — ITEM 8: O TESTE DO ZERO. **A contabilidade fecha: 3.471 de 3.471 boletos.**

Banco descartável **`saas_ux_zero`** (clone de `saas_ux_antes_etapa3` com todas as tabelas `cobranca_*`
truncadas, menos a `cobranca_carteira`). Ordem: **Inadimplência → Receitas → Acordos**, TL1 e TL2.
`--confirmar` só neste banco, como autorizado. `saas_ux` **não foi tocado**.

### 13.1 🔑 A resposta à pergunta do dono

> *"quando eu limpar tudo e fazer as importações, vai estar tudo certo, sem valor faltando em nenhuma
> unidade de nenhum condomínio?"*

**SIM.** Cruzamento boleto a boleto (chave NN + competência), sistema × planilha de inadimplência:

| carteira | batem | só na CONTÁBIL (o sistema não cobraria) | só no sistema, fora parcela de acordo |
|---|---:|---:|---:|
| TOP LIFE 1 | **2.944 de 2.944** | **0 — R$ 0,00** | **0 — R$ 0,00** |
| TOP LIFE 2 | **527 de 527** | **0 — R$ 0,00** | **0 — R$ 0,00** |

**Nenhuma dívida da contabilidade fica de fora, e nenhuma dívida é inventada.**

### 13.2 Os acordos: 359 de 359, com o status exato

| carteira | ativo | cumprido | total | a contábil declara |
|---|---:|---:|---:|---|
| TOP LIFE 1 | 66 | 259 | **325** | 66 em andamento · 259 liquidados ✅ |
| TOP LIFE 2 | 8 | 26 | **34** | 8 em andamento · 26 liquidados ✅ |

Receitas bate ao centavo com o §6.0.2: **R$ 1.272.816,33** (TL1, 7.411 recebimentos) e **R$ 137.148,49**
(TL2, 858). **Zero abas ignoradas** nos 4 arquivos de acordos — a recusa R1 do item 5 não dispara quando a
ordem é respeitada, exatamente como a spec previu.

**Prévia × confirmação: idênticas nos 4 arquivos** (6/914/0 · 8/0/0 · 0/10/0 · 0/0/0). A invariável do §6
da spec-mãe vale no dado real, não só em teste.

### 13.3 🔴 A spec do item 5 estava ERRADA, e o teste do zero mostrou onde

| | spec (planilha×planilha) | medido no zero |
|---|---:|---:|
| acordos que o item 5 cria (TL1+TL2) | 29 | **14** |
| parcelas desses acordos | — | **99 — R$ 24.618,36** |

**A causa: o importador de INADIMPLÊNCIA também cria acordo** (pela coluna `Acordo N - Parc. p/t`), e a
minha medição só considerou a Receitas. Os 7 da TL1 que faltaram (155, 344, 353, 374, 380, 413, 421) e os
8 da TL2 nascem lá — conferido: todos têm `data_acordo` no dia 1º do mês, a assinatura daquele importador,
não a `Data base` exata que o item 5 grava.

⚠️ **O dinheiro não sumiu, mudou de dono:** os R$ 24.618,36 são dos 6 acordos que o item 5 cria de fato
(414, 407, 394, 411, 426, 420); o resto dos R$ 28.926,43 previstos é criado pelo importador de acordos
para acordos que a inadimplência já tinha criado — é o §3.1 antigo, não o item 5.

⚠️ **E o R$ 220.112,27 que o relatório imprime NÃO é do item 5**: é a soma de TODAS as parcelas futuras que
o importador de acordos cria, feature que existe desde julho. Mais um caso de **número grande não é
dinheiro na tela**.

### 13.4 🔑 Por que a Receitas completa travava a máquina — e por que agora não trava

Duas causas, as duas medidas:

1. **`memory_limit=3G` numa máquina de 3,7 GB.** O PHP tinha licença para pedir mais RAM do que existia
   livre, e a máquina ia para o swap. Com **1200M** a importação passou inteira, e a memória livre do host
   nunca caiu de 425 MB.
2. **`APP_ENV=dev` acumula um backtrace por query** (`BacktraceDebugDataHolder`, do Doctrine). Medido: a
   prévia da inadimplência da TL1 estoura 128 MB em dev e **passa folgado em prod**, com o mesmo limite.

**A Receitas completa (7.411 recebimentos) passou numa transação única.** A restrição do §9.4 —
*"a prova final não pode ser uma transação única nesta máquina"* — **cai**: podia, com o limite certo e
`APP_ENV=prod`. Leva ~25 min, quase tudo imprimindo a projeção.

### 13.5 ⏳ O que ficou em aberto no item 8

1. 🟠 **241 parcelas de acordo vencidas na TL1 (R$ 51.738,56)** que o sistema cobra e a inadimplência da
   contábil não lista. São parcelas de acordos vigentes com vencimento até 04/08 e sem pagamento na
   Receitas. Não é dívida inventada (a fonte é o relatório de Acordos), mas **o dono precisa olhar**: ou a
   contábil não as considera inadimplência ainda, ou foram pagas fora da janela do relatório.
2. 🟠 **R$ 2.171,70 de diferença no conjunto que bate** (R$ 424.477,77 no sistema × R$ 422.306,07 na
   contábil, 0,5%) — é a forma conhecida do **item 3**, que está na fila por último.
3. 🔴 **A carteira da AMLI BR 060 NÃO EXISTE** — em nenhum banco, nem em produção. O dono decidiu que a
   AMLI entra, mas ninguém criou a carteira. **Sem ela a AMLI não pode ser importada em lugar nenhum**, e
   por isso ela ficou fora deste teste do zero. É cadastro de tela, e é do dono.
4. ⏳ O **cadastro de condôminos** não entrou neste teste (não afeta dinheiro; afeta contatos).

### 13.6 O banco de teste continua de pé

**`saas_ux_zero`** está com o estado completo e pode ser usado para o smoke do item 5 na tela — basta
apontar o `DATABASE_URL` do dev para ele. ⛔ **Não fiz isso por conta própria**: o `.env.local` é
compartilhado e há outra sessão trabalhando no mesmo ambiente.

### 13.7 🔴 As 241 parcelas, explicadas — **os dois relatórios da contábil discordam entre si**

Pergunta do dono, em 07/08: *"tudo o que está no sistema veio da contabilidade. Então como que 241
parcelas de acordo vencidas a inadimplência não lista?"*

**Vieram da contabilidade, sim — do relatório de ACORDOS.** A inadimplência e o de acordos, emitidos no
mesmo dia pela mesma contábil, discordam. Decomposto:

| # | o quê | parcelas | valor |
|---|---|---:|---:|
| 1 | a inadimplência **lista**, mas o sistema rejeita por "só encargos" | 13 | R$ 3.361,79 |
| 2 | 🔴 **mesma unidade + mesma competência cobrada NOS DOIS** | **17** | **R$ 3.098,59** |
| 3 | só no relatório de Acordos, sem boleto concorrente | 211 | R$ 45.278,18 |

**Nenhuma das 241 consta como paga na Receitas** — conferido primeiro, porque era o risco que importava.
**O sistema não está cobrando ninguém que pagou.**

**O item 1 é conhecido:** são os 13 boletos do antigo item 2, e o valor bate **ao centavo** com o §9.2.

**O item 2 é o achado novo e é dinheiro cobrado duas vezes.** Exemplo medido — unidade **01-04B**,
competência **05/2026**: entra como parcela do acordo 224 (NN 67604, R$ 117,32) **e** como boleto 74952 na
inadimplência. Mesma mensalidade, duas cobranças. A unidade tem três acordos (163, 224, 338); o 224 está
abandonado, a contábil reboletou os meses, mas o relatório de Acordos continua dizendo `Em andamento`.

**O item 3 tem explicação medida:** dos 24 acordos que seguram essas 211 parcelas,

| último pagamento | acordos | parcelas | valor |
|---|---:|---:|---:|
| **há MAIS DE UM ANO** | **19** | **187** | **R$ 37.286,17** |
| 3 a 12 meses atrás | 5 | 40 | R$ 10.652,24 |
| nos últimos 3 meses | 1 | 1 | R$ 438,36 |

**São acordos abandonados que a contábil nunca deu baixa.** Ela parou de cobrá-los na inadimplência (por
isso não aparecem lá) mas continua declarando `Em andamento` no relatório de Acordos. O sistema, seguindo
"a planilha manda", mantém as parcelas exigíveis.

⚠️ **Isto é decisão do dono, não de código** — e é a primeira vez nesta frente em que *"o importe é a fonte
da verdade"* colide **consigo mesmo**: as duas fontes são o importe. Três caminhos possíveis, nenhum
tomado:

1. **a inadimplência ganha a disputa** — acordo sem pagamento há mais de N meses tem as parcelas retiradas
   do exigível (o mais próximo do que a contábil de fato cobra hoje);
2. **o relatório de acordos ganha** — fica como está, e o escritório cobra o que a contábil esqueceu
   (pode ser dinheiro recuperável de verdade: R$ 37 mil);
3. **ninguém ganha sozinho** — os 17 casos de dobra são corrigidos (é defeito em qualquer leitura) e os
   demais viram um relatório de "acordo parado há mais de um ano, confira".

⛔ Nada foi mexido. O aviso de divergência e o comportamento atual continuam como estão.

---

## 14. 🔴 07/08 (fim do dia) — DECISÃO DO SETOR DE COBRANÇA: **a inadimplência manda no que se cobra**

O dono levou o §13.7 ao setor de cobrança e voltou com a regra de negócio, textual:

> *"O setor é de cobrança, o que vale para nós são os inadimplentes. O que está no relatório de
> inadimplência são quem vamos cobrar. Os acordos que não foram pagos e não estão nos inadimplentes, na
> prática não são cobrados — isso é um problema de regra de negócio do sistema da contabilidade. Os
> acordos em andamento e atrasados mas que não estão na tabela de inadimplentes não são para ser
> cobrados."*

**É a opção 1 das três que o §13.7 deixou em aberto.** Nada foi implementado ainda.

### 14.1 ⛔ A PRÓXIMA SESSÃO COMEÇA AQUI: duas perguntas que precisam de resposta ANTES de escrever código

A pergunta foi montada e **não chegou a ser respondida** — o dono encerrou a sessão antes. **Não implemente
sem isso**: as duas leituras diferem em **R$ 170.583,90**.

**Pergunta 1 — alcance.** A regra vale só para as parcelas **JÁ VENCIDAS**, ou para **toda** parcela de
acordo ausente da inadimplência?

| leitura | sai do saldo | o que acontece |
|---|---:|---|
| **só as já vencidas** (recomendada) | **241 parcelas · R$ 51.738,56** | as 683 parcelas **futuras** (R$ 170.583,90) continuam a receber |
| toda parcela fora da inadimplência | 924 parcelas · R$ 222.322,46 | o relatório de Acordos deixa de somar dinheiro e vira só histórico |

🔑 **Por que a recomendação é a primeira:** a parcela que vence semana que vem **não está na inadimplência
porque ainda não venceu**, não porque a contábil desistiu dela. A frase do setor diz *"em andamento e
ATRASADOS"*. E a leitura literal desfaz a razão de ser da importação de acordos (§3.1 da spec-mãe:
*"completar as parcelas futuras — R$ 1.399,49 a receber que nenhum relatório enxerga"*).

**Pergunta 2 — volta atrás.** Quando uma parcela excluída reaparecer na inadimplência numa importação
futura, ela volta a ser cobrada **sozinha** (a regra é reavaliada a cada importe — recomendado, coerente
com *"o importe é a fonte da verdade"*), ou **fica fora** até alguém reativar na tela?

### 14.2 O que já está medido para a spec

Estado do banco `saas_ux_zero` (importação do zero, §13), obrigações exigíveis em aberto:

| | TOP LIFE 1 | TOP LIFE 2 |
|---|---:|---:|
| boleto comum (não é parcela de acordo) | 2.858 · R$ 399.750,00 | 519 · R$ 88.230,00 |
| parcela de acordo **já vencida** | 327 · R$ 76.466,33 | 8 · R$ 3.641,17 |
| parcela de acordo **a vencer** | 673 · R$ 168.373,71 | 10 · R$ 2.210,19 |

Das 327 vencidas da TL1, **241 não estão na inadimplência** (são as do §13.7) e 86 estão. Na TL2 as 8
estão todas na inadimplência — **a TL2 não é afetada pela regra nova**.

### 14.3 Pontos de desenho já levantados (não decididos)

1. **É uma regra CRUZADA entre dois relatórios**, a primeira desta frente. Hoje cada importador decide
   sozinho; esta regra precisa que o resultado da inadimplência influencie parcelas criadas pelo importador
   de acordos. **A ordem de importação vira ainda mais crítica.**
2. **Nunca apagar** (invariável 14). A parcela continua existindo; o que muda é ela sair do **exigível**.
   Provavelmente um campo/estado novo na `Obrigacao`, não um `DELETE`.
3. **Idempotência e reversibilidade** dependem da resposta da pergunta 2.
4. **Os 13 boletos "só encargos"** (§13.7 item 1) **estão** na inadimplência — a contábil os cobra. Sob a
   regra nova eles continuam cobráveis; hoje entram pela planilha de Acordos. Não mexer.
5. **Risco ALTO** — tira dívida do saldo de devedor real. Spec + prova por reintrodução + duas revisões.

### 14.4 Entregue ao dono nesta sessão (arquivos, pasta gitignored por conter nome de devedor)

- `planilhas atualizadas/RELATORIO_ACORDOS_PARADOS.pdf` — **2 páginas**, para o setor de cobrança: 25
  acordos parados em 16 unidades, R$ 48.376,77, com a tabela de prioridade dos 17 meses com cobrança
  sobreposta. ⚠️ A 1ª versão dizia "boleto novo" para todos; **corrigida** depois que o dono perguntou:
  em **13** casos o boleto concorrente é **parcela de um acordo mais novo**, em **10** é **mensalidade
  avulsa**. São investigações diferentes.
- `planilhas atualizadas/GUIA_CONFERENCIA_RELATORIOS.pdf` — **1 página**, passo a passo de onde olhar nos
  três relatórios, com o caso 01-04B destrinchado.

🔑 **Descoberta que vale para sempre:** a coluna **F ("Detalhamento")** da seção *Relação das contas
originais* do relatório de Acordos **documenta a cadeia**: no acordo 224 ela diz `Acordo 163 - Parcela
4/12`; no 338 diz `Acordo 224 - Parcela 5/24`. Dá para reconstruir a história inteira do devedor por ela.
O 01-04B refez o acordo três vezes e **nenhum dos antigos recebeu baixa**.

### 14.5 Smoke do item 6 — ✅ FEITO no navegador (3 de 3)

O dono pediu explicitamente o Playwright. Recorte errado → recusa com o campo e o rodapé lido, nada
gravado · recorte certo → prévia abre normal (o passo que importa) · arquivo truncado → *"não foi possível
abrir o arquivo"*, e **não** "o recorte não serve". Prints em `.playwright-mcp/`.

⚠️ **Nenhum arquivo de inadimplência que temos tem recorte errado** — o problema era em Receitas e Acordos.
O roteiro do §10.6 mandava usar "o arquivo manual antigo", e ele é **aceito**. O par do smoke foi fabricado
mudando só a linha `Filtros:` de uma cópia.

⏳ **Smoke do item 5 NÃO foi feito:** os acordos criados só existem em `saas_ux_zero`, e apontar o
`.env.local` para lá mexeria no ambiente da outra sessão. Decisão do dono.

### 14.6 A fila depois desta sessão

~~4~~ → ~~1~~ → ~~3~~ → ~~2~~ → ~~6~~ → ~~5~~ → ~~8~~ → **NOVO: a regra do setor de cobrança (§14)** →
**7** (AMLI) → **3** (o aviso, por último).

⛔ O aviso de divergência continua exatamente como está.

---

## 15. 🔑 07/08 — O SUPORTE DA CONTÁBIL RESPONDEU: **não é defeito, é desenho.** A §14 muda de rumo.

O dono trouxe print do WhatsApp do suporte do **Group Software** (13/07/2026, analista Gabriel):

> *"O acordo anterior permanecerá com o status **'Em andamento' até que o novo acordo seja totalmente
> liquidado**. Isso ocorre porque o acordo original mantém o vínculo com as novas parcelas geradas durante
> o refinanciamento, garantindo a rastreabilidade de todo o processo. Assim que o novo acordo for quitado
> integralmente, o status do acordo anterior será atualizado automaticamente."*

### 15.1 A regra foi TESTADA contra os dados e se sustenta

Nos **52 acordos substituídos** da TOP LIFE 1: a regra **bate em 46**.

E os dados acrescentam uma cláusula que o suporte não disse: **sucessor CANCELADO mantém o antigo "Em
andamento"** — e está certo, porque a renegociação fracassou e a dívida velha volta a valer. Testei a
versão que ignora os cancelados e ela **piora** (43 acertos). A régua correta é: *o antigo só fecha quando
**TODOS** os sucessores estiverem liquidados.*

**🐛 6 quebras, e 4 delas são bug do sistema deles:** os acordos **348, 292, 372 e 82** estão "Em
andamento" com o sucessor (393, 326, 425, 115) **já liquidado** — a atualização automática que o Gabriel
prometeu não disparou. **Reclamação concreta para mandar ao suporte, com número.** As outras 2 (73 e 111)
foram fechadas mesmo tendo sucessor cancelado — inconsistência na direção segura.

### 15.2 A "Situação" NÃO é inútil — responde outra pergunta

| Situação | de fato ativos | já substituídos |
|---|---:|---:|
| **Liquidado** | 239 | 20 |
| **Cancelado** | 96 | 3 |
| **Em andamento** | **34** | **32** |

**"Liquidado" nunca mente: 259 de 259 têm TODAS as parcelas pagas.** `Em andamento` significa *"esta dívida
ainda não foi quitada"* — o que é **verdade**: ela está viva, dentro do acordo mais novo. O que ele **não**
significa é *"estamos cobrando este acordo"*.

### 15.3 O que o dono quer, e por que "só marcar o antigo como encerrado" NÃO funciona

> *"Não quero que apareçam acordos substituídos, apenas os que estão realmente vigentes."*

🔴 **A armadilha, medida no código:** o sistema tem duas alavancas e **nenhuma faz isso sozinha** —
`doCasoExigiveis` exclui o que está substituído por acordo **vigente** e as parcelas de acordo **não
vigente**:

| marcar o acordo antigo como… | as parcelas DELE | as dívidas antigas que ELE renegociou |
|---|---|---|
| **Rompido / Cancelado** | saem do saldo ✅ | **VOLTAM para o saldo** ❌ |
| **Cumprido** | continuam no saldo ❌ | ficam fora ✅ |

Marcar como rompido **ressuscita** as taxas de condomínio que aquele acordo tinha renegociado — trocaria
R$ 48 mil de dívida errada por outra dívida errada.

### 15.4 O caminho certo: o acordo NOVO assume as parcelas do antigo

É o que a contábil diz que aconteceu. Aí as parcelas velhas saem do saldo **por terem sido renegociadas**,
não por o acordo ter morrido — e as originais continuam fora, porque o vínculo não se rompe.

**O obstáculo é a INV-I**, e ele não é teórico: a importação do zero produziu **286 recusas** dela
(285 na TL1 `EM_ANDAMENTO` + 1 na `LIQUIDADO`):

> *"61183: esta MESMA importação a criou como parcela do acordo 211 — não é dívida original, não marcada
> (INV-I)."*

A INV-I foi escrita para impedir "acordo sobre acordo" e contar dívida duas vezes. **Só que acordo sobre
acordo é a operação NORMAL da contábil** — e agora existe a prova documental de que a substituição é
legítima: a **coluna F (`Detalhamento`)** da seção *Relação das contas originais*, que diz
`Acordo 163 - Parcela 4/12`.

⚠️ **O adapter documenta essa coluna no cabeçalho da classe e NÃO a lê.** Ler é a parte trivial.

### 15.5 🔁 Isto SUBSTITUI a frente da §14

A regra passa a ser **dentro de um relatório só** (Acordos), com a contábil documentando cada substituição
— em vez de cruzar Acordos × Inadimplência, que era frágil e dependia da ordem de importação.

As duas perguntas da §14.1 **provavelmente caem**. Confirmar com o dono antes de descartá-las:
das 241 parcelas, **227 pertencem a 24 acordos COM sucessor** (R$ 48.187,48) e só **1 acordo sem sucessor**
(R$ 189,29 — acordo 176, unidade 19-03B). Se o caminho da §15.4 funcionar, sobra quase nada para a §14.

### 15.6 Ainda NÃO dá para lançar em produção

1. **A carteira da AMLI não existe** em banco nenhum — cadastro de tela, do dono;
2. **Smoke do item 5** não foi feito;
3. **64 commits** não publicados, deploy não feito;
4. Esta mudança é **risco ALTO** (mexe na guarda que hoje protege contra dívida duplicada): spec, teste
   provado por reintrodução, **duas** revisões.

---

## 16. ✅ 08/08 — A §15 ENTREGUE: **o acordo novo assume as parcelas do anterior**

Spec: `docs/specs/cobranca-acordo-assume-parcelas-do-anterior.md`. **3 commits locais, 2 revisões, 19
injeções de prova, suíte 3.416 verde. Nada publicado, nada em produção, nada gravado em `saas_ux`.**

### 16.1 🔴 TRÊS COISAS DA §15 CAÍRAM NA MEDIÇÃO

**1. A unidade da substituição é a PARCELA, não o acordo.** A aba do acordo 393 (R$ 301,69 inteiro) tem
UMA conta original: `NN 75125 … Acordo 348 - Parcela 2/40`. Ele não substituiu o 348 — renegociou **uma
parcela** dele. O 348 segue com **38 parcelas em aberto, R$ 9.197,90**.

**2. Dos 4 "bugs do suporte", 3 não são bugs.** O sucessor levou 1 ou 2 parcelas de 40:

| acordo | o sucessor assumiu | o velho ainda deve |
|---|---|---:|
| 348 | 1 de 40 — R$ 242,05 | 38 parcelas — R$ 9.197,90 |
| 292 | 1 de 40 — R$ 191,76 | 28 parcelas — R$ 5.369,48 |
| 372 | 2 de 40 — R$ 511,64 | 37 parcelas — R$ 9.460,16 |
| **82** | 1 (8/10) — R$ 278,50 | **1 parcela — R$ 278,50** ← zera |

**Reclamar só do 82** (decisão do dono, 08/08). Os outros três estão "Em andamento" porque estão mesmo.

**3. A INV-I CAUSAVA a dobra que existia para impedir.** 286 parcelas velhas no saldo (R$ 63.961,06) ao
lado das 387 parcelas novas que as substituem (R$ 100.483,89) — a mesma dívida, do mesmo devedor, duas
vezes.

### 16.2 O que mudou, e qual planilha alimenta o quê

A **planilha de Acordos detalhados**, seção *"Relação das contas originais"*, **coluna F
("Detalhamento")** — documentada no adapter desde julho e nunca lida. Ela diz `Acordo 163 - Parcela 4/12`
quando a conta original é, na verdade, parcela de um acordo anterior.

A coluna F **não** virou chave de busca (o casamento continua sendo NN + competência): virou a **condição
de aceitar**. Sem declaração, declarando outro acordo, declarando o próprio acordo da aba, ou com a origem
não vigente, a recusa da INV-I continua idêntica. **A tela do gestor não mudou** — lá não há prova
nenhuma (decisão do dono).

**Regularidade da fonte, medida:** 5.029 grupos na seção, **0** com detalhamento divergente; só **2**
formas de texto no acervo inteiro (`-` e `Acordo N - Parcela N/N`); e em **0** casos o acordo declarado
difere do que o sistema já registrava.

### 16.3 O efeito, medido em banco descartável (`saas_ux_f15`, clone do `saas_ux_zero`)

| carteira | antes | depois | delta |
|---|---:|---:|---:|
| **TOP LIFE I** | 3.858 obrigações · R$ 644.590,04 | 3.572 · R$ 580.628,98 | **−286 · −R$ 63.961,06** |
| TOP LIFE II | 537 · R$ 94.081,36 | 537 · R$ 94.081,36 | **0 — a carteira é indiferente** |

⚠️ **O relatório imprime R$ 77.916,65** ("principal que sai"), mas **R$ 13.955,59** já estavam fora do
saldo. O que sai da tela é R$ 63.961,06. **7ª vez** nesta frente.

**Nada mais mudou:** 0 obrigações criadas ou apagadas, 0 acordos com status alterado, 0 pagamentos, 0
alocações. Rodei a mesma importação com a **guarda antiga reinjetada** para isolar — e ela revelou que
**a INV-I nunca impediu o estado que existia para impedir**: 131 obrigações ficam com origem E substituto
mesmo com a guarda original, porque o substituto é gravado enquanto a origem ainda é nula.

**Na tela: 37 acordos** deixam de se anunciar apenas como vigentes (17 diziam "Ativo", 20 "Cumprido") e
passam a exibir *"Substituído pelo acordo #N"* ao lado do estado. Os **12** parcialmente renegociados não
recebem o selo: continuam devendo.

### 16.4 A §14 quase morre

As 241 parcelas / R$ 51.738,56 da §13.7 reproduzem exatas. Com esta regra, **214 se resolvem
(R$ 46.258,31)** e sobram **27 — R$ 5.480,25**.

### 16.5 As duas revisões — e a 2ª achou defeito nas correções da 1ª pela DÉCIMA PRIMEIRA vez

| revisão | o achado que mais importou |
|---|---|
| 1ª | a **porta B era mais frouxa que a porta A**: trocava em silêncio um substituto vigente já existente, somava como "sai do saldo" dívida que já estava fora, e apagava a única memória de quem a substituíra antes |
| 2ª | 🔴 **a PRÉVIA gravava no banco** — e não "um pouco": o teste escrito antes da correção morreu com `A new entity was found through the relationship`. Na prévia o acordo novo nunca é persistido, o flush o arrasta e **derruba a projeção inteira** |
| 2ª | a correção da 1ª **trocou um furo de coerência por um furo de PARIDADE**: ler a vigência da entidade dá respostas diferentes na prévia e na confirmação, porque o status só é gravado em um dos modos. A saída não era escolher entre as duas — o status é *decidido* nos dois modos e só *escrito* em um, e é a decisão que ficou no acumulador |

Outros corrigidos: o selo estava **no lugar** do estado (apagava o "Cumprido" de 20 acordos) · escolhia um
sucessor a esmo quando havia vários (8 acordos, um com 22) · a mensagem de recusa mandava investigar a
coisa errada · imprimia o **id interno**, que não existe em fonte nenhuma para quem confere a planilha.

🔑 **E a 2ª revisão derrubou um número MEU:** a §2.3 da spec dizia 302 parcelas / R$ 67.469,44. Era erro
da minha medição — o script contava sucessores do arquivo `*_CANCELADO.xlsx`, que não é importado. O certo
é **286**, e a conta fecha exata: 457 chaves declaradas − 19 só de cancelado = 438 = **286** + 152 que já
funcionavam.

**19 injeções de defeito, 19 vermelhos** — 4 delas só depois de eu corrigir o teste, não o código.

### 16.6 ⏳ O que falta nesta frente

- ✅ **Smoke na tela FEITO em 08/08** (a pedido explícito do dono): **4 de 4 casos corretos, nenhum
  defeito**. Detalhe na **§16.10** e na §11 da spec;
- publicar os commits e o deploy;
- **reclamar com o suporte — só do acordo 82.**

### 16.7 🧪 Bancos descartáveis de 08/08 (podem ser apagados)

- **`saas_ux_f15`** — `saas_ux_zero` + a importação com o código novo. É a prova do §16.3.
- **`saas_ux_f15b`** — a mesma importação com a guarda ANTIGA reinjetada, para isolar o delta.
- ⛔ **`saas_ux_zero` NÃO foi tocado** — continua sendo a prova do item 8. `saas_ux` também não.

### 16.8 A fila depois desta sessão

~~4~~ → ~~1~~ → ~~3~~ → ~~2~~ → ~~6~~ → ~~5~~ → ~~8~~ → ~~**15** (o acordo que assume o anterior)~~ →
~~**7** (AMLI — fechado em 08/08, §17)~~ → **3** (o aviso, por último — e continua ⛔ **sem mexer** até
o dono mandar).

⛔ O aviso de divergência continua exatamente como está.

### 16.9 🎭 ROTEIRO DO SMOKE — alvos concretos, levantados no `saas_ux_f15`

⚠️ **O smoke NÃO roda no `saas_ux`.** Os acordos substituídos só existem no `saas_ux_f15` (clone do
`saas_ux_zero` com a importação nova). Para ver na tela é preciso apontar o `DATABASE_URL` do dev para lá
— e o `app/.env.local` é **compartilhado com a outra sessão**:

```bash
# ANTES: guardar a linha atual (ela aponta para saas_ux)
grep DATABASE_URL app/.env.local
# trocar para o banco da prova
sed -i 's#/saas_ux"#/saas_ux_f15"#' app/.env.local
# … smoke …
# DEPOIS, obrigatoriamente: devolver
sed -i 's#/saas_ux_f15"#/saas_ux"#' app/.env.local
```

Login: `farlei.rocha@gmail.com` · senha `Prime123!` (todas as senhas do dev). URL:
`http://localhost:8080/cobrancas/objetos/<id>`.

**Os 4 casos que o smoke tem de separar** (a 5ª coluna é o que deve aparecer):

| # | objeto | unidade | acordo (id na tela) | o que conferir |
|---|---:|---|---|---|
| 1 | **280** | 04-03C | Acordo **#410** | cai em **"Acordos encerrados"**, com `Cumprido` **e** `Substituído pelo acordo #141` lado a lado. É o caso "não sobrou nada" |
| 2 | **5** | 01-04B | Acordo **#101** | fica na seção **"Dívida em aberto"** como grupo (sobraram 3 parcelas pagas) e o cabeçalho do grupo mostra `Ativo` **e** `Substituído por 2 acordos` — sem número, porque foram dois (#2 e #102) |
| 3 | **299** | 10-02D | Acordo **#191** | mesmo caso, no extremo: **12 sucessores**. Tem de dizer `Substituído por 12 acordos`, nunca "pelo acordo #N" |
| 4 | **8** | 01-09 | Acordo **#105** | 🔴 **CONTROLE — NÃO pode ter selo nenhum.** É o 348 da contábil: o sucessor levou 1 parcela e sobraram **38 em aberto**. Ele continua vigente e cobrando |

**O que o smoke procura de errado:** selo aparecendo no caso 4 · selo *no lugar* do estado (o `Cumprido`
do caso 1 sumindo) · "Substituído pelo acordo #N" com número nos casos 2 e 3 · acordo sumindo da tela ·
as duas frases contraditórias ("saíram do total em aberto" + "voltaram ao total em aberto") na mesma linha.

⛔ **Não rodar importador com `--confirmar` contra `saas_ux`.** O smoke é só leitura de tela.

---

## 16.10 ✅ 08/08 — O SMOKE DA §16.9 RODOU: **4 de 4 corretos, zero defeito**

**Nenhuma linha de código foi tocada** — o smoke não achou o que corrigir. Detalhe completo na **§11 da
spec** (`docs/specs/cobranca-acordo-assume-parcelas-do-anterior.md`).

| # | objeto · unidade | a tela mostrou | ✔ |
|---|---|---|---|
| 1 | 280 · 04-03C | "Acordos encerrados": `Acordo #410` · `Cumprido` · `Substituído pelo acordo #141` **lado a lado** | ✅ |
| 2 | 5 · 01-04B | grupo: `Acordo #101` · `Ativo` · `Substituído por 2 acordos` — **sem número** | ✅ |
| 3 | 299 · 10-02D | `Acordo #191` · `Ativo` · `Substituído por 12 acordos` — **sem número** | ✅ |
| 4 | 8 · 01-09 | 🔴 controle: `Acordo #105` · `Ativo` · **nenhum selo** · *1 de 39 pagas · R$ 9.468,41* | ✅ |

Os 5 defeitos que a §16.9 mandava caçar: **nenhum apareceu.** A checagem de "acordo sumiu / apareceu em
dois lugares" foi **contada contra o banco**, não olhada: **4/5/4/37 na tela = 4/5/4/37 no banco**, com
interseção vazia entre as duas seções. Prints em `.playwright-mcp/smoke-f15/` (gitignored).

🔑 **O selo depende de sobrar parcela EM ABERTO, não de existir sucessor** — conferido em dois casos fora
do roteiro: o `#100` (1 sucessor, R$ 152,50 em aberto) e o `#204` (**22 sucessores**, R$ 487,75 em
aberto) **não** recebem selo, e é isso que se espera de renegociação parcial.

⚠️ **`.env.local`:** apontado para `saas_ux_f15` durante o smoke e **devolvido** ao `saas_ux` ao fim,
conferido byte a byte contra a cópia guardada antes. Nada foi gravado em banco nenhum (só leitura de tela).

⚠️ **Achado operacional, de OUTRO domínio:** o modal do ponto *"Você ainda não registrou sua entrada
hoje!"* é `data-bs-backdrop="static"` e **não tem botão de fechar** — ele intercepta o clique na aba
"Dívida" do objeto e deixa a aba inalcançável até registrar o ponto. Não é da cobrança; fica anotado.

---

## 17. ✅ 08/08 — ITEM 7 FECHADO: o cadastro da AMLI foi reemitido e baixado

**O arquivo existe:** `planilhas atualizadas/2026-08-04-completo/amli_br_060_Dados_cadastrais.xlsx`
(gitignored). Emissão 06/08/2026 15:22, **51 unidades**, condomínio conferido no histórico
(`condominioId 3 · AMLI BR 060`) e rodapé **`Filtros: Unidades: Todas`** — que é exatamente o que
`RecorteEsperado::cadastro()` exige (`self::exato('Unidades', 'Todas')`). **O lote da AMLI ficou
completo: 5 de 5 arquivos.**

O cadastro **não mudou** entre 29/07 e 06/08: mesmas 51 unidades, mesma estrutura, 65 linhas.

### 17.1 🔴 A ARMADILHA NOVA: payload errado devolve **HTTP 200** e o job trava PARA SEMPRE

Este é o achado que vale mais do que o arquivo. A emissão é *fire-and-forget*: o servidor responde
**200 com corpo vazio antes de validar o conteúdo**. Um payload com o nome de campo errado é aceito, o
job nasce no histórico e fica em **`EM_PROCESSAMENTO` indefinidamente** — não vira erro, não vira
`NENHUM_REGISTRO_ENCONTRADO`, não expira. Fica lá.

**O campo certo é `tipoLancamentoUnidade`, não `tipoUnidade`.** Payload real, lido do
`dadosCadastraisCondominosForm.jsx` dentro do `main.js` deles (o `beforeSubmit` anula `unidadeId` e
`grupoUnidadeId` quando o tipo é `TODAS_UNIDADES`):

```json
{"tipoLancamentoUnidade":"TODAS_UNIDADES","unidadeId":null,"grupoUnidadeId":null,
 "exibirIdCOM21":false,"tipo":"XLSX"}
```

**Provado nos dois sentidos, no mesmo dia e no mesmo condomínio de controle (TOP LIFE 1):**

| emissão | payload | resultado |
|---|---|---|
| 3027 | `tipoUnidade` (errado) | **`EM_PROCESSAMENTO` após 3 min** — e segue preso |
| 3028 | `tipoLancamentoUnidade` (certo) | **`FINALIZADO` em menos de 20 s** |
| 3029 (AMLI) | `tipoLancamentoUnidade` (certo) | **`FINALIZADO` em menos de 25 s** |

⚠️ **Honestidade sobre a causa de 04/08:** não dá para afirmar que foi *este* o motivo do travamento
daquele dia — o script da sessão anterior morreu junto com o scratchpad e o payload que ele mandou não é
recuperável. O que está medido é que **um payload errado reproduz o sintoma exato** e que, com o payload
da UI, a AMLI emite normalmente. A hipótese antiga de que *"o problema é a carteira da AMLI"* não se
sustenta: hoje ela finalizou como qualquer outra.

🔑 **Consequência para a automação (item 8 da §6):** o `status` do histórico **não** é um sinal de erro
utilizável. `EM_PROCESSAMENTO` significa "processando" **ou** "morreu na entrada e ninguém vai te avisar".
Qualquer agendamento precisa de **timeout próprio** (o registrado é 15–20 s; use ~2 min) e de tratar o
estouro como **falha**, não como "ainda rodando". Sem isso, um erro de campo vira um relatório que
simplesmente nunca chega — silenciosamente, que é o modo de falhar mais caro desta frente.

🧹 **Lixo deixado no sistema deles:** os jobs **3006** (04/08), **3025**, **3026** (AMLI) e **3027** (TL1)
ficam presos em `EM_PROCESSAMENTO` no histórico. Não existe endpoint para cancelar. São inofensivos —
mas quem olhar o histórico vai vê-los.

### 17.2 ✅ O BLOQUEIO DA CARTEIRA CAIU — a AMLI já existe no **dev** e importa limpo

Era real: `SELECT … FROM cobranca_carteira` em `saas_ux`, `saas_ux_zero` e `saas_ux_f15` devolvia **só
TOP LIFE I e II**. **O dono trouxe os dados que faltavam em 08/08** (CNPJ e razão social) e autorizou a
criação pelo console **no `saas_ux`**.

**Criado:** `cliente id 7` (PJ) + `carteira id 3` — CNPJ **63.490.838/0001-20** (dígitos verificadores
conferidos por cálculo, não pelo formato), razão social *ASSOCIAÇÃO DE MORADORES LOTEAMENTO IMPERIAL
BR 060*, fantasia *AMLI BR 060*. Contagens antes → depois: cliente 6→7, cliente_pj 2→3, carteira 2→3.
**Nada mais foi tocado**, e há dump das 3 tabelas de antes, no scratchpad.

🔑 **A carteira nasceu JÁ com os encargos (1% juros · 2% multa · 15% honorários), e isso não é
capricho:** o docblock de `CriarCarteiraUseCase` avisa que *"o caso snapshota a config da carteira no
instante em que nasce"* — carteira criada sem taxa produz casos pinados em **0%** que configurar depois
**NÃO conserta**. Os três percentuais vieram da **L3 da Inadimplência da AMLI** e já tinham sido
conferidos contra o print da tela de configuração em 04/08.

Script descartável (pasta gitignored): `planilhas atualizadas/_criar_amli.php` — usa as **entidades** do
Doctrine, não SQL cru (foi o que pegou que `modo`, `regime_juros` e as bases são **enums PHP**, não
string), tem prévia sem `--confirmar`, aborta se o banco não for `saas_ux` e aborta se o CNPJ já existir.

**Prova de que a carteira funciona** — dry-run, nada gravado:

| relatório | resultado |
|---|---|
| Dados cadastrais | **51 unidades · 52 pessoas · 0 rejeições** (7 pessoas já existiam e foram reaproveitadas) |
| Inadimplências | **25 boletos · 9 unidades devedoras · 0 rejeições** |

🔴 **CORREÇÃO (mesma sessão, §19): este "importa limpo" estava ERRADO, e a prova acima escondia o
defeito.** Aquele dry-run imprimia *"Pessoas criadas: 9"* — e as 9 eram **as mesmas pessoas que o
cadastro tinha acabado de criar**, duplicadas. Ler "0 rejeições" como "está limpo" foi o erro: a linha
que denunciava era outra. Corrigido na §19.

⚠️ **Em PRODUÇÃO a carteira continua não existindo.** O que foi feito vale só para o `saas_ux`. O
cadastro em prod é do dono, pela tela — e os dados são os mesmos desta seção.

### 17.3 A pendência da §7.1 continua aberta

A emissão de hoje repetiu as chamadas internas do site com a conta da secretária, como todas as
anteriores. **Ninguém perguntou à contábil se a automação é permitida** — a §7.1 recomendou perguntar e
isso **não foi feito**. O item 7 não muda esse quadro: só o executa mais uma vez.

---

## 18. 📏 08/08 — O ITEM 3 FOI **REMEDIDO** no banco do zero (e continua ⛔ sem mexer no aviso)

**Nenhuma linha de código tocada, e o aviso continua exatamente como está** — a decisão do dono
(06/08, reiterada em 08/08) é que ele vai por último e não muda até ele mandar. O que se fez aqui é a
medição que a **§8.5 deixou pendente por escrito**: os números do item 3 tinham sido medidos contra
`saas_ux_pos_etapa3`, *"um estado que não vai existir de novo"*.

Remedido por dry-run de `app:cobranca:importar-acordos` (TL1, `EM_ANDAMENTO` + `LIQUIDADO`) contra o
**`saas_ux_f15`** — que é a importação do zero, o estado que vai existir de verdade:

| | §8 (`saas_ux_pos_etapa3`) | **agora (`saas_ux_f15`)** |
|---|---:|---:|
| divergências | 75 | **206** |
| diferença somada | R$ 6.923,49 | **R$ 18.297,34** |

⚠️ **O aumento era previsto e não é defeito** — a §8.5 avisou que a contagem mudaria numa importação do
zero, porque o import antigo era estreito. **O que importa é a composição, e ela se sustenta:**

🔑 **206 de 206 apontam no MESMO sentido — a planilha é sempre maior que o sistema, e ZERO casos no
sentido contrário.** É exatamente a assinatura de *"a planilha soma encargo ao principal e o sistema
guarda os dois em campos separados"* (§8.3). Se a divergência fosse principal errado, haveria casos nos
dois sentidos. **A decisão de deixar o aviso quieto continua correta**, e a de sobrescrever continua
sendo a que cobraria juros sobre juros.

**33 das 206 têm `sistema R$ 0,00`** (planilha soma R$ 7.667,41): são as parcelas só de honorário e
juros que a §8.7 antecipou. Todas as 33 estão **liquidadas — nenhuma em aberto**, então nenhuma cobra
ninguém hoje.

### 18.1 Achado medido e dimensionado: **1 obrigação com valor original NEGATIVO**

`cobranca_obrigacao 8183` · NN **64408** · unidade 10-04A · `Acordo 145 - Parc. 2/11` ·
**`valor_original = −R$ 45,29`**, com juros R$ 169,71 e multa R$ 38,40 lançados.

**Dimensionado antes de virar problema:** é **1 em 16.069 obrigações** (0,006%), existe igual no
`saas_ux_zero` e no `saas_ux_f15`, e está **liquidada** — não cobra ninguém, não aparece em saldo aberto.
**Não vira item de fila.** Fica registrado porque um principal negativo é uma forma que a modelagem não
prevê, e se um dia aparecer em obrigação *em aberto* o encargo calculado ao vivo sai errado.

### 18.2 🔑 Técnica que elimina o risco do `.env.local` compartilhado

Medido hoje: **`docker exec -e DATABASE_URL="…/saas_ux_f15" jusprime_php_dev …` aponta o CLI para o
banco descartável sem tocar no arquivo.** Variável de ambiente real tem precedência sobre o `.env.local`
(o Dotenv não sobrescreve o que já existe no processo) — conferido nos dois sentidos: o comando com `-e`
respondeu `saas_ux_f15` e, logo depois, o comando sem `-e` respondeu `saas_ux`, com o arquivo intacto.

**Use isto no lugar do `sed` no `.env.local`.** A troca do arquivo continua necessária **só para o
smoke no navegador** (o php-fpm lê o arquivo), e é lá que mora o risco de a outra sessão pegar o banco
errado.

---

## 19. 🔴 08/08 — IMPORTANDO A AMLI, UM DEFEITO DE IDENTIDADE DO DEVEDOR APARECEU

Spec: `docs/specs/cobranca-importe-nao-duplica-devedor-do-cadastro.md`. **Risco ALTO.**

**O dono mandou importar os dados da AMLI. A importação parou no meio, de propósito** — só o cadastro
entrou. O resto está travado até esta correção ser aprovada.

### 19.1 O defeito

A unidade que veio do **cadastro de condôminos** tem a pessoa vinculada (nome, CPF, e-mail, telefone) e
**nenhum caso de cobrança**. Os importes de **inadimplência** e de **receitas**, ao abrir o caso, não
olhavam quem já estava na unidade: criavam **outra pessoa com o mesmo nome, sem documento**, e abriam o
caso **cobrando essa cópia**.

| porta | pessoas duplicadas que criaria na AMLI |
|---|---:|
| Inadimplência | **9** |
| Receitas | **45** |

**45 das 51 unidades** ficariam com o devedor em duplicata, e as 44 pessoas com CPF ficariam de fora da
cobrança.

### 19.2 Duas coisas medidas que mudaram a decisão

1. **Inverter a ordem não resolve.** Testado nos dois sentidos em banco descartável
   (`saas_ux_amli_ordem`): cadastro→inadimplência e inadimplência→cadastro dão **o mesmo estrago** (54
   pessoas, 9 unidades duplicadas). As fontes se reconhecem por chaves diferentes — o cadastro casa por
   **CPF**, o importe casa por **nome**.
2. 🔑 **Por que isto nunca apareceu:** `cobranca_pessoa` com CPF, medido — **TL1: 0 · TL2: 0 · AMLI:
   44**. **A AMLI é a primeira carteira que recebe o cadastro.** O teste do zero (§13) rodou
   *Inadimplência → Receitas → Acordos*, sem cadastro: o caminho com defeito nunca foi exercitado.

### 19.3 O que a correção faz

Um serviço único (`ResolvedorPessoaNoObjeto`) usado pelas **duas** portas: antes de criar a pessoa,
procura entre as **já vinculadas àquela unidade** uma com o mesmo nome normalizado. Achou, reusa; não
achou, cria como antes. Escopo é o objeto — nunca global, nunca entre carteiras, nunca entre tenants.

**Serviço único de propósito:** dois trechos parecidos foi como nasceu a *"porta B mais frouxa que a
porta A"* que a 1ª revisão da §16 teve de corrigir.

### 19.4 Estado

- **suíte completa: 3.426 verde** · **6 injeções de defeito, 5 vermelhos e 1 que PASSOU** (a 6ª, e o
  que ela ensinou está na §8.2 da spec);
- **duas revisões feitas**. A 1ª achou 3 MÉDIOS — um deles: **este handoff afirmava "importa limpo"**
  sobre o import que duplicava 45 devedores. A 2ª **achou defeito nas correções da 1ª pela DÉCIMA
  SEGUNDA vez seguida**: eu havia fechado os dois furos de teste **só na porta A**, e o teste novo da
  porta B usava UMA linha — quando a AMLI tem ~7 recebimentos por unidade. Era "a porta B mais frouxa
  que a porta A" de novo, na mesma frente que já tinha corrigido isso na §16.

### 19.5 ⛔ O que NÃO foi importado, e por quê

Só o **cadastro** da AMLI está no `saas_ux` (51 unidades, 44 CPFs, sem duplicação). **Inadimplência,
Receitas e Acordos ficaram de fora** — importar antes da correção gravaria as 45 duplicatas em dado
real. A importação completa da AMLI é o passo seguinte à aprovação.

🔑 **O que este episódio ensina, e vale para o resto da frente:** *"0 rejeições"* não quer dizer *"está
limpo"*. A linha que denunciava o defeito era **"Pessoas criadas: 9"** num arquivo cujas 9 unidades já
tinham dono — e ela estava impressa na tela, lida e registrada como prova de sucesso.
