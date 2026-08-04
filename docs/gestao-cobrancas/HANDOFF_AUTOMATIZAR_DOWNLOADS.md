# HANDOFF — Automatizar o download dos relatórios da contábil

**Estado em 2026-08-04:** investigação da API **COMPLETA e provada**. 12 planilhas baixadas e conferidas.
**Nenhuma linha de código implementada.** A frente agora inclui um segundo item: fazer o importe
**sobrescrever a situação do acordo**.

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
2. **Alinhar o `ImportarAcordosDetalhadosUseCase`**: hoje ele só **reporta** divergência de situação
   (`:598-631`) em vez de aplicar. Com 259 liquidados contra 66 em andamento, isso deixa de ser detalhe.
   É código em produção e mexe em saldo → **risco ALTO: spec em `docs/specs/` antes de implementar,
   `/review` em duas passadas com correção entre elas, e todo teste provado reintroduzindo o defeito.**
3. **Rodar Receitas → Acordos no dev** e medir.

**Depois (a automação em si):**
4. **Validador da linha `Filtros:`** — recusar arquivo cujo recorte não seja o esperado. É o que impede o
   erro original de voltar, e **vale mesmo com download manual**. Comparação exata por campo (ver §3.6).
5. **Conferência da carteira** contra o `condominioNome` do histórico (ver §3.1).
6. Comando Symfony + armazenamento + agendamento, com a busca do arquivo atrás de uma interface
   (`PastaLocal` hoje, `GroupCondominiosApi` depois). Precedentes no repo: **DJEN**, **índices do BCB**,
   **sync do Drive**.

## 7. Segurança

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
