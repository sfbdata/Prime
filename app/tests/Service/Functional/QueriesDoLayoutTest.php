<?php

declare(strict_types=1);

namespace App\Tests\Service\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Trava de consultas do layout (spec `permission-checker-memoria.md`, Testes): Meu Perfil é a página mais
 * simples, então quase tudo o que ela consulta vem do layout (sidebar e `base.html.twig`). O teto é o valor
 * medido depois da memória do vínculo e do EAGER das permissões, com uma folga pequena.
 */
#[CoversClass(PermissionChecker::class)]
final class QueriesDoLayoutTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const FOLGA = 3;

    /** As permissões do colaborador da medição de 05/11/2026. */
    private const PERMISSOES = [
        'modules.agenda.view', 'modules.bi.view', 'modules.clientes.view', 'modules.cobrancas.view', 'modules.djen.view',
        'modules.expediente.view', 'modules.financeiro.view', 'modules.pastas.view', 'modules.ponto.view',
        'modules.processos.view', 'modules.tarefas.view', 'modules.calculo.view', 'modules.kanban.view',
        'modules.servicedesk.view', 'modules.trabalho_externo.view', 'modules.inteligencia.view',
        'resources.agenda.ver_prazos', 'resources.cliente.view', 'resources.cliente.edit',
        'resources.pasta.view', 'resources.pasta.edit', 'resources.processo.view', 'resources.processo.edit',
        'resources.cobranca.gerenciar',
    ];

    /** @return iterable<string, array{string, int}> */
    public static function pessoas(): iterable
    {
        yield 'primário' => ['primario', 57];
        yield 'colaborador' => ['colaborador', 55];
    }

    #[DataProvider('pessoas')]
    #[TestDox('Meu Perfil, como $pessoa, não passa de $medido + folga consultas')]
    public function testMeuPerfilNaoPassaDoTeto(string $pessoa, int $medido): void
    {
        $this->client = static::createClient();
        [$primario, $tenant] = $this->criarEscritorio();
        $ana = $this->criarColaborador($tenant, 'Ana Equipe', self::PERMISSOES);
        $quem = $pessoa === 'primario' ? $primario : $ana;

        // Aquecimento, como na medição: a primeira requisição usa o kernel que montou o cenário.
        $this->contar($primario, $tenant, '/notificacoes/count', true);

        [$status, $consultas] = $this->contar($quem, $tenant, '/perfil', false);
        $teto = $medido + self::FOLGA;

        self::assertSame(200, $status);
        self::assertLessThanOrEqual($teto, $consultas, sprintf(
            'Meu Perfil, como %s, fez %d consultas (teto %d = %d medidas + %d de folga). Confira se entrou consulta repetida no layout (sidebar, base.html.twig, PermissionExtension) antes de subir o teto.',
            $pessoa,
            $consultas,
            $teto,
            $medido,
            self::FOLGA,
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
