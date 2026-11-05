<?php

declare(strict_types=1);

namespace App\Tests\Service\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Trava "o número de consultas não cresce por colega": o perfil do usuário (UserProfile) é o lado inverso de um
 * OneToOne em User, que o Doctrine não troca por proxy. Sem o perfil carregado junto na consulta da equipe, cada
 * colega listado custava uma consulta a mais. As telas abaixo listam a equipe inteira do escritório; com 1 e com 6
 * colegas elas precisam fazer o MESMO número de consultas.
 */
#[CoversClass(UserRepository::class)]
#[CoversClass(UserTenantRepository::class)]
final class QueriesPorColegaTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES = [
        'modules.agenda.view', 'modules.expediente.view', 'modules.pastas.view', 'modules.tarefas.view',
        'modules.bi.view', 'resources.agenda.ver_prazos', 'resources.pasta.view', 'resources.pasta.edit',
    ];

    private const COLEGAS_A_MAIS = 5;

    /** @return iterable<string, array{string, string, bool}> */
    public static function telas(): iterable
    {
        yield 'dashboard, como primário' => ['/dashboard', 'primario', false];
        yield 'agenda, como primário' => ['/agenda', 'primario', false];
        yield 'central, como primário' => ['/central', 'primario', false];
        yield 'equipe da central, como primário' => ['/central/equipe', 'primario', false];
        yield 'minhas metas, como primário' => ['/tarefas/minhas', 'primario', false];
        yield 'acervo geral, como primário' => ['/expediente/painel/acervo-geral', 'primario', true];
        yield 'pasta, como primário' => ['pasta', 'primario', false];
        yield 'pasta, como colaborador' => ['pasta', 'colaborador', false];
        yield 'agenda, como colaborador' => ['/agenda', 'colaborador', false];
        yield 'central, como colaborador' => ['/central', 'colaborador', false];
    }

    #[DataProvider('telas')]
    #[TestDox('$url, como $pessoa, faz as mesmas consultas com 1 e com 6 colegas')]
    public function testConsultasNaoCrescemPorColega(string $url, string $pessoa, bool $ajax): void
    {
        $this->client = static::createClient();
        [$primario, $tenant] = $this->criarEscritorio();
        $ana  = $this->criarColaborador($tenant, 'Ana Equipe', self::PERMISSOES);
        $quem = $pessoa === 'primario' ? $primario : $ana;

        if ($url === 'pasta') {
            $pasta = $this->criarPasta($tenant);
            $pasta->setResponsavel($ana);
            $this->em()->flush();
            $url = '/pasta/' . $pasta->getId();
        }

        // Aquecimento: a primeira requisição usa o kernel que montou o cenário.
        $this->contar($quem, $tenant, $url, $ajax);

        [$statusAntes, $comUm] = $this->contar($quem, $tenant, $url, $ajax);

        // A contagem limpou o EntityManager: o escritório volta a ser gerenciado antes de ganhar colegas.
        $escritorio = $this->em()->find(Tenant::class, $tenant->getId());
        self::assertInstanceOf(Tenant::class, $escritorio);
        for ($i = 1; $i <= self::COLEGAS_A_MAIS; ++$i) {
            $this->criarColaborador($escritorio, 'Colega ' . $i, ['modules.expediente.view']);
        }

        [$statusDepois, $comSeis] = $this->contar($quem, $escritorio, $url, $ajax);

        self::assertSame(200, $statusAntes);
        self::assertSame(200, $statusDepois);
        self::assertSame($comUm, $comSeis, sprintf(
            '%s, como %s: %d consultas com 1 colega e %d com %d colegas a mais (diferença %d). Alguma lista da equipe '
            . 'voltou a buscar algo por pessoa; o caso conhecido é o perfil (User::$profile, lado inverso de OneToOne): '
            . 'carregue junto com leftJoin(\'u.profile\', \'pf\')->addSelect(\'pf\').',
            $url,
            $pessoa,
            $comUm,
            $comSeis,
            self::COLEGAS_A_MAIS,
            $comSeis - $comUm,
        ));
    }

    /** @return array{0: int, 1: int} status e número de consultas */
    private function contar(User $quem, Tenant $tenant, string $url, bool $ajax): array
    {
        $this->logarComTenant($this->client, $quem, $tenant);
        $this->em()->clear();
        $this->client->enableProfiler();
        $this->client->request('GET', $url, [], [], $ajax ? ['HTTP_X-Requested-With' => 'XMLHttpRequest'] : []);
        $perfil = $this->client->getProfile();
        self::assertNotFalse($perfil, 'O profiler não está ligado no ambiente de teste.');
        $db = $perfil->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $db);

        return [$this->client->getResponse()->getStatusCode(), $db->getQueryCount()];
    }
}
