<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use App\Pasta\Attribute\PastaPelaFilha;
use App\Pasta\Attribute\PastaPorId;
use App\Pasta\Entity\Pasta;
use App\Pasta\EventListener\BarreiraDoCadeadoListener;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Trava o alcance da `BarreiraDoCadeadoListener` (spec pasta-cadeado §3), no molde do
 * `PastaSomenteLeituraRotasArquiteturaTest`. A diferença: o cadeado recusa também LEITURA (quem está
 * `Oculto` não abre nada da pasta), então o teste olha as rotas de TODOS os métodos.
 *
 * De toda rota que envolva pasta (nome `pasta_*`, `/pasta/` no path, variável `pastaId`/`pasta_id`/
 * `pasta`, ou action que recebe a pasta, uma filha ou uma neta pela meta), exige UM destes:
 *  1. a action recebe `Pasta`, uma entidade com `getPasta()` ou uma que chega a ela por
 *     `getTarefa()->getPasta()`: o `PastaDaRota` a vê nos argumentos;
 *  2. a action declara `#[PastaPelaFilha]` ou `#[PastaPorId]`;
 *  3. a rota está em `BarreiraDoCadeadoListener::ROTAS_SEM_PASTA`, com o motivo escrito lá.
 *
 * Rota que traga o id da pasta só no corpo da requisição não dá sinal nenhum, nem para este teste nem
 * para a barreira; o teto do `canAccessResource` continua valendo nela.
 */
#[CoversNothing]
final class BarreiraDoCadeadoRotasArquiteturaTest extends KernelTestCase
{
    private const VARIAVEIS_DE_PASTA = ['pastaId', 'pasta_id', 'pasta'];

    /** @return array<string, Route> */
    private static function rotasComPasta(): array
    {
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');

        return array_filter(
            $router->getRouteCollection()->all(),
            static fn (Route $rota, string $nome): bool => self::envolvePasta($nome, $rota),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private static function envolvePasta(string $nome, Route $rota): bool
    {
        if (str_starts_with($nome, 'pasta_') || str_contains($rota->getPath(), '/pasta/')) {
            return true;
        }
        if (array_intersect($rota->compile()->getVariables(), self::VARIAVEIS_DE_PASTA) !== []) {
            return true;
        }
        $action = self::action($rota);

        return $action !== null && self::recebeAPasta($action);
    }

    private static function action(Route $rota): ?\ReflectionMethod
    {
        $controller = $rota->getDefault('_controller');
        if (!is_string($controller) || $controller === '') {
            return null;
        }
        [$classe, $metodo] = str_contains($controller, '::') ? explode('::', $controller, 2) : [$controller, '__invoke'];
        if (!class_exists($classe) || !method_exists($classe, $metodo)) {
            return null;
        }

        return new \ReflectionMethod($classe, $metodo);
    }

    private static function recebeAPasta(\ReflectionMethod $action): bool
    {
        foreach ($action->getParameters() as $parametro) {
            $tipo = $parametro->getType();
            if (!$tipo instanceof \ReflectionNamedType || $tipo->isBuiltin()) {
                continue;
            }
            $classe = $tipo->getName();
            if (is_a($classe, Pasta::class, true) || (class_exists($classe) && method_exists($classe, 'getPasta'))) {
                return true;
            }
            if (self::chegaAPastaPelaMeta($classe)) {
                return true;
            }
        }

        return false;
    }

    private static function chegaAPastaPelaMeta(string $classe): bool
    {
        if (!class_exists($classe) || !method_exists($classe, 'getTarefa')) {
            return false;
        }
        $retorno = (new \ReflectionMethod($classe, 'getTarefa'))->getReturnType();
        if (!$retorno instanceof \ReflectionNamedType || $retorno->isBuiltin()) {
            return false;
        }

        return class_exists($retorno->getName()) && method_exists($retorno->getName(), 'getPasta');
    }

    private static function declaraAPasta(\ReflectionMethod $action): bool
    {
        return $action->getAttributes(PastaPelaFilha::class) !== [] || $action->getAttributes(PastaPorId::class) !== [];
    }

    /** @return list<string> */
    private static function descobertas(): array
    {
        $descobertas = [];
        foreach (self::rotasComPasta() as $nome => $rota) {
            if (in_array($nome, BarreiraDoCadeadoListener::ROTAS_SEM_PASTA, true)) {
                continue;
            }
            $action = self::action($rota);
            self::assertNotNull($action, "{$nome}: o _controller não resolve para Classe::metodo.");
            if (self::recebeAPasta($action) || self::declaraAPasta($action)) {
                continue;
            }
            $descobertas[] = sprintf('%s %s (%s::%s)', $nome, implode('|', $rota->getMethods()) ?: 'ANY', $action->getDeclaringClass()->getShortName(), $action->getName());
        }

        return $descobertas;
    }

    #[TestDox('toda rota que envolve pasta, de leitura ou de escrita, é alcançada pela barreira do cadeado ou é exceção documentada')]
    public function testTodaRotaDePastaEstaCoberta(): void
    {
        $rotas = self::rotasComPasta();
        $leitura = array_filter($rotas, static fn (Route $r): bool => in_array('GET', $r->getMethods(), true));
        self::assertGreaterThan(80, count($rotas), 'o router devolveu rotas de pasta de menos: o filtro do teste quebrou?');
        self::assertGreaterThan(20, count($leitura), 'nenhuma rota de leitura reconhecida: o teste do cadeado precisa delas.');

        self::assertSame(
            [],
            self::descobertas(),
            "Rota de pasta que a BarreiraDoCadeadoListener não alcança: quem está Oculto abriria a pasta por ela.\n"
            . 'Receba a entidade (Pasta ou filha com getPasta()), declare #[PastaPelaFilha]/#[PastaPorId] ou registre a exceção, com o motivo, no listener.',
        );
    }

    #[TestDox('as listas de exceção da barreira só têm rotas que existem')]
    public function testExcecoesNaoEnvelhecem(): void
    {
        $rotas = self::rotasComPasta();
        foreach ([...BarreiraDoCadeadoListener::ROTAS_SEM_PASTA, ...BarreiraDoCadeadoListener::ROTAS_DO_OCULTO, ...BarreiraDoCadeadoListener::ROTAS_DO_VISUALIZAR] as $nome) {
            self::assertArrayHasKey($nome, $rotas, "exceção '{$nome}' não é (mais) rota de pasta: tire da lista.");
        }
    }

    #[TestDox('o que o Oculto alcança é só a tela trancada e o pedido')]
    public function testOcultoSoAlcancaATelaEOPedido(): void
    {
        self::assertSame(['pasta_show', 'pasta_acesso_pedir', 'expediente_pasta_pedir_acesso'], BarreiraDoCadeadoListener::ROTAS_DO_OCULTO);
        self::assertNotContains('pasta_push_lida', BarreiraDoCadeadoListener::ROTAS_DO_VISUALIZAR, 'marcar lida vale para o escritório: é escrita.');
    }
}
