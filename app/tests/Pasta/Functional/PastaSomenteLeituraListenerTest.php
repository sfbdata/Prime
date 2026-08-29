<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\EventListener\PastaSomenteLeituraListener;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * O portão de somente-leitura da pasta riscada, provado por HTTP.
 *
 * A prova precisa ser funcional: o que está sendo testado é que a trava alcança rotas que ela não
 * conhece pelo nome — o ponto inteiro de ela ser um listener e não uma checagem por rota.
 */
#[CoversClass(PastaSomenteLeituraListener::class)]
final class PastaSomenteLeituraListenerTest extends JusPrimeWebTestCase
{
    /** @return array{0: User, 1: Tenant} */
    private function criarUsuarioAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Lapide ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_lapide_' . uniqid() . '@test.com');
        $user->setFullName('Admin Lapide');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant, string $nup, bool $excluida, User $autor): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);

        if ($excluida) {
            $pasta->marcarExcluida($autor, new \DateTimeImmutable());
        }

        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    /**
     * Caminhos e métodos conferidos no `debug:router` — não chutados. O `responsavel` é PATCH de
     * propósito: é o que prova que o portão não olha só para POST.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, string>}>
     */
    public static function rotasDeEscrita(): array
    {
        return [
            'editar'            => ['POST',  '/editar',            ['nup' => '9', 'nome_cliente' => 'X']],
            'alternar situação' => ['POST',  '/alternar-situacao', []],
            'prioridade'        => ['POST',  '/prioridade',        ['prioridade' => 'urgente']],
            'responsável'       => ['PATCH', '/responsavel',       []],
            'excluir de novo'   => ['POST',  '/deletar',           []],
        ];
    }

    #[TestDox('Pasta riscada recusa escrita')]
    #[DataProvider('rotasDeEscrita')]
    public function testPastaRiscadaRecusaEscrita(string $metodo, string $sufixo, array $payload): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, 'LAPIDE-' . uniqid(), true, $user);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request($metodo, "/pasta/{$pasta->getId()}{$sufixo}", $payload);

        // Sem JS o portão devolve redirect com aviso; nunca deixa a escrita passar.
        self::assertResponseRedirects();
        self::assertStringContainsString(
            "/pasta/{$pasta->getId()}",
            (string) $client->getResponse()->headers->get('Location'),
        );
    }

    #[TestDox('AJAX na pasta riscada recebe 403 em JSON, não uma página HTML')]
    public function testAjaxNaPastaRiscadaRecebeJson403(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, 'LAPIDE-' . uniqid(), true, $user);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request(
            'POST',
            "/pasta/{$pasta->getId()}/prioridade",
            ['_token' => 'TOKEN_pasta_prioridade_' . $pasta->getId(), 'prioridade' => 'urgente'],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest'],
        );

        self::assertResponseStatusCodeSame(403);
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('erro', $dados['status'] ?? null);
        self::assertStringContainsString('somente para leitura', (string) ($dados['mensagem'] ?? ''));
    }

    #[TestDox('A MESMA rota numa pasta normal continua funcionando')]
    public function testPastaNormalNaoEhAfetada(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, 'NORMAL-' . uniqid(), false, $user);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request(
            'POST',
            "/pasta/{$pasta->getId()}/prioridade",
            ['_token' => 'TOKEN_pasta_prioridade_' . $pasta->getId(), 'prioridade' => 'urgente'],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest'],
        );

        self::assertResponseIsSuccessful();
    }

    #[TestDox('GET na pasta riscada continua abrindo: ela é para consulta')]
    public function testGetNaPastaRiscadaContinuaAbrindo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, 'LAPIDE-' . uniqid(), true, $user);

        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertResponseIsSuccessful();
    }

    #[TestDox('Restaurar é a única escrita liberada, e devolve a pasta para ativa')]
    public function testRestaurarPassaPeloPortaoEDevolveAPastaParaAtiva(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, 'LAPIDE-' . uniqid(), true, $user);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/restaurar", [
            '_token' => 'TOKEN_restaurar_pasta_' . $pasta->getId(),
        ]);

        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $recarregada = $em->find(Pasta::class, $pasta->getId());

        self::assertFalse($recarregada->estaExcluida());
        self::assertSame(Pasta::SITUACAO_ATIVA, $recarregada->getSituacao());
    }
}
