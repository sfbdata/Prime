# Handoff — Política de Privacidade (frente `politica-privacidade`)

**Estado: implementada, revisada, NÃO aprovada.** 3 commits locais, nada publicado.
Pausado em 19/08/2026 por limite de contexto, logo após a revisão.

## Onde está

Worktree: `.claude/worktrees/politica-privacidade` · branch `politica-privacidade` · base `master`
local @ `0b634bcf`. **`git diff` sem argumento vem vazio — está tudo commitado.** Use:

```bash
cd .claude/worktrees/politica-privacidade
git diff 0b634bcf..HEAD
```

Commits: `69e5da9c` (documento + rota) · `4b553a61` (as 5 ligações) · `ee1dc209` (limitação do PDF).

Testes **só** por `scripts/frente-testar.sh politica-privacidade --filter <Nome>` (um nome por vez,
o script não aceita `|`). O comando padrão do CLAUDE.md testaria o repositório principal.

⚠️ O master principal **andou 2 commits** (`f34a55a3`, `99c072c8`) durante a sessão — outra sessão
está commitando lá. Confirmar a base antes de integrar.

## O que foi decidido (contrato desta frente)

- **SÓ LEITURA.** A Política não entra no aceite. `TermoVigente::VERSAO` não muda, ninguém é parado
  em tela de reaceite. Confirmado pelo revisor: nada na frente cria aceite.
- O `.docx` era **minuta**, com 14 campos `[inserir]`. As 4 respostas do dono:
  1. hospedagem **Hostinger, São Paulo/Brasil** (medido: RDAP do RIPE, bloco `HOSTINGER-HOSTING`);
  2. Drive do sync em **conta Gmail pessoal**;
  3. e-mail transacional **Gmail hoje**, migração para provedor profissional já decidida;
  4. pagamento / assinatura eletrônica / IA: **as 3 linhas removidas**.
- Removida também a "Nota de revisão" final (recado do advogado ao cliente).

Plano aprovado: `/home/prime/.claude/plans/clever-seeking-fern.md`.
🔴 **Não existe spec em `docs/specs/`** — risco MÉDIO exige uma. É correção pendente.

## O que foi entregue

| Peça | Caminho |
|---|---|
| Fonte | `docs/legal/politica-de-privacidade.docx` |
| Texto (fonte única de página e PDF) | `app/templates/legal/_politica_privacidade_texto.html.twig` |
| Página pública | `/politica-de-privacidade` |
| PDF | `/politica-de-privacidade.pdf` |
| Versão vigente | `app/src/Legal/PoliticaPrivacidadeVigente.php` |

Ligada em 5 lugares: rodapé do login, cadastro público, aceite de convite, tela de aceite dos
Termos, menu do usuário. Suíte 3891/3891 (14.608 asserções), 4 provas por reintrodução feitas.

---

# RELATÓRIO DA REVISÃO (`feature-review-agent`, 19/08) — **NÃO APROVADO**

## ACHADO 1 — ALTA · o link é beco sem saída para quem não tem escritório selecionado, e os dois testes novos arrumam justamente o estado que esconde isso

A frente liberou as rotas no **portão dos Termos** e esqueceu o **portão de tenant**, que roda logo depois.

Cadeia provada:

1. `app/src/EventListener/TenantContextValidatorListener.php:20-32` — `ROTAS_IGNORADAS` **não** tem `legal_politica_privacidade` nem `legal_politica_privacidade_pdf`.
2. Ele roda depois do gate de Termos (prioridade 6 vs 7) — e isso não é leitura minha, é o que `app/tests/Termo/Functional/TermoAceiteListenerOrdemTest.php:56-60` assevera contra o dispatcher real.
3. `TenantContextValidatorListener.php:55-63`: usuário autenticado + `!hasCurrentTenant()` + sem `ROLE_SUPER_ADMIN` → `RedirectResponse(tenant_selecionar)`.
4. `app/templates/tenant/selecionar.html.twig:1` faz `{% extends 'base.html.twig' %}`, e o dropdown com o link novo está dentro do ramo `{% if app.user %}` (`app/templates/base.html.twig:100` … `379`).

Resultado: na tela **`/escritorio/selecionar`** — onde o usuário está logado e por definição **não tem** tenant — o item "Política de Privacidade" aparece e, clicado (`target="_blank"`), abre uma aba que volta para `/escritorio/selecionar`. Mesmo efeito em `app/templates/termo/aceite.html.twig:110` quando o aceite é alcançado sem tenant — estado que o próprio sistema suporta de propósito (o comentário em `TenantContextValidatorListener.php:28-30` diz que `termo_aceite` precisa ser alcançável antes da seleção de escritório).

Isso é exatamente o defeito que o commit `4b553a61` diz ter corrigido: *"o link voltaria para a própria tela — um beco sem saída que nenhum teste de 'o link existe' pegaria"*. Corrigiu num portão, ficou no outro.

**Por que os testes não pegam** — e este é o ponto que mais me incomoda:

- `app/tests/Legal/Functional/PoliticaPrivacidadeLigacoesTest.php:107` chama `logarComTenant()`, que grava `current_tenant_id` na sessão (`app/tests/Functional/JusPrimeWebTestCase.php:23`) — o único estado em que o redirect não acontece;
- `PoliticaPrivacidadeLigacoesTest.php:76-79` usa `ROLE_SUPER_ADMIN` com o comentário *"só para atravessar o TenantContextValidatorListener"*. O autor sabia que o listener redireciona, contornou no teste, e não olhou a lista branca dele.

Tamanho medido (dataset real do dev, `saas`): de 13 usuários com vínculo, **4 caem em `/escritorio/selecionar` a cada login** — 3 com 0 escritórios ativos e 1 com 2. Não é caso de borda.

Falta o caso de teste que importa: usuário **sem** `ROLE_SUPER_ADMIN` e **sem** tenant na sessão seguindo o link.

## ACHADO 2 — MÉDIA · a decisão nº 4 do dono entrou só no Anexo I; o corpo do texto continua dizendo o contrário

`app/templates/legal/_politica_privacidade_texto.html.twig:239` (capítulo 13, renderizado, público):

> "Suboperadores contratados para hospedagem, **processamento de pagamento**, envio de comunicações, **assinatura eletrônica**, monitoramento de segurança e suporte […] conforme relação do Anexo I."

O dono mandou remover pagamento e assinatura eletrônica porque **não existem**. Saíram do Anexo I (3 linhas, corretas) e ficaram no capítulo que aponta para o Anexo I como a lista autoritativa — o documento se contradiz apontando para si mesmo.

Na mesma linha, `_politica_privacidade_texto.html.twig:246` afirma que a BLUEJUS "mantém **contrato escrito com cada um deles**, contendo cláusulas de proteção de dados". A resposta nº 2 do dono foi **conta Gmail pessoal** para o Drive — não há DPA nem contrato escrito com o Google numa conta individual.

O teste `PoliticaPrivacidadeControllerTest.php:97-99` só procura essas palavras **dentro de `#anexo-i-suboperadores`**, então passa verde com elas vivas 100 linhas acima. Isso é decisão jurídica, não minha — mas o parecer não pode registrar "o Anexo I diz exatamente o que foi decidido" sem dizer que o corpo não diz.

## ACHADO 3 — MÉDIA · a data de publicação é a de hoje e nada guarda contra ela ficar errada

`app/src/Legal/PoliticaPrivacidadeVigente.php:39` — `DATA_PUBLICACAO = '2026-08-19'`, exatamente hoje. O próprio plano (pendência 1) manda trocá-la pelo dia do deploy.

O teste que deveria proteger, `PoliticaPrivacidadeControllerTest.php:80-87`, deriva o valor esperado **da mesma constante** — é tautológico: passa com qualquer data. A data sai em dois lugares de um documento jurídico (cabeçalho `_politica_privacidade_texto.html.twig:24` e Anexo III, linha 380). Se a frente for integrada e publicada daqui a duas semanas, a Política nasce declarando uma data de vigência falsa, sem sintoma nenhum.

Ponto a favor, e conferi: o `null|date` que o comentário do controller teme **não existe** aqui — não há um único filtro `|date` nos templates de `legal/`, a formatação é feita em PHP (`PoliticaPrivacidadeController.php:71-73`). A defesa está certa; o guarda automático é que não existe.

## ACHADO 4 — MÉDIA · endereço residencial e Gmail pessoal em página pública, sem `robots.txt` e sem `noindex`

`_politica_privacidade_texto.html.twig:75-76` e `:327-331` publicam nome pessoal, `farlei.rocha@gmail.com`, telefone/WhatsApp e **"QNF 03 – 31 – CEP: 72.125-530 – Taguatinga Norte / DF"**. Não existe `app/public/robots.txt` e nenhum template de `legal/` emite `noindex` — conferido.

Publicar o Encarregado e um canal de contato é obrigação da LGPD; o que é escolha é **qual** contato. O plano registrou isso como pendência 4 "não bloqueia" — discordo da classificação: trocar por `privacidade@bluejus.com.br` e um endereço comercial é uma linha antes de publicar, e é irreversível depois de indexado. Decisão do dono, não minha — mas ela precisa ser tomada **antes** do deploy, não depois.

## ACHADO 5 — BAIXA · rota pública que gasta CPU sem limitador, contra o padrão da casa

`app/src/Legal/Controller/PoliticaPrivacidadeController.php:41-62` roda dompdf sobre um documento de 387 linhas com 11 tabelas a cada requisição, anônima e sem cache. Medido: **~0,59 s contra ~0,25 s** da rota HTML no mesmo harness, e ~30 MB a mais de pico.

`app/config/packages/framework.yaml:13-30` mostra que **toda** rota pública deste projeto tem limitador (`login`, `convite_aceite`, `convite_criar`, `cadastro_auto`, `oab_verificar`). Esta é a primeira sem. Um `curl` em laço prende worker de PHP. Custo do conserto: um limitador ou um PDF estático — nenhum dos dois é escopo obrigatório desta frente, mas a omissão contra o próprio padrão precisa ser decidida, não herdada por descuido.

## ACHADO 6 — BAIXA · o teste do PDF não prova conteúdo nenhum

`PoliticaPrivacidadeControllerTest.php:105-113` verifica cabeçalho e os 4 bytes `%PDF`. Apague o `{% include 'legal/_politica_privacidade_texto.html.twig' %}` de `app/templates/legal/politica_privacidade_pdf.html.twig:85` e a suíte inteira segue verde entregando um PDF em branco.

A limitação de `/ToUnicode` documentada em `PoliticaPrivacidadeController.php:22-30` (que conferi ser plausível e não vou contestar) realmente impede asserção de texto — mas não impede piso de bytes nem contagem de páginas. Hoje não há nenhum dos dois.

## ACHADO 7 — BAIXA · segundo menu de usuário ficou sem o link

`app/templates/layout_peticionar.html.twig:849` tem outro `.usuario-dropdown` e não recebeu o item. Usado só por `app/templates/pasta/peticionar.html.twig` — o menu muda de conteúdo conforme a tela. Consequência prática: pequena.

## ACHADO 8 — NIT · instanciação com `new` onde o precedente injeta

`PoliticaPrivacidadeController.php:71` faz `new PoliticaPrivacidadeVigente()`. A classe que ela espelha, `TermoVigente`, é injetada pelo container (`TermoAceiteListener.php:55`). E a action do PDF injeta `Environment $twig` sendo que `AbstractController::renderView()` já existe. Zero consequência funcional.

## O que o revisor conferiu e está LIMPO — para ninguém refazer

**Nada nesta frente cria aceite.** `TermoVigente` não foi tocado, não há migration, não há campo obrigatório novo nem registro persistido. Os textos de ciência em `auth/cadastro/form.html.twig:86-89` e `auth/convite/ver.html.twig:66-69` estão **fora** dos blocos de aceite — e conferi que os blocos são de fato `.form-check` (`form.html.twig:77` e `ver.html.twig:57`), então a asserção negativa `assertCount(0, '.form-check a[href=...]')` **não** é vácua: ela quebra de verdade se o link migrar para dentro. A justificativa citada nos comentários também confere — o item 1.3 (`_politica_privacidade_texto.html.twig:84`) diz literalmente que "o cadastro, o acesso ou a permanência na plataforma caracterizam ciência inequívoca desta política".

**Multi-tenancy: superfície zero, lido no código.** O controller usa só duas constantes e renderiza Twig; nenhum repositório, nenhum `EntityManager`, nenhum ID vindo da URL. Para visitante anônimo o `base.html.twig` também não consulta banco: `app/src/Twig/NotificacaoExtension.php:30` e `app/src/Twig/ProfileExtension.php:23` retornam cedo sem usuário. Padrão B-route não se aplica.

**`security.yaml:44` está correto.** Vem antes do coringa `^/` (linha 49), não colide com nada, e `debug:router` na worktree devolve exatamente 2 rotas sob o prefixo. O prefixo sem âncora casaria `/politica-de-privacidade-qualquer-coisa`, mas não existe rota lá — consequência hoje é zero, registro só para não parecer que não olhei.

**Alargar a lista branca do `TermoAceiteListener` não abre o portão.** As duas rotas são `GET`, renderizam e devolvem; não gravam sessão nem marcam `SESSION_KEY`. O gate rearma na requisição seguinte.

**Transcrição do `.docx`: fiel, verificada por script e não pelo relato.** Comparei os 153 parágrafos longos do `word/document.xml` contra o template: **2 ausentes**, ambos intencionais — o `[inserir data]` do cabeçalho (virou constante) e a "Nota de revisão". Na direção inversa, **nenhum** parágrafo novo foi inventado. Entre as linhas curtas, o que sumiu é exatamente o conjunto de células-modelo do Anexo I (as 6 linhas com `[inserir]`, incluindo "Processamento de pagamentos", "Assinatura eletrônica de documentos" e "Processamento de funcionalidades de inteligência artificial") e as 6 linhas em branco do Anexo III. Nenhum `[inserir]` sobrevive no renderizado — a única ocorrência de "inserir" é prosa ("Ao inserir dados pessoais…", linha 179). O Anexo I tem as 3 linhas decididas, com Hostinger/Brasil (São Paulo), e o `MAILER_DSN` em `app/.env:15` confirma o SMTP do Gmail.

**Camada e padrões.** `App\Legal` só com `Controller/` + a classe de versão na raiz espelha `App\Termo` (que também não está na tabela de domínios do `app/src/CLAUDE.md`) — aceitável por precedente. `final` e `declare(strict_types=1)` nos dois arquivos novos; 2 actions, ~15 linhas cada — dentro da heurística 5-10-20. O nome `legal_politica_privacidade` foge do `app_<dominio>_<acao>` da skill, mas segue `termo_aceite`/`auth_*`; o repositório é misto. Não conto como achado. Idem para o `new User()` à mão nos testes: 207 arquivos fazem assim contra 14 com `UserFactory` — é o padrão de fato.

**Commits.** Três, atômicos, sem escopo misturado; `docs/frentes-ativas.md` entrou no mesmo commit da ligação que descreve. `lint:twig` OK, suíte 3891/3891 na worktree. Base `0b634bcf` contida em `master`, que já andou 2 commits (`f34a55a3`, `99c072c8`) durante esta sessão — outra sessão está commitando no mesmo master.

---

# CORREÇÕES PROPOSTAS (nenhuma aplicada — aguardam decisão do dono)

Ordem sugerida. As 3 primeiras são as que travam a entrega.

| # | Achado | O que fazer | Decisão? |
|---|---|---|---|
| 1 | 1 (ALTA) | Acrescentar `legal_politica_privacidade` e `_pdf` a `TenantContextValidatorListener::ROTAS_IGNORADAS`. **E** o teste que falta: usuário sem `ROLE_SUPER_ADMIN` e sem tenant na sessão seguindo o link — provado por reintrodução. | não, é conserto |
| 2 | 3 (MÉDIA) | Guarda contra data errada: teste que compara `DATA_PUBLICACAO` com a data do último commit da frente (ou asserção literal da data esperada), em vez de derivar da própria constante. | não, é conserto |
| 3 | 6 (BAIXA) | Teste do PDF: piso de bytes + contagem de páginas (`/Type /Page`), que pegam o PDF em branco sem depender de extrair texto. | não, é conserto |
| 4 | 2 (MÉDIA) | Capítulo 13 lista pagamento e assinatura eletrônica que não existem; e o §246 promete "contrato escrito com cada um" incompatível com Gmail pessoal. **Alterar texto jurídico exige o dono/advogado.** | 🔴 **SIM** |
| 5 | 4 (MÉDIA) | Trocar `farlei.rocha@gmail.com` e o endereço residencial por canal/endereço comerciais; decidir `robots.txt`/`noindex`. **Irreversível depois de indexado.** | 🔴 **SIM** |
| 6 | 5 (BAIXA) | Limitador na rota do PDF (padrão de `framework.yaml`) ou PDF estático. | 🟡 sim, qual das duas |
| 7 | 7 (BAIXA) | Link no `.usuario-dropdown` de `layout_peticionar.html.twig:849`. | não |
| 8 | 8 (NIT) | Injetar `PoliticaPrivacidadeVigente`; usar `renderView()` em vez de injetar `Environment`. | não |
| 9 | — | **Escrever a spec em `docs/specs/politica-privacidade.md`** — risco MÉDIO exige, e não existe. | não |

**Pergunta aberta do revisor, para o dono:** o Achado 2 (capítulo 13) é bloqueante junto do
Achado 1, ou volta para o advogado como decisão separada enquanto o conserto técnico segue?

**Risco MÉDIO → depois das correções, o ciclo exige `/review` de novo antes de integrar.**

## Pendências que já eram conhecidas

- ⚠️ Trocar `DATA_PUBLICACAO` para o dia do deploy.
- 🔴 **Fora do escopo, mas grave: `app/.env` está versionado no git com a senha de aplicativo do
  Gmail em texto puro.** Revogar no Google, não só remover do arquivo. Frente própria.
- O PDF do dompdf **não é pesquisável** (`Identity-H` + `ToUnicode` identidade). Medido, não é
  config; desligar `isFontSubsettingEnabled` vai de 102 KB para 1,3 MB e não corrige.
- Quando o e-mail migrar para provedor profissional: atualizar o Anexo I e subir para 1.1.
