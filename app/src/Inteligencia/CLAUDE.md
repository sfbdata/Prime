# src/Inteligencia/ — BlueJus IA

Spec: `docs/specs/inteligencia-resumo-do-push.md` · investigação: `docs/specs/trilha-b-inventario/inteligencia.md`.
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
5. **Conteúdo externo é dado, não instrução.** As movimentações entram entre `<movimentacoes>` e
   `</movimentacoes>` com a instrução explícita; o texto não pode fechar a tag. A resposta é renderizada
   **escapada** (sem `|raw`).
6. **O que foi enviado não é persistido** (D5): `contexto_hash` + `contexto_resumo` (ids, chaves,
   contagens). A resposta integral fica em `texto_bruto`.

## Cadeia

`AnalisePushController::solicitar` → `SolicitarResumoDoPushUseCase` (pasta do tenant → `canAccessResource`
→ `DisponibilidadeDeInteligencia` → idempotência → contexto → linha `pendente` + `ProcessarAnaliseDeInteligencia`
no `async`) → `ProcessarAnaliseDeInteligenciaHandler` (contexto → `PromptResumoDoPush` → `ProvedorDeLinguagem`
→ `InterpretadorDeRespostaDePush` → `concluida`).

Disponibilidade, na ordem: plataforma (`ia_habilitada` + provedor) → escritório (`inteligencia_configuracao`)
→ permissão de módulo → cota diária/mensal. A Twig `ia_disponibilidade()` devolve o enum para a tela.

## Ligar um provedor de verdade

Implementar `ProvedorDeLinguagem` com `HttpClientInterface` (classificar 4xx definitivo × 5xx/rede transitório
em `FalhaDoProvedorException`), trocar o alias em `services.yaml`, definir `IA_HABILITADA=1` e as credenciais no
`.env.prod`, rebuild (prod é imagem baked), atualizar o Anexo I da Política de Privacidade (D3).
