<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Expediente\Controller\ExpedienteController;
use App\Expediente\Entity\Marcador;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * A resposta de expediente_pasta_marcadores é consumida por JavaScript que faz
 * `data.marcadores.map(...)`. Isso exige uma LISTA JSON (`[...]`) — um objeto
 * (`{"3":{...}}`) faz o `.map` estourar TypeError no navegador, o modal exibe
 * "Erro de comunicação." e não fecha, mesmo com a gravação já concluída (HTTP 200).
 *
 * O buraco aparecia só quando a sincronização REMOVIA algum marcador: removeElement()
 * deixa lacunas nos índices da coleção, toArray() preserva essas chaves, array_map()
 * também preserva, e json_encode() serializa array não-sequencial como objeto.
 */
#[CoversClass(ExpedienteController::class)]
final class ExpedienteMarcadoresRespostaJsonTest extends JusPrimeWebTestCase
{
    #[TestDox('sincronizar REMOVENDO marcadores devolve "marcadores" como lista JSON (não objeto)')]
    public function testRespostaEhListaMesmoQuandoRemoveMarcadores(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();

        [$user, $tenant] = $this->criarUsuarioComTenant();
        $pasta = $this->criarPasta($tenant);

        // Três marcadores na pasta: índices 0, 1 e 2 da coleção.
        $primeiro = $this->criarMarcador($tenant, $user, '1. Despachar');
        $segundo  = $this->criarMarcador($tenant, $user, '2. Distribuir');
        $terceiro = $this->criarMarcador($tenant, $user, '3. Urgente');
        $this->vincular($pasta, [$primeiro, $segundo, $terceiro]);

        $this->logarComTenant($client, $user, $tenant);

        // Mantém só o terceiro: remove os índices 0 e 1, sobrando a chave 2.
        $client->xmlHttpRequest(
            'POST',
            '/expediente/pasta/' . $pasta->getId() . '/marcadores',
            [
                '_token'      => 'TOKEN_pasta_marcadores_' . $pasta->getId(),
                'marcadores'  => [(string) $terceiro->getId()],
            ]
        );

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        // A forma do JSON é o que o navegador consome — objeto quebra o .map().
        self::assertStringContainsString('"marcadores":[', $body, 'marcadores veio como objeto JSON: ' . $body);

        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('marcadores', $data);
        self::assertTrue(
            array_is_list($data['marcadores']),
            'marcadores precisa ser lista com chaves 0..n; veio: ' . json_encode(array_keys($data['marcadores'])),
        );
        self::assertCount(1, $data['marcadores']);
        self::assertSame($terceiro->getId(), $data['marcadores'][0]['id']);
    }

    #[TestDox('sincronizar removendo TODOS os marcadores devolve lista vazia')]
    public function testRespostaEhListaVaziaQuandoRemoveTodos(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();

        [$user, $tenant] = $this->criarUsuarioComTenant();
        $pasta = $this->criarPasta($tenant);
        $this->vincular($pasta, [
            $this->criarMarcador($tenant, $user, 'A'),
            $this->criarMarcador($tenant, $user, 'B'),
        ]);

        $this->logarComTenant($client, $user, $tenant);

        $client->xmlHttpRequest(
            'POST',
            '/expediente/pasta/' . $pasta->getId() . '/marcadores',
            ['_token' => 'TOKEN_pasta_marcadores_' . $pasta->getId()]
        );

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('"marcadores":[]', $body);
    }

    #[TestDox('sincronizar só ADICIONANDO continua devolvendo lista JSON')]
    public function testRespostaEhListaQuandoApenasAdiciona(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();

        [$user, $tenant] = $this->criarUsuarioComTenant();
        $pasta = $this->criarPasta($tenant);
        $um    = $this->criarMarcador($tenant, $user, 'Um');
        $dois  = $this->criarMarcador($tenant, $user, 'Dois');

        $this->logarComTenant($client, $user, $tenant);

        $client->xmlHttpRequest(
            'POST',
            '/expediente/pasta/' . $pasta->getId() . '/marcadores',
            [
                '_token'     => 'TOKEN_pasta_marcadores_' . $pasta->getId(),
                'marcadores' => [(string) $um->getId(), (string) $dois->getId()],
            ]
        );

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue(array_is_list($data['marcadores']));
        self::assertCount(2, $data['marcadores']);
    }

    // ----------------------------------------------------------------- helpers

    /** @return array{0: User, 1: Tenant} */
    private function criarUsuarioComTenant(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant MARC ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('marc_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Marcadores');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        // Papel de sistema (administrador) — sem ele o módulo Expediente responde 403.
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('MARC-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function criarMarcador(Tenant $tenant, User $criadoPor, string $nome): Marcador
    {
        $em       = static::getContainer()->get(EntityManagerInterface::class);
        $marcador = new Marcador($nome . ' ' . uniqid(), $tenant, $criadoPor);
        $em->persist($marcador);
        $em->flush();

        return $marcador;
    }

    /** @param Marcador[] $marcadores */
    private function vincular(Pasta $pasta, array $marcadores): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($marcadores as $marcador) {
            $pasta->addMarcador($marcador);
        }
        $em->flush();
    }

    /**
     * TokenStorage em memória com tokens previsíveis, sem depender de sessão HTTP.
     */
    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void {}

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }
}
