# Fase 5b.1 — Domain Layer: UseCases + Testes Unitários

Parte da sub-etapa 5b (Fluxos de Convite) da refatoração de identidade global.
Documento de referência maior: `docs/refatoracao-identidade-global.md`

---

## Ordem de execução

```
1. Métodos de repositório  (InvitationRepository + UserTenantRepository)
2. 7 DTOs                  (todos readonly class, sem dependências entre si)
3. UseCases + testes, nesta ordem crescente de complexidade:
   a. RevogarConviteUseCase                     — 1 dep, sem email check
   b. RecusarConviteEscritorioUseCase            — 1 dep, adiciona email check
   c. CriarConvitePlataformaUseCase              — 2 deps, cria Invitation
   d. CriarConviteEscritorioUseCase              — 3 deps, adiciona vínculo check
   e. AceitarConviteEscritorioComContaUseCase    — aceite sem password hashing
   f. AceitarConviteEscritorioSemContaUseCase    — adiciona criação de User + UserTenant
   g. AceitarConvitePlataformaUseCase            — mais complexo: OAB + User
```

Para cada UseCase: escrever o teste antes de implementar.

Verificação ao final:
```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Auth/Unit/'
```

---

## Passo 1 — Métodos de repositório

### `app/src/Repository/InvitationRepository.php` — 2 métodos novos

```php
public function encontrarPorToken(string $token): ?Invitation
{
    return $this->findOneBy(['token' => $token]);
}

public function encontrarPendentesPorEmail(string $email): array
{
    return $this->createQueryBuilder('i')
        ->andWhere('i.email = :email')
        ->andWhere('i.status = :status')
        ->andWhere('i.expiresAt > :now')
        ->setParameter('email', $email)
        ->setParameter('status', 'pending')
        ->setParameter('now', new \DateTimeImmutable())
        ->orderBy('i.createdAt', 'DESC')
        ->getQuery()
        ->getResult();
}
```

### `app/src/Repository/UserTenantRepository.php` — 1 método novo

```php
public function existeVinculoAtivo(User $user, Tenant $tenant): bool
{
    return $this->createQueryBuilder('ut')
        ->select('COUNT(ut.id)')
        ->andWhere('ut.user = :user')
        ->andWhere('ut.tenant = :tenant')
        ->andWhere('ut.isActive = true')
        ->setParameter('user', $user)
        ->setParameter('tenant', $tenant)
        ->getQuery()
        ->getSingleScalarResult() > 0;
}
```

---

## Passo 2 — DTOs

Todos em `app/src/Auth/DTO/`, todos `readonly class` com constructor promotion.

### `CriarConvitePlataformaInput.php`

```php
<?php
declare(strict_types=1);

namespace App\Auth\DTO;

use App\Entity\Auth\User;

readonly class CriarConvitePlataformaInput
{
    public function __construct(
        public string $email,
        public ?string $fullName,
        public User $criadoPor,
    ) {}
}
```

### `CriarConviteEscritorioInput.php`

```php
<?php
declare(strict_types=1);

namespace App\Auth\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;

readonly class CriarConviteEscritorioInput
{
    public function __construct(
        public string $email,
        public ?string $fullName,
        public Tenant $tenant,
        public TenantRole $tenantRole,
        public User $criadoPor,
    ) {}
}
```

### `AceitarConvitePlataformaInput.php`

```php
<?php
declare(strict_types=1);

namespace App\Auth\DTO;

readonly class AceitarConvitePlataformaInput
{
    public function __construct(
        public string $token,
        public string $fullName,
        public string $senha,
        public string $oabNumero,
        public string $oabUf,
    ) {}
}
```

### `AceitarConviteEscritorioSemContaInput.php`

```php
<?php
declare(strict_types=1);

namespace App\Auth\DTO;

readonly class AceitarConviteEscritorioSemContaInput
{
    public function __construct(
        public string $token,
        public string $fullName,  // string vazia '' se não preenchido pelo usuário
        public string $senha,
    ) {}
}
```

### `AceitarConviteEscritorioComContaInput.php`

```php
<?php
declare(strict_types=1);

namespace App\Auth\DTO;

use App\Entity\Auth\User;

readonly class AceitarConviteEscritorioComContaInput
{
    public function __construct(
        public string $token,
        public User $usuarioAtual,
    ) {}
}
```

### `RecusarConviteEscritorioInput.php`

```php
<?php
declare(strict_types=1);

namespace App\Auth\DTO;

use App\Entity\Auth\User;

readonly class RecusarConviteEscritorioInput
{
    public function __construct(
        public string $token,
        public User $usuarioAtual,
    ) {}
}
```

### `RevogarConviteInput.php`

```php
<?php
declare(strict_types=1);

namespace App\Auth\DTO;

readonly class RevogarConviteInput
{
    public function __construct(
        public string $token,
    ) {}
}
```

---

## Passo 3a — `RevogarConviteUseCase`

### `app/src/Auth/UseCase/RevogarConviteUseCase.php`

```php
<?php
declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\RevogarConviteInput;
use App\Entity\Auth\Invitation;
use App\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;

final class RevogarConviteUseCase
{
    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(RevogarConviteInput $input): Invitation
    {
        $invitation = $this->invitationRepository->encontrarPorToken($input->token);

        if ($invitation === null) {
            throw new \DomainException('Convite não encontrado.');
        }

        if ($invitation->getStatus() !== 'pending') {
            throw new \DomainException('Apenas convites pendentes podem ser revogados.');
        }

        $invitation->revogar();
        $this->em->flush();

        return $invitation;
    }
}
```

### Cenários de teste — `app/tests/Auth/Unit/RevogarConviteUseCaseTest.php`

| # | Cenário | Resultado esperado |
|---|---|---|
| 1 | Token válido, status=pending | `revogar()` chamado, `flush()` chamado, retorna Invitation |
| 2 | Token não encontrado | `\DomainException` "não encontrado" |
| 3 | Status = accepted | `\DomainException` "apenas pendentes" |
| 4 | Status = rejected | `\DomainException` "apenas pendentes" |
| 5 | Status = revoked | `\DomainException` "apenas pendentes" |
| 6 | Status = expired | `\DomainException` "apenas pendentes" |

---

## Passo 3b — `RecusarConviteEscritorioUseCase`

### `app/src/Auth/UseCase/RecusarConviteEscritorioUseCase.php`

```php
<?php
declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\RecusarConviteEscritorioInput;
use App\Entity\Auth\Invitation;
use App\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;

final class RecusarConviteEscritorioUseCase
{
    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(RecusarConviteEscritorioInput $input): Invitation
    {
        $invitation = $this->invitationRepository->encontrarPorToken($input->token);

        if ($invitation === null) {
            throw new \DomainException('Convite não encontrado.');
        }

        if ($invitation->getStatus() !== 'pending') {
            throw new \DomainException('Este convite não pode mais ser recusado.');
        }

        if ($invitation->isExpired()) {
            throw new \DomainException('Este convite está expirado.');
        }

        if (strtolower($invitation->getEmail()) !== strtolower((string) $input->usuarioAtual->getEmail())) {
            throw new \DomainException('Este convite pertence a outro usuário.');
        }

        $invitation->recusar();
        $this->em->flush();

        return $invitation;
    }
}
```

### Cenários de teste — `app/tests/Auth/Unit/RecusarConviteEscritorioUseCaseTest.php`

| # | Cenário | Resultado esperado |
|---|---|---|
| 1 | Token válido, email bate, não expirado | `recusar()` chamado, retorna Invitation |
| 2 | Token não encontrado | `\DomainException` |
| 3 | Status != pending (ex: accepted) | `\DomainException` "não pode mais ser recusado" |
| 4 | Invitation expirada (`expiresAt` no passado) | `\DomainException` "expirado" |
| 5 | Email da invitation ≠ email do usuário | `\DomainException` "pertence a outro usuário" |
| 6 | Email difere só em case (`TEST@` vs `test@`) | Aceita — normalização funciona |
| 7 | `flush()` não é chamado quando há exceção | Nenhum efeito colateral |

---

## Passo 3c — `CriarConvitePlataformaUseCase`

### `app/src/Auth/UseCase/CriarConvitePlataformaUseCase.php`

```php
<?php
declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\CriarConvitePlataformaInput;
use App\Entity\Auth\Invitation;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final class CriarConvitePlataformaUseCase
{
    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(CriarConvitePlataformaInput $input): Invitation
    {
        $email = strtolower(trim($input->email));

        if ($this->userRepository->findOneBy(['email' => $email]) !== null) {
            throw new \DomainException('Já existe uma conta com este e-mail.');
        }

        $invitation = new Invitation(
            email: $email,
            token: bin2hex(random_bytes(32)),
            type: 'platform',
            expiresAt: new \DateTimeImmutable('+24 hours'),
        );
        $invitation->setFullName($input->fullName);
        $invitation->setCreatedBy($input->criadoPor);

        $this->em->persist($invitation);
        $this->em->flush();

        return $invitation;
    }
}
```

### Cenários de teste — `app/tests/Auth/Unit/CriarConvitePlataformaUseCaseTest.php`

| # | Cenário | Resultado esperado |
|---|---|---|
| 1 | Email novo | `type='platform'`, `expiresAt` ≈ now+24h, `persist()` e `flush()` chamados |
| 2 | Email já existe como User | `\DomainException` "Já existe uma conta" |
| 3 | Email com espaços e maiúsculas (`" TEST@X.COM "`) | Normalizado para `test@x.com` antes da query e na Invitation |
| 4 | `fullName` nulo | Aceita — campo opcional |
| 5 | `fullName` preenchido | `$invitation->getFullName()` retorna o valor |
| 6 | Token gerado tem 64 chars | `strlen($invitation->getToken()) === 64` |
| 7 | `criadoPor` propagado | `$invitation->getCreatedBy() === $input->criadoPor` |

---

## Passo 3d — `CriarConviteEscritorioUseCase`

### `app/src/Auth/UseCase/CriarConviteEscritorioUseCase.php`

```php
<?php
declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\CriarConviteEscritorioInput;
use App\Entity\Auth\Invitation;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use Doctrine\ORM\EntityManagerInterface;

final class CriarConviteEscritorioUseCase
{
    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly UserRepository $userRepository,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(CriarConviteEscritorioInput $input): Invitation
    {
        $email = strtolower(trim($input->email));

        $existingUser = $this->userRepository->findOneBy(['email' => $email]);
        if ($existingUser !== null && $this->userTenantRepository->existeVinculoAtivo($existingUser, $input->tenant)) {
            throw new \DomainException('Este usuário já é colaborador ativo deste escritório.');
        }

        $invitation = new Invitation(
            email: $email,
            token: bin2hex(random_bytes(32)),
            type: 'office',
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $invitation->setFullName($input->fullName);
        $invitation->setTenant($input->tenant);
        $invitation->setTenantRole($input->tenantRole);
        $invitation->setCreatedBy($input->criadoPor);

        $this->em->persist($invitation);
        $this->em->flush();

        return $invitation;
    }
}
```

### Cenários de teste — `app/tests/Auth/Unit/CriarConviteEscritorioUseCaseTest.php`

| # | Cenário | Resultado esperado |
|---|---|---|
| 1 | Email sem conta | `type='office'`, `tenant` e `tenantRole` setados, `expiresAt` ≈ now+7d |
| 2 | Email com conta, mas **sem** vínculo ativo | Aceita — convite criado normalmente |
| 3 | Email com conta, **com** vínculo ativo | `\DomainException` "já é colaborador" |
| 4 | Email normalizado (upper + espaços) | Normalizado antes da query e na Invitation |
| 5 | `existeVinculoAtivo` **não chamado** se user não existe | Apenas 1 query ao UserRepository |
| 6 | `tenant` e `tenantRole` propagados | Getters da Invitation retornam os valores do input |

---

## Passo 3e — `AceitarConviteEscritorioComContaUseCase`

### `app/src/Auth/UseCase/AceitarConviteEscritorioComContaUseCase.php`

```php
<?php
declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\AceitarConviteEscritorioComContaInput;
use App\Entity\Auth\UserTenant;
use App\Repository\InvitationRepository;
use App\Repository\UserTenantRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AceitarConviteEscritorioComContaUseCase
{
    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(AceitarConviteEscritorioComContaInput $input): UserTenant
    {
        $invitation = $this->invitationRepository->encontrarPorToken($input->token);

        if ($invitation === null) {
            throw new \DomainException('Convite não encontrado.');
        }

        if ($invitation->getStatus() !== 'pending') {
            throw new \DomainException('Este convite não está mais disponível.');
        }

        if ($invitation->isExpired()) {
            throw new \DomainException('Este convite está expirado.');
        }

        if (strtolower($invitation->getEmail()) !== strtolower((string) $input->usuarioAtual->getEmail())) {
            throw new \DomainException('Este convite não pertence à sua conta.');
        }

        $tenant = $invitation->getTenant();
        if ($tenant === null) {
            throw new \DomainException('Convite inválido: escritório não encontrado.');
        }

        if ($this->userTenantRepository->existeVinculoAtivo($input->usuarioAtual, $tenant)) {
            throw new \DomainException('Você já é colaborador deste escritório.');
        }

        $userTenant = new UserTenant($input->usuarioAtual, $tenant);
        if ($invitation->getTenantRole() !== null) {
            $userTenant->setTenantRole($invitation->getTenantRole());
        }

        $invitation->aceitar($input->usuarioAtual);

        $this->em->persist($userTenant);
        $this->em->flush();

        return $userTenant;
    }
}
```

### Cenários de teste — `app/tests/Auth/Unit/AceitarConviteEscritorioComContaUseCaseTest.php`

| # | Cenário | Resultado esperado |
|---|---|---|
| 1 | Happy path | `UserTenant` criado, `aceitar()` chamado, `persist()` + `flush()` |
| 2 | TenantRole na invitation | `$userTenant->getTenantRole()` retorna o role da invitation |
| 3 | Sem TenantRole na invitation | `UserTenant` criado com `tenantRole = null` |
| 4 | Token não encontrado | `\DomainException` |
| 5 | Status != pending | `\DomainException` "não está mais disponível" |
| 6 | Invitation expirada | `\DomainException` "expirado" |
| 7 | Email não corresponde | `\DomainException` "não pertence à sua conta" |
| 8 | Email difere só em case | Aceita — normalização funciona |
| 9 | Já é colaborador ativo | `\DomainException` "já é colaborador" |
| 10 | Invitation sem tenant (`tenant = null`) | `\DomainException` "escritório não encontrado" |

---

## Passo 3f — `AceitarConviteEscritorioSemContaUseCase`

### `app/src/Auth/UseCase/AceitarConviteEscritorioSemContaUseCase.php`

```php
<?php
declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\AceitarConviteEscritorioSemContaInput;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AceitarConviteEscritorioSemContaUseCase
{
    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    public function executar(AceitarConviteEscritorioSemContaInput $input): User
    {
        $invitation = $this->invitationRepository->encontrarPorToken($input->token);

        if ($invitation === null) {
            throw new \DomainException('Convite não encontrado.');
        }

        if ($invitation->getStatus() !== 'pending') {
            throw new \DomainException('Este convite não está mais disponível.');
        }

        if ($invitation->isExpired()) {
            throw new \DomainException('Este convite está expirado.');
        }

        if ($this->userRepository->findOneBy(['email' => $invitation->getEmail()]) !== null) {
            throw new \DomainException('Já existe uma conta com este e-mail.');
        }

        $fullName = $input->fullName !== '' ? $input->fullName : $invitation->getFullName();
        if ($fullName === null || $fullName === '') {
            throw new \InvalidArgumentException('Nome completo é obrigatório.');
        }

        $tenant = $invitation->getTenant();
        if ($tenant === null) {
            throw new \DomainException('Convite inválido: escritório não encontrado.');
        }

        $user = new User();
        $user->setEmail($invitation->getEmail());
        $user->setFullName($fullName);
        $user->setIsActive(true);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $input->senha));

        $userTenant = new UserTenant($user, $tenant);
        if ($invitation->getTenantRole() !== null) {
            $userTenant->setTenantRole($invitation->getTenantRole());
        }

        $invitation->aceitar($user);

        $this->em->persist($user);
        $this->em->persist($userTenant);
        $this->em->flush();

        return $user;
    }
}
```

### Cenários de teste — `app/tests/Auth/Unit/AceitarConviteEscritorioSemContaUseCaseTest.php`

| # | Cenário | Resultado esperado |
|---|---|---|
| 1 | Happy path, fullName no input | `User` + `UserTenant` criados, `aceitar()` chamado, 2x `persist()` + `flush()` |
| 2 | `input.fullName` vazio, `invitation.fullName` preenchido | Usa o nome da invitation |
| 3 | `input.fullName` preenchido, `invitation.fullName` também | Input tem prioridade |
| 4 | TenantRole na invitation | Propagado para `UserTenant` |
| 5 | Token não encontrado | `\DomainException` |
| 6 | Status != pending | `\DomainException` |
| 7 | Invitation expirada | `\DomainException` "expirado" |
| 8 | Email já tem conta | `\DomainException` "já existe uma conta" |
| 9 | `input.fullName` vazio E `invitation.fullName` nulo | `\InvalidArgumentException` "Nome completo é obrigatório" |
| 10 | Invitation sem tenant | `\DomainException` "escritório não encontrado" |
| 11 | `hashPassword()` foi chamado | Mock verifica invocação |

---

## Passo 3g — `AceitarConvitePlataformaUseCase`

### `app/src/Auth/UseCase/AceitarConvitePlataformaUseCase.php`

```php
<?php
declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\AceitarConvitePlataformaInput;
use App\Entity\Auth\User;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AceitarConvitePlataformaUseCase
{
    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    public function executar(AceitarConvitePlataformaInput $input): User
    {
        $invitation = $this->invitationRepository->encontrarPorToken($input->token);

        if ($invitation === null) {
            throw new \DomainException('Convite não encontrado.');
        }

        if ($invitation->getStatus() !== 'pending') {
            throw new \DomainException('Este convite não está mais disponível.');
        }

        if ($invitation->isExpired()) {
            throw new \DomainException('Este convite está expirado.');
        }

        if ($this->userRepository->findOneBy(['email' => $invitation->getEmail()]) !== null) {
            throw new \DomainException('Já existe uma conta com este e-mail.');
        }

        if (!preg_match('/^\d+$/', $input->oabNumero)) {
            throw new \InvalidArgumentException('Número da OAB deve conter apenas dígitos.');
        }

        if (!preg_match('/^[A-Z]{2}$/', $input->oabUf)) {
            throw new \InvalidArgumentException('UF da OAB deve ter exatamente 2 letras maiúsculas.');
        }

        $user = new User();
        $user->setEmail($invitation->getEmail());
        $user->setFullName($input->fullName);
        $user->setIsActive(true);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $input->senha));
        $user->setOabNumero($input->oabNumero);
        $user->setOabUf($input->oabUf);

        $invitation->aceitar($user);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
```

### Cenários de teste — `app/tests/Auth/Unit/AceitarConvitePlataformaUseCaseTest.php`

| # | Cenário | Resultado esperado |
|---|---|---|
| 1 | Happy path, OAB válido | `User` criado com `isActive=true`, `oabNumero`, `oabUf`; `aceitar()` chamado |
| 2 | `User.email` vem da invitation (não do input) | `$user->getEmail() === $invitation->getEmail()` |
| 3 | Senha hasheada | `hashPassword()` chamado; `persist()` + `flush()` |
| 4 | Token não encontrado | `\DomainException` |
| 5 | Status != pending | `\DomainException` |
| 6 | Invitation expirada | `\DomainException` "expirado" |
| 7 | Email já tem conta | `\DomainException` "já existe uma conta" |
| 8 | `oabNumero` com letras (`"12A34"`) | `\InvalidArgumentException` "apenas dígitos" |
| 9 | `oabNumero` vazio (`""`) | `\InvalidArgumentException` "apenas dígitos" |
| 10 | `oabUf` com 1 letra (`"S"`) | `\InvalidArgumentException` "exatamente 2 letras" |
| 11 | `oabUf` com 3 letras (`"SPP"`) | `\InvalidArgumentException` "exatamente 2 letras" |
| 12 | `oabUf` com minúsculas (`"sp"`) | `\InvalidArgumentException` "letras maiúsculas" |
| 13 | `oabUf` com número (`"S1"`) | `\InvalidArgumentException` |
| 14 | OAB válido: `oabNumero="12345"`, `oabUf="SP"` | Aceita normalmente |

---

## Estrutura de pastas ao final da Fase 5b.1

```
app/src/Auth/
├── DTO/
│   ├── AceitarConviteEscritorioComContaInput.php
│   ├── AceitarConviteEscritorioSemContaInput.php
│   ├── AceitarConvitePlataformaInput.php
│   ├── CriarConviteEscritorioInput.php
│   ├── CriarConvitePlataformaInput.php
│   ├── RecusarConviteEscritorioInput.php
│   └── RevogarConviteInput.php
└── UseCase/
    ├── AceitarConviteEscritorioComContaUseCase.php
    ├── AceitarConviteEscritorioSemContaUseCase.php
    ├── AceitarConvitePlataformaUseCase.php
    ├── CriarConviteEscritorioUseCase.php
    ├── CriarConvitePlataformaUseCase.php
    ├── RecusarConviteEscritorioUseCase.php
    └── RevogarConviteUseCase.php

app/tests/Auth/Unit/
    ├── AceitarConviteEscritorioComContaUseCaseTest.php   (10 cenários)
    ├── AceitarConviteEscritorioSemContaUseCaseTest.php   (11 cenários)
    ├── AceitarConvitePlataformaUseCaseTest.php           (14 cenários)
    ├── CriarConviteEscritorioUseCaseTest.php             (6 cenários)
    ├── CriarConvitePlataformaUseCaseTest.php             (7 cenários)
    ├── RecusarConviteEscritorioUseCaseTest.php           (7 cenários)
    └── RevogarConviteUseCaseTest.php                     (6 cenários)

app/src/Repository/ (modificados, não movidos)
    ├── InvitationRepository.php  (+2 métodos: encontrarPorToken, encontrarPendentesPorEmail)
    └── UserTenantRepository.php  (+1 método: existeVinculoAtivo)
```

**Total:** 2 métodos de repositório · 7 DTOs · 7 UseCases · 7 arquivos de teste · ~61 cenários
