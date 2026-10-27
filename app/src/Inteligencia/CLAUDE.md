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
4. **Segredo de justiça nunca sai** (`Processo::emSegredoDeJustica()`: `nivelSigilo > 0` do tribunal OU "Sim"
   manual no cartão do processo, P5b). `MontadorDeContextoDoPush` lança `ContextoBloqueadoException` antes de
   qualquer leitura; o UseCase recusa (409) e o handler marca `falhou` sem chamar o provedor.
   **Desligar corta a fila (IA-034L):** o handler reconfere `DisponibilidadeDeInteligencia::paraProcessamento`
   (plataforma, escritório, módulo de quem pediu; a cota não) antes do fluxo e do provedor. Indisponível →
   `indisponivel`/`falhou` com o motivo, sem chamar o provedor e sem relançar.
5. **Conteúdo externo é dado, não instrução.** TODO valor que não nasceu no código (NUP, classe, assunto,
   órgão, tribunal, nomes, tipo/fonte/texto das movimentações, metas, anotações, documentos, resposta
   anterior do modelo) entra no prompt dentro de um bloco delimitado — `<processo>`, `<equipe>`,
   `<movimentacoes>`, `<analise_anterior>` (Push) e `<pasta>`, `<processos_vinculados>`, `<clientes>`,
   `<metas>`, `<anotacoes>`, `<observacoes>`, `<documentos>`, `<checklist>`, `<financeiro>` (agentes),
   `<conversa_anterior>`, `<pergunta>` (aba Perguntar) e `<pessoa>`, `<historico>`, `<pergunta>` (chat da Central) — e
   passa pelo `NeutralizadorDeConteudo` (tag delimitadora vira texto inerte; controle/zero-width/bidi
   saem). Campo novo no prompt = campo neutralizado, dentro de um bloco, com o nome do bloco em
   `NeutralizadorDeConteudo::DELIMITADORES`. A resposta é renderizada **escapada** (sem `|raw`).
6. **O que foi enviado não é persistido** (D5): `contexto_hash` + `contexto_resumo` (ids, chaves,
   contagens). A resposta integral fica em `texto_bruto`; a análise integral do agente, em `texto_da_analise`.
7. **Do cliente sai só o nome.** CPF/CNPJ, e-mail, telefone e endereço nunca entram no contexto; o
   financeiro só entra se `VisibilidadeDoFinanceiroDaPasta` disse sim na solicitação (decisão gravada em
   `contexto_resumo.financeiro`; o worker obedece, não decide). **Conteúdo de documento** só sai no
   comando `ler_documentos` ("Ler N documentos", PR F da spec `ia-anthropic-ligar`): só os arquivos que a
   pessoa marcou, visíveis na aba Documentos dela (abas gravadas e as de agora), fora da lixeira, da pasta
   e do escritório da análise (o worker relê por id + tenant e reconfere o acesso de quem pediu), PDF ou
   imagem conferidos pelo conteúdo, até 3 por vez e 100 páginas somadas (`RegraDaLeituraDeDocumentos`), lidos pela chave do armazenamento e mandados inline (sem Files API,
   sem OCR nosso), com o aceite do escritório na versão `VersaoDoAceiteDaIa::ComDocumentos` (2) ou maior.
   Grava-se só id + sha256 (`contexto_resumo.documentosLidos`); log e motivo de falha nunca levam nome
   nem conteúdo. Todo outro comando lê só metadados, e o prompt diz isso.

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

Perguntar (drawer, F3): `ConversaDaPastaController::perguntar` (`/pasta/{id}/ia/conversa/perguntas`) →
`PerguntarSobreAPastaUseCase` (mesmas guardas; texto 1 a 2000 → senão 422; uma em andamento por pessoa e
pasta) → análise `pergunta_livre` do Gestor + `inteligencia_pergunta` → mesmo handler →
`FluxoDaPerguntaLivre` (contexto do Gestor + últimas 4 concluídas da MESMA pessoa na pasta, neutralizadas
e mascaradas → `PromptDaPergunta`). A conversa é da pessoa: lista por autor e, nas rotas por id, a
análise `pergunta_livre` só abre para quem perguntou (`VisibilidadeDaAnaliseDaPasta::podeLer`).

Chat da Central (F3, `docs/specs/ia-chat-central.md`): tipo `chat_central`, SEM pasta (`FluxoDeAnaliseSemPasta`; o handler
vai por um ramo que não procura pasta nem notifica e grava resposta e conclusão numa transação). Alvo = `conversa_ia`
(`alvo_id` = conversa; `contexto_resumo` = ids e contagens, nunca texto). O fluxo fala com a Central pela porta
`Contract/ConversaDaIaNaCentral` (implementada em `Central\Service\ConversaDaIaDaCentral`): relê pergunta e até 12
mensagens anteriores (400 caracteres cada, respeitando "excluir"); se não confere, `\LogicException` e `falhou` sem provedor.
A IA não lê pasta, equipe nem produtividade aí; `metricas`, `acoes` e `trace` são ignorados. É conteúdo particular
(`ehParticular`): nada do texto vai para o `audit_log`.

As consultas por alvo do repositório filtram por `tipo` (e `agente`): uma pendente de agente não trava o
Push nem vira "análise anterior" dele.

Disponibilidade, na ordem: plataforma (`ia_habilitada` + provedor + chave geral do Master em
`inteligencia_plataforma`) → liberada pelo Master no escritório (`liberada_pelo_master`) → escritório ligado com
aceite (`inteligencia_configuracao`) → permissão de módulo → cota diária/mensal → orçamento mensal em US$ (só nos
modos incluso e adicional; o custo é estimado pelo `CustoDaIa` a partir dos tokens gravados, e `limite_gratis` não trava). O modelo vem do banco
(`FonteDoModeloDeIa`, escolhido na aba "ia" do Painel do Master), com `IA_MODELO` de reserva. A Twig `ia_disponibilidade()` devolve o enum para a tela.

**Retenção** (`ExpirarConteudoDaInteligenciaUseCase`, agendado às 00:20): o prazo é o `meses_de_retencao` de
`inteligencia_plataforma`, escolhido pelo Master (12 por padrão, decidido pelo dono em 24/10). Análise terminada antes
do prazo perde o conteúdo (resumo, pontos, textos, edições, texto da pergunta) e ganha `excluida_em`; a linha fica
pelos números (modelo, tokens, datas). Campo novo de conteúdo na análise = campo novo no `expirarConteudoAntesDe`.

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

## Provedor: Anthropic (D1, 14/10/2026)

`ProvedorAnthropic` (HTTP direto pelo `symfony/http-client`, sem SDK) é o alias da porta. **Dormente** sem
`ANTHROPIC_API_KEY` e `IA_MODELO` no ambiente. Proposta de modelo, custo, privacidade e o checklist de ativação:
`docs/specs/inteligencia-provedor-anthropic.md`. Regras do adaptador:

- **Nunca `temperature`/`top_p`/`top_k` nem prefill** — os modelos atuais respondem 400. A `temperatura` do pedido é
  intenção do prompt; o último turno é sempre do usuário.
- **Mensagem de exceção = status + tipo + `request-id`.** Ela vira motivo gravado da análise: nunca o corpo do erro
  nem o texto do pedido. 429 do teto de gasto (`enforced_spend_limit_reached`) é definitivo.
- **Nenhum id de escritório, usuário ou pasta em metadado** da requisição.
- Testes só com `MockHttpClient`; a suíte usa o `ProvedorFalso`. Nunca uma chamada real em teste.
- Não usar os "Covered Models" (Fable/Mythos): exigem retenção de 30 dias do lado da Anthropic.
- **`inference_geo`** sai de `IA_INFERENCE_GEO` (padrão `us`, decisão de 24/10/2026); valor fora de `us`/`global`/vazio
  deixa o provedor dormente.
- **`retry-after`** do 429/529/503 vira `FalhaDoProvedorException::esperarSegundos` (teto de 5 min); o handler re-tenta
  com esse atraso (`RecoverableMessageHandlingException`), sempre dentro de `TENTATIVAS_MAXIMAS`.
- **Uma chamada por entrega:** o handler trava a linha (`PESSIMISTIC_WRITE`) e relê o status antes de passar a
  `processando`. Sem a trava, mensagem reentregue ou dois workers cobravam a mesma análise duas vezes.
