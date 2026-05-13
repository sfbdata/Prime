# Levantamento de referências legadas — 13/05/2026

> Atualização do escopo para Fase 5c.3e. Não substitui `5c-levantamento.md` (11/05/2026).

> **Revisão 13/05/2026:** 2 refs reclassificadas (TenantController L475–476: REGRESSÃO → ESPERADO);
> 4 refs promovidas de Apêndice 7 para PENDENTE (AceitarConviteEscritorioComContaUseCase L58–62).
> Total PENDENTE: 18→22 refs. REGRESSÃO: 2→0. Ver histórico nas seções 2, 5, 7.

---

## 0. Metadados

- **Commit HEAD:** `e768d3e`
- **Branch:** `refactor/etapa-5-identidade-global`
- **Data de geração:** 13/05/2026

### Estado das fases no doc mestre

| Fase | Bloco estruturado | Arquivos explícitos | Status |
|---|---|---|---|
| 5c.1 | ✅ Sim | 3 | ✅ Concluída |
| 5c.2 | ✅ Sim | 2 | ✅ Concluída |
| 5c.3a | ✅ Sim | 11 mod + 2 não-mod | ✅ Concluída |
| **5c.3b** | **❌ Apenas audit notes** | **Não listados** | **Concluída (audit notes)** |
| **5c.3c** | **❌ Apenas audit notes** | **Não listados** | **Concluída (audit notes)** |
| Lotes 1–3 | ✅ Sim | 7 controllers + PastaController parcial | ✅ Concluídos |
| Lote 4a | ✅ Sim | PontoController | ✅ Concluído |
| Lote 4b (4b.1–4b.6b) | ✅ Audit por sub-lote | TenantController (ações específicas) | ✅ Concluído |

### Nota sobre 5c.3b e 5c.3c

5c.3b e 5c.3c foram concluídas operacionalmente (audit notes em pretérito no doc mestre)
mas **não possuem bloco de histórico estruturado** em "Histórico de operações em ambiente".
Ver Apêndice 9 — Pendência de documentação.

### Descoberta sobre o levantamento original (11/05)

O levantamento original usou o padrão amplo `->getTenant()` (sem filtro de receiver), capturando
**chamadas de método em entidades ORM** (ex: `$marcador->getTenant()`, `$pasta->getTenant()`)
que **não irão quebrar com a remoção de colunas da tabela `user`**. O presente levantamento
filtra por receiver (`$user`, `$currentUser`, `$usuario`, `$this->getUser()`) para capturar
apenas referências que realmente quebrarão na Etapa 6. Isso reduz significativamente o escopo
real em relação aos 46 arquivos do levantamento anterior.

### Nota sobre ExpedienteController

O doc mestre listava `ExpedienteController.php:39` como pré-requisito bloqueante da Etapa 6
(`$usuario->getTenant()`). **A nota está desatualizada**: L39 é atualmente injeção de
`PermissionChecker` no construtor, e L49 usa `$this->assertAccess($user)` (padrão correto).
5c.3c foi aplicada com sucesso. ExpedienteController está limpo.

---

## 1. Sumário

| Categoria | REGRESSÃO | PENDENTE | AMBÍGUO | ESPERADO | FALSO POSITIVO |
|---|---|---|---|---|---|
| C1 — Tenant via User | 0 | 14 refs / 6 arq | 1 (comment) | 5 refs (TenantCtrl) | 0 |
| C2 — Campos migrados | 0 | 3 refs / 2 arq | 0 | 2 refs (TenantCtrl) | 4 (PontoCtrl null-safe) |
| C3 — Twig | 0 | 4 refs / 2 arq | 0 | 0 | 0 |
| C4 — SQL/DQL bruto | 0 | 1 ref / 1 arq | 1 (comment) | 10 refs / 2 migr | 0 |
| **TOTAL** | **0** | **22 refs / 11 arq** | **2** | **17** | **4** |

**Total PENDENTE real (exclui ESPERADO e FALSO POSITIVO): 22 refs em 11 arquivos.**

---

## 2. ⚠️ REGRESSÕES

Nenhuma regressão detectada.

> **Revisão (13/05/2026):** TenantController L475–476 (`getCodigoFuncionario`/`setCodigoFuncionario`)
> reclassificadas como ESPERADO após verificação: ambas estão dentro do método `editUserRole`
> (range L425–L583), mesmo bloco deferido que L468/470/525/536. O A2 original rastreava 5 refs;
> leitura direta do arquivo confirmou 7 refs no método. Ver Seção 5.

---

## 3. PENDENTES

Ordenado por número de refs (decrescente). Path completo + linha + trecho.

### `app/src/Auth/UseCase/AceitarConviteEscritorioComContaUseCase.php` — 4 refs (C1, via DTO)

Receiver: `$input->usuarioAtual` (objeto `User` declarado em `AceitarConviteEscritorioComContaInput.php:13`
como `public User $usuarioAtual`). Não capturado pelo grep C1 (filtro de receiver). **Revisão 13/05/2026:**
promovido de Apêndice 7 para PENDENTE após verificação do tipo da propriedade DTO.

| Linha | Trecho |
|---|---|
| 58 | `if ($input->usuarioAtual->getTenant() === null) {` |
| 59 | `$input->usuarioAtual->setTenant($tenant);` |
| 61 | `if ($invitation->getTenantRole() !== null && $input->usuarioAtual->getTenantRole() === null) {` |
| 62 | `$input->usuarioAtual->setTenantRole($invitation->getTenantRole());` |

### `app/src/Entity/Tenant/Tenant.php` — 3 refs (C1)

Métodos `addUser()`/`removeUser()` da entidade Tenant que mantêm a associação bidirecional ORM.
Quando `user.tenant_id` for removido na Etapa 6, esses métodos quebrarão.

| Linha | Trecho |
|---|---|
| 239 | `$user->setTenant($this);` |
| 248 | `if ($user->getTenant() === $this) {` |
| 249 | `$user->setTenant(null);` |

### `app/src/Command/MigrateLegatyRolesCommand.php` — 3 refs (C1)

| Linha | Trecho |
|---|---|
| 215 | `if ($user->getTenantRole() !== null) {` |
| 219 | `$user->getTenantRole()->getName()` |
| 242 | `$user->setTenantRole($targetRole);` |

### `app/src/Profile/DTO/PerfilOutput.php` — 2 refs (C2)

| Linha | Trecho |
|---|---|
| 37 | `cargo: $user->getCargo()?->getNome(),` |
| 38 | `lotacao: $user->getLotacao()?->getNome(),` |

### `app/src/Auth/UseCase/AceitarConviteEscritorioSemContaUseCase.php` — 2 refs (C1)

Nota: linha 67 (`// Mantemos user.tenant_id em sincronia...`) também aparece no C4 mas é
apenas comentário sobre o código das linhas abaixo; não conta separadamente.

| Linha | Trecho |
|---|---|
| 68 | `$user->setTenant($tenant);` |
| 70 | `$user->setTenantRole($invitation->getTenantRole());` |

### `app/templates/layout_peticionar.html.twig` — 2 refs (C3)

| Linha | Trecho |
|---|---|
| 829 | `{{ app.user.lastLogin is defined and app.user.lastLogin` |
| 830 | `? app.user.lastLogin\|date('d/m/Y - H:i:s')` |

### `app/templates/base.html.twig` — 2 refs (C3)

| Linha | Trecho |
|---|---|
| 175 | `{{ app.user.lastLogin is defined and app.user.lastLogin` |
| 176 | `? app.user.lastLogin\|date('d/m/Y - H:i:s')` |

### `app/src/DataFixtures/AppFixtures.php` — 1 ref (C1)

| Linha | Trecho |
|---|---|
| 132 | `$user->setTenant($tenant);` |

Nota: doc mestre lista "Refatorar fixtures pra criarem UserTenant" como pendência de auditoria.

### `app/src/Tenant/Controller/TenantUserProfileController.php` — 1 ref (C1)

| Linha | Trecho |
|---|---|
| 41 | `$isOwnTenant  = $currentUser->getTenant()?->getId() === $tenantId;` |

### `app/src/Security/UserAuthenticator.php` — 1 ref (C2)

| Linha | Trecho |
|---|---|
| 59 | `$user->setLastLogin(new \DateTimeImmutable());` |

### `app/migrations/Version20260428124222.php` — 1 ref (C4)

Migração histórica de abril de 2026, já aplicada. **Não quebra ambientes existentes.**
Em criação de banco fresco pós-Etapa 6, o down() pode falhar se referência à coluna removida.

| Linha | Trecho |
|---|---|
| 36 | `AND u.codigo_funcionario IS NULL` |

---

## 4. AMBÍGUOS

### `app/src/Auth/UseCase/AceitarConviteEscritorioComContaUseCase.php`

| Linha | Cat | Trecho | Motivo |
|---|---|---|---|
| 55 | C1 | `// TODO Etapa 6: remover após refatoração das referências legadas a $user->getTenant().` | Match em comentário; código real (L58–62) usa receiver `$input->usuarioAtual` não coberto pelo filtro C1. Ver Apêndice 7. |
| 56 | C4 | `// Mantemos user.tenant_id em sincronia com UserTenant durante a transição.` | Match em comentário. Código real em L59 (`$input->usuarioAtual->setTenant()`) não capturado pelo grep. |

**Ação sugerida (atualizado 13/05):** L58–62 verificados e promovidos para Seção 3 (PENDENTES).
DTO confirmado como `public User $usuarioAtual`. Comentários L55/L56 permanecem AMBÍGUOS
(match em texto de comentário, não em código executável).

---

## 5. ESPERADOS

### TenantController — refs deferidas documentadas (A2 do doc mestre)

| Arquivo | Linha | Trecho | Motivo |
|---|---|---|---|
| `app/src/Controller/TenantController.php` | 468 | `$targetTenantId = $isSuperAdmin ? ($user->getTenant()?->getId() ?? $tenantId) : $tenantId;` | Deferred editUserRole — L468 |
| `app/src/Controller/TenantController.php` | 470 | `$targetTenant   = $user->getTenant();` | Deferred editUserRole — L470 |
| `app/src/Controller/TenantController.php` | 525 | `$jornadaTenant = $user->getTenant()?->getJornadaTenant();` | Deferred editUserRole — L525 |
| `app/src/Controller/TenantController.php` | 536 | `$feriados = $user->getTenant() !== null ? $feriadoRepository->findByTenant($user->getTenant()) : [];` | Deferred editUserRole — L536×2 |
| `app/src/Controller/TenantController.php` | 475 | `if ($user->getCodigoFuncionario() === null && $targetTenant !== null) {` | Deferred editUserRole — range L425–L583 (revisão 13/05) |
| `app/src/Controller/TenantController.php` | 476 | `$user->setCodigoFuncionario($gerarCodigo->executar($targetTenant));` | Deferred editUserRole — range L425–L583 (revisão 13/05) |

### Migrations da refatoração

| Arquivo | Refs (C4) | Motivo |
|---|---|---|
| `app/migrations/Version20260507160000.php` | 9 | Migração de dados user→user_tenant (Etapa 2) |
| `app/migrations/Version20260509000000.php` | 1 | Invitation table + `u.tenant_id` em SELECT |

---

## 6. Apêndice — Arquivos refatorados confirmados limpos

Zero matches em C1/C2/C3/C4 após execução dos greps.

### [REFATORADO_COMPLETO]

| Arquivo | Fase | Obs |
|---|---|---|
| `app/templates/_sidebar.html.twig` | 5c.1 | ✅ |
| `app/templates/tenant/index.html.twig` | 5c.1 | ✅ |
| `app/src/Controller/Tenant/TenantSelecaoController.php` | 5c.2 | ✅ |
| `app/src/Kanban/UseCase/ListarBoardsUseCase.php` | 5c.3a | ✅ |
| `app/src/Kanban/UseCase/CriarBoardUseCase.php` | 5c.3a | ✅ |
| `app/src/Kanban/UseCase/AtualizarBoardUseCase.php` | 5c.3a | ✅ |
| `app/src/Kanban/UseCase/AtualizarCardUseCase.php` | 5c.3a | ✅ |
| `app/src/Kanban/Controller/KanbanBoardController.php` | 5c.3a | ✅ |
| `app/src/Kanban/Controller/KanbanCardController.php` | 5c.3a | ✅ |
| `app/src/Kanban/Controller/KanbanComentarioController.php` | 5c.3a | ✅ |
| `app/src/Kanban/Controller/KanbanAnexoController.php` | 5c.3a | ✅ |
| `app/src/Kanban/Controller/KanbanMarcadorController.php` | 5c.3a | ✅ |
| `app/src/Kanban/Controller/KanbanChecklistController.php` | 5c.3a | ✅ |
| `app/src/Controller/AuditLogController.php` | Lote 1 | ✅ |
| `app/src/Controller/FeriadoController.php` | Lote 1 | ✅ |
| `app/src/Controller/JornadaTenantController.php` | Lote 1 | ✅ |
| `app/src/Controller/TenantRoleController.php` | Lote 2 | ✅ |
| `app/src/Controller/AccessRequestController.php` | Lote 2 | ✅ |
| `app/src/Controller/JornadaColaboradorController.php` | Lote 2 | ✅ |
| `app/src/Controller/TarefaController.php` | Lote 3 | ✅ |
| `app/src/Controller/PontoController.php` | Lote 4a | ✅ (C2 teve 4 falsos positivos — ver abaixo) |

**PontoController — falsos positivos C2:** O grep C2 com lookbehind `(?<!\$userTenant)` não
cobriu o operador null-safe `?->`. Linhas 814, 815, 921, 950 usam `$userTenant?->getCargo()`,
`$userTenant?->getLotacao()`, `$userTenant?->getCodigoFuncionario()` — padrão **correto**
da Etapa 4a. São falsos positivos; o arquivo está limpo.

### [REFATORADO_PARCIAL]

| Arquivo | Fase | Obs |
|---|---|---|
| `app/src/Controller/PastaController.php` | Lote 3 | Zero matches C1/C2/C3/C4. Nota: $responsavel->getTenant() em L775 não coberto pelo grep — ver Apêndice 7. |

### [REFATORADO_SEM_BLOCO_ESTRUTURADO] — confirmados limpos

Pasta/UseCase/ (23 arquivos) e Expediente/UseCase/ (4 arquivos) tiveram zero matches C1.
O levantamento original (11/05) listava esses arquivos por usarem `->getTenant()` em
**entidades ORM** (ex: `$marcador->getTenant()`), não em `$user`. Com o filtro de receiver
atual, confirma-se que nunca tiveram o problema de `$user->getTenant()`.

| Diretório | Arquivos | C1 matches | Conclusão |
|---|---|---|---|
| `app/src/Pasta/UseCase/` | 23 | 0 | Limpos — nunca tiveram User.getTenant() direto |
| `app/src/Expediente/UseCase/` | 4 | 0 | Limpos — idem |
| `app/src/Expediente/Controller/ExpedienteController.php` | 1 | 0 | Limpo — 5c.3c refatorou L39 (agora TenantContext) |

---

## 7. Apêndice — Receivers não cobertos pelo grep C1

Referências a `getTenant`/`setTenant`/`getTenantRole`/`setTenantRole` em **User** usando
variáveis não incluídas no filtro de receiver. Aguardam decisão sobre segunda passada.

> **Revisão (13/05/2026):** `AceitarConviteEscritorioComContaUseCase.php` foi **promovido
> para Seção 3 (PENDENTES)** após verificação do tipo da propriedade DTO (Checks 3–5).
> `$input->usuarioAtual` é `public User $usuarioAtual` — sem ambiguidade de tipo.
> Demais UseCases Auth verificados (`SemConta`, `CriarConvitePlataforma`, `CriarConviteEscritorio`)
> não chamam métodos legados via propriedades DTO User.

### `app/src/Controller/PastaController.php:775` — 1 ref citada no doc mestre

Doc mestre menciona: `$responsavel->getTenant()` em L775 como ref que quebrará na Etapa 6.
C1 não capturou (receiver `$responsavel` fora do filtro). Verificar manualmente.

---

## 8. Apêndice — Segunda passada e gaps conhecidos

### Gaps de escopo neste levantamento

1. **`app/tests/` não coberto pelos greps.** Arquivos relevantes:
   - `app/tests/Pasta/Functional/PastaSecaoControllerTest.php` — 5c.3b implementou helper `logarComTenant()` aqui
   - `app/tests/Functional/DeleteSedeCrossTenantTest.php` — usa `User::setTenant()` (method legado em User)
   - Doc mestre: "Inventariar todos os testes funcionais que usam `setTenant` antes de remover o campo na Etapa 6"
   - Grep sugerido: `rg -n -t php 'setTenant' app/tests/`

2. **`app/src/Controller/PastaController.php:775`** — `$responsavel->getTenant()` (receiver não coberto). Verificar manualmente.

3. **`AceitarConviteEscritorioComContaUseCase.php:58-62`** — verificado e promovido para Seção 3 (PENDENTES) em 13/05/2026. Não é mais um gap.

### Patterns sugeridos para checagem complementar (não executados)

```bash
# Receivers não-padrão que podem ser User
rg -n -t php 'usuarioAtual->setTenant\|usuarioAtual->getTenant\|usuarioAtual->setTenantRole\|usuarioAtual->getTenantRole' app/src/

# Receivers curtos suspeitos ($u, $a, $adv)
rg -n -t php '\$u->getTenant\(\)\|\$a->getTenant\(\)' app/src/

# Form/DTO mascarando User
rg -n -t php 'getCargo\(\)\|getLotacao\(\)\|getCodigoFuncionario\(\)' app/src/ -g '*DTO*' -g '*Form*'

# Testes com setTenant em User
rg -n -t php 'setTenant\(' app/tests/
```

---

## 9. Apêndice — Pendência de documentação identificada

### Fases sem bloco estruturado no Histórico de operações

| Fase | Audit note confirma conclusão | Bloco estruturado |
|---|---|---|
| 5c.3b | ✅ ("foram removidos", "precisou implementar") | ❌ Ausente |
| 5c.3c | ✅ ("5c.3c adiciona apenas assertAccess()") | ❌ Ausente |

Ação sugerida (sprint dedicada, não agora): consolidar com git log antes de fechar 5c.

```bash
git log --oneline refactor/etapa-5-identidade-global --grep="5c\.3b\|5c\.3c"
```

### Nota desatualizada no doc mestre

A seção "Pré-requisitos bloqueantes da Etapa 6" lista:

> `app/src/Expediente/Controller/ExpedienteController.php:39`: usa `$usuario->getTenant()`

Esta nota está **desatualizada**. ExpedienteController.php L39 é atualmente injeção de
`PermissionChecker` no construtor; a refatoração 5c.3c está aplicada. A nota deve ser
removida da seção de pré-requisitos bloqueantes no doc mestre.
