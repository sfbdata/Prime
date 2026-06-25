# Progresso — Pendências do JusPrime

> Documento vivo. Ponto de retomada entre sessões. Atualizar ao fim de cada sub-etapa,
> **antes** de pedir o commit ao humano. Plano completo:
> `.claude/plans/atualize-a-questao-do-humming-newell.md`.

## Tabela mestre

| ID | Etapa | Complexidade | Status | Commit/Obs |
|----|-------|--------------|--------|------------|
| E1 | Finalizar tema escuro (ajustes de cor) | Baixa | ✅ feito | 14 trechos em 6 arquivos; lint Twig OK |
| E2 | Corrigir bug tipo notificação evento + atualizar 2 specs | Baixa | ✅ feito | lint PHP OK; 17/17 testes Notificacao |
| E3 | `notificarNovoChamado()` + conserto de bug crítico do ServiceDesk | Média | ✅ feito | suíte 705/705; revisão adversarial OK |
| H1 | Hotfix: isolamento multi-tenant do ServiceDesk (dashboard + IDOR) | Alta | ✅ feito | coluna tenant + migration + filtros + guard 404; suíte 715/715 |
| E4 | Migrar ServiceDesk → `src/ServiceDesk/` | Alta | 🟡 em andamento | E4.1 feito; E4.2 liberado (schema decidido) |
| E5 | Migrar Agenda → `src/Agenda/` | Alta | ⬜ pendente | ~2.374 linhas, sem testes |
| E6 | Migrar Ponto → `src/Ponto/` | Alta (risco ALTO) | ⬜ pendente | ~10.500 linhas, 0 testes funcionais |

Status: ⬜ pendente · 🟡 em andamento · ✅ feito · ⏭️ pulado

---

## Detalhamento por etapa

### E1 — Finalizar tema escuro ✅
Convertidas as últimas cores claras fixas → variáveis do Bootstrap. Regra aplicada:
badges com cor custom do marcador mantêm `color:#333` (legível sobre pastel em ambos os
temas); só o ramo de fallback "sem cor" usa `var(--bs-tertiary-bg)` / `var(--bs-body-color)`.

Arquivos alterados:
- `app/public/css/app.css:257` — hover logout `#fff0f0` → `var(--bs-secondary-bg)`.
- `app/templates/pasta/_tabela.html.twig` — badges desktop/mobile + popover JS (linha ~607,
  esta NÃO estava no levantamento original).
- `app/templates/expediente/_painel_marcador.html.twig:6` — badge fallback.
- `app/templates/expediente/index.html.twig` — badges em JS (popover + AJAX, 2 blocos).
- `app/templates/pasta/peticionar.html.twig` — toolbar + título do editor.
- `app/templates/_partials/modal_mover_marcador.html.twig` — `#eef2ff` → `rgba(13,110,253,.12)`
  (também fora do levantamento original; `#e9ecef` remanescente ali é sentinela de lógica, não cor).

Verificação: `lint:twig` OK nos 5 templates. **Smoke visual ainda não feito** (recomendado
abrir Expediente/Pasta/peticionar no tema escuro e conferir contraste dos badges).

### E2 — Bug notificação evento + specs ✅
- `AgendaController`: 3 literais (`'EVENTO_CRIADO'` x2, `'EVENTO_CANCELADO'` x1 — a 3ª, na
  `criarAjax`, não estava no levantamento) trocados pelas constantes
  `Notificacao::TIPO_EVENTO_CRIADO`/`..._CANCELADO`; adicionado `use App\Entity\Notificacao`.
- `docs/specs/tema-escuro.md`: cabeçalho → implementado (jun/2026).
- `docs/specs/notificacoes-link-justificativa-ponto.md`: tabela + não-objetivos corrigidos.
- Verificação: `php -l` OK; `phpunit tests/Notificacao` → 17/17.
- ⚠️ **Dívida de dados (não tratada):** notificações antigas gravadas com tipo
  `'EVENTO_CRIADO'` (maiúsculo) não casam com o mapa de ícone/categoria. São transitórias;
  não foi feita migration. Decidir depois se vale um UPDATE pontual.

### E3 — Notificação de novo chamado + conserto de bug crítico ✅
Spec: `docs/specs/servicedesk-notificacao-novo-chamado.md`. Decisão: feito cirúrgico no
legado (não migração). Entregue:
- `NotificacaoService::notificarNovoChamado(Chamado, Tenant, url)` — notifica gestores
  `admin.servicedesk.manage`, exclui o solicitante.
- `Notificacao`: `TIPO_SERVICEDESK_NOVO` (em `TIPOS_GESTAO` + ícone) e `TIPO_SERVICEDESK`
  (constante para os literais `'servicedesk'`).
- `ServiceDeskController::notificarNovoChamado()` implementado; literais padronizados.

🔴 **DESCOBERTA IMPORTANTE — ServiceDesk estava 100% quebrado (HTTP 500):** controller e
`show.html.twig` chamavam `ChamadoInteracao::setUsuario/getUsuario` e
`ChamadoAnexo::setUsuario`, métodos inexistentes (certos: `setAutor/getAutor` e
`setEnviadoPor`). Corrigidas TODAS as ocorrências. Abrir/interagir/atribuir/mudar status
davam 500 — agora funcionam. Também modernizado `ChamadoType` (deprecation `File`).

- Testes: `tests/Notificacao/Functional/NotificacaoServiceTest.php` (3 casos: gestor recebe,
  sem-permissão não recebe, solicitante excluído, **cross-tenant não vaza**, sem-gestores) +
  `tests/ServiceDesk/Functional/CriarChamadoControllerTest.php` (happy path HTTP).
- Verificação: suíte completa **705/705 OK**; revisão `feature-review-agent` endereçada
  (cross-tenant test e doc de limitação adicionados).
- ⚠️ Dívida deixada para E4: os caminhos interagir/atribuir/status só tiveram o conserto de
  método, **sem teste automatizado ainda** (entram no test net da E4). `Chamado` não tem
  tenant — persistir na migração.

### E4 — Migrar ServiceDesk → `src/ServiceDesk/` 🟡 EM ANDAMENTO
Spec: `docs/specs/servicedesk-migracao-dominio.md`.

**E4.1 ✅ feito** — spec da migração (contrato de comportamento de todas as rotas + achados)
+ rede de testes do comportamento atual em
`tests/ServiceDesk/Functional/ServiceDeskFluxoControllerTest.php` (show/acesso, interação,
atribuir, status, status inválido, negações). 9 testes (1 skipped = vazamento conhecido).

**H1 ✅ Hotfix de isolamento (decisão tomada: coluna tenant + hotfix antes da migração).**
Spec: `docs/specs/servicedesk-isolamento-tenant.md`. Entregue:
- `Chamado.tenant` (ManyToOne not null) + migration `Version20260625120342` (add nullable →
  backfill via solicitante → NOT NULL + FK/índice na convenção Doctrine).
- `ChamadoRepository`: 7 queries do dashboard agora filtram por tenant.
- `ServiceDeskController`: `novo()` grava tenant, `index()` passa tenant; guard
  `garantirChamadoDoTenant()` (404) fecha IDOR cross-tenant nas actions por ID
  (show/interacao/atribuir/status).
- `AppFixtures`: chamados de seed gravam o tenant do solicitante.
- Testes: `ChamadoRepositoryTest` (isolamento das queries) + casos cross-tenant 404 no
  `ServiceDeskFluxoControllerTest`. Suíte 715/715; `schema:validate` OK (dev+teste);
  `fixtures:load` OK. Revisão `feature-review-agent` endereçada (fixtures + spec + IDOR).

🔴 **Item de segurança AINDA ABERTO (fix separado):** anexos de chamado são servidos como
arquivo público estático (`asset('uploads/chamados/...')`) sem auth nem tenant — qualquer um
baixa anexo de qualquer escritório sabendo o nome. Precisa de rota de download com
permissão + tenant + tirar do diretório público. Candidato a hotfix dedicado.

Achados para o E4.2 (sem bloqueio): bug do label em `status()` (monta após `setStatus`) e
ausência de CSRF em `atribuir`/`status`.

Próximo passo: E4.2 (UseCases + correções de CSRF/label) — schema do tenant já resolvido.

### E5–E6
Ver plano. Não iniciadas.

---

## Como retomar

1. Ler este doc (tabela + detalhamento da etapa em 🟡).
2. Ler o plano em `.claude/plans/atualize-a-questao-do-humming-newell.md`.
3. Para E4/E5/E6, ler também a spec da migração em `docs/specs/` (criada na 1ª sub-etapa).
4. Continuar do "Próximo passo" da etapa em andamento.
