<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\Feriado;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Atalho "+" da folha do ponto: justificar batida esquecida direto da célula vazia.
 *
 * Cobre o contrato do POST (que não tinha NENHUM teste funcional até aqui) e a regra de
 * exibição do botão. O dia usado é sempre um dia útil do MÊS ANTERIOR: determinístico,
 * garantidamente passado e sem feriado (o tenant nasce vazio) — rodar no dia 1º não quebra.
 */
#[CoversClass(PontoController::class)]
final class JustificativaEsquecimentoControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('POST do atalho cria a justificativa de esquecimento pendente e volta para a competência de origem')]
    public function testAtalhoCriaJustificativaPendenteEPreservaCompetencia(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario();
        $this->vincular($user, $tenant);

        $dia  = $this->diaUtilDoMesAnterior();
        $chave = $dia->format('Y-m-d');
        $competencia = $dia->format('Y-m');

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/ponto/justificativa/nova', [
            'justificativa_ponto' => [
                '_token'                => $this->gerarCsrf('justificativa_ponto'),
                'tipo'                  => 'esquecimento_registro',
                'datas'                 => $chave,
                'tipoRegistroEsquecido' => 'saida',
                'horaRegistroEsquecido' => '18:05',
            ],
            'competencia' => $competencia,
        ]);

        self::assertResponseRedirects('/ponto/?competencia=' . $competencia);

        $justificativas = $this->justificativasDo($user);
        self::assertCount(1, $justificativas);

        $justificativa = $justificativas[0];
        self::assertSame('esquecimento_registro', $justificativa->getTipo());
        self::assertSame('pendente', $justificativa->getStatus());
        self::assertSame('saida', $justificativa->getTipoRegistroEsquecido());
        self::assertSame('18:05', $justificativa->getHoraRegistroEsquecido()?->format('H:i'));
        self::assertSame($chave, $justificativa->getData()->format('Y-m-d'));
    }

    #[TestDox('POST do atalho sem a hora não cria nada')]
    public function testAtalhoSemHoraNaoCriaJustificativa(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario();
        $this->vincular($user, $tenant);

        $dia = $this->diaUtilDoMesAnterior();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/ponto/justificativa/nova', [
            'justificativa_ponto' => [
                '_token'                => $this->gerarCsrf('justificativa_ponto'),
                'tipo'                  => 'esquecimento_registro',
                'datas'                 => $dia->format('Y-m-d'),
                'tipoRegistroEsquecido' => 'saida',
            ],
            'competencia' => $dia->format('Y-m'),
        ]);

        self::assertResponseRedirects('/ponto/?competencia=' . $dia->format('Y-m'));
        self::assertCount(0, $this->justificativasDo($user), 'sem hora não pode nascer justificativa');
    }

    #[TestDox('Dia sem nenhuma batida (só justificativa abonada) não oferece o atalho')]
    public function testDiaSemBatidaNaoOfereceAtalho(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario();
        $this->vincular($user, $tenant);

        $diaTrabalhado = $this->diaUtilDoMesAnterior(1);
        $diaAbonado    = $this->diaUtilDoMesAnterior(2);
        $this->criarEntrada($user, $tenant, $diaTrabalhado);
        $this->criarAbonoDeDiaInteiro($user, $tenant, $diaAbonado);

        $crawler = $this->abrirFolha($client, $user, $tenant, $diaTrabalhado->format('Y-m'));

        // O dia abonado ESTÁ na tabela — a justificativa o mantém lá mesmo sem batida nenhuma.
        // Sem essa prova, a asserção de "zero botões" passaria por a linha simplesmente não existir.
        // Seletor ancorado na TABELA: a lista "Minhas Justificativas" da mesma página também tem
        // badge de abono, e sem a âncora a prova viraria vacuosa se os rótulos se parecessem.
        self::assertCount(
            1,
            $crawler->filter('table tbody span.badge:contains("Abonada")'),
            'o dia abonado precisa estar renderizado na folha'
        );
        self::assertCount(
            0,
            $crawler->filter(sprintf('button[data-esquecimento-dia="%s"]', $diaAbonado->format('Y-m-d'))),
            'dia fechado por abono não pode oferecer atalho para criar batida'
        );
        self::assertCount(
            1,
            $crawler->filter($this->seletorAtalho($diaTrabalhado->format('Y-m-d'), 'saida')),
            'controle: o dia com batida segue oferecendo o atalho'
        );
    }

    #[TestDox('Fim de semana e feriado não oferecem o atalho')]
    public function testFimDeSemanaEFeriadoNaoOferecemAtalho(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario();
        $this->vincular($user, $tenant);

        $diaComum   = $this->diaUtilDoMesAnterior(1);
        $diaFeriado = $this->diaUtilDoMesAnterior(2);
        $sabado     = $this->sabadoDoMesAnterior();

        $this->criarEntrada($user, $tenant, $diaComum);
        $this->criarEntrada($user, $tenant, $diaFeriado);
        $this->criarEntrada($user, $tenant, $sabado);
        $this->criarFeriado($tenant, $diaFeriado);

        $crawler = $this->abrirFolha($client, $user, $tenant, $diaComum->format('Y-m'));

        self::assertCount(
            1,
            $crawler->filter($this->seletorAtalho($diaComum->format('Y-m-d'), 'saida')),
            'controle: dia útil comum oferece o atalho'
        );
        self::assertCount(
            0,
            $crawler->filter(sprintf('button[data-esquecimento-dia="%s"]', $sabado->format('Y-m-d'))),
            'fim de semana não oferece o atalho'
        );
        self::assertCount(
            0,
            $crawler->filter(sprintf('button[data-esquecimento-dia="%s"]', $diaFeriado->format('Y-m-d'))),
            'feriado não oferece o atalho'
        );
    }

    #[TestDox('POST do atalho com token CSRF inválido não cria nada')]
    public function testAtalhoComCsrfInvalidoNaoCriaJustificativa(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario();
        $this->vincular($user, $tenant);

        $dia = $this->diaUtilDoMesAnterior();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/ponto/justificativa/nova', [
            'justificativa_ponto' => [
                '_token'                => 'token-invalido',
                'tipo'                  => 'esquecimento_registro',
                'datas'                 => $dia->format('Y-m-d'),
                'tipoRegistroEsquecido' => 'saida',
                'horaRegistroEsquecido' => '18:05',
            ],
            'competencia' => $dia->format('Y-m'),
        ]);

        // Redirect (e não 4xx/5xx): prova que a recusa veio do CSRF do form, não de outro caminho.
        self::assertResponseRedirects('/ponto/?competencia=' . $dia->format('Y-m'));
        self::assertCount(0, $this->justificativasDo($user), 'CSRF inválido não pode criar justificativa');
    }

    #[TestDox('A folha mostra o "+" na batida faltante e não mostra na batida já registrada')]
    public function testFolhaMostraAtalhoApenasNaCelulaVazia(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario();
        $this->vincular($user, $tenant);

        $dia   = $this->diaUtilDoMesAnterior();
        $chave = $dia->format('Y-m-d');
        $this->criarEntrada($user, $tenant, $dia);

        $crawler = $this->abrirFolha($client, $user, $tenant, $dia->format('Y-m'));

        self::assertCount(
            1,
            $crawler->filter($this->seletorAtalho($chave, 'saida')),
            'saída faltante deve oferecer o atalho'
        );
        self::assertCount(
            0,
            $crawler->filter($this->seletorAtalho($chave, 'entrada')),
            'entrada já registrada não pode oferecer o atalho'
        );
        self::assertStringContainsString(
            '09:00:00',
            $crawler->filter('table')->first()->html(),
            'a célula preenchida continua imprimindo o horário — o ramo novo não pode engoli-la'
        );
    }

    #[TestDox('O "+" some quando já existe esquecimento pendente para o mesmo dia e a mesma batida')]
    public function testAtalhoSomeComEsquecimentoPendente(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario();
        $this->vincular($user, $tenant);

        $dia   = $this->diaUtilDoMesAnterior();
        $chave = $dia->format('Y-m-d');
        $this->criarEntrada($user, $tenant, $dia);
        $this->criarEsquecimentoPendente($user, $tenant, $dia, 'saida');

        $crawler = $this->abrirFolha($client, $user, $tenant, $dia->format('Y-m'));

        self::assertCount(
            0,
            $crawler->filter($this->seletorAtalho($chave, 'saida')),
            'não pode oferecer um segundo esquecimento para a batida já justificada'
        );
        self::assertCount(
            1,
            $crawler->filter($this->seletorAtalho($chave, 'repouso')),
            'as demais batidas faltantes do dia seguem com atalho'
        );
    }

    // ----------------------------------------------------------------- helpers

    private function seletorAtalho(string $chaveDia, string $campo): string
    {
        return sprintf('button[data-esquecimento-dia="%s"][data-esquecimento-campo="%s"]', $chaveDia, $campo);
    }

    private function abrirFolha(KernelBrowser $client, User $user, Tenant $tenant, string $competencia): \Symfony\Component\DomCrawler\Crawler
    {
        $this->logarComTenant($client, $user, $tenant);
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $crawler = $client->request('GET', '/ponto/?competencia=' . $competencia);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** N-ésimo dia útil do mês anterior: sempre no passado, sempre fora do fim de semana. */
    private function diaUtilDoMesAnterior(int $ordinal = 1): \DateTimeImmutable
    {
        $dia = new \DateTimeImmutable('first day of last month');
        $encontrados = 0;
        while (true) {
            if ((int) $dia->format('N') <= 5) {
                $encontrados++;
                if ($encontrados === $ordinal) {
                    return $dia->setTime(0, 0, 0);
                }
            }
            $dia = $dia->modify('+1 day');
        }
    }

    private function sabadoDoMesAnterior(): \DateTimeImmutable
    {
        $dia = new \DateTimeImmutable('first day of last month');
        while ((int) $dia->format('N') !== 6) {
            $dia = $dia->modify('+1 day');
        }

        return $dia->setTime(0, 0, 0);
    }

    /** @return JustificativaPonto[] */
    private function justificativasDo(User $user): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();

        return $em->createQuery(
            'SELECT j FROM ' . JustificativaPonto::class . ' j WHERE j.user = :user'
        )->setParameter('user', $user->getId())->getResult();
    }

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant ESQUECIMENTO ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuario(): User
    {
        $container = static::getContainer();
        $em     = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('esquecimento_' . uniqid() . '@test.com');
        $user->setFullName('User ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** Vínculo ativo com role isSystem → bypassa o PermissionChecker, isolando o que se quer testar. */
    private function vincular(User $user, Tenant $tenant): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Admin ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();
    }

    private function criarEntrada(User $user, Tenant $tenant, \DateTimeImmutable $dia): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setTenant($tenant);
        $registro->setTipo(RegistroPonto::TIPO_ENTRADA);
        $registro->setDataHora(new \DateTime($dia->format('Y-m-d') . ' 09:00:00'));
        $registro->setSedeNomeSnapshot('Teste');
        $em->persist($registro);
        $em->flush();
    }

    private function criarEsquecimentoPendente(User $user, Tenant $tenant, \DateTimeImmutable $dia, string $campo): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $justificativa = new JustificativaPonto();
        $justificativa->setUser($user);
        $justificativa->setTenant($tenant);
        $justificativa->setData(new \DateTime($dia->format('Y-m-d')));
        $justificativa->setTipo('esquecimento_registro');
        $justificativa->setStatus('pendente');
        $justificativa->setTipoRegistroEsquecido($campo);
        $justificativa->setHoraRegistroEsquecido(new \DateTime('18:05'));
        $em->persist($justificativa);
        $em->flush();
    }

    /** Abono de dia inteiro: mantém o dia na folha mesmo sem nenhuma batida (atestado, folga). */
    private function criarAbonoDeDiaInteiro(User $user, Tenant $tenant, \DateTimeImmutable $dia): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $justificativa = new JustificativaPonto();
        $justificativa->setUser($user);
        $justificativa->setTenant($tenant);
        $justificativa->setData(new \DateTime($dia->format('Y-m-d')));
        $justificativa->setTipo('atestado_medico');
        $justificativa->setStatus('abonado');
        $em->persist($justificativa);
        $em->flush();
    }

    private function criarFeriado(Tenant $tenant, \DateTimeImmutable $dia): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $feriado = new Feriado();
        $feriado->setNome('Feriado de teste');
        $feriado->setData(new \DateTime($dia->format('Y-m-d')));
        $feriado->setRecorrente(false);
        $feriado->setTenant($tenant);
        $em->persist($feriado);
        $em->flush();
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

    private function gerarCsrf(string $tokenId): string
    {
        return 'TOKEN_' . $tokenId;
    }
}
