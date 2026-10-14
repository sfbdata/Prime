# src/Inteligencia/ — BlueJus IA

Specs: `docs/specs/inteligencia-resumo-do-push.md` (fatia 1, Push) e `docs/specs/inteligencia-agentes-da-pasta.md`
(fatia 2, agentes no cabeçalho da pasta) · investigação: `docs/specs/trilha-b-inventario/inteligencia.md`.
Namespace `App\Inteligencia` · módulo de permissão `inteligencia` (`modules.inteligencia.view`) · admin
`admin.inteligencia.manage` · prefixo de rota `inteligencia_`.

## Regras inegociáveis

1. **Nenhuma resposta que não veio do modelo.** Sem provedor configurado → `ProvedorIndisponivelException`
   → status `indisponivel` → a tela diz "IA não configurada nesta instalação". O único provedor com
   resposta programada é o `ProvedorFalso`, que mora em `app/tests/` e só entra pelo `when@test`.
2. **Tenant explícito em toda consulta do worker.** O handler roda sem sessão; o `TenantFilter` fica
   inerte. O repositório e a `FonteDeMovimentacoesDoPushDoctrine` filtram por tenant à mão; o handler
   revalida o `tenantId` da mensagem contra a linha antes de agir (padrão do Sync).
3. **Nunca o prompt no log.** Canal monolog `inteligencia` recebe ids, tenant, tipo, provedor, tokens,
   duração e classe do erro. O texto do pedido carrega conteúdo processual.
4. **`nivelSigilo > 0` nunca sai.** `MontadorDeContextoDoPush` lança `ContextoBloqueadoException` antes de
   qualquer leitura; o UseCase recusa (409) e o handler marca `falhou` sem chamar o provedor.
   **Desligar corta a fila (IA-034L):** o handler reconfere `DisponibilidadeDeInteligencia::paraProcessamento`
   (plataforma, escritório, módulo de quem pediu; a cota não) antes do fluxo e do provedor. Indisponível →
   `indisponivel`/`falhou` com o motivo, sem chamar o provedor e sem relançar.
5. **Conteúdo externo é dado, não instrução.** TODO valor que não nasceu no código (NUP, classe, assunto,
   órgão, tribunal, nomes, tipo/fonte/texto das movimentações, metas, anotações, documentos, resposta
   anterior do modelo) entra no prompt dentro de um bloco delimitado — `<processo>`, `<equipe>`,
   `<movimentacoes>`, `<analise_anterior>` (Push) e `<pasta>`, `<processos_vinculados>`, `<clientes>`,
   `<metas>`, `<anotacoes>`, `<observacoes>`, `<documentos>`, `<checklist>`, `<financeiro>` (agentes) — e
   passa pelo `NeutralizadorDeConteudo` (tag delimitadora vira texto inerte; controle/zero-width/bidi
   saem). Campo novo no prompt = campo neutralizado, dentro de um bloco, com o nome do bloco em
   `NeutralizadorDeConteudo::DELIMITADORES`. A resposta é renderizada **escapada** (sem `|raw`).
6. **O que foi enviado não é persistido** (D5): `contexto_hash` + `contexto_resumo` (ids, chaves,
   contagens). A resposta integral fica em `texto_bruto`; a análise integral do agente, em `texto_da_analise`.
7. **Do cliente sai só o nome.** CPF/CNPJ, e-mail, telefone e endereço nunca entram no contexto; o
   conteúdo de documentos não é lido (só metadados, e o prompt diz isso); o financeiro só entra se
   `VisibilidadeDoFinanceiroDaPasta` disse sim na solicitação (decisão gravada em
   `contexto_resumo.financeiro`; o worker obedece, não decide).

## Cadeia

Push: `AnalisePushController::solicitar` → `SolicitarResumoDoPushUseCase` (pasta do tenant → `canAccessResource`
→ `DisponibilidadeDeInteligencia` → idempotência por tipo → contexto → linha `pendente` + `EnfileiradorDeAnalise`)
→ `ProcessarAnaliseDeInteligenciaHandler` → `FluxoDoResumoDoPush` (`MontadorDeContextoDoPush` →
`PromptResumoDoPush`) → `ProvedorDeLinguagem` → `InterpretadorDeRespostaDePush` → `concluida`.

Agentes: `AnaliseDaPastaController::solicitar` (`/pasta/{id}/ia/agentes/{agente}/analises`) →
`SolicitarAnaliseDaPastaUseCase` (mesmas guardas; idempotência por **agente**; decide o financeiro) →
mesmo handler → `FluxoDaAnaliseDaPasta` (`MontadorDeContextoDaPasta` lê as seções do `Agente` com tenant
explícito → `PromptDoAgente`) → provedor → `InterpretadorDeRespostaDoAgente` → `concluida` com
`texto_da_analise`. Status/lida/excluir/interna são as rotas por id do Push. O handler escolhe o
`FluxoDeAnalise` pelo `tipo` da linha; tipo novo = fluxo novo, não `if` no handler.

As consultas por alvo do repositório filtram por `tipo` (e `agente`): uma pendente de agente não trava o
Push nem vira "análise anterior" dele.

Disponibilidade, na ordem: plataforma (`ia_habilitada` + provedor) → escritório (`inteligencia_configuracao`)
→ permissão de módulo → cota diária/mensal. A Twig `ia_disponibilidade()` devolve o enum para a tela.

## Agente de Pastas (`/api/agente/*`)

Spec: `docs/specs/designer-central-ia.md` §3 (origem: `ESPEC_AGENTE_PASTAS.md` do Designer 13/10). Fase 1 = só
leitura, sem IA: `AgenteDePastasController` → `Buscar`/`Contar`/`ListarDocumentosDaPasta`/`ListarOpcoesDoAgente`
UseCases. Porta de entrada é o módulo **Expediente** (não o da IA): a busca devolve o que a Lista já mostra.

- **Uma consulta só, a da Lista.** `ConsultaDePastasDoAgente` chama `PastaRepository::findByFilters`/
  `findByFiltrosEMarcador` (e as contagens) com as chaves de `FiltroDoAgenteInput::paraRepositorio()` — as MESMAS
  de `ExpedienteController::filtrosDaRequest` (texto livre é `busca`). Não use os ramos `cliente`/`acao` do
  repositório: a Lista não os usa e eles não ignoram acento nem caixa. A saída repete o texto da tela
  (`ResolvedorIdentificadorDaPasta`, recuo da ação).
- **Valor que o repositório ignoraria em silêncio é 422** (`FiltroDoAgenteInvalidoException` com o `campo`), senão o
  agente recebe "todas as pastas" achando que filtrou. Marcador de outro escritório também é 422, nunca lista vazia.
- **Tenant explícito mesmo com o `TenantFilter` ativo:** o conector MCP da Fase 4 roda sem sessão. O teste HTTP não
  prova essa guarda (o filtro esconde antes) — quem prova é `ConsultaDePastasDoAgenteTest`.
- **NUP não é chave** (não é único no escritório): rotas por id; `nups` pode devolver mais pastas que NUPs.

## Ligar um provedor de verdade

Implementar `ProvedorDeLinguagem` com `HttpClientInterface` (classificar 4xx definitivo × 5xx/rede transitório
em `FalhaDoProvedorException`), trocar o alias em `services.yaml`, definir `IA_HABILITADA=1` e as credenciais no
`.env.prod`, rebuild (prod é imagem baked), atualizar o Anexo I da Política de Privacidade (D3).
