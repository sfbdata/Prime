# Auditoria integral da `pasta_show` contra o Claude Designer (16/10/2026)

> **Para quem:** o dono e a sessão que for implementar. Auditoria **somente leitura**: nenhum código, banco ou dado foi alterado; nada foi commitado. O plano (`06`) espera aprovação.
> **Fonte oficial:** protótipo ONLINE `02 - EXPEDIENTES 1.2.3.dc.html` do projeto Claude Design `4f6a5f61-fa47-40a0-9630-6744f1bb1cac`, lido pelo MCP `claude_design` em 16/10/2026, com todas as dependências que ele carrega.
> **Alvo:** worktree `fidelidade-designer`, branch `fidelidade-designer` @ `9c081725` (E8d).

## Atualização de 16/10, 17h30: o que mudou depois da auditoria

A auditoria descreve o HEAD `9c081725`. Depois dela, a mesma frente recebeu as entregas E12 a E12e (`3ba6c561`..`287a98c1`, 3 migrations), que levaram à `pasta_show` itens marcados aqui como Ausentes:
- **Prazos e audiências** (`13aa6645`, `04d072e4`): `PastaPrazo`, `PastaPrazoController`, `pasta-prazos.js/css`, trilho;
- **datas escritas nos registros viram prazos** (`634e0cfd`): `LeitorDeDatas`, `PrazosDosRegistros`;
- **Andamento da pasta em fases** (`66f1a1cd`): `PastaAndamento`, `_andamento.html.twig`; e sugestões de fase (`287a98c1`). Com isso a P3 deixou de valer na prática;
- **as 4 situações** com carimbo e cartão (`4864044a`); pasta trancada e destaque no domínio (`3ba6c561`);
- **Cadastro do cliente (E12f)**, em andamento e sem commit no momento desta nota.

As linhas correspondentes da matriz (PZ-TRI-N01 a N14, PZ-CAB-36, PZ-DAD-14, VIS-19, VIS-23 e afins) estão **desatualizadas** e devem ser reconferidas por quem continuar.
**Os 5 P0 seguem abertos no código desta data** (conferido): `ExpedienteController.php:335` ainda devolve `trace`; o endpoint de marcadores ainda não checa a pasta; `TarefaController::concluir` ainda não chama `canAccessResource`; `_metas.html.twig:92` ainda não usa `podeEditarPasta`; `_cliente_linha.html.twig:91` ainda não tem `data-ps-cf`.

## Documentos

| # | Arquivo | Conteúdo |
|---|---|---|
| 1 | `01-inventario-integral.md` | Inventário do Designer (partes A e B), da tela do BlueJus e do backend (leitura, domínio, testes) |
| 2 | `02-catalogo-interacoes.md` | Handlers (clique, duplo clique, botão direito, teclado, delegados, dinâmicos), modais, menus, confirmações com texto exato, atalhos, chaves de localStorage, rotas chamadas pela tela, alertas nativos |
| 3 | `03-matriz-designer-x-bluejus.md` | Matriz `ID · Área · Elemento · Gatilho · Designer · BlueJus · Status · Lacuna · Dependências · Prioridade · Evidência` (519 linhas) |
| 4 | `04-relatorio-divergencias.md` | Achado zero (Designer mudou), P0, decisões de política, lacunas P1, divergências visuais, defeitos do protótipo, ✓ só no papel, funcionalidade a preservar |
| 5 | `05-mapa-dependencias.md` | Matriz de segurança dos 89 comandos que gravam; dependências por tipo; cadeia entre entregas; arquivos compartilhados |
| 6 | `06-plano-incremental.md` | Decisões do dono, entregas P0, P1 e P2 com escopo, arquivos, testes, critérios de aceite e ordem |
| 7 | `07-roteiro-smoke.md` | Confirmar achados, medir na tela, regressão por área, novidades de 16/10 |
| | `anexos/diff-*.txt` | Diferença entre o pacote local de 13/10 e o online de 16/10 (02, 03 e `bj-agente.js`) |

## Resumo quantitativo

**Matriz (519 linhas, partes A e B):**

| Status | Linhas | % |
|---|---|---|
| Fiel | 189 | 36% |
| Parcial | 87 | 17% |
| Divergente | 29 | 6% |
| Ausente | 138 | 27% |
| Não aplicável (com justificativa: defeito do desenho, simulação sem lastro, funcionalidade só do BlueJus a preservar, decisão aprovada P1/P2/P3) | 75 | 14% |
| Não verificado | 1 | 0% |

Por área (Fiel · Parcial · Divergente · Ausente · N.A. · Não verificado):

| Área | F | P | D | A | N.A. | NV | Total |
|---|---|---|---|---|---|---|---|
| Cabeçalho (com modais) | 34 | 16 | 6 | 22 | 9 | 0 | 87 |
| Cadastro do cliente | 0 | 10 | 0 | 11 | 1 | 0 | 22 |
| Dados e trilho | 15 | 8 | 3 | 18 | 4 | 0 | 48 |
| Metas | 17 | 7 | 2 | 6 | 1 | 0 | 33 |
| Processo | 24 | 3 | 3 | 16 | 0 | 0 | 46 |
| Financeiro | 24 | 7 | 0 | 5 | 5 | 0 | 41 |
| Só BlueJus (parte A) | 0 | 0 | 0 | 0 | 27 | 0 | 27 |
| Detalhes | 8 | 5 | 2 | 8 | 5 | 0 | 28 |
| Editor rico | 10 | 3 | 0 | 9 | 0 | 0 | 22 |
| Documentos | 26 | 4 | 3 | 4 | 4 | 0 | 41 |
| Push | 11 | 8 | 3 | 16 | 2 | 0 | 40 |
| IA e Chat | 4 | 4 | 2 | 13 | 6 | 0 | 29 |
| Globais | 6 | 8 | 3 | 4 | 7 | 1 | 29 |
| Índices transversais | 10 | 4 | 2 | 6 | 4 | 0 | 26 |
| **Total** | **189** | **87** | **29** | **138** | **75** | **1** | **519** |

**Prioridades:** P0 = 5 achados (3 com linha própria na matriz); P1 = 48 linhas; P2 = 205 linhas.
**Outros números:** inventário do Designer com 274 itens (A) e 256 linhas (B, com índices); tela do BlueJus com 331 linhas por área e 22 itens só do BlueJus; 89 comandos de escrita mapeados no backend; 27 divergências visuais (VIS-01 a 27); 41 defeitos do próprio protótipo (não copiar); **40 ✓ do checklist da Pasta que caem** para Parcial (37) ou Divergente (3).

**Atenção à leitura dos números:**
- O "Não verificado" baixo **não** quer dizer que tudo foi visto na tela. A matriz compara comportamento e gatilho pelo código. **Fiel em aspecto visual = igual por leitura estática.** A medição na tela não foi feita (ver Limitações). Os pontos que só a tela resolve estão no `04` §7 e no `07` §B.
- Ausente concentra o que o desenho tem e o sistema não tem backend para ter: Chat I.A./Central, prazos manuais, cadeado, link externo, IA estruturada. A matriz registra esses itens com a dependência, sem descartá-los.

## Os achados que mais pesam

1. **O Designer online mudou depois de 13/10** (`04` §1). O `02` ganhou a aba Réu e o painel Prazos e audiências. A **Lista 03 também mudou** (266 linhas), e a E8d foi auditada contra a versão anterior.
2. **P0, todos CONFIRMADOS no código** (`04` §2). Nenhum vazamento entre escritórios.
   - P0-1: metas concluídas, e com responsáveis trocados, sem acesso à pasta.
   - P0-2: marcadores mudados com permissão só de ver.
   - P0-3: stack trace no JSON de erro.
   - P0-4: desvincular cliente sem confirmação.
   - P0-5: modelos de checklist do escritório alterados com permissão de uma pasta. O comportamento está confirmado; que seja defeito é hipótese.
3. **Prazos e audiências não existem no BlueJus.** O trilho mostra só prazos de metas, até 3. É a maior lacuna funcional nova.
4. **O próprio desenho de 16/10 tem defeitos.** O "+" do prazo preenche o responsável com o CPF, a lista de feriados é sobrescrita, há `confirm()` e `prompt()` nativos e o alias de CPF fica pela metade. Estão listados com o comportamento correto, para não serem copiados.

## Método

1. A fonte foi baixada pelo `render_preview` do MCP `claude_design` e comparada por SHA-256 com o pacote local, depois de retirados os blocos que o serviço de pré-visualização injeta.
2. A investigação foi feita por subagentes read-only, no máximo dois ao mesmo tempo (limite do WSL):
   - inventário do Designer, partes A e B;
   - inventário da tela do BlueJus;
   - inventário do backend e da segurança;
   - comparação visual estática;
   - matrizes A e B.
3. O orquestrador conferiu no código cada P0 e os números citados (linhas de `TarefaController`, `MetaNaListaController`, `ExpedienteController`, `_cliente_linha`, `_metas`, `PastaPrazoOutput`, `_historico_drawer`, `_cabecalho`, z-index do drawer da IA). Também cruzou o checklist anterior com a matriz.
4. **Evidência × hipótese.** Nos documentos, CONFIRMADO quer dizer código lido, com arquivo:linha. HIPÓTESE vem com o motivo. Na matriz, `[código]` marca o que o auditor conferiu e `[inventário]` o que foi herdado do inventário.

## Trabalho concorrente na mesma worktree (detectado ao fim da auditoria)

Ao fechar os documentos (16/10, por volta das 13h29), `git status` mostrou alterações que **não são desta auditoria**. Elas são de outra sessão, que trabalha na entrega "E12 · Lista do Expediente integral contra o 1.1 online" (`docs/specs/fidelidade-designer-1310/E12-LISTA-INTEGRAL.md`, risco MÉDIO, migration + permissão nova). Os arquivos foram gravados entre 13h22 e 13h27, sem commit:

- código: `Pasta.php` (situações **Suspenso e Cancelado**; **pasta trancada** com `trancadaEm`/`trancadaPor`), `PermissionChecker.php` (`isAdministradorDoEscritorio`, apresentado como o "usuário primário" do desenho), `Marcador` e os UseCases de marcador, `Notificacao`, `UserTenantRepository`, `PurgarEscritorioUseCase`, `AuditavelCoberturaTest`;
- arquivos novos: `Version20261016150000.php`, `PastaDestaque` e repositório, `AlternarTrancaDaPastaUseCase`, `DestacarPastaUseCase`, `PedirAcessoAPastaTrancadaUseCase`, `RenomearClienteDaPastaUseCase`, `ReordenarMarcadorUseCase`, `src/Expediente/Service/`.

Consequências para esta auditoria:
- **A auditoria descreve o código do HEAD `9c081725`**, como estava quando cada agente leu os arquivos. As alterações da E12 **não foram auditadas**. Algumas podem ter sido lidas no meio da escrita.
- Itens marcados aqui como Ausente estão sendo feitos pela E12 na camada de domínio: Suspenso e Cancelado (PZ-CAB-36, P1-9), trancar pasta (parte de PZ-CAB-21/32/37, P1-11) e o papel de "primário" (PZ-GLB-N03). **O plano precisa ser conciliado com a E12 antes de qualquer implementação** (decisão D-10 no `06`).
- A spec da E12 diz que a tranca "não bloqueia a pasta_show no servidor" (é só um aviso na Lista). Isso toca a segurança da `pasta_show`, mas não foi avaliado aqui.
- Esta auditoria escreveu **somente** em `docs/specs/fidelidade-designer-1310/auditoria-pasta-show/`. Nada da E12 foi tocado. Com duas sessões na mesma worktree, a regra "um piloto de git por vez" precisa do dono, para que um `git add` de uma sessão não arraste os arquivos da outra.

## Limitações (não esconder)

- **Sem medição na tela.** A comparação no navegador, com Playwright contra a demonstração da 8000 e o protótipo online numa porta própria, foi **recusada pelo classificador de permissões desta sessão**, porque poderia interferir na demonstração que o dono usa. Foi trocada por análise estática. Os itens "PRECISA MEDIR" e a responsividade seguem abertos. Fica a decisão D-8 do plano.
- `bluejus-central.js` (1,4 MB) não foi lido por inteiro: o uso pela pasta está descrito pelas chamadas, não pelo efeito interno.
- O comportamento do runtime do Designer (`support.js`) foi inferido estaticamente. Um exemplo: o `onDblClick` do arquivo financeiro provavelmente não funciona, mas isso não foi visto em execução.
- O impacto real dos P0-1 e P0-2 (quantos perfis têm o módulo sem acesso à pasta) não foi consultado. O MCP de produção é só leitura e permite consultar; não foi usado nesta auditoria.
- A Lista 03 nova não foi auditada (fora do escopo).
- Parte dos JS grandes do BlueJus (`pasta-explorador.js`, `editor-rico.js`, scripts de pagamentos) foi lida por amostragem dirigida: menus, atalhos, rotas e confirmações.
- Os relatórios intermediários e as cópias do protótipo ficaram no scratchpad da sessão, que não é versionado. Os documentos daqui contêm todo o conteúdo deles.
