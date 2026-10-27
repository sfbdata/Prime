<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Functional;

use App\Agenda\Controller\AgendaNovaController;
use App\Agenda\Entity\Evento;
use App\Agenda\Service\PermissoesDaAgenda;
use App\Agenda\Enum\TipoDeCompromisso;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\PastaPrazo;
use App\Profile\Entity\UserProfile;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(AgendaNovaController::class)]
final class AgendaNovaControllerTest extends JusPrimeWebTestCase
{
    private const PERIODO = '/agenda/compromissos?de=2030-03-01&ate=2030-03-31';

    #[TestDox('/agenda mostra a tela nova no arranjo do desenho: lateral e calendário lado a lado, próximos feriados abaixo')]
    public function testArranjoDaTela(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarUsuario($tenant, true), $tenant);

        $crawler = $client->request('GET', '/agenda');

        self::assertResponseIsSuccessful();
        self::assertSame('agenda_index', $client->getRequest()->attributes->get('_route'));
        self::assertCount(1, $crawler->filter('.ag-page > .ag-topo > h1.ag-titulo'));
        self::assertCount(1, $crawler->filter('.ag-page > .ag-topo > .ag-resumo'));
        self::assertCount(1, $crawler->filter('.ag-page > .ag-corpo > .ag-lado'));
        self::assertCount(1, $crawler->filter('.ag-page > .ag-corpo > .ag-direita > .ag-cal'));
        self::assertCount(1, $crawler->filter('.ag-corpo > .ag-direita > .ag-cal + .ag-pf'));
        self::assertCount(1, $crawler->filter('.ag-lado > .ag-lado-novo > #agNovo'));
        self::assertCount(1, $crawler->filter('.ag-cal > .ag-barra > #agVistas'));
        self::assertSame('Agenda', trim($crawler->filter('.navtop-subnav-link.active')->text()));
    }

    #[TestDox('/agenda/classica serve a tela de 18/10 enquanto a 1A não é aprovada (docs/specs/agenda-1a.md, D5)')]
    public function testClassicaContinuaDePe(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarUsuario($tenant, true), $tenant);

        $crawler = $client->request('GET', '/agenda/classica');

        self::assertResponseIsSuccessful();
        self::assertSame('agenda_classica', $client->getRequest()->attributes->get('_route'));
        self::assertCount(1, $crawler->filter('.ag-page > .ag-corpo > .ag-lado'));
    }

    #[TestDox('o JSON leva local e descrição separados, e a observação continua juntando os dois')]
    public function testLocalEDescricaoSeparados(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $eu = $this->criarUsuario($tenant, true);
        $evento = $this->criarEvento($tenant, $eu, 'Audiência', '2030-03-10 09:00', Evento::VISIBILIDADE_TODOS);
        $evento->setLocal('Fórum Central, Sala 3')->setDescricao('Levar os quesitos');
        $this->criarEvento($tenant, $eu, 'Sem detalhe', '2030-03-11 09:00', Evento::VISIBILIDADE_TODOS);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->logarComTenant($client, $eu, $tenant);
        $porTitulo = array_column($this->itens($client, self::PERIODO), null, 'titulo');

        self::assertSame('Fórum Central, Sala 3', $porTitulo['Audiência']['local']);
        self::assertSame('Levar os quesitos', $porTitulo['Audiência']['descricao']);
        self::assertSame("Fórum Central, Sala 3\nLevar os quesitos", $porTitulo['Audiência']['observacao']);
        self::assertNull($porTitulo['Sem detalhe']['local']);
        self::assertNull($porTitulo['Sem detalhe']['descricao']);
    }

    #[TestDox('Equipe: vê os próprios, os da equipe e não vê o "Só eu" do colega nem nada de outro escritório')]
    public function testEquipeRespeitaVisibilidadeETenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $eu = $this->criarUsuario($tenantA, true);
        $colega = $this->criarUsuario($tenantA, true);
        $deFora = $this->criarUsuario($tenantB, true);
        $this->criarEvento($tenantA, $eu, 'Meu atendimento', '2030-03-10 09:00', Evento::VISIBILIDADE_SOMENTE_EU);
        $this->criarEvento($tenantA, $colega, 'Reunião do colega', '2030-03-11 14:00', Evento::VISIBILIDADE_TODOS);
        $this->criarEvento($tenantA, $colega, 'Particular do colega', '2030-03-12 10:00', Evento::VISIBILIDADE_SOMENTE_EU);
        $this->criarEvento($tenantB, $deFora, 'Evento de outro escritório', '2030-03-13 10:00', Evento::VISIBILIDADE_TODOS);

        $this->logarComTenant($client, $eu, $tenantA);
        $titulos = array_column($this->itens($client, self::PERIODO . '&escopo=equipe'), 'titulo');

        self::assertContains('Meu atendimento', $titulos);
        self::assertContains('Reunião do colega', $titulos);
        self::assertNotContains('Particular do colega', $titulos);
        self::assertNotContains('Evento de outro escritório', $titulos);
    }

    #[TestDox('Minha agenda: só os que eu criei ou de que participo')]
    public function testMinhaAgenda(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $eu = $this->criarUsuario($tenant, true);
        $colega = $this->criarUsuario($tenant, true);
        $this->criarEvento($tenant, $eu, 'Meu atendimento', '2030-03-10 09:00', Evento::VISIBILIDADE_TODOS);
        $this->criarEvento($tenant, $colega, 'Reunião do colega', '2030-03-11 14:00', Evento::VISIBILIDADE_TODOS);

        $this->logarComTenant($client, $eu, $tenant);
        $itens = $this->itens($client, self::PERIODO . '&escopo=minha');

        self::assertSame(['Meu atendimento'], array_column($itens, 'titulo'));
        self::assertTrue($itens[0]['meu']);
        self::assertSame('09:00', $itens[0]['h1']);
    }

    #[TestDox('Prazos e audiências das pastas entram, os cumpridos como concluídos (desenho 09, L152); de pasta na lixeira não')]
    public function testPrazosDasPastas(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $eu = $this->criarUsuario($tenant, true);
        $pasta = PastaFactory::createOne(['tenant' => $tenant, 'nup' => 'AG-0001'])->_real();
        $lixo = PastaFactory::createOne(['tenant' => $tenant, 'nup' => 'AG-0002'])->_real();
        $this->criarPrazo($tenant, $pasta, PastaPrazo::TIPO_AUDIENCIA, 'Conciliação', '2030-03-15', '14:30', null);
        $this->criarPrazo($tenant, $pasta, PastaPrazo::TIPO_PRAZO, 'Contestação', '2030-03-16', null, null);
        $this->criarPrazo($tenant, $pasta, PastaPrazo::TIPO_PRAZO, 'Já cumprido', '2030-03-17', null, $eu);
        $lixo->marcarExcluida($eu, new \DateTimeImmutable());
        $this->criarPrazo($tenant, $lixo, PastaPrazo::TIPO_PRAZO, 'Da lixeira', '2030-03-18', null, null);

        $this->logarComTenant($client, $eu, $tenant);
        $itens = $this->itens($client, self::PERIODO);
        $porTitulo = array_column($itens, null, 'titulo');

        self::assertSame(['Audiência · Pasta AG-0001', 'Contestação · Pasta AG-0001', 'Já cumprido · Pasta AG-0001'], array_column($itens, 'titulo'));
        self::assertSame('concluido', $porTitulo['Já cumprido · Pasta AG-0001']['status']);
        self::assertNull($porTitulo['Contestação · Pasta AG-0001']['status']);
        self::assertSame('aud', $porTitulo['Audiência · Pasta AG-0001']['calendario']);
        self::assertSame('14:30', $porTitulo['Audiência · Pasta AG-0001']['h1']);
        self::assertTrue($porTitulo['Contestação · Pasta AG-0001']['dia']);
        self::assertSame($pasta->getId(), $porTitulo['Contestação · Pasta AG-0001']['pastaId']);
    }

    #[TestDox('Com acesso à Agenda e sem acesso às pastas, os prazos das pastas não vêm')]
    public function testSemPermissaoDePastasNaoVePrazos(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $so = $this->criarUsuario($tenant, false, null, ['modules.agenda.view']);
        $pasta = PastaFactory::createOne(['tenant' => $tenant, 'nup' => 'AG-0003'])->_real();
        $this->criarPrazo($tenant, $pasta, PastaPrazo::TIPO_PRAZO, 'Contestação', '2030-03-16', null, null);
        $this->criarEvento($tenant, $so, 'Meu atendimento', '2030-03-10 09:00', Evento::VISIBILIDADE_TODOS);

        $this->logarComTenant($client, $so, $tenant);

        self::assertSame(['Meu atendimento'], array_column($this->itens($client, self::PERIODO), 'titulo'));
    }

    #[TestDox('Ver prazos na agenda é permissão própria: com Pastas e sem ela, sem prazos mas com a pasta do compromisso; com ela e sem Pastas, com prazos')]
    public function testPermissaoVerPrazosSeparadaDePastas(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $soPastas = $this->criarUsuario($tenant, false, null, ['modules.agenda.view', 'modules.pastas.view']);
        $soPrazos = $this->criarUsuario($tenant, false, null, ['modules.agenda.view', PermissoesDaAgenda::VER_PRAZOS]);
        $pasta = PastaFactory::createOne(['tenant' => $tenant, 'nup' => 'AG-0004'])->_real();
        $this->criarPrazo($tenant, $pasta, PastaPrazo::TIPO_PRAZO, 'Contestação', '2030-03-16', null, null);
        $this->criarEvento($tenant, $soPastas, 'Reunião da pasta', '2030-03-10 09:00', Evento::VISIBILIDADE_TODOS)->setPasta($pasta);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->logarComTenant($client, $soPastas, $tenant);
        $comPastas = $this->itens($client, self::PERIODO);
        $this->logarComTenant($client, $soPrazos, $tenant);
        $comPrazos = $this->itens($client, self::PERIODO);

        self::assertSame(['Reunião da pasta'], array_column($comPastas, 'titulo'));
        self::assertSame($pasta->getId(), $comPastas[0]['pastaId'], 'a pasta do compromisso segue o módulo Pastas');
        self::assertSame(['Reunião da pasta', 'Contestação · Pasta AG-0004'], array_column($comPrazos, 'titulo'));
        self::assertNull($comPrazos[0]['pastaId'], 'sem o módulo Pastas, o compromisso não mostra a pasta');
        self::assertNull($comPrazos[1]['pastaId'], 'nem o prazo leva o atalho para a pasta (daria 403)');
    }

    #[TestDox('Contagem da lateral: os meus de cada calendário em qualquer data, o antigo sem tipo pela cor como na grade, sem os de outra pessoa')]
    public function testContagensDaLateral(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $eu = $this->criarUsuario($tenant, false, null, ['modules.agenda.view']);
        $colega = $this->criarUsuario($tenant, false, null, ['modules.agenda.view']);
        $this->criarEvento($tenant, $eu, 'Antigo azul', '2030-03-10 09:00', Evento::VISIBILIDADE_TODOS);
        $this->criarEvento($tenant, $eu, 'Antigo fora do período', '2031-07-01 09:00', Evento::VISIBILIDADE_TODOS);
        $this->criarEvento($tenant, $eu, 'Reunião nova', '2030-03-11 09:00', Evento::VISIBILIDADE_TODOS)->setTipo(TipoDeCompromisso::Reuniao);
        $this->criarEvento($tenant, $eu, 'Meu particular', '2030-03-12 09:00', Evento::VISIBILIDADE_SOMENTE_EU)->setTipo(TipoDeCompromisso::Meus)->setCor(Evento::COR_ROXO);
        $this->criarEvento($tenant, $colega, 'Do colega', '2030-03-10 10:00', Evento::VISIBILIDADE_TODOS)->setTipo(TipoDeCompromisso::Reuniao);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->logarComTenant($client, $eu, $tenant);

        $client->request('GET', self::PERIODO);
        $r = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame(['meus' => 1, 'aud' => 2, 'reun' => 1, 'prazo' => 0, 'urg' => 0, 'pess' => 0, 'lemb' => 0], $r['contagens']);
        $calendarioDoAntigo = array_column($r['itens'], 'calendario', 'titulo')['Antigo azul'];
        self::assertSame('aud', $calendarioDoAntigo, 'na grade, o antigo azul também aparece em Audiências');
    }

    #[TestDox('"Para todos" com participantes só aparece para o criador e os participantes')]
    public function testParaTodosComParticipantes(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $criador = $this->criarUsuario($tenant, true);
        $participante = $this->criarUsuario($tenant, true);
        $deFora = $this->criarUsuario($tenant, true);
        $evento = $this->criarEvento($tenant, $criador, 'Reunião fechada', '2030-03-10 09:00', Evento::VISIBILIDADE_TODOS);
        $evento->addParticipante($participante);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->logarComTenant($client, $participante, $tenant);
        self::assertSame(['Reunião fechada'], array_column($this->itens($client, self::PERIODO), 'titulo'));
        $this->logarComTenant($client, $deFora, $tenant);
        self::assertSame([], $this->itens($client, self::PERIODO));
    }

    #[TestDox('Aniversário de colega ativo aparece no dia, com o nome curto')]
    public function testAniversarios(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $eu = $this->criarUsuario($tenant, true);
        $colega = $this->criarUsuario($tenant, false, 'Maria Clara Souza Lima');
        $perfil = new UserProfile($colega);
        $perfil->setDataNascimento(new \DateTime('1990-03-20'));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($perfil);
        $em->flush();

        $this->logarComTenant($client, $eu, $tenant);
        $aniv = array_values(array_filter($this->itens($client, self::PERIODO), static fn (array $i): bool => 'aniv' === $i['calendario']));

        self::assertCount(1, $aniv);
        self::assertSame('2030-03-20', $aniv[0]['data']);
        self::assertSame('🎂 Aniversário de Maria Clara', $aniv[0]['titulo']);
    }

    #[TestDox('Período inválido devolve 400 e sem acesso ao módulo devolve 403')]
    public function testErros(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarUsuario($tenant, true), $tenant);
        $client->request('GET', '/agenda/compromissos?de=2030-03-31&ate=2030-03-01');
        self::assertResponseStatusCodeSame(400);

        $outro = $this->criarTenant();
        $this->logarComTenant($client, $this->criarUsuario($outro, false), $outro);
        $client->request('GET', self::PERIODO);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/agenda/nova');
        self::assertResponseRedirects();
        $client->request('GET', '/agenda');
        self::assertResponseRedirects();
    }

    /** @return list<array<string, mixed>> */
    private function itens(KernelBrowser $client, string $endereco): array
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $client->request('GET', $endereco);
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true)['itens'];
    }

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant AGN ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /** @param list<string> $permissoes */
    private function criarUsuario(Tenant $tenant, bool $gestor, ?string $nome = null, array $permissoes = []): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('agn_' . uniqid() . '@test.com');
        $user->setFullName($nome ?? 'Usuário ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel ' . uniqid());
        $role->setIsSystem($gestor);
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

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarEvento(Tenant $tenant, User $criador, string $titulo, string $inicio, string $visibilidade): Evento
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $evento = new Evento();
        $evento->setTitulo($titulo);
        $evento->setDataInicio(new \DateTimeImmutable($inicio));
        $evento->setDataFim((new \DateTimeImmutable($inicio))->modify('+1 hour'));
        $evento->setCriador($criador);
        $evento->setTenant($tenant);
        $evento->setVisibilidade($visibilidade);
        $em->persist($evento);
        $em->flush();

        return $evento;
    }

    private function criarPrazo(Tenant $tenant, object $pasta, string $tipo, string $titulo, string $data, ?string $hora, ?User $cumpridoPor): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $prazo = new PastaPrazo($tenant, $pasta, null);
        $prazo->definir($tipo, $titulo, new \DateTimeImmutable($data), $hora, null);
        if (null !== $cumpridoPor) {
            $prazo->cumprir($cumpridoPor);
        }
        $em->persist($prazo);
        $em->flush();
    }
}
