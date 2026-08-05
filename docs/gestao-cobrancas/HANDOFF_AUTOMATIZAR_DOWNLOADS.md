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
| ⏳ `amli_br_060_Dados_cadastrais.xlsx` | emitido, **preso em `EM_PROCESSAMENTO`** há >25 min (o registrado eram 15–20 s). Reemitir |

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
| 7 | Reemitir **cadastro da AMLI** | travou em `EM_PROCESSAMENTO` no sistema deles em 04/08. ⏸️ **O dono mandou deixar por ÚLTIMO** (05/08). O script de emissão está pronto em `scratchpad/emitir_amli_cadastro.sh` — foi bloqueado pelo classificador de segurança do Bash, não por defeito |
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
