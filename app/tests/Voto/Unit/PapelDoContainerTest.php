<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Voto\EventListener\PapelDoContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * O papel do container (spec docs/specs/bluejus-voto.md, D1-B e A2): no `voto` só passam as rotas `voto_publico_`; no
 * `bluejus` (APP_PAPEL vazia ou ausente) elas são 404 e todo o resto passa. Sub-requisição sempre passa.
 */
#[CoversClass(PapelDoContainer::class)]
final class PapelDoContainerTest extends TestCase
{
    /** @return iterable<string, array{?string, string, bool}> papel, rota, passa */
    public static function casos(): iterable
    {
        yield 'voto: rota pública do Voto passa' => ['voto', 'voto_publico_saude', true];
        yield 'voto: login é 404' => ['voto', 'app_login', false];
        yield 'voto: tela da equipe do Voto é 404' => ['voto', 'voto_index', false];
        yield 'voto: requisição sem rota é 404' => ['voto', '', false];
        yield 'bluejus: rota pública do Voto é 404' => ['bluejus', 'voto_publico_saude', false];
        yield 'bluejus: login passa' => ['bluejus', 'app_login', true];
        yield 'bluejus: tela da equipe do Voto passa' => ['bluejus', 'voto_index', true];
        yield 'vazio vale bluejus: rota pública do Voto é 404' => ['', 'voto_publico_saude', false];
        yield 'vazio vale bluejus: login passa' => ['', 'app_login', true];
        yield 'ausente vale bluejus: rota pública do Voto é 404' => [null, 'voto_publico_saude', false];
        yield 'ausente vale bluejus: login passa' => [null, 'app_login', true];
        yield 'maiúscula e espaço: VOTO vale voto' => [' VOTO ', 'app_login', false];
        yield 'desconhecido fecha como voto: login é 404' => ['votoo', 'app_login', false];
        yield 'desconhecido fecha como voto: rota pública passa' => ['votoo', 'voto_publico_saude', true];
    }

    #[DataProvider('casos')]
    #[TestDox('papel $papel com a rota "$rota" na requisição principal: passa = $passa')]
    public function testRequisicaoPrincipal(?string $papel, string $rota, bool $passa): void
    {
        $evento = $this->evento($rota, HttpKernelInterface::MAIN_REQUEST);

        if (!$passa) {
            $this->expectException(NotFoundHttpException::class);
        }
        (new PapelDoContainer($papel))($evento);

        self::assertFalse($evento->hasResponse(), 'o listener nunca monta resposta: ou passa, ou é 404');
    }

    /** @return iterable<string, array{?string, string}> papel da variável, papel gravado */
    public static function papeisGravados(): iterable
    {
        yield 'voto' => ['voto', 'voto'];
        yield 'bluejus' => ['bluejus', 'bluejus'];
        yield 'vazio vale bluejus' => ['', 'bluejus'];
        yield 'ausente vale bluejus' => [null, 'bluejus'];
        yield 'desconhecido fecha como voto' => ['votoo', 'voto'];
    }

    #[DataProvider('papeisGravados')]
    #[TestDox('grava o papel efetivo ($gravado) no atributo _papel da requisição principal')]
    public function testGravaOPapelNaRequisicaoPrincipal(?string $papel, string $gravado): void
    {
        $rotaQuePassa = $gravado === 'voto' ? 'voto_publico_saude' : 'app_login';
        $evento = $this->evento($rotaQuePassa, HttpKernelInterface::MAIN_REQUEST);

        (new PapelDoContainer($papel))($evento);

        self::assertSame($gravado, $evento->getRequest()->attributes->get(PapelDoContainer::ATRIBUTO));
    }

    /** @return iterable<string, array{?string, string}> */
    public static function subRequisicoes(): iterable
    {
        yield 'voto: login' => ['voto', 'app_login'];
        yield 'voto: página de erro' => ['voto', 'error_controller'];
        yield 'bluejus: rota pública do Voto' => ['bluejus', 'voto_publico_saude'];
        yield 'vazio: rota pública do Voto' => ['', 'voto_publico_saude'];
    }

    #[DataProvider('subRequisicoes')]
    #[TestDox('sub-requisição passa em qualquer papel ($papel, rota "$rota")')]
    public function testSubRequisicaoPassa(?string $papel, string $rota): void
    {
        $evento = $this->evento($rota, HttpKernelInterface::SUB_REQUEST);

        (new PapelDoContainer($papel))($evento);

        self::assertFalse($evento->hasResponse());
        self::assertFalse($evento->getRequest()->attributes->has(PapelDoContainer::ATRIBUTO), 'sub-requisição não é tocada');
    }

    private function evento(string $rota, int $tipo): RequestEvent
    {
        $requisicao = Request::create('/qualquer');
        if ($rota !== '') {
            $requisicao->attributes->set('_route', $rota);
        }

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $requisicao, $tipo);
    }
}
