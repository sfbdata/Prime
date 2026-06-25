# Spec — Migração do ServiceDesk para `src/ServiceDesk/` (E4)

## Motivo
ServiceDesk é legado: controller único (`src/Controller/ServiceDeskController.php`, ~525
linhas com lógica de negócio inline), entidades em `src/Entity/ServiceDesk/`, repos/forms
nas pastas globais. O projeto exige um domínio próprio (`src/<Dominio>/` com
`Controller/ UseCase/ Entity/ Repository/ DTO/ Form/`). Toca permissão e fluxo de
notificação → risco **MÉDIO**.

> Pré-requisito atendido: o E3 já consertou os métodos quebrados (`setAutor`/`getAutor`/
> `setEnviadoPor`) e o módulo voltou a funcionar. Esta spec parte do comportamento atual
> (funcional) como contrato a preservar.

## Inventário a migrar
| Camada | Origem (legado) | Destino |
|---|---|---|
| Controller | `src/Controller/ServiceDeskController.php` | `src/ServiceDesk/Controller/` |
| Entity | `src/Entity/ServiceDesk/{Chamado,ChamadoInteracao,ChamadoAnexo}.php` | `src/ServiceDesk/Entity/` |
| Repository | `src/Repository/{Chamado,ChamadoInteracao,ChamadoAnexo}Repository.php` | `src/ServiceDesk/Repository/` |
| Form | `src/Form/{Chamado,ChamadoInteracao}Type.php` | `src/ServiceDesk/Form/` |
| Templates | `templates/servicedesk/*` | permanecem em `templates/servicedesk/` |

Namespace base: `App\ServiceDesk`. Módulo de permissão: `servicedesk` /
`admin.servicedesk.manage`. Prefixo de rota: mantém `servicedesk_*` (não quebrar URLs/links
existentes em notificações já gravadas).

## Contrato de comportamento (preservar)
Rotas e regras atuais — a migração NÃO pode alterá-las (salvo as correções abaixo):

| Rota | Método | Gate | Efeito |
|---|---|---|---|
| `servicedesk_index` `/` | GET | `canAdminister(admin.servicedesk.manage)` senão 403 | dashboard com filtros + estatísticas |
| `servicedesk_meus_chamados` `/meus-chamados` | GET | `canAccessModule(servicedesk)` senão redirect home+flash | lista chamados do solicitante |
| `servicedesk_novo` `/novo` | GET/POST | `canAccessModule(servicedesk)` senão redirect | cria chamado + interação de abertura + anexos + **notifica gestores (E3)**; redirect meus-chamados |
| `servicedesk_show` `/{id}` | GET | admin **ou** solicitante do chamado; senão 403 | detalhe + form de interação + (admin) lista de técnicos |
| `servicedesk_interacao` `/{id}/interacao` | POST | admin **ou** solicitante; senão 403 | cria interação (RESPOSTA se admin, COMENTARIO senão) + notifica; redirect show |
| `servicedesk_atribuir` `/{id}/atribuir` | POST | `canAdminister` senão 403 | define responsável; se ABERTO→EM_ANDAMENTO; interação ATRIBUICAO; notifica novo responsável |
| `servicedesk_status` `/{id}/status` | POST | `canAdminister` senão 403 | valida status; troca; interação STATUS; notifica solicitante |

Tipos de interação: `SISTEMA` (abertura), `COMENTARIO`/`RESPOSTA` (interação),
`ATRIBUICAO`, `STATUS`. Status: `aberto → em_andamento → resolvido → fechado`.

## UseCases planejados (E4.2)
- `AbrirChamadoUseCase` — cria chamado + interação SISTEMA + anexos + dispara notificação
  de novo chamado (absorve `notificarNovoChamado` do E3).
- `AdicionarInteracaoUseCase` — cria interação (tipo conforme papel) + notificação.
- `AtribuirChamadoUseCase` — define responsável, transição ABERTO→EM_ANDAMENTO, interação
  ATRIBUICAO, notificação.
- `AlterarStatusUseCase` — valida e troca status, interação STATUS, notificação.
A notificação continua via `NotificacaoService` (transversal, fica em `Shared`/legado por ora).

## Correções a fazer DURANTE a migração (não antes — preservar p/ test net)
1. **Bug do label de status** (`status()` legado, ~linha 358): a mensagem da interação
   monta `getStatusLabel()` **depois** do `setStatus($novoStatus)`, então o rótulo "de X"
   mostra o status novo. Capturar o label ANTERIOR antes de trocar (no `AlterarStatusUseCase`).
2. **CSRF ausente** em `atribuir` e `status`: são POST que mudam estado lendo
   `request->get()` direto, **sem token CSRF**. Adicionar `isCsrfTokenValid` + token nos
   forms do `show.html.twig` (padrão do projeto, ver `project_csrf_criterion`). O form de
   `interacao` e o de `novo` já têm CSRF (Symfony Form).
3. **`Chamado` sem tenant**: hoje o isolamento depende do `TenantContext` no request.
   Avaliar adicionar `tenant` (ManyToOne, not null) ao `Chamado` + migration, e filtrar as
   queries do `ChamadoRepository` por tenant (hoje `findAllFiltered`/`countByStatus` etc.
   **não filtram por tenant** — risco de vazamento entre escritórios no dashboard!). Decidir
   no início do E4.2; se entrar, é o item de maior risco e ganha testes dedicados.

> 🔴 **CONFIRMADO — vazamento multi-tenant (severidade ALTA):** `ChamadoRepository` NÃO
> filtra por tenant em `findAllFiltered` (dashboard + busca), `countByStatus`,
> `countByCategoria`, `findAbertosNaoAtribuidos`, `findUrgentes`, `findRecentes` e
> `getTempoMedioResolucao` (SQL puro `FROM chamado`). Um gestor de TI de um escritório vê
> os chamados de TODOS os escritórios. `findBySolicitante`/`findByResponsavel` são seguros
> (filtram por usuário). **Causa raiz:** `Chamado` não tem coluna `tenant` — não há por onde
> filtrar. **Correção exige decisão de schema** (adicionar `tenant` ao `Chamado` + migration
> + backfill via solicitante). É o item de MAIOR risco do E4 e precisa de aprovação humana
> antes de implementar. Test net: incluído um teste `markTestSkipped` documentando o
> vazamento até a correção.

## Test net (E4.1 — comportamento atual, antes de mover)
Arquivos em `tests/ServiceDesk/Functional/` (`JusPrimeWebTestCase`):
- `CriarChamadoControllerTest` (já existe, E3) — happy path do `novo`.
- `ServiceDeskFluxoControllerTest` (novo) — `show` (acesso/negação), `interacao`,
  `atribuir` (transição + notificação), `status` (troca + notificação + status inválido),
  e negações de permissão para não-gestor.
Atores: gestor = papel `isSystem`; comum = papel não-sistema sem permissão.

## Sub-etapas
- **E4.1** Spec (este doc) + test net. *(commit aditivo, não quebra nada)*
- **E4.2** UseCases + (decisão) tenant no Chamado + correções 1–3.
- **E4.3** Controller em `src/ServiceDesk/Controller/` consumindo UseCases (mantém rotas).
- **E4.4** Mover Entity/Repository/Form; ajustar namespaces e referências (forms, EntityType,
  NotificacaoService import de Chamado, query_builder do responsável).
- **E4.5** Mover templates (se necessário) / remover legado; smoke manual (abrir, interagir,
  atribuir, mudar status, dashboard) — risco MÉDIO exige `/review` antes do commit final.

## Não-objetivos
- Não mudar URLs/rotas nem o visual.
- Não alterar o `NotificacaoService` além do necessário para o import de namespace.
