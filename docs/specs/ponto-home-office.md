# Spec — Home Office no ponto (bypass de geofencing por colaborador)

## Motivo
Hoje o ponto só é registrado dentro do raio de uma **Sede** do tenant (geofencing): `PontoController::batida()`
faz Haversine contra as sedes do tenant e retorna **403** se o colaborador estiver fora de todos os raios.
Quem trabalha em **home office** não consegue bater ponto. Queremos liberar a batida fora do raio para
colaboradores específicos, conforme padrões definidos pelo admin. **Risco ALTO** (ponto + bypass de
geofencing → segurança e isolamento multi-tenant inegociáveis).

## Decisões (confirmadas com o usuário)
1. Regra suporta **dias fixos da semana (recorrentes)** E **datas avulsas pontuais**.
2. **Vigência opcional** (`de/até`) em qualquer regra; sem datas = permanente. Um modelo cobre 100%, alguns dias e por período.
3. Batida remota é **SEM GPS** (não grava coordenadas).
4. Batidas remotas **entram direto** (sem aprovação por batida).

### Decisões secundárias
- **Chave = `(user, tenant)`** unique (bypass é tenant-scoped; User pode estar em vários tenants). Sem getter no `User`; acesso só via repositório com filtro de tenant explícito.
- **Datas avulsas independentes da vigência** (a vigência limita só a recorrência semanal).
- **Prioridade home office**: dia liberado → pula geofencing (não tenta casar sede).
- **Timezone de referência**: da 1ª sede do tenant, fallback `America/Sao_Paulo`.
- **Marcação**: registro remoto tem `homeOffice=true`, `sede`/`lat`/`lng` nulos; selo "Home Office" no espelho (HTML).

## Modelo
- Nova entidade `App\Ponto\Entity\HomeOfficeConfig` (implements `TenantAware`, não-`final`):
  `user` (ManyToOne nn), `tenant` (ManyToOne nn) → **unique(user, tenant)**; `diasSemana` json (int[] 1–7);
  `datasAvulsas` json (string[] `Y-m-d`); `vigenciaInicio`/`vigenciaFim` date_immutable nullable; `ativo` bool.
- `RegistroPonto`: nova coluna `homeOffice bool NOT NULL DEFAULT false`.
- Migration: `CREATE TABLE home_office_config` (unique user_id+tenant_id, FKs) + `ALTER TABLE registro_ponto ADD home_office`.

## Resolver
`App\Ponto\Service\HomeOfficeResolver::estaLiberado(User, Tenant, \DateTimeInterface): bool`:
1. Config via `HomeOfficeConfigRepository::findByUserETenant` (tenant explícito). Sem config / `ativo=false` → false.
2. Normaliza a data para o TZ de referência → `diaSemana` (`N`) e `diaStr` (`Y-m-d`).
3. Liberado se `(diaSemana ∈ diasSemana E (sem vigência OU diaStr em [inicio,fim]))` **OU** `(diaStr ∈ datasAvulsas)`.

## Enforcement (`batida()`)
- Após validar `tipo`/`$hoje`, antes da exigência de GPS e do loop de sedes: `$homeOfficeHoje = resolver->estaLiberado(user, tenantAtual, hoje)`.
- Bloco GPS + loop de sedes envolvido em `if (!$homeOfficeHoje)` (fluxo normal idêntico: sem GPS→422, fora do raio→403).
- Repouso/interjornada (não dependem de sede/GPS) extraídos para método comum e rodam nos dois caminhos.
- Registro home office: `sede=null`, sem lat/lng/precisão, `homeOffice=true`. Resposta sem `sede`/`distancia`.

## Frontend
- `index()` passa `homeOfficeHoje`; template via `data-home-office` (json_encode, nunca `|raw`).
- JS: se home office, não inicia geolocation, habilita botão só com `tipo`, POST sem coordenadas; badge "Home Office hoje". Fluxo normal intacto (servidor reautoriza sempre).
- Espelho: selo "Home Office" nos registros com `homeOffice=true`. O **cálculo** do `FolhaPontoBuilder` não muda; ele apenas ganha um campo `homeOffice` por linha (aditivo, só exibição).

## Admin
- `App\Ponto\Controller\HomeOfficeConfigController` (GET/POST/DELETE `/tenant/{tenantId}/user/{id}/home-office`),
  guards copiados de `JornadaColaboradorController`: permissão `admin.users.manage`/SUPER_ADMIN, IDOR via
  `UserTenantRepository::existeVinculoAtivo`, CSRF após autz, `tenant` sempre da URL.
- Payload `{ diasSemana[], datasAvulsas[], vigenciaInicio, vigenciaFim, ativo }`; validação server-side.
- Aba "Home Office" em `edit_user_role.html.twig` + partial `_home_office_tab.html.twig`.
- `HomeOfficeConfigRepository::findByUserETenant` com `andWhere('h.tenant = :tenant')` explícito.

## Segurança multi-tenant
- Config sempre filtrada por tenant (repo explícito; resolver nunca usa getter no User).
- Admin: IDOR (colaborador tem vínculo ativo no tenant da URL), permissão, CSRF.
- Batida resolve com o tenant atual → config do tenant A nunca libera no tenant B.

## Testes (risco ALTO)
- **Unit `HomeOfficeResolverTest`**: sem config; inativa; dia on/off; vigência dentro/fora/sem; data avulsa (mesmo fora da vigência → libera); timezone.
- **Functional batida**: home office sem GPS cria registro `homeOffice=true`/`sede=null`; não-home-office ainda 422/403; repouso/interjornada em home office; tenant sem sede.
- **Functional admin**: CRUD; permissão negada; IDOR.
- **Cross-tenant**: config de A não libera em B; admin de B não acessa user de A.
- `HomeOfficeConfigFactory` (Foundry).

## Purga de escritório
`home_office_config` é tenant-scoped → adicionada ao `ORDEM_DELECAO` do `PurgarEscritorioUseCase`
(apagada por `tenant_id`, antes do tenant). Guard-rail `PurgaCoberturaSchemaTest` cobre o drift.

## Não-objetivos
- Não altera o cálculo da folha (`FolhaPontoBuilder`) — só a exibição ganha o selo (flag `homeOffice` por linha).
- Não cria fluxo de aprovação por batida (admin autoriza via config).
