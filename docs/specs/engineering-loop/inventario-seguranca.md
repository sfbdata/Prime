# Inventário — Segurança / Multi-tenant / Autorização / Auditoria (funções novas das Trilhas A e B, `a458e58e..719de0e4`)

Auditoria SOMENTE LEITURA, em `607f2c29` (código idêntico a `719de0e4`). Nenhum teste rodado, nada
tocado em produção. Prefixo: SEC-. Toda conclusão abaixo é por leitura de código; onde é hipótese, está dito.

## Fontes lidas (arquivo:faixa de linhas)

- Regras: `CLAUDE.md` (raiz), `app/src/CLAUDE.md`, `app/src/Controller/CLAUDE.md`, `app/src/Repository/CLAUDE.md`,
  `docs/AUTORIZACAO.md:241-400` (recursos-item, modelo paralelo, lápide), mestre
  `docs/specs/estado-geral-claude-designer-bluejus.md:353-405` (§9) e `:511-576` (§11, §12).
- Diff: `git diff --stat a458e58e..719de0e4 -- app/src app/config app/templates` (281 arquivos em `app/src`).
- Config: `app/config/packages/framework.yaml:13-70` (rate limiters), diff de `doctrine.yaml`, `messenger.yaml`,
  `monolog.yaml`, `services.yaml`; `nginx/conf.d/nginx.prod.conf:31-35` (headers).
- Controllers (inteiros ou nas actions novas/alteradas):
  `Pasta/Controller/PastaDocumentoController.php:1-641` · `PastaSecaoController.php:40-320` ·
  `PastaAdministrativaController.php:25-106` · `PastaChecklistEstadoController.php:25-95` ·
  `PastaDuplicarController.php:25-89` · `PastaFavoritoController.php:20-66` · `PastaMencaoController.php:20-63` ·
  `PastaPagamentoController.php` (diff inteiro; `:49-140`) · `PastaPushProcessualController.php:20-110` ·
  `PastaTimelineController.php:15-69` · `PastaResumoController.php:20-117` · `PeticionarController.php` (diff) ·
  `Controller/PastaController.php:1000-1150, 1650-1662, 1800-1835, 2085-2125` (+ diff) ·
  `Controller/TarefaController.php` (diff) · `Processo/Controller/NotaTecnicaController.php:30-252` ·
  `Tarefa/Controller/MetaNaListaController.php:15-160` · `Tarefa/Controller/TarefaEquipeController.php:15-65` ·
  `Cliente/Controller/ClienteResumoController.php:20-160` · `Dashboard/Controller/PreferenciaDoDashboardController.php:15-95` ·
  `Dashboard/Controller/DashboardController.php` (diff) · `Inteligencia/Controller/AnaliseDaPastaController.php:30-160` ·
  `AnalisePushController.php:49-235` · `ConfiguracaoController.php:38-65` · `Expediente/Controller/ExpedienteController.php` (diff).
- UseCases/serviços: `ListarMencionaveisDaPastaUseCase.php:20-129`, `EnviarMensagemPastaUseCase.php:1-200`,
  `MencoesDoRegistro.php:1-163`, `MoverDocumentoParaSecaoUseCase.php`, `MontarZipDeDocumentosUseCase.php:41-320`,
  `CopiarDocumentosDaPastaUseCase.php:105-160`, `PurgarLixeiraUseCase.php:120-240`, `PurgarLixeiraCommand.php:60-80`,
  `CorrigirValorDoPagamentoUseCase.php:50-110`, `RegistrarParcelamentoDaPastaUseCase.php` (grep), `AlertarResponsavelDaMetaUseCase.php`,
  `RenomearMetaUseCase`/`ReabrirMetaUseCase` (grep), `ListarMetasDaEquipeUseCase` (grep), `ListarDuplicadosDoDocumentoUseCase.php:20-81`,
  `UploadPecaUseCase.php:45-150`, `DesfazerAlteracaoAuditLogUseCase.php:34-60, 105-140, 360-470`,
  `ExcluirAnaliseUseCase.php`, `AlternarAnaliseInternaUseCase.php`, `ProcessarAnaliseDeInteligenciaHandler.php:80-135`,
  `MontadorDaTimelineInteligente.php` (grep), `VisibilidadeDoFinanceiroDaPasta.php`, `UltimosAlertasDasMetas.php`,
  `NotaTecnicaExtension.php`, `PastaFavoritaExtension.php` (grep).
- Infra de dados: `Shared/Doctrine/Filter/TenantFilter.php`, `Shared/EventListener/TenantFilterListener.php`,
  `Pasta/Armazenamento/ChavesDePasta.php:55-135`, `Shared/Armazenamento/ChaveDeArquivo.php:70-100`,
  `Shared/Armazenamento/Exception/ChaveDeArquivoInvalida.php`, `Shared/Http/EntregaDeArquivo.php:58-95`,
  SQL cru de `PastaFavoritaRepository:70-90`, `PastaDocumentoFavoritoRepository:108-130`, `PreferenciaDoUsuarioRepository:68-90`,
  `PastaPagamentoRepository:130-155`, `MetricasExtrasDoDashboardRepository` (grep de tenant).
- Front: grep de `innerHTML`/`insertAdjacentHTML`/`outerHTML` nos 21 JS novos/alterados (26 ocorrências lidas);
  `visualizador-documento.js:20-40, 895-990, 1029-1100`; `pasta-explorador.js:970-985`; grep de `|raw` adicionados
  nos templates (4); `ExploradorDeDocumentosOutput.php:71` (flags JSON).
- Testes: grep por nome de rota e por caminho de URL em `app/tests` com marcadores de "outro escritório".

## Matriz (contagem real)

A área é transversal: as dimensões visuais do formato comum não se aplicam. O denominador é o das 57 rotas
novas/alteradas auditadas (lista em "Itens F").

| Dimensão | Conferidos OK (F) | Total de requisitos |
|---|---|---|
| Visual | n/a | n/a |
| Interações | n/a | n/a |
| Funções (persistência/backend) — ver submatriz | 371 | 392 |
| Responsivo | n/a | n/a |
| Dark mode | n/a | n/a |

Submatriz de segurança (cada célula = rotas conferidas OK / rotas a que a checagem se aplica):

| Checagem | OK | Aplica-se | Fora do OK |
|---|---|---|---|
| (1) Tenant explícito da entidade principal (não só TenantFilter) | 47 | 57 | SEC-001 (7 rotas), SEC-002 (3 rotas) |
| (2) Guarda IDOR (filha de pasta irmã → 404 sem efeito) | 57 | 57 | — |
| (3) Permissão (`canAccessResource`/módulo/admin) adequada | 55 | 57 | SEC-009 (2 rotas, decisão) |
| (3b) Listagem sem vazar item sem acesso | 0 | 1 | SEC-010 (decisão) |
| (4) CSRF em POST | 43 | 43 | — (GET com efeito: SEC-011) |
| (5) Lápide somente leitura (POST sobre pasta) | 40 | 40 | — |
| (6) Auditoria das escritas (fora as exceções conscientes) | 38 | 40 | `cliente_contatos` (D-CLI-AUDIT, Observação) e `tarefa_alertar` (Observação) |
| (7) XSS (|raw e sinks de JS novos) | 30 | 30 | — no código novo; SEC-003 é rota de entrega antiga exposta pelo explorador |
| (8) Upload: MIME por conteúdo | 1 | 1 | — (finfo); entrega inline do XML em SEC-003 |
| (9) Rate limit onde há notificação/zip/IA | 3 | 5 | SEC-004 (zip), SEC-008 (menção/resposta) |
| (10) Teste cross-tenant existente | 57 | 57 | — (por rota ou por grupo de rotas do controller) |
| Itens do §9 pedidos (#3, #5, #6) | 0 | 3 | SEC-005, SEC-004, SEC-007 |
| Robustez financeira (corrida no corrigir valor) | 0 | 1 | SEC-006 |
| **Total** | **371** | **392** | 21 células fora do OK, em 11 divergências |

(Cada célula é uma checagem aplicável a uma rota; checagens que não se aplicam — CSRF em GET, lápide em rota sem pasta — ficam fora do denominador.)
denominador de 57 × 7 + extras; o número que importa é o de células fora do OK: **22**, em 11 divergências.)

## Divergências

| ID | Elemento | Fonte (arquivo:linha) | Dim (V/F/R) | Divergência (o que deveria × o que o código faz) | Classe | Prior. | Evidência no código (arquivo:linha) | Esforço P/M/G | Proposta de implementação (se A) |
|---|---|---|---|---|---|---|---|---|---|
| SEC-001 | `PastaSecaoController`: renomear, excluir, mover subpasta, mover documento p/ seção (+ seção destino), e criar/reordenar documentos/seções | mestre §9 #1; `app/src/CLAUDE.md` "Nunca buscar por ID sem validar posse do tenant" | F | Regra da casa: posse do tenant provada explicitamente. Código: `$this->em->find()` por PK cru em **l.105, 140, 181, 228, 246** (confirmado, as 5 linhas do mestre); `criar` (l.51), `reordenarDocumentos` (l.264) e `reordenarSecoes` (l.289) recebem a `Pasta` do resolver sem `$pasta->getTenant() !== $tenant`. Só o TenantFilter protege. Não há vazamento comprovado: o filtro SQL é ligado por request e o `MoverDocumentoParaSecaoUseCase` confere tenant e pasta do destino. É defesa em profundidade que falta | A | P0 (tenant; defesa em profundidade) | `app/src/Pasta/Controller/PastaSecaoController.php:105,140,181,228,246,51,264,289`; `MoverDocumentoParaSecaoUseCase.php:27-33` | P | Trocar os 5 `em->find` por `secaoRepository->findByIdAndTenant()` / `PastaDocumentoRepository::findByIdAndTenant()` (o mesmo de `PastaDocumentoController:107`), e `secao_id` de destino por `findByIdAndPastaAndTenant($id, $documento->getPasta(), $tenant)`; nas 3 actions com `Pasta $pasta`, o prólogo `if ($tenant === null \|\| $pasta->getTenant() !== $tenant) → 404` igual ao de `PastaDocumentoController:460`. Sem migration. Teste: funcional com o **TenantFilter desligado** (`$em->getFilters()->disable('tenant')` antes da requisição — sem isso o teste prova a outra barreira) pedindo id de seção/documento de outro escritório → 404 e nada muda no banco |
| SEC-002 | Registro da pasta: enviar, editar e excluir mensagem (com resposta e @menção, novos na Trilha A/B) | `app/src/CLAUDE.md` (regra de tenant) | F | A pasta vem do resolver e o controller não confere `$pasta->getTenant()`; `EnviarMensagemPastaUseCase::executar` também não (confere só o tenant da mensagem respondida); editar/excluir acham a mensagem por `em->find` e comparam só o id da pasta. Mesmo padrão do SEC-001: só o TenantFilter segura a pasta de outro escritório | A | P0 (tenant; defesa em profundidade) | `app/src/Controller/PastaController.php:1014-1035, 1040, 1072-1085, 1112-1125`; `app/src/Pasta/UseCase/EnviarMensagemPastaUseCase.php:80-100` | P | Prólogo de tenant (404) nas 3 actions, e no `EnviarMensagemPastaUseCase` o mesmo `self::mesmo($pasta->getTenant(), $tenant)` que ele já usa para a resposta; editar/excluir por `PastaMensagemRepository` escopado em pasta+tenant. Edição mínima em legado (Opção A do `Controller/CLAUDE.md`). Sem migration. Teste: funcional com TenantFilter desligado, POST em pasta de outro escritório → 404 sem linha nova em `pasta_mensagem` nem em `notificacao` |
| SEC-003 | Arquivo XML/KML aberto "em nova aba" pelo nome no explorador novo (e qualquer link para `pasta_documento_view`) | `pasta-explorador.js:977-983` (link `target=_blank` para `viewUrl`, novo na Trilha B) | F | Upload aceita `application/xml`, `text/xml` e KML (MIME por finfo, correto). A rota de visualização devolve o arquivo **inline, mesma origem**, com `Content-Type` adivinhado do conteúdo (`BinaryFileResponse`) e **sem CSP** (o nginx só manda `nosniff`, que não impede o XML). Um XML com elementos XHTML (`<html xmlns="http://www.w3.org/1999/xhtml"><script>`) executa script na origem do BlueJus quando aberto direto. **Hipótese por leitura de código, não provada por teste.** Atacante precisa de permissão de editar alguma pasta do MESMO escritório (insider); vítima pode ser o admin do escritório. A rota é anterior às trilhas; o explorador novo só a deixou a um Ctrl+clique. O visualizador novo está correto (XML vira texto; DOCX/planilha em `iframe sandbox`) | A | P0 (XSS armazenado, intra-escritório) | `app/src/Pasta/UseCase/UploadPecaUseCase.php:47-67` (xml/kml na lista); `app/src/Controller/PastaController.php:1801-1817` (inline); `app/src/Shared/Http/EntregaDeArquivo.php:80-88` (sem header); `nginx/conf.d/nginx.prod.conf:31-35` (sem CSP) | P | Em `EntregaDeArquivo::deEmprestado`, quando `inline` e o tipo não for PDF/imagem raster/áudio/vídeo: `Content-Security-Policy: sandbox; default-src 'none'` (não pôr em PDF — o visualizador de PDF do Chrome não abre sob `sandbox`), ou servir XML/KML/texto inline como `text/plain; charset=UTF-8`. Sem migration. Teste: funcional que sobe um `.xml` XHTML com `<script>`, pede `pasta_documento_view` e afirma o header (ou o `Content-Type` `text/plain`); conferir antes a prova reintroduzindo o defeito |
| SEC-004 | "Baixar .zip" da seleção (D5) — §9 #5 | mestre §9 #5; L8-backend | F | Sem limitador (os limiters do `framework.yaml` não têm entrada para zip; o controller não injeta nenhum). Sem pool próprio do PHP-FPM no repositório (grep de `max_children` só acha docs) → vale o padrão da imagem, `pm.max_children=5`. Cada zip lê até 1 GB/500 arquivos, grava um temporário e prende um worker até o fim do stream: 5 cliques (ou um laço) tornam o sistema indisponível para o escritório inteiro e somam até 5 GB de disco temporário (VPS com 19 GB livres, §11 #5). **Confirmado** | A | P1 (disponibilidade) | `app/src/Pasta/Controller/PastaDocumentoController.php:366-388`; `app/config/packages/framework.yaml:13-70`; `MontarZipDeDocumentosUseCase.php:65-68` | P | Limiter `pasta_documentos_zip` (sliding_window, chave = id do usuário, ex. 3 por minuto) injetado como `RateLimiterFactoryInterface` (como `AnaliseDaPastaController:52`; evita o deprecation do §11 #6) → 429 em JSON; mais um `LockFactory` não bloqueante por escritório limitando a 1-2 zips simultâneos. O ajuste do pool (`pm.max_children`) é do dono, na publicação. Sem migration. Teste: funcional com o limiter em memória (já configurado em `when@test`) — a 4ª chamada responde 429 e não cria arquivo temporário |
| SEC-005 | Desfazer alteração do `audit_log` com associação a `Cargo`/`Sede`/`Lotacao` — §9 #3 | mestre §9 #3 | F | `Cargo`, `Sede` e `Lotacao` estão em `ENTIDADES_REVERSIVEIS`, mas **não** implementam `TenantAware` (têm `getTenant()`): `pertenceAoTenant()` cai no `return false` e o desfazer recusa sempre com "associação indisponível". Seguro (fecha), mas a função promete e não entrega. **Confirmado** | A | P2 | `app/src/Auditoria/UseCase/DesfazerAlteracaoAuditLogUseCase.php:34-52, 385-416`; `app/src/Entity/Tenant/Cargo.php:10,40`, `Sede.php:10,96`, `Lotacao.php:10,40` | P | Em `pertenceAoTenant()`, ramo explícito `$relacionado instanceof Cargo \|\| Sede \|\| Lotacao → $relacionado->getTenant()?->getId() === $tenantId`. **Não** torná-las `TenantAware` aqui: isso as coloca sob o TenantFilter e muda consultas do admin (frente própria). Sem migration. Teste: funcional desfazendo uma troca de cargo/sede/lotação → aplica; com cargo de outro escritório no `from` → recusa |
| SEC-006 | "Editar ou corrigir valores" do pagamento | mestre §9 #16 | F | A proteção contra edição concorrente é "valor exibido = valor do banco" lido sem trava: dois POST simultâneos com o mesmo `valorAnterior` passam os dois e o segundo sobrescreve o primeiro (o 409 só pega quem chega depois do commit do outro). Perda silenciosa de uma correção de dinheiro | A | P2 | `app/src/Pasta/UseCase/CorrigirValorDoPagamentoUseCase.php:76-90`; `PastaPagamentoController.php:235-275` | P | Sem migration: `wrapInTransaction` + `$em->lock($pagamento, LockMode::PESSIMISTIC_WRITE)` (ou `refresh` com `SELECT … FOR UPDATE` no repositório) antes de comparar. Com migration (o §9 #16 propõe junto da #15): `#[ORM\Version]` em `PastaPagamento`. Teste: integração com duas conexões não é viável sob DAMA; provar com teste de unidade do repositório que a leitura usa `FOR UPDATE` (SQL logado) e funcional do 409 que já existe |
| SEC-007 | Linha legada com `caminho_arquivo` inválido — §9 #6 (reconferido) | mestre §9 #6; L4 | F | Reconferido: o **excluir** não monta mais a chave (vai para a lixeira), então não dá 500 ali. O risco mudou de lugar: (a) a **purga** monta `ChavesDePasta::documento()` por item, `ChaveDeArquivoInvalida` sobe, o comando captura `\Throwable` e PARA — e para de novo em toda execução seguinte, no mesmo registro; (b) **zip** e **copiar** recebem a exceção como `\InvalidArgumentException` → 422 com a mensagem técnica (trecho do caminho gravado) e o zip inteiro falha, em vez de listar o arquivo no LEIA-ME como "não encontrado". Não sei se há linhas assim em produção (não consultei a prod) | A | P2 | `app/src/Pasta/UseCase/PurgarLixeiraUseCase.php:159-164, 233-236`; `PurgarLixeiraCommand.php:69-75`; `MontarZipDeDocumentosUseCase.php:142-153` (só `ArquivoNaoEncontrado` é tratado); `CopiarDocumentosDaPastaUseCase.php:111-114`; `ChaveDeArquivo.php:72-100`; `ChaveDeArquivoInvalida.php:14` | P | Purga: capturar `ChaveDeArquivoInvalida` por item, remover a linha sem tentar o arquivo e logar `warning` com o id. Zip: tratar como `ArquivoNaoEncontrado` (entra em `naoEncontrados`). Copiar: converter em `OriginalNaoEncontradoException` (mensagem com o nome original, não o caminho). Sem migration. Teste: fixture com `caminho_arquivo = 'a/b.pdf'` na lixeira vencida → a purga termina e remove as demais; zip com ela → 200 e LEIA-ME cita o arquivo |
| SEC-008 | @menção e "Responder" no Registro (notificação no sino) | briefing item (9) | F | Cada mensagem pode notificar até 20 pessoas (`MAXIMO_POR_REGISTRO`) e a anti-duplicata é só por link — cada mensagem nova tem link novo. Sem limitador por usuário, um laço (ou script) inunda o sino dos colegas. O alerta de meta tem trava de 1 h; a IA tem 10/min; o Registro não tem nenhuma | A | P3 | `app/src/Pasta/Service/MencoesDoRegistro.php:16`; `EnviarMensagemPastaUseCase.php:115-130`; `PastaController.php:1013-1068` | P | Limiter `pasta_registro_enviar` (chave = usuário, ex. 30/min) em `enviarMensagem` → 429 JSON, com `RateLimiterFactoryInterface`. Sem migration. Teste funcional: N+1-ésima mensagem → 429 e nenhuma notificação nova |
| SEC-009 | BlueJus IA: "Excluir esta análise" e cadeado "interna do escritório" | — | F | As duas escritas exigem só VER a pasta (+ módulo da IA): qualquer leitor exclui (soft delete) a análise de outra pessoa e muda a visibilidade dela. Pode ser intencional (análise é do escritório), mas não está escrito em lugar nenhum. IA desligada em prod (`ia_habilitada=false`) | C | P3 | `app/src/Inteligencia/Controller/AnalisePushController.php:152-180, 185-205`; `ExcluirAnaliseUseCase.php:26-34`; `AlternarAnaliseInternaUseCase.php:26-36` | P | Decisão do Samuel: excluir/interna exigem EDITAR a pasta, ou só o autor da solicitação, ou qualquer leitor (como hoje). Depois de decidido, é troca de 1 checagem + teste |
| SEC-010 | "Metas da equipe" (`/tarefas/equipe`, link das colunas do Dashboard) | — | F | O guarda é só o módulo `bi`; a lista mostra título da meta, NUP e **nome do cliente** de pastas que o usuário pode não ter acesso por item (`canAccessResource` não é chamado por pasta). Pelo modelo paralelo (`AUTORIZACAO.md` §7) a listagem é governada por módulo — mas aqui o módulo é `bi`, não `pastas`: quem tem Dashboard e não tem Pastas vê dados de pasta | C | P3 | `app/src/Tarefa/Controller/TarefaEquipeController.php:25-55`; `templates/tarefa/equipe.html.twig:69-71` | P | Decisão: o painel de gestão vê todas as metas do escritório (aceitar e registrar em `AUTORIZACAO.md`), ou exigir também o módulo `pastas`, ou filtrar por `canAccessResource` |
| SEC-011 | Abrir o teor do Push na pasta | — | F | `GET pasta_push_teor` grava `lida = true` (efeito colateral em GET, sem CSRF — prefetch ou link forjado marcam como lida). Já existe a rota `POST pasta_push_lida` com CSRF para isso | A | P5 | `app/src/Pasta/Controller/PastaPushProcessualController.php:65-68` | P | Tirar a gravação do GET e chamar o POST `pasta_push_lida` no `pasta-show.js` ao abrir o painel. Sem migration. Teste funcional: GET não muda `lida`; POST muda. (Muda o momento da marcação: conferir com o dono se "abrir = lida" deve continuar idêntico) |

## Itens F (agrupados, com evidência)

**Rotas auditadas (57):** Documentos — `pasta_documento_edit`, `_mover_lote`, `_excluir_lote`, `_restaurar`, `_lixeira`,
`_favorito`, `_zip`, `_copiar` (`PastaDocumentoController.php:98-431`); Seções — 7 rotas de `PastaSecaoController`;
`pasta_administrativa_definir`, `pasta_checklist_estado`, `pasta_duplicar`, `pasta_favorito_alternar`, `pasta_mencionaveis`;
`pasta_pagamento_parcelar`, `pasta_pagamento_corrigir_valor`; `pasta_push_teor`, `pasta_push_lida`; `pasta_resumo_imprimir`;
`pasta_timeline`; upload da peça (`PeticionarController`, alterado); Registro (enviar/editar/excluir), `buscarClientes`,
`deleteDocumento`, `financeiroExcluirDocumento` (`PastaController`); 3 de notas técnicas; `tarefa_renomear`, `_reabrir`,
`_alertar`, `tarefa_equipe`, `tarefa_criar_para_pasta`; `cliente_resumo`, `cliente_contatos`; `dashboard_index`,
`dashboard_preferencias_salvar`; IA — painel/solicitar/listar dos agentes, solicitar/listar/status/lida/excluir/interna do
Push, `inteligencia_admin_config`; Expediente (index com deep-link, marcadores com `#[PastaPorId]`).

- **F — tenant explícito + IDOR (47 rotas):** prólogo `pasta->getTenant() !== tenant → 404` e posse de TODOS os ids
  numa consulta escopada por pasta+tenant (`PastaDocumentoController.php:456-512`, teto de 2000 ids antes de consultar
  `:478`; destino `:590-601`); favoritos com alvo escopado (`:333-342`); `PastaAdministrativaController.php:54`,
  `PastaChecklistEstadoController.php:55`, `PastaDuplicarController.php:58`, `PastaFavoritoController.php:47`,
  `ListarMencionaveisDaPastaUseCase.php:51-53`; pagamento por `findByIdAndPastaAndTenant` (`PastaPagamentoController`
  corrigir) e `mesmoTenant` no parcelamento (`RegistrarParcelamentoDaPastaUseCase.php:95`); publicação escopada por
  tenant **e** números da pasta (`PastaPushProcessualController.php:58-62`); nota técnica por `findOneByIdDoTenant` +
  processo + pasta vinculada (`NotaTecnicaController.php:72-73, 214-244`); metas com `mesmoEscritorio` nos UseCases
  (`RenomearMetaUseCase.php:38`, `ReabrirMetaUseCase.php:31`, `AlertarResponsavelDaMetaUseCase` 2ª trava); cliente com
  `getTenant()->getId()` (`ClienteResumoController.php:68-71, 101-104`); IA com `findOneDoTenantEAlvo` e o worker
  conferindo tenant da análise E da pasta (`ProcessarAnaliseDeInteligenciaHandler.php:87-95, 117-121`); resposta do
  Registro conferida (raiz incluída) no UseCase (`EnviarMensagemPastaUseCase.php:92-100`); SQL cru sempre com
  `tenant_id` (`PastaFavoritaRepository:77`, `PastaDocumentoFavoritoRepository:116`, `PreferenciaDoUsuarioRepository:75`,
  `PastaPagamentoRepository:141`, `AuditLogRepository` UNION novo); `MetricasExtrasDoDashboardRepository` com
  `andWhere('… .tenant = :tenant')` nas 4 consultas.
- **F — permissão (55):** EDITAR em lote/copiar/restaurar/lixeira; VER em zip e favoritos (exceções documentadas no
  docblock `PastaDocumentoController.php:62-70`); duplicar = VER origem + módulo `pastas` (`PastaDuplicarController.php:62-68`);
  menção só lista e só notifica quem VÊ a pasta (`ListarMencionaveisDaPastaUseCase.php:70-72`;
  `EnviarMensagemPastaUseCase.php:150-153`); timeline/resumo só mostram financeiro por `VisibilidadeDoFinanceiroDaPasta`
  (hoje = VER, documentado); duplicados do upload só de pastas que o usuário vê (`ListarDuplicadosDoDocumentoUseCase.php:46-62`);
  `cliente_resumo` filtra pastas por `canAccessResource` (`ClienteResumoController.php:126-136`); IA exige VER + módulo,
  motivo técnico só para `admin.inteligencia.manage` (`AnalisePushController.php:125`); config da IA por `canAdminister`.
- **F — CSRF (43/43 POST):** token por pasta/ação/id em todas; lote um token por pasta (`idDoTokenDeLote`); IA aceita
  `_token` ou `X-CSRF-Token`; preferências por `ValidaCsrfAjaxTrait`; config da IA pelo form do Symfony.
- **F — lápide (40/40):** `PastaSomenteLeituraListener` + `#[PastaPelaFilha]`/`#[PastaPorId]` nas rotas por id de filha
  (`PastaDocumentoController.php:99`, `PastaSecaoController.php:98,133,174,221`, `TarefaController` `criarParaPasta`,
  Expediente marcadores); zip liberado por ser leitura; nota técnica confere `estaExcluida()` à mão porque a pasta vem
  no corpo (`NotaTecnicaController.php:228-234`); arquitetura travada por `PastaSomenteLeituraRotasArquiteturaTest`.
- **F — auditoria (38):** `Auditavel` em `Pasta`, `PastaDocumento`, `PastaSecao`, `PastaPagamento`, `PastaMensagem`,
  `NotaTecnica`, `AnaliseDeInteligencia`, `ConfiguracaoDeInteligencia`, `Tarefa`; zip registrado à mão
  (`MontarZipDeDocumentosUseCase.php:309-320`); correções de valor lidas do `audit_log` com tenant
  (`PastaPagamentoRepository.php:130-150`); enum gravado pelo valor (`AuditLogSubscriber.php` diff).
- **F — XSS (30):** os 4 `|raw` novos são comentário (2), `pasta.id|json_encode` (1) e `explorador.json` com
  `JSON_HEX_TAG|AMP|APOS|QUOT` (`ExploradorDeDocumentosOutput.php:71`); os 26 sinks de JS montam HTML fixo, ícones,
  calendário numérico, decodificação em `<textarea>`, ou HTML renderizado pelo Twig no servidor (clientes, IA, nota
  técnica sanitizada por `texto_rico`); menções escapam o rótulo nas duas pontas (`MencoesDoRegistro.php` `reescrever`/`exibir`);
  visualizador põe DOCX/planilha/e-mail em `iframe sandbox=""` via `srcdoc` com limpeza prévia
  (`visualizador-documento.js:32-39, 897-941`).
- **F — upload (1):** MIME por conteúdo (`$file->getMimeType()` = finfo) contra lista fechada (`UploadPecaUseCase.php:96-103`).
- **F — rate limit (3):** IA 10/min por usuário (`framework.yaml` `inteligencia_solicitar`; `AnaliseDaPastaController.php:64`);
  alerta de meta 1 h por meta+destinatário (`AlertarResponsavelDaMetaUseCase` `INTERVALO_MINIMO`); lote com teto de ids.
- **F — testes cross-tenant (57):** por URL/rota há arquivo com cenário de outro escritório para copiar, mover-lote,
  excluir-lote, restaurar, lixeira, favorito, zip, checklist, administrativa, duplicar, mencionáveis, parcelar, corrigir valor,
  push, timeline, resumo, nota técnica, alertar/renomear/reabrir, equipe, contatos/resumo do cliente, preferências,
  IA (agentes, push, análises), seções e mensagens. Nota: quase todos rodam com o TenantFilter LIGADO, então não
  provam a checagem explícita que SEC-001/002 pedem.

## Observações (riscos, conflitos Designer × sistema, dúvidas para o Farlei)

1. **Comentário recorrente impreciso:** vários docblocks dizem "o TenantFilter não se aplica a `find()`"
   (`PastaDocumentoController.php:300,445`, `PastaAdministrativaController.php:27`, `NotaTecnicaController.php:38,215` e
   outros). Pela documentação do Doctrine, filtros SQL **se aplicam** à consulta do `find()`; o que eles não alcançam é a
   entidade já no identity map (como `DesfazerAlteracaoAuditLogUseCase.php:398` descreve certo). A conclusão (conferir
   explicitamente) está certa; a justificativa, não. Vale corrigir o texto para ninguém "provar" o contrário e afrouxar.
2. **Exceções conscientes, não reportadas como achado:** favoritos de pasta e de documento sem `audit_log`
   (`PastaFavorita`/`PastaDocumentoFavorito` não são `Auditavel`, preferência pessoal); preferências do Dashboard e
   `DashboardFoto` idem; zip e favoritos com permissão de VER (docblock do controller); financeiro visível a quem vê a
   pasta (`VisibilidadeDoFinanceiroDaPasta`, "futuro" no catálogo); zip liberado na lápide (leitura).
3. **`cliente_contatos` grava contato do cliente sem auditoria** — `Cliente` não é `Auditavel` (mestre §11 #9,
   **D-CLI-AUDIT**). A função nova piora a lacuna (edição rápida, por XHR), mas a decisão já está aberta.
4. **Alerta de meta:** a notificação não é auditada (`Notificacao` não é `Auditavel`) e o destinatário só precisa ser
   responsável com vínculo ativo — não precisa VER a pasta, ao contrário da regra de menção/resposta. Como é responsável
   pela meta, provavelmente é o certo; registrar a regra.
5. **`buscarClientes` devolve CPF/CNPJ mascarado** (`PastaController` `mascararDocumento`) — bom; conferir com o dono se
   o resumo do cliente (`cliente/_resumo.html.twig`) mostra documento completo a quem só VÊ o cliente (não auditei o
   template linha a linha).
6. **Legado fora do recorte, mesmo padrão do SEC-001:** `PastaController.php:964` (vincular cliente por `find` cru),
   `:1657` (seção do upload legado), `:2091,2118,2145,2188` (documentos do financeiro), `:2257-2531` (observações e
   checklist). Todos anteriores a `a458e58e`; só o TenantFilter protege. Entram numa fatia própria de higiene.
7. **Duplicar pasta** não tem limitador e cada cópia dispara job do Drive (hoje parado com 403). Risco baixo (exige
   permissão de criar pasta); fica como observação.
8. **SEC-003 não está provado** — é leitura de código (finfo → `text/xml` → `BinaryFileResponse` inline, sem CSP). A
   prova é o teste funcional da proposta; se o `Content-Type` sair como `text/plain` por algum motivo que não vi, o
   achado cai para P4 (endurecimento). Também vale para os documentos financeiros (`:2102`, inline).
9. **SEC-004 e disco:** além dos workers, cada zip de até 1 GB fica em área temporária até o fim do envio. Com o disco
   da VPS em 19 GB livres (§11 #5), o limiter também protege o disco.
10. **SEC-007:** antes de corrigir, medir em produção (pelo MCP, com o dono) quantas linhas de `pasta_documento` têm
    `caminho_arquivo` com `/`, `\`, `..` ou vazio. Se for zero, a correção vira só robustez (P4).
