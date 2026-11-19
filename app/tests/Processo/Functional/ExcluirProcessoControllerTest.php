<?php

declare(strict_types=1);

namespace App\Tests\Processo\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Processo\Controller\ProcessoController;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/** A rota `processo_delete` (POST /processos/{id}/deletar) pelo UseCase: permissão, CSRF, escritório e a exclusão. */
#[CoversClass(ProcessoController::class)]
final class ExcluirProcessoControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('Quem pode excluir apaga o processo e volta para a lista')]
    public function testExcluiEVoltaParaALista(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->tenant();
        $id = (int) $this->processo($tenant)->getId();
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $this->usuario($tenant, true), $tenant);

        $client->request('POST', "/processos/{$id}/deletar", ['_token' => 'TOKEN_delete_processo_' . $id]);

        self::assertResponseRedirects('/processos/');
        self::assertNull($this->achar($id));
    }

    #[TestDox('Token CSRF inválido: 403 e o processo continua')]
    public function testTokenInvalido(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->tenant();
        $id = (int) $this->processo($tenant)->getId();
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $this->usuario($tenant, true), $tenant);

        $client->request('POST', "/processos/{$id}/deletar", ['_token' => 'errado']);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->achar($id));
    }

    #[TestDox('Sem permissão de excluir: pede acesso, não apaga')]
    public function testSemPermissao(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->tenant();
        $id = (int) $this->processo($tenant)->getId();
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $this->usuario($tenant, false), $tenant);

        $client->request('POST', "/processos/{$id}/deletar", ['_token' => 'TOKEN_delete_processo_' . $id]);

        self::assertResponseRedirects();
        self::assertStringContainsString('requestAccess=1', (string) $client->getResponse()->headers->get('Location'));
        self::assertNotNull($this->achar($id));
    }

    #[TestDox('Processo de outro escritório: 404 e nada some')]
    public function testOutroEscritorio(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $meu = $this->tenant();
        $id = (int) $this->processo($this->tenant())->getId();
        $usuario = $this->usuario($meu, true);
        // Sem o processo na memória, a busca da rota vai ao banco e passa pelo filtro do escritório.
        $this->em()->clear();
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $usuario, $meu);

        $client->request('POST', "/processos/{$id}/deletar", ['_token' => 'TOKEN_delete_processo_' . $id]);

        self::assertResponseStatusCodeSame(404);
        // Pelo SQL: o filtro do escritório esconderia o processo alheio de uma busca do ORM.
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM processo WHERE id = ?', [$id]));
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }
            public function setToken(string $tokenId, string $token): void
            {
            }
            public function removeToken(string $tokenId): ?string
            {
                return null;
            }
            public function hasToken(string $tokenId): bool
            {
                return true;
            }
            public function clear(): void
            {
            }
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function achar(int $id): ?Processo
    {
        $this->em()->clear();

        return $this->em()->find(Processo::class, $id);
    }

    private function tenant(): Tenant
    {
        $tenant = (new Tenant())->setName('Escritório ' . uniqid())->setIsActive(true);
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** `$sistema` true: papel de sistema (passa pelo PermissionChecker); false: papel comum sem nenhuma permissão. */
    private function usuario(Tenant $tenant, bool $sistema): User
    {
        $usuario = (new User())->setEmail('exc_' . uniqid() . '@test.com')->setFullName('Pessoa ' . uniqid())->setRoles(['ROLE_USER'])->setIsActive(true);
        $usuario->setPassword('sem-uso');
        $papel = (new TenantRole())->setTenant($tenant)->setName('Papel ' . uniqid())->setIsSystem($sistema);
        $vinculo = (new UserTenant($usuario, $tenant))->setTenantRole($papel);
        foreach ([$usuario, $papel, $vinculo] as $entidade) {
            $this->em()->persist($entidade);
        }
        $this->em()->flush();

        return $usuario;
    }

    private function processo(Tenant $tenant): Processo
    {
        $processo = new Processo();
        $processo->setNumeroProcesso('EXC' . substr(uniqid(), -9));
        $processo->setSiglaTribunal('TRT2');
        $processo->setOrgaoJulgador('1a Vara');
        $processo->setClasseProcessual('Reclamação');
        $processo->setAssuntoProcessual('Rescisão');
        $processo->setSituacaoProcesso('Em Andamento');
        $processo->setInstancia('1a Instância');
        $processo->setTenant($tenant);
        $this->em()->persist($processo);
        $this->em()->flush();

        return $processo;
    }
}
