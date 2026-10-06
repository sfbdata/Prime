# Trilha B — Claude Designer → BlueJus real (fidelidade + funções novas)

Início: 05/10/2026 (madrugada) · Base: `master` @ `beff02fd` (= produção, Trilha A publicada como entrega
intermediária) · Risco: BAIXO nas telas; MÉDIO quando tocar permissão; ALTO não entra sem spec própria.

> **Este arquivo é o LEDGER da rodada.** Uma sessão nova continua daqui sem depender da conversa.
> Atualizado durante a execução. A Trilha A (`docs/specs/trilha-a-visual-pje.md`) continua valendo para as
> regras absolutas (§1), o inventário de preservação (§2) e as decisões já tomadas (§5).

## 0. Regras desta rodada (herdadas do dono, 05/10)

1. O Designer define o comportamento; a engenharia acha a implementação. Desvio visual do desenho
   aprovado exige perguntar antes (CLAUDE.md da raiz).
2. **Nada fake**: nenhum botão sem função, dado fictício, localStorage no lugar de persistência, IA simulada.
   Elemento que depende de função ainda não feita **não é renderizado**.
3. Nenhuma função atual some. Ids, classes, `data-*`, XHRs e fragmentos do §2 da Trilha A são contrato.
4. Isolamento por escritório em toda consulta nova (TenantFilter + guarda IDOR + teste cross-tenant).
5. Sem deploy, sem push, sem ação em produção nesta rodada. Commits locais pequenos.
6. Escopo: Dashboard 1.2.2 + Expedientes 1.2.3 + editor + Push (notas técnicas, Compartilhado) +
   Chat/Central + Connect + permissões + relatórios PDF + visualizador/duplicados + padrão PJe.
   **Fora:** Carteira, Objeto, Pasta 1A, redesenho da Agenda.

Fonte: `docs/design/claude-design-2026-10-05 (1)/` (não versionado; abrir pela raiz).
Relatórios das investigações desta rodada: resumidos no §2; o detalhe ficou no scratchpad da sessão
(não versionado) — o que importa para continuar está copiado aqui.

## 1. Estado do repositório

| Quando | HEAD | Suíte | Observação |
|---|---|---|---|
| início | `beff02fd` | 5633/5633 (Trilha A) | árvore limpa; só o pacote do Designer untracked |

## 2. Inventário Designer × BlueJus (classificação A/B/C/D/E)

A = já existe · B = parcial · C = implementável com infra atual · D = infra interna nova sem decisão
externa · E = depende de decisão/credencial/integração externa.

_(preenchido após as investigações)_

## 3. Entregas (ledger)

| # | Funcionalidade | Fonte no Designer | Antes | Agora | Classe | Arquivos | Testes | Commit | Pendência / próxima ação |
|---|---|---|---|---|---|---|---|---|---|
| B1 | Janela de 15 min para o autor editar/excluir comentário da pasta (chat, Detalhes, Financeiro) + "· N min" | README intelligence ("autor só nos 15 minutos… o menu mostra · N min"); decisão do dono 05/10 | 24h, regra duplicada em 6 UseCases + 3 templates | 15 min num só serviço (`Pasta\Service\JanelaDeEdicaoDeComentario`) + funções Twig `comentario_editavel`/`comentario_minutos_restantes` | B | 6 UseCases, 5 templates, 2 classes novas, 11 testes | Pasta 936/936; prova por reintrodução (21 caem com PT24H) | `01ae95a0` | bypass Master/primário do desenho → depende da spec Master (§4) |

## 4. DECISÕES/BLOQUEIOS DO SAMUEL

_(cada item: funcionalidade · o que já foi feito · ponto exato do bloqueio · decisão necessária ·
opções · recomendação · impacto de adiar)_

## 5. Handoff — próxima ação exata

_(atualizado a cada checkpoint)_
