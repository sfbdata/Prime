# B5 — Frente 1: Listener da trava automática de escopo por tenant

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adicionar um listener que fixa o `TenantFilter` no `{tenantId}` da URL para qualquer rota de escritório, fechando a classe da "frestinha super-admin" sem depender de cada rota lembrar de escopar.

**Architecture:** Um `kernel.request` listener (`TenantUrlScopeListener`) roda depois do roteamento e depois do `TenantFilterListener` (prioridade 4 < 5): se a rota casada tem o atributo de request `tenantId`, ele liga o filtro `tenant` com esse id, sobrescrevendo o pin da sessão. Não autoriza nada — só restringe dados; o guard de cada controller continua barrando quem não pode.

**Tech Stack:** Symfony 7.4, PHP 8.2, Doctrine ORM 3 (SQLFilter `tenant`), PHPUnit 11 (KernelTestCase, DAMA rollback).

## Global Constraints

- Idioma pt-BR; `camelCase` métodos/vars, `PascalCase` classes, `snake_case` colunas/rotas.
- `declare(strict_types=1);` em todo arquivo PHP novo; type hints 100%; `final` em serviços/listeners; constructor property promotion `private readonly`.
- Comandos PHP/PHPUnit SEMPRE no container: `docker exec jusprime_php_dev bash -c 'cd app && ...'`. PHPUnit com `-d memory_limit=512M`.
- Git de escrita é do humano: os passos "Commit" entregam o comando em bloco `# Execute manualmente no terminal externo`; o orquestrador NÃO roda git de escrita.
- Risco ALTO (mexe no mecanismo global de escopo): após o fix, disparar `/review` (feature-review-agent) antes de seguir.
- Spec alvo: `docs/specs/super-admin-escopo-tenant.md`.

---

## File Structure

- **Create** `app/src/Shared/EventListener/TenantUrlScopeListener.php` — o listener da trava. Responsabilidade única: pinar o filtro `tenant` no `{tenantId}` da rota.
- **Create** `app/tests/Shared/Functional/TenantUrlScopeListenerTest.php` — prova comportamental (KernelTestCase): pina no tenant da URL; no-op sem `tenantId`; no-op em sub-request.

Nenhum arquivo existente é modificado nesta frente (o listener é autoconfigurado via `#[AsEventListener]`, igual ao `TenantFilterListener`).

---

### Task 1: Listener `TenantUrlScopeListener` + testes comportamentais

**Files:**
- Create: `app/src/Shared/EventListener/TenantUrlScopeListener.php`
- Test: `app/tests/Shared/Functional/TenantUrlScopeListenerTest.php`

**Interfaces:**
- Consumes: `Doctrine\ORM\EntityManagerInterface` (para `getFilters()->enable('tenant')->setParameter(...)`); `Symfony\Component\HttpKernel\Event\RequestEvent`.
- Produces: classe `App\Shared\EventListener\TenantUrlScopeListener` com `public function __invoke(RequestEvent $event): void`. Efeito: se `request.attributes['tenantId']` é dígito e é main request, o filtro Doctrine `tenant` fica habilitado com `tenant = (int) tenantId`.

- [ ] **Step 1: Escrever o teste que falha**

Cria `app/tests/Shared/Functional/TenantUrlScopeListenerTest.php`. Usa `Notificacao` (TenantAware desde o M4) como sonda: cria 1 notificação em cada tenant para o mesmo usuário; após o listener pinar no tenant A, a query só enxerga a de A.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Shared\EventListener\TenantUrlScopeListener;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A trava automática (B5) fixa o TenantFilter no {tenantId} da URL. Prova comportamental:
 * com a sonda Notificacao (TenantAware), após o listener pinar no tenant A, só os dados de A
 * são visíveis — independentemente de não haver tenant na sessão (cenário do super-admin).
 */
#[CoversClass(TenantUrlScopeListener::class)]
final class TenantUrlScopeListenerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        if ($this->em->getFilters()->isEnabled('tenant')) {
            $this->em->getFilters()->disable('tenant');
        }
    }

    #[TestDox('Com {tenantId} na rota, fixa o filtro naquele tenant (só dados do tenant da URL)')]
    public function testFixaFiltroNoTenantDaUrl(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();
        $this->criarNotificacao($user, $tenantA, 'De A');
        $this->criarNotificacao($user, $tenantB, 'De B');

        ($this->listener())($this->evento(['tenantId' => (string) $tenantA->getId()]));
        $this->em->clear();

        $notifs = $this->em->getRepository(Notificacao::class)->findAll();
        self::assertCount(1, $notifs, 'a trava deveria escopar no tenant da URL');
        self::assertSame('De A', $notifs[0]->getTitulo());
    }

    #[TestDox('Sem {tenantId} na rota, não pina nada (filtro permanece desligado)')]
    public function testSemTenantIdNaoPina(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();
        $this->criarNotificacao($user, $tenantA, 'De A');
        $this->criarNotificacao($user, $tenantB, 'De B');

        ($this->listener())($this->evento([]));
        $this->em->clear();

        self::assertCount(2, $this->em->getRepository(Notificacao::class)->findAll(), 'sem {tenantId} o filtro não pode pinar');
    }

    #[TestDox('Em sub-request, não faz nada')]
    public function testSubRequestNaoPina(): void
    {
        $tenantA = $this->criarTenant();
        $user = $this->criarUser();
        $this->criarNotificacao($user, $tenantA, 'De A');

        $evento = new RequestEvent(
            self::$kernel,
            $this->requestComAtributos(['tenantId' => (string) $tenantA->getId()]),
            HttpKernelInterface::SUB_REQUEST,
        );
        ($this->listener())($evento);

        self::assertFalse($this->em->getFilters()->isEnabled('tenant'), 'sub-request não pode pinar');
    }

    // ----------------------------------------------------------------- helpers

    private function listener(): TenantUrlScopeListener
    {
        return new TenantUrlScopeListener($this->em);
    }

    private function evento(array $attrs): RequestEvent
    {
        return new RequestEvent(self::$kernel, $this->requestComAtributos($attrs), HttpKernelInterface::MAIN_REQUEST);
    }

    private function requestComAtributos(array $attrs): Request
    {
        $request = new Request();
        foreach ($attrs as $k => $v) {
            $request->attributes->set($k, $v);
        }

        return $request;
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Trava ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('trava_' . uniqid() . '@test.com');
        $user->setFullName('User Trava');
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function criarNotificacao(User $user, Tenant $tenant, string $titulo): void
    {
        $notif = new Notificacao();
        $notif->setUsuario($user);
        $notif->setTenant($tenant);
        $notif->setTipo(Notificacao::TIPO_TAREFA_CRIADA);
        $notif->setTitulo($titulo);
        $this->em->persist($notif);
        $this->em->flush();
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit tests/Shared/Functional/TenantUrlScopeListenerTest.php'`
Expected: FAIL — `Class "App\Shared\EventListener\TenantUrlScopeListener" not found`.

- [ ] **Step 3: Implementar o listener (mínimo para passar)**

Cria `app/src/Shared/EventListener/TenantUrlScopeListener.php`:

```php
<?php

declare(strict_types=1);

namespace App\Shared\EventListener;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Trava automática de escopo por escritório (B5): quando a rota casada tem o parâmetro
 * {tenantId}, fixa o TenantFilter NESSE tenant — independentemente do tenant da sessão.
 *
 * Roda DEPOIS do roteamento (atributos de rota já populados) e DEPOIS do TenantFilterListener
 * (prioridade 5), por isso prioridade 4: sobrescreve o pin da sessão com o tenant da URL nas
 * rotas de escritório. Em rotas de plataforma (sem {tenantId}) é inerte → visão global do
 * super-admin preservada.
 *
 * NÃO autoriza nada — só restringe (escopa) os dados. O guard de cada controller continua
 * rejeitando quem não pode operar naquele tenant; fixar o filtro num tenant nunca concede acesso.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final class TenantUrlScopeListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $tenantId = $event->getRequest()->attributes->get('tenantId');
        if ($tenantId === null || !ctype_digit((string) $tenantId)) {
            return;
        }

        $this->em->getFilters()
            ->enable('tenant')
            ->setParameter('tenant', (int) $tenantId, Types::INTEGER);
    }
}
```

- [ ] **Step 4: Rodar o teste e confirmar que passa**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit tests/Shared/Functional/TenantUrlScopeListenerTest.php'`
Expected: PASS (3 testes).

- [ ] **Step 5: Mutação — confirmar que os testes pegam a regressão**

Neutralizar a pinagem (trocar `->enable('tenant')` por `->disable('tenant')` temporariamente) e rodar: o teste `testFixaFiltroNoTenantDaUrl` deve FICAR VERMELHO. Reverter em seguida.

Run (mutação): editar o listener, rodar o arquivo de teste, confirmar 1 falha, reverter.
Expected: 1 failure com a mutação; 3 PASS após reverter.

- [ ] **Step 6: Suíte completa (sem regressão no resto)**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit'`
Expected: tudo verde (baseline atual 876 + 3 novos = 879). **Atenção:** se algum teste existente quebrar, é sinal de que o listener pinou indevidamente numa rota que tem `{tenantId}` mas não devia — investigar antes de prosseguir (ALTO risco).

- [ ] **Step 7: `/review` (risco ALTO)**

Disparar o `feature-review-agent` contra o diff (listener + teste), alvo `docs/specs/super-admin-escopo-tenant.md`. Focar: o listener pina cedo demais e vaza alguma query escopada antes do guard? Há rota `{tenantId}` que NÃO deveria ser escopada (onde pinar quebra função)? A prioridade 4 garante rodar após roteamento (32) e após `TenantFilterListener` (5)? Endereçar achados antes do commit.

- [ ] **Step 8: Commit (entregar comando ao humano)**

```bash
# Execute manualmente no terminal externo
cd /home/prime/projetos/jusprime
git add \
  app/src/Shared/EventListener/TenantUrlScopeListener.php \
  app/tests/Shared/Functional/TenantUrlScopeListenerTest.php
git commit -m "Trava automatica: escopar filtro no {tenantId} da rota (B5 frente 1)"
```

---

## Sequência das próximas frentes (registro — fora deste plano)

- **Frente 2:** padronizar as 5 rotas legadas `{id}`→`{tenantId}` (`app_tenant_show/edit/delete/users/sedes`) + ~23 callers → a trava markerless passa a cobri-las.
- **Frente 3:** remendo explícito (`escoparFiltroNoTenant`) nas rotas que o B5 citou (`listUsers`, `downloadAnexoJustificativa`, `removeResourceAccess`) + testes por-rota de não-vazamento para super-admin.
- **Frente 4:** B3 completo — `ResourceAccess` TenantAware (migration, 🔴 FREIO), agora destravado.

## Self-Review (writing-plans)

- **Cobertura da spec (frente 1):** o listener (mecanismo 1 da spec) está na Task 1; teste de regressão da classe (comportamental) idem. Frentes 2-4 explicitamente fora, registradas na sequência. ✓
- **Placeholders:** nenhum — código completo no listener e nos 3 testes. ✓
- **Consistência de tipos:** `TenantUrlScopeListener::__invoke(RequestEvent): void`, construtor `(EntityManagerInterface $em)` — usados igual no teste (`new TenantUrlScopeListener($this->em)`). Sonda `Notificacao` usa API real (`setUsuario/setTenant/setTipo/setTitulo`, `TIPO_TAREFA_CRIADA`). ✓
- **Risco ALTO:** Step 6 alerta para regressão de rota `{tenantId}` indevida; Step 7 exige `/review`. ✓
