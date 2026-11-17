<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use App\Voto\Entity\Assembleia;
use App\Voto\EventListener\BarreiraDaEquipeListener;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Trava o alcance da `BarreiraDaEquipeListener` (spec voto-equipe-e-master-1611, seção 1.2), no molde do
 * `BarreiraDoCadeadoRotasArquiteturaTest`. De toda rota `voto_assembleia_*` exige UM destes:
 *  1. a rota leva o `{id}` da assembleia (a barreira o resolve pelo `AssembleiaDaRota`);
 *  2. a action recebe uma `Assembleia`;
 *  3. a rota está em `BarreiraDaEquipeListener::ROTAS_SEM_ASSEMBLEIA`, com o motivo escrito lá.
 */
#[CoversNothing]
final class BarreiraDaEquipeRotasArquiteturaTest extends KernelTestCase
{
    /** @return array<string, Route> */
    private static function rotas(): array
    {
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');

        return array_filter(
            $router->getRouteCollection()->all(),
            static fn (Route $r, string $nome): bool => str_starts_with($nome, BarreiraDaEquipeListener::PREFIXO_DAS_ROTAS),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private static function recebeAssembleia(Route $rota): bool
    {
        $controller = $rota->getDefault('_controller');
        if (!\is_string($controller) || !str_contains($controller, '::')) {
            return false;
        }
        [$classe, $metodo] = explode('::', $controller, 2);
        foreach ((new \ReflectionMethod($classe, $metodo))->getParameters() as $parametro) {
            $tipo = $parametro->getType();
            if ($tipo instanceof \ReflectionNamedType && is_a($tipo->getName(), Assembleia::class, true)) {
                return true;
            }
        }

        return false;
    }

    #[TestDox('toda rota voto_assembleia_* resolve a assembleia (id da rota ou argumento) ou é exceção com motivo')]
    public function testTodaRotaEstaNaBarreira(): void
    {
        $rotas = self::rotas();
        self::assertGreaterThan(40, \count($rotas), 'o router devolveu rotas do Voto de menos: o filtro quebrou?');

        $descobertas = [];
        foreach ($rotas as $nome => $rota) {
            if (isset(BarreiraDaEquipeListener::ROTAS_SEM_ASSEMBLEIA[$nome])) {
                continue;
            }
            if (!\in_array('id', $rota->compile()->getVariables(), true) && !self::recebeAssembleia($rota)) {
                $descobertas[] = $nome . ' ' . $rota->getPath();
            }
        }

        self::assertSame([], $descobertas, "Rota voto_assembleia_* que a BarreiraDaEquipeListener não alcança: quem está Oculto a abriria.\nLeve o {id} da assembleia, receba a Assembleia ou registre a exceção, com o motivo, no listener.");
    }

    #[TestDox('as listas de exceção só têm rotas que existem, com motivo escrito, e a exceção não leva {id}')]
    public function testExcecoesNaoEnvelhecem(): void
    {
        $rotas = self::rotas();
        foreach ([...BarreiraDaEquipeListener::ROTAS_SEM_ASSEMBLEIA, ...BarreiraDaEquipeListener::ROTAS_DO_VISUALIZAR] as $nome => $motivo) {
            self::assertArrayHasKey($nome, $rotas, "exceção '{$nome}' não é (mais) rota do Voto: tire da lista.");
            self::assertGreaterThan(20, mb_strlen($motivo), "exceção '{$nome}' sem motivo.");
        }
        foreach (array_keys(BarreiraDaEquipeListener::ROTAS_SEM_ASSEMBLEIA) as $nome) {
            self::assertNotContains('id', $rotas[$nome]->compile()->getVariables(), "'{$nome}' leva {id}: a barreira a alcança, tire da exceção.");
        }
    }

    #[TestDox('o Visualizar alcança só as minutas (leitura) e a decisão do conselho (a única escrita), e a escrita confere o papel no UseCase')]
    public function testVisualizarSoAlcancaAsMinutasEADecisaoDoConselho(): void
    {
        self::assertSame(
            ['voto_assembleia_minuta', 'voto_assembleia_pauta_minuta', 'voto_assembleia_avaliacao_decidir'],
            array_keys(BarreiraDaEquipeListener::ROTAS_DO_VISUALIZAR),
            'rota nova em ROTAS_DO_VISUALIZAR dá escrita a quem só lê: decisão do Coordenador e re-revisão do teto.',
        );
        $rota = self::rotas()['voto_assembleia_avaliacao_decidir'];
        self::assertSame(['POST'], $rota->getMethods());
        // O papel é conferido também no UseCase, nunca só pela barreira de rota.
        $usecase = (string) file_get_contents(__DIR__ . '/../../src/Voto/UseCase/DecidirAvaliacaoUseCase.php');
        self::assertStringContainsString('podeDecidirAvaliacao(', $usecase);
        self::assertStringContainsString('SemPapelNaAvaliacaoException', $usecase);
    }
}
