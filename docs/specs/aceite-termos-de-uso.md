# Spec — Aceite obrigatório dos Termos de Uso

## Motivo
O sistema não tem mecanismo de consentimento aos Termos e Condições de Uso. Num SaaS
jurídico, o que dá segurança jurídica é o **registro versionado do consentimento** (quem,
qual versão, quando, de onde) — não o arquivo em si. A feature implementa (1) um **gate
obrigatório** que impede o uso do sistema até o usuário aceitar a versão vigente e (2) o
**registro auditável** do aceite. Mexe no gate de autenticação por request e na identidade
do `User` → risco **ALTO**.

## Comportamento
- **Gate por request:** usuário autenticado sem aceite da versão vigente é redirecionado
  para `termo_aceite` e não acessa nenhuma outra rota protegida.
- **Versão por constante:** `App\Termo\TermoVigente::VERSAO` (ex.: `'2026-06-23'`) é o único
  ponto de verdade. Trocar a constante força todos a reaceitar no próximo request. Ao trocar,
  atualizar no mesmo commit o partial do texto e o PDF para baterem com a versão gravada.
- **Por usuário, uma vez por versão:** o termo é da plataforma, independe de tenant. O tenant
  ativo é gravado só como metadado de auditoria (nullable).
- **Idempotente:** registrar o aceite duas vezes na mesma versão não duplica linha
  (`UniqueConstraint(user, versao)`).
- **Sem bypass de super admin:** o termo vale para todos.
- **XHR/JSON:** se a request espera JSON, o gate responde **403** em vez de redirect 302, para
  não quebrar chamadas AJAX autenticadas.

## Registro (entidade `AceiteTermo`)
| Campo | Tipo | Observação |
|---|---|---|
| `id` | int autoincrement | padrão do projeto (não UUID) |
| `user` | ManyToOne User, not null | quem aceitou |
| `versao` | string(40) | versão vigente no momento do aceite |
| `aceitoEm` | datetime_immutable | timestamp |
| `ip` | string(45) | `Request::getClientIp()` |
| `userAgent` | text nullable | header `User-Agent` |
| `tenant` | ManyToOne Tenant nullable | metadado de auditoria |

`#[ORM\UniqueConstraint]` em `(user_id, versao)` — garante idempotência e indexa a leitura do gate.

## Gate (`TermoAceiteListener`, `kernel.request` prioridade 7)
Ordem em `kernel.request`: **firewall do Symfony (8) → gate de aceite (7) →
`TenantContextValidatorListener` (6)**. O gate precisa rodar **depois** do firewall — senão
`Security::getUser()` é `null` numa request real baseada em sessão (o firewall também roda em
prioridade 8 e popula o token) e o gate se autoignora — e **antes** do gate de tenant, para o
aceite da plataforma vir antes da seleção de escritório. Por isso o `TenantContextValidatorListener`
foi rebaixado de 7 para 6 (continua depois do firewall; nada mais ocupa a prioridade 6).

Ignora (retorna sem agir) quando:
- não é main request; ou `_route` começa com `_`; ou usuário não é `App\Entity\Auth\User`;
- rota ∈ `app_login`, `app_logout`, `tenant_selecionar`, `termo_aceite`, `termo_aceite_registrar`,
  `auth_aceite_convite`, `auth_aceite_convite_plataforma`, `auth_aceite_convite_criar_conta`,
  `auth_aceite_convite_aceitar`, `auth_aceite_convite_recusar`.

Verificação (com cache em sessão, evita query no caminho quente):
1. se `session('termo_aceito_versao') === VERSAO` → passa;
2. senão → `AceiteTermoRepository::existeAceiteVigente(user, VERSAO)`; se true grava o marcador
   na sessão e passa; se false → 403 (XHR/JSON) ou redirect para `termo_aceite`.

O PDF (`/termos/termos-de-uso.pdf`) e assets são estáticos (sem `_route`) → já passam.

## Tela de aceite (`TermoController`)
- `GET termo_aceite` — página **standalone** (não estende `base.html.twig`, para não expor a
  navegação): mostra o texto do termo (partial `_texto.html.twig`), checkbox "Li e aceito",
  botão, link para baixar o PDF e link de logout (para quem recusar poder sair).
- `POST termo_aceite_registrar` — valida CSRF e o checkbox; chama `RegistrarAceiteTermoUseCase`;
  grava o marcador `termo_aceito_versao` na sessão; redireciona para `homepage`. Sem checkbox →
  flash de erro e volta para `termo_aceite`.

## Fluxo de dados
`Request → TermoController → RegistrarAceiteTermoInput (user, ip, userAgent) → RegistrarAceiteTermoUseCase
→ AceiteTermo → AceiteTermoRepository → flush()`. A versão vem do `TermoVigente` injetado (UseCase
agnóstico de framework; recebe primitivos, nunca `Request`).

## Integração no convite (signup)
- `AceitarConvitePlataformaInput` e `AceitarConviteEscritorioSemContaInput` ganham `aceiteTermos`
  (bool), `ip` (string), `userAgent` (string).
- `ConviteController` captura `aceite_termos` (getBoolean), `getClientIp()`, header `User-Agent`.
- Os dois UseCases que **criam** User validam o checkbox (`\DomainException` se falso) e persistem
  `AceiteTermo` antes do `flush()` (tenant: `null` no plataforma, `$invitation->getTenant()` no
  escritório). Reusam `RegistrarAceiteTermoUseCase`.
- `AceitarConviteEscritorioComContaUseCase` **não** muda (usuário já existe → gate cobre).
- Checkbox `required` + link PDF no template `auth/convite/ver.html.twig`.

## Componentes (`src/Termo/`)
- `Entity/AceiteTermo.php`
- `TermoVigente.php` — `const VERSAO` + `getVersao()`
- `Repository/AceiteTermoRepository.php` — `existeAceiteVigente(User,string): bool`, `salvar()`
- `UseCase/RegistrarAceiteTermoUseCase.php` — idempotente
- `Controller/TermoController.php`
- `DTO/RegistrarAceiteTermoInput.php`
- `EventListener/TermoAceiteListener.php` (junto do gate de tenant existente)
- `templates/termo/aceite.html.twig` + `_texto.html.twig`
- migration em `app/migrations/`

## Fora de escopo (YAGNI)
- Tela admin / entidade de versões de termo (decisão: versão por constante).
- Aceite no fluxo `...ComConta` (gate cobre).
- Servir o PDF via rota (é estático em `public/termos/`).

## Verificação
- Unit: `RegistrarAceiteTermoUseCase` (cria registro; idempotência).
- Functional: gate (sem aceite → 302 `termo_aceite`; após POST → acessa; XHR → 403);
  `TermoController` GET/POST; convite plataforma e sem-conta marcando/sem marcar o checkbox.
- Smoke: remember-me, super admin sem tenant, multi-tenant, troca de `VERSAO` forçando reaceite,
  logout sempre acessível, sem loop de redirect.
