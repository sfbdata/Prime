<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Repository\RecursoEstadoRepository;
use App\Master\Service\RecursosDoSistema;
use App\Master\Service\RegraDosMasters;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use App\Ponto\Repository\ConfiguracaoDoPontoRepository;
use App\Ponto\Service\FuncaoDoAceite;
use App\Ponto\Service\FuncoesDoPonto;
use App\Ponto\Service\SeloDaFolha;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * O aceite mensal vale com as duas camadas ligadas (Global E Escritório) e o selo da folha pronto (spec
 * ponto-desenho-0811, E16, decisões 16 e 19).
 */
#[Group('ponto')]
#[CoversClass(FuncaoDoAceite::class)]
final class FuncaoDoAceiteTest extends TestCase
{
    #[TestDox('Sem linha de configuração e com o Global ligado, o aceite vale (o padrão é ligado)')]
    public function testValePorPadrao(): void
    {
        self::assertTrue($this->funcao([], null, true)->vale(new Tenant()));
    }

    #[TestDox('O Master desligou no Global: não vale em nenhum escritório')]
    public function testDesligadoNoGlobal(): void
    {
        self::assertFalse($this->funcao([FuncaoDoAceite::RECURSO => $this->desligada()], null, true)->vale(new Tenant()));
    }

    #[TestDox('O escritório desligou o card "Aceite mensal da folha": não vale nele')]
    public function testDesligadoNoEscritorio(): void
    {
        $tenant = new Tenant();
        $configuracao = new ConfiguracaoDoPonto($tenant);
        $configuracao->definir(FuncoesDoPonto::ACEITE, false, new User(), new \DateTimeImmutable());

        self::assertFalse($this->funcao([], $configuracao, true)->vale($tenant));
    }

    #[TestDox('Decisão 16: sem o segredo do HMAC (selo indisponível), não vale mesmo com as duas camadas ligadas')]
    public function testSemOSegredo(): void
    {
        self::assertFalse($this->funcao([], null, false)->vale(new Tenant()));
    }

    /** @param array<string, RecursoEstado> $gravados */
    private function funcao(array $gravados, ?ConfiguracaoDoPonto $configuracao, bool $seloPronto): FuncaoDoAceite
    {
        $estados = $this->createStub(RecursoEstadoRepository::class);
        $estados->method('todosPorChave')->willReturn($gravados);
        $recursos = new RecursosDoSistema(
            $estados,
            new MockClock('2026-11-09 10:00:00'),
            $this->createStub(Security::class),
            new RegraDosMasters($this->createStub(ContasDoSistemaRepository::class)),
            new RequestStack(),
            new NullLogger(),
        );
        $configuracoes = $this->createStub(ConfiguracaoDoPontoRepository::class);
        $configuracoes->method('doTenant')->willReturn($configuracao);
        $selo = $this->createStub(SeloDaFolha::class);
        $selo->method('disponivel')->willReturn($seloPronto);

        return new FuncaoDoAceite(new FuncoesDoPonto($recursos, $configuracoes, $this->createStub(PermissionChecker::class)), $selo);
    }

    private function desligada(): RecursoEstado
    {
        $estado = new RecursoEstado(FuncaoDoAceite::RECURSO);
        $estado->desativar(null, null, null, new \DateTimeImmutable('2026-11-01'));

        return $estado;
    }
}
