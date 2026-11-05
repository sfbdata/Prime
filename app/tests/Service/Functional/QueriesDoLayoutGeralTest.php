<?php

declare(strict_types=1);

namespace App\Tests\Service\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\NivelDeAcesso;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Spec memoria-do-layout: trava o número de consultas do layout (Meu Perfil, página inteira) e do contador do sino
 * (/notificacoes/count), para o primário e para um colaborador com cadeado. O teto é o medido depois da memória
 * por requisição mais uma folga de 3: se subir, alguém voltou a repetir consulta no layout.
 *
 * E a consulta repetida, igual (mesmo SQL, mesmos parâmetros), não volta nas tabelas que agora ficam guardadas.
 */
#[CoversNothing]
final class QueriesDoLayoutGeralTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const FOLGA = 3;

    /** Medido depois da memória por requisição (este cenário). */
    private const MEDIDO = [
        'primario'    => ['/perfil' => 75, '/notificacoes/count' => 10],
        'colaborador' => ['/perfil' => 70, '/notificacoes/count' => 12],
    ];

    private const TABELAS_GUARDADAS = ['pedido_exclusao_conta', 'central_configuracao_presenca', 'preferencia_usuario', 'pasta_cadeado'];

    /** @return iterable<string, array{string, string}> */
    public static function paginas(): iterable
    {
        foreach (['primario', 'colaborador'] as $quem) {
            yield "$quem /perfil" => [$quem, '/perfil'];
            yield "$quem /notificacoes/count" => [$quem, '/notificacoes/count'];
        }
    }

    #[DataProvider('paginas')]
    #[TestDox('Teto de consultas: $quem em $url')]
    public function testTetoDeConsultas(string $quem, string $url): void
    {
        $this->client = static::createClient();
        [$primario, $tenant] = $this->criarEscritorio();
        $colaborador = $this->criarColaborador($tenant, 'Ana Layout', ['modules.expediente.view', 'modules.pastas.view', 'resources.pasta.view']);
        $pasta = $this->criarPasta($tenant);
        $this->trancar($pasta, $primario, [[$colaborador, NivelDeAcesso::Visualizar]]);
        $this->trancar($this->criarPasta($tenant, 'OUTRO CLIENTE'), $primario);

        $pessoa = $quem === 'primario' ? $primario : $colaborador;
        // Aquecimento: a 1ª requisição do kernel leva junto as consultas da montagem do cenário.
        $this->medir($pessoa, $tenant, '/notificacoes/count');
        [$total, $repetidas] = $this->medir($pessoa, $tenant, $url);

        $teto = self::MEDIDO[$quem][$url] + self::FOLGA;
        self::assertLessThanOrEqual($teto, $total, sprintf('%s em %s: %d consultas (teto %d)', $quem, $url, $total, $teto));
        self::assertSame([], $repetidas, 'consulta repetida numa tabela guardada');
    }

    /** @return array{0: int, 1: array<string, int>} total de consultas e as repetidas iguais das tabelas guardadas */
    private function medir(User $pessoa, Tenant $tenant, string $url): array
    {
        $this->logarComTenant($this->client, $pessoa, $tenant);
        $this->em()->clear();
        $this->client->enableProfiler();
        $ajax = str_contains($url, '/count') ? ['HTTP_X-Requested-With' => 'XMLHttpRequest'] : [];
        $this->client->request('GET', $url, [], [], $ajax);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $url);

        $perfil = $this->client->getProfile();
        self::assertNotFalse($perfil);
        /** @var DoctrineDataCollector $db */
        $db = $perfil->getCollector('db');

        $exatas = [];
        foreach ($db->getQueries() as $lista) {
            foreach ($lista as $q) {
                $sql = (string) $q['sql'];
                foreach (self::TABELAS_GUARDADAS as $tabela) {
                    if (preg_match('/^\s*SELECT\b.*\bFROM\s+' . $tabela . '\b/is', $sql)) {
                        $chave = $sql . ' | ' . json_encode($q['params'] ?? []);
                        $exatas[$chave] = ($exatas[$chave] ?? 0) + 1;
                    }
                }
            }
        }

        return [$db->getQueryCount(), array_filter($exatas, static fn (int $n): bool => $n > 1)];
    }
}
