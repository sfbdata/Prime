# HANDOFF — Automatizar o download dos relatórios da contábil

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

### 🔴 Dinheiro que ainda fica de fora — **R$ 7.109,07** (eram R$ 24.553,73)

| # | O quê | Valor | Onde está o diagnóstico |
|---|---|---:|---|
| 1 | ✅ **FECHADO — dívidas sem número de boleto (NN)** | ~~R$ 17.444,66~~ | `docs/specs/cobranca-divida-sem-numero-de-boleto.md`. Chave = `SNN:<vencimento>`, agrupando por **caso + competência + vencimento** (a classe NÃO entra — ver §7.3). **Provado no dado real: 99 obrigações, R$ 12.510,00 de principal, idempotente.** ⚠️ Só R$ 8.912,21 viram dívida na tela; os R$ 6.750,00 dos acordos nascem substituídos |
| 2 | **Boletos só de encargos/honorário** | **R$ 4.396,07** | §6.1 — **13 boletos em 8 unidades, remedido hoje**; decisão do dono já cobre (§6.2) |
| 3 | **Valores divergentes** (planilha ≠ sistema) | **R$ 2.713,00** | §6.1 — 33 casos, planilha maior em 33 de 33; hoje só reportado. ⚠️ **Medir primeiro se a diferença é principal ou encargo congelado** — sobrescrever principal com número que embute encargo contaria duas vezes |
| 4 | ✅ **FECHADO — encargos de TL1/TL2 NÃO estão sobrescritos** | — | **§7.2**. Não precisou reemitir: o arquivo imprime os encargos na L4, e 4 emissões manuais anteriores à automação (08/07, 22/07, 29/07, 03/08) trazem os mesmos percentuais da emissão pela API |

Os itens 1–3 são **risco ALTO**: spec + teste provado por reintrodução + **duas** revisões cada.

### 🟡 Completude e proteção (não é dinheiro perdido)

| # | O quê | Por quê |
|---|---|---|
| 5 | Mover **criação de acordo** para o importador de Acordos | fecha os últimos **29** de 359 acordos (§6.0.2 — deixou de ser bloqueante) |
| 6 | **Validador da linha `Filtros:`** | recusa arquivo com recorte errado; **teria pego o filtro de 2026 sozinho** |
| 7 | Reemitir **cadastro da AMLI** | travou em `EM_PROCESSAMENTO` no sistema deles em 04/08. ⏸️ **O dono mandou deixar por ÚLTIMO** (05/08). O script de emissão está pronto em `scratchpad/emitir_amli_cadastro.sh` — foi bloqueado pelo classificador de segurança do Bash, não por defeito |
| 8 | **Teste do zero** | limpar banco → importar tudo → bater com a contabilidade. **É a prova final.** O dono autorizou `--confirmar` **em banco descartável** para isto |

### ⏸️ Do dono

| # | O quê |
|---|---|
| 9 | Smokes na tela: "Já pago", caso 193, excluir recebimento, acordos sobrescritos |
| 10 | Publicar os **51 commits** |
| 11 | Deploy em produção (lembrar: prod é imagem baked, exige `./scripts/deploy-prod-tls.sh`) |
| 12 | **Confirmar com a contábil** se a automação de download é permitida — §7.1 |

### Ordem recomendada (atualizada em 05/08, com a AMLI por último a pedido do dono)

~~**4** → **1**~~ (feitos) → **3** → **2** (mesma diretriz do dono; o 3 vem antes porque a medição dele
— principal × encargo congelado — muda o desenho dos dois) → **6** (trava o recorte) → **5** → **8**
(a prova final) → **7** (AMLI, por último).

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
