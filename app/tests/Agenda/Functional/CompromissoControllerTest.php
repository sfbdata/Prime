<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Functional;

use App\Agenda\Controller\CompromissoController;
use App\Agenda\Entity\Evento;
use App\Agenda\Service\PermissoesDaAgenda;
use App\Agenda\Enum\TipoDeCompromisso;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\PastaPrazo;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(CompromissoController::class)]
final class CompromissoControllerTest extends JusPrimeWebTestCase
{
    private const CORPO = [
        'titulo' => 'Audiência de instrução', 'tipo' => 'aud', 'data' => '2030-03-10', 'diaInteiro' => false,
        'inicio' => '14:00', 'fim' => '15:30', 'visibilidade' => 'equipe', 'pasta' => '', 'lembreteMinutos' => 60, 'observacao' => 'Sala 3',
    ];

    #[TestDox('Cria pelo modal: grava no escritório certo e aparece na agenda')]
    public function testCria(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $eu = $this->criarUsuario($tenant, true);
        $this->logarComTenant($client, $eu, $tenant);

        $r = $this->postar($client, '/agenda/compromissos/novo', self::CORPO);

        self::assertResponseIsSuccessful();
        $evento = $this->em()->find(Evento::class, $r['id']);
        self::assertSame($tenant->getId(), $evento->getTenant()->getId());
        self::assertSame(TipoDeCompromisso::Audiencia, $evento->getTipo());
        self::assertSame('2030-03-10 15:30', $evento->getDataFim()->format('Y-m-d H:i'));
        $client->request('GET', '/agenda/compromissos?de=2030-03-01&ate=2030-03-31');
        $item = json_decode((string) $client->getResponse()->getContent(), true)['itens'][0];
        self::assertSame(['Audiência de instrução', 'aud', 60, true], [$item['titulo'], $item['calendario'], $item['lembreteMinutos'], $item['editavel']]);
    }

    #[TestDox('Os três tipos da tela 1A (urgente, pessoal, lembrete) são aceitos e voltam como calendário próprio')]
    public function testTiposDaAgenda1A(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarUsuario($tenant, true), $tenant);

        foreach (['urg' => TipoDeCompromisso::Urgente, 'pess' => TipoDeCompromisso::Pessoal, 'lemb' => TipoDeCompromisso::Lembrete] as $valor => $tipo) {
            $r = $this->postar($client, '/agenda/compromissos/novo', ['tipo' => $valor, 'titulo' => 'Tipo ' . $valor] + self::CORPO);
            self::assertResponseIsSuccessful();
            self::assertSame($tipo, $this->em()->find(Evento::class, $r['id'])->getTipo());
        }
        $client->request('GET', '/agenda/compromissos?de=2030-03-01&ate=2030-03-31');
        $calendarios = array_column(json_decode((string) $client->getResponse()->getContent(), true)['itens'], 'calendario', 'titulo');

        self::assertSame(['Tipo urg' => 'urg', 'Tipo pess' => 'pess', 'Tipo lemb' => 'lemb'], $calendarios);
    }

    #[TestDox('Sem token CSRF é recusado; título vazio devolve 422 com a mensagem')]
    public function testCsrfEValidacao(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarUsuario($tenant, true), $tenant);

        $this->postar($client, '/agenda/compromissos/novo', self::CORPO, null);
        self::assertResponseStatusCodeSame(403);

        $r = $this->postar($client, '/agenda/compromissos/novo', ['titulo' => ''] + self::CORPO);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Informe o título.', $r['erro']);
    }

    #[TestDox('Editar ou excluir compromisso de outro escritório devolve 404 e não muda nada')]
    public function testOutroEscritorio(): void
    {
        $client = $this->cliente();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $deB = $this->criarEvento($tenantB, $this->criarUsuario($tenantB, true));
        $this->logarComTenant($client, $this->criarUsuario($tenantA, true), $tenantA);

        $this->postar($client, '/agenda/compromissos/' . $deB . '/salvar', self::CORPO);
        self::assertResponseStatusCodeSame(404);
        $this->postar($client, '/agenda/compromissos/' . $deB . '/excluir', []);
        self::assertResponseStatusCodeSame(404);
        $this->postar($client, '/agenda/compromissos/' . $deB . '/mover', ['data' => '2030-03-12']);
        self::assertResponseStatusCodeSame(404);
        $this->postar($client, '/agenda/compromissos/' . $deB . '/status', ['status' => 'cancelado']);
        self::assertResponseStatusCodeSame(404);
        if ($this->em()->getFilters()->isEnabled('tenant')) {
            $this->em()->getFilters()->disable('tenant');
        }
        $this->em()->clear();
        self::assertSame('Original', $this->em()->find(Evento::class, $deB)->getTitulo());
    }

    #[TestDox('Compromisso "Só eu" do colega não existe para mim: salvar, excluir e avisar dão 404')]
    public function testPrivadoDoColega(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $dono = $this->criarUsuario($tenant, true);
        $id = $this->criarEvento($tenant, $dono);
        $evento = $this->em()->find(Evento::class, $id);
        $evento->setVisibilidade(Evento::VISIBILIDADE_SOMENTE_EU);
        $this->em()->flush();
        $this->logarComTenant($client, $this->criarUsuario($tenant, true), $tenant);

        foreach (['salvar' => self::CORPO, 'excluir' => [], 'avisar' => ['ids' => [$dono->getId()]], 'mover' => ['data' => '2030-03-12'], 'status' => ['status' => 'concluido']] as $acao => $corpo) {
            $this->postar($client, '/agenda/compromissos/' . $id . '/' . $acao, $corpo);
            self::assertResponseStatusCodeSame(404, $acao);
        }
    }

    #[TestDox('Arrastar move o compromisso; cancelar avisa o participante; situação inválida dá 422')]
    public function testMoverECancelar(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $eu = $this->criarUsuario($tenant, true);
        $colega = $this->criarUsuario($tenant, true);
        $id = $this->criarEvento($tenant, $eu);
        $evento = $this->em()->find(Evento::class, $id);
        $evento->addParticipante($colega);
        $this->em()->flush();
        $this->logarComTenant($client, $eu, $tenant);

        $r = $this->postar($client, '/agenda/compromissos/' . $id . '/mover', ['data' => '2030-03-15', 'hora' => '16:00']);
        self::assertSame('2030-03-15', $r['data']);
        $this->postar($client, '/agenda/compromissos/' . $id . '/mover', ['data' => '15/03/2030']);
        self::assertResponseStatusCodeSame(422);
        $this->postar($client, '/agenda/compromissos/' . $id . '/mover', ['data' => '2030-02-31']);
        self::assertResponseStatusCodeSame(422, 'data impossível não pode rolar para março');
        $this->postar($client, '/agenda/compromissos/' . $id . '/status', ['status' => 'cancelado']);
        self::assertResponseIsSuccessful();
        $this->postar($client, '/agenda/compromissos/' . $id . '/status', ['status' => 'arquivado']);
        self::assertResponseStatusCodeSame(422);

        $this->em()->clear();
        $salvo = $this->em()->find(Evento::class, $id);
        self::assertSame('2030-03-15 16:00 2030-03-15 17:00', $salvo->getDataInicio()->format('Y-m-d H:i') . ' ' . $salvo->getDataFim()->format('Y-m-d H:i'));
        self::assertSame(Evento::STATUS_CANCELADO, $salvo->getStatus());
        $avisos = $this->em()->getRepository(Notificacao::class)->findBy(['usuario' => $colega, 'tipo' => Notificacao::TIPO_EVENTO_CANCELADO]);
        self::assertCount(1, $avisos);
    }

    #[TestDox('Só o criador (ou quem administra usuários) exclui, move e muda a situação; o colega comum recebe 403')]
    public function testExcluir(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $criador = $this->criarUsuario($tenant, false, ['modules.agenda.view']);
        $colega = $this->criarUsuario($tenant, false, ['modules.agenda.view']);
        $id = $this->criarEvento($tenant, $criador);

        $this->logarComTenant($client, $colega, $tenant);
        $this->postar($client, '/agenda/compromissos/' . $id . '/excluir', []);
        self::assertResponseStatusCodeSame(403);
        $this->postar($client, '/agenda/compromissos/' . $id . '/mover', ['data' => '2030-03-12']);
        self::assertResponseStatusCodeSame(403);
        $this->postar($client, '/agenda/compromissos/' . $id . '/status', ['status' => 'concluido']);
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($client, $criador, $tenant);
        $this->postar($client, '/agenda/compromissos/' . $id . '/excluir', []);
        self::assertResponseIsSuccessful();
        $this->em()->clear();
        self::assertNull($this->em()->find(Evento::class, $id));
    }

    #[TestDox('Avisar no Chat gera aviso para o colega do escritório, uma vez só, e ignora quem é de fora')]
    public function testAvisar(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $outro = $this->criarTenant();
        $eu = $this->criarUsuario($tenant, true);
        $colega = $this->criarUsuario($tenant, true);
        $deFora = $this->criarUsuario($outro, true);
        $id = $this->criarEvento($tenant, $eu);
        $this->logarComTenant($client, $eu, $tenant);

        $r1 = $this->postar($client, '/agenda/compromissos/' . $id . '/avisar', ['ids' => [$colega->getId(), $deFora->getId()]]);
        $r2 = $this->postar($client, '/agenda/compromissos/' . $id . '/avisar', ['ids' => [$colega->getId()]]);

        self::assertSame([1, 0], [$r1['avisados'], $r2['avisados']]);
        $avisos = $this->em()->getRepository(Notificacao::class)->findBy(['usuario' => $colega]);
        self::assertCount(1, $avisos);
        self::assertSame('/agenda#ev=ev-' . $id . '-2030-03-10', (string) $avisos[0]->getUrl());
        self::assertSame('Agenda: Original', $avisos[0]->getTitulo(), 'título como no desenho');
        self::assertStringContainsString('marcou você em um compromisso: Original', (string) $avisos[0]->getMensagem(), 'o resumo vai no corpo');
        self::assertCount(0, $this->em()->getRepository(Notificacao::class)->findBy(['usuario' => $deFora]));
    }

    #[TestDox('Avisar no Chat também vale para prazo de pasta (desenho 09, L238): atalho para a pasta; sem acesso às pastas ou de outro escritório, 404')]
    public function testAvisarPrazo(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $outro = $this->criarTenant();
        $eu = $this->criarUsuario($tenant, true);
        $colega = $this->criarUsuario($tenant, true);
        $soAgenda = $this->criarUsuario($tenant, false, ['modules.agenda.view']);
        $soPastas = $this->criarUsuario($tenant, false, ['modules.agenda.view', 'modules.pastas.view']);
        $soPrazos = $this->criarUsuario($tenant, false, ['modules.agenda.view', PermissoesDaAgenda::VER_PRAZOS]);
        $pasta = PastaFactory::createOne(['tenant' => $tenant, 'nup' => 'AV-0001'])->_real();
        $prazo = new PastaPrazo($tenant, $pasta, null);
        $prazo->definir(PastaPrazo::TIPO_PRAZO, 'Contestação', new \DateTimeImmutable('2030-03-16'), null, null);
        $this->em()->persist($prazo);
        $this->em()->flush();
        $deOutro = PastaFactory::createOne(['tenant' => $outro, 'nup' => 'AV-0002'])->_real();
        $prazoDeOutro = new PastaPrazo($outro, $deOutro, null);
        $prazoDeOutro->definir(PastaPrazo::TIPO_PRAZO, 'Réplica', new \DateTimeImmutable('2030-03-16'), null, null);
        $this->em()->persist($prazoDeOutro);
        $this->em()->flush();

        $this->logarComTenant($client, $eu, $tenant);
        $r = $this->postar($client, '/agenda/prazos/' . $prazo->getId() . '/avisar', ['ids' => [$colega->getId()]]);
        $this->postar($client, '/agenda/prazos/' . $prazoDeOutro->getId() . '/avisar', ['ids' => [$colega->getId()]]);
        $deFora = $client->getResponse()->getStatusCode();
        $this->logarComTenant($client, $soAgenda, $tenant);
        $this->postar($client, '/agenda/prazos/' . $prazo->getId() . '/avisar', ['ids' => [$colega->getId()]]);
        $semVerPrazos = $client->getResponse()->getStatusCode();
        $this->logarComTenant($client, $soPastas, $tenant);
        $this->postar($client, '/agenda/prazos/' . $prazo->getId() . '/avisar', ['ids' => [$colega->getId()]]);
        $soPastas = $client->getResponse()->getStatusCode();
        $this->logarComTenant($client, $soPrazos, $tenant);
        $comVerPrazos = $this->postar($client, '/agenda/prazos/' . $prazo->getId() . '/avisar', ['ids' => [$colega->getId()]]);

        self::assertSame(1, $r['avisados']);
        self::assertSame([404, 404, 404], [$deFora, $semVerPrazos, $soPastas], 'de outro escritório, sem Ver prazos e só com Pastas');
        self::assertSame(1, $comVerPrazos['avisados'], 'Ver prazos na agenda basta, sem o módulo Pastas');
        $avisos = $this->em()->getRepository(Notificacao::class)->findBy(['usuario' => $colega], ['id' => 'ASC']);
        self::assertCount(2, $avisos);
        self::assertSame('/pasta/' . $pasta->getId(), (string) $avisos[0]->getUrl());
        self::assertSame('Agenda: Contestação · Pasta AV-0001', $avisos[0]->getTitulo());
        self::assertStringContainsString('um prazo: Contestação · sáb, 16/03 · Pasta AV-0001', (string) $avisos[0]->getMensagem());
    }

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        // Várias requisições no mesmo teste: sem reiniciar o kernel, o armazenamento falso de CSRF fica.
        $client->disableReboot();
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

        return $client;
    }

    /**
     * @param array<string, mixed> $corpo
     *
     * @return array<string, mixed>
     */
    private function postar(KernelBrowser $client, string $url, array $corpo, ?string $token = 'TOKEN_ajax'): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
        if (null !== $token) {
            $server['HTTP_X_CSRF_TOKEN'] = $token;
        }
        $client->request('POST', $url, [], [], $server, (string) json_encode($corpo));

        return json_decode((string) $client->getResponse()->getContent(), true) ?? [];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarTenant(): Tenant
    {
        $tenant = (new Tenant())->setName('Tenant CMP ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** @param list<string> $permissoes */
    private function criarUsuario(Tenant $tenant, bool $gestor, array $permissoes = []): User
    {
        $em = $this->em();
        $user = new User();
        $user->setEmail('cmp_' . uniqid() . '@test.com');
        $user->setFullName('Usuário ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $em->persist($user);
        $role = (new TenantRole())->setTenant($tenant)->setName('Papel ' . uniqid())->setIsSystem($gestor);
        $em->persist($role);
        foreach ($permissoes as $codigo) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if (null === $perm) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup(explode('.', $codigo)[0]);
                $em->persist($perm);
            }
            $vinculo = new TenantRolePermission();
            $vinculo->setTenantRole($role);
            $vinculo->setPermission($perm);
            $em->persist($vinculo);
            $role->getTenantRolePermissions()->add($vinculo);
        }
        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $user;
    }

    private function criarEvento(Tenant $tenant, User $criador): int
    {
        $evento = new Evento();
        $evento->setTitulo('Original');
        $evento->setDataInicio(new \DateTimeImmutable('2030-03-10 09:00'));
        $evento->setDataFim(new \DateTimeImmutable('2030-03-10 10:00'));
        $evento->setCriador($criador);
        $evento->setTenant($tenant);
        $this->em()->persist($evento);
        $this->em()->flush();

        return (int) $evento->getId();
    }
}
