<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Controller\AnaliseDoPrazoController;
use App\Tarefa\Enum\EstadoDoPrazoJuridico;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\DesligaTenantFilterNaRequisicao;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(AnaliseDoPrazoController::class)]
final class AnaliseDoPrazoDaMetaNovaTest extends JusPrimeWebTestCase
{
    use DesligaTenantFilterNaRequisicao;

    private EntityManagerInterface $em;

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Prazo ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function usuario(Tenant $tenant): User
    {
        $user = new User();
        $user->setEmail('prazo_' . uniqid() . '@test.com');
        $user->setFullName('Usuário ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $this->em->persist($user);
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $this->em->persist($role);
        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $this->em->persist($vinculo);
        $this->em->flush();

        return $user;
    }

    private function pasta(Tenant $tenant, User $criador): int
    {
        $pasta = new Pasta();
        $pasta->setNup('TEST-PRAZO-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criador);
        $this->em->persist($pasta);
        $this->em->flush();

        return (int) $pasta->getId();
    }

    private function csrf(): void
    {
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
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
        });
    }

    /** @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: int, 2: User} */
    private function cenario(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = $this->tenant();
        $autor = $this->usuario($tenant);
        $pastaId = $this->pasta($tenant, $autor);
        $this->csrf();
        $this->logarComTenant($client, $autor, $tenant);

        return [$client, $pastaId, $autor];
    }

    /** @return array<string, mixed> */
    private function json(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        return (array) json_decode((string) $client->getResponse()->getContent(), true);
    }

    #[TestDox('Análise: contestação com a ciência informada volta estimada, com data, fonte e explicação, sem rótulo I.A.')]
    public function testAnaliseDevolveEstimativa(): void
    {
        [$client, $pastaId] = $this->cenario();

        $client->request('POST', "/tarefas/pasta/{$pastaId}/analise-do-prazo", [
            '_token' => 'TOKEN_tarefa_criar_' . $pastaId,
            'titulo' => 'Contestação',
            'descricao' => 'Apresentar a contestação do réu',
            'data_ciencia' => '2026-10-19',
        ]);

        self::assertResponseIsSuccessful();
        $analise = $this->json($client)['analise'];
        self::assertSame('estimado', $analise['estado']);
        self::assertSame('Estimado', $analise['estadoRotulo']);
        self::assertTrue($analise['precisaConfirmar']);
        self::assertNotNull($analise['prazoJuridico']);
        self::assertStringContainsString('CPC', $analise['fonteLegal']);
        self::assertStringNotContainsString('I.A.', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Análise: texto curto devolve null; sem token é 403; pasta de outro escritório é 404')]
    public function testRecusasEBorda(): void
    {
        [$client, $pastaId] = $this->cenario();
        $token = 'TOKEN_tarefa_criar_' . $pastaId;

        $client->request('POST', "/tarefas/pasta/{$pastaId}/analise-do-prazo", ['_token' => $token, 'titulo' => 'ab', 'descricao' => '']);
        self::assertNull($this->json($client)['analise']);

        $client->request('POST', "/tarefas/pasta/{$pastaId}/analise-do-prazo", ['_token' => 'errado', 'titulo' => 'Contestação']);
        self::assertResponseStatusCodeSame(403);

        $tenantAlheio = $this->tenant();
        $pastaAlheia = $this->pasta($tenantAlheio, $this->usuario($tenantAlheio));
        $client->request('POST', "/tarefas/pasta/{$pastaAlheia}/analise-do-prazo", ['_token' => 'TOKEN_tarefa_criar_' . $pastaAlheia, 'titulo' => 'Contestação']);
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Criar com a análise à vista: estimado sem confirmar é 422 e nada é gravado')]
    public function testEstimadoSemConfirmarNaoGrava(): void
    {
        [$client, $pastaId, $autor] = $this->cenario();

        $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", $this->corpoDaMeta($pastaId, $autor) + ['data_ciencia' => '2026-10-19']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Confirme a análise do prazo', $this->json($client)['erro']);
        self::assertSame(0, $this->em->getRepository(Tarefa::class)->count(['titulo' => 'Contestação']));
    }

    #[TestDox('Criar confirmando a análise: grava prazo jurídico, estado Confirmado, ciência e que usou o prazo sugerido')]
    public function testConfirmadoGravaAAnalise(): void
    {
        [$client, $pastaId, $autor] = $this->cenario();
        $analise = $this->analisar($client, $pastaId);

        $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", [
            'prazo' => $analise['prazoInterno'],
            'data_ciencia' => '2026-10-19',
            'prazo_juridico' => $analise['prazoJuridico'],
            'analise_confirmada' => '1',
        ] + $this->corpoDaMeta($pastaId, $autor));

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $meta = $this->em->find(Tarefa::class, $this->json($client)['id']);
        self::assertSame(EstadoDoPrazoJuridico::Confirmado, $meta->getEstadoDoPrazoJuridico());
        self::assertSame($analise['prazoJuridico'], $meta->getPrazoJuridico()?->format('Y-m-d'));
        self::assertSame('2026-10-19', $meta->getDataDaCiencia()?->format('Y-m-d'));
        self::assertTrue($meta->usouPrazoSugerido());
    }

    #[TestDox('Criar informando o prazo jurídico em outro prazo da meta: estado Informado e não usou o sugerido')]
    public function testInformadoGravaSemTerUsadoOSugerido(): void
    {
        [$client, $pastaId, $autor] = $this->cenario();

        $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", [
            'prazo' => '2027-01-29',
            'prazo_juridico' => '2027-02-05',
        ] + $this->corpoDaMeta($pastaId, $autor));

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $meta = $this->em->find(Tarefa::class, $this->json($client)['id']);
        self::assertSame(EstadoDoPrazoJuridico::Informado, $meta->getEstadoDoPrazoJuridico());
        self::assertFalse($meta->usouPrazoSugerido());
    }

    #[TestDox('Criar sem analise_prazo (Push, chamadas antigas): a meta nasce como sempre, sem análise gravada')]
    public function testSemAnalisePrazoNaoMudaNada(): void
    {
        [$client, $pastaId, $autor] = $this->cenario();
        $corpo = $this->corpoDaMeta($pastaId, $autor);
        unset($corpo['analise_prazo']);

        $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", $corpo + ['data_ciencia' => '2026-10-19']);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $meta = $this->em->find(Tarefa::class, $this->json($client)['id']);
        self::assertNull($meta->getEstadoDoPrazoJuridico());
        self::assertNull($meta->getPrazoJuridico());
    }

    #[TestDox('Confirmar sem prazo jurídico cai em Estimado: 422 com o código da análise')]
    public function testConfirmadaSemPrazoJuridicoRecusa(): void
    {
        [$client, $pastaId, $autor] = $this->cenario();

        $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", $this->corpoDaMeta($pastaId, $autor) + ['data_ciencia' => '2026-10-19', 'analise_confirmada' => '1']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('analise_do_prazo', $this->json($client)['codigo']);
    }

    /** @return array<string, mixed> */
    private function analisar(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, int $pastaId): array
    {
        $client->request('POST', "/tarefas/pasta/{$pastaId}/analise-do-prazo", [
            '_token' => 'TOKEN_tarefa_criar_' . $pastaId,
            'titulo' => 'Contestação',
            'descricao' => 'Apresentar a contestação do réu',
            'data_ciencia' => '2026-10-19',
        ]);

        return $this->json($client)['analise'];
    }

    /** @return array<string, mixed> */
    private function corpoDaMeta(int $pastaId, User $autor): array
    {
        return [
            '_token' => 'TOKEN_tarefa_criar_' . $pastaId,
            'titulo' => 'Contestação',
            'descricao' => 'Apresentar a contestação do réu',
            'prazo' => '2027-01-29',
            'etapa' => 'juridico',
            'analise_prazo' => '1',
            'responsaveis' => [(int) $autor->getId()],
        ];
    }
}
