<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Auth\User;
use App\Master\DTO\EditarSedeDaSeccionalInput;
use App\Master\Entity\SeccionalOab;
use App\Master\Exception\SeccionalNaoEncontrada;
use App\Master\Exception\SeccionalRecusada;
use App\Master\Repository\SeccionalOabRepository;
use App\Master\UseCase\EditarSedeDaSeccionalUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Editar a sede de uma seccional (spec master-seccionais-oab, "Editar sede"): endereço até 255, telefone até 40,
 * site com http:// ou https:// até 255; grava dentro do escopo de plataforma.
 */
#[CoversClass(EditarSedeDaSeccionalUseCase::class)]
#[CoversClass(EditarSedeDaSeccionalInput::class)]
#[CoversClass(SeccionalOab::class)]
final class EditarSedeDaSeccionalUseCaseTest extends TestCase
{
    private SeccionalOabRepository&MockObject $repositorio;
    private EscopoDeAuditoriaDePlataforma $escopo;
    private SeccionalOab $seccional;
    private User $master;

    protected function setUp(): void
    {
        $this->repositorio = $this->createMock(SeccionalOabRepository::class);
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->seccional = new SeccionalOab('SP', 'OAB Seccional São Paulo');
        $this->master = (new User())->setRoles(['ROLE_SUPER_ADMIN']);
    }

    #[TestDox('grava a sede aparada (vazio vira nulo), quem e quando, dentro do escopo de plataforma')]
    public function testGravaASede(): void
    {
        $this->repositorio->method('buscarPorUf')->with('SP')->willReturn($this->seccional);
        $this->repositorio->expects($this->once())->method('salvar')->with($this->seccional, true)
            ->willReturnCallback(fn () => self::assertTrue($this->escopo->estaLigado(), 'grava dentro do escopo de plataforma'));

        $this->useCase()->executar(EditarSedeDaSeccionalInput::doJson($this->master, 'SP', ['endereco' => '  Rua Um, 10 ', 'telefone' => '', 'site' => 'https://sede.exemplo.test']));

        self::assertSame('Rua Um, 10', $this->seccional->getEndereco());
        self::assertNull($this->seccional->getTelefone());
        self::assertSame('https://sede.exemplo.test', $this->seccional->getSite());
        self::assertSame($this->master, $this->seccional->getAtualizadoPor());
        self::assertSame('2026-10-21 09:00', $this->seccional->getAtualizadoEm()?->format('Y-m-d H:i'));
        self::assertFalse($this->escopo->estaLigado());
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function recusas(): iterable
    {
        yield 'endereço longo' => [['endereco' => str_repeat('a', 256)], 'O endereço passa de 255 caracteres.'];
        yield 'telefone longo' => [['telefone' => str_repeat('9', 41)], 'O telefone passa de 40 caracteres.'];
        yield 'site longo' => [['site' => 'https://exemplo.test/' . str_repeat('a', 240)], 'O site passa de 255 caracteres.'];
        yield 'site sem esquema' => [['site' => 'www.exemplo.test'], 'Informe o site completo, começando com http:// ou https://.'];
        yield 'site javascript' => [['site' => 'javascript:alert(1)'], 'Informe o site completo, começando com http:// ou https://.'];
        yield 'site ftp' => [['site' => 'ftp://exemplo.test'], 'Informe o site completo, começando com http:// ou https://.'];
    }

    /** @param array<string, string> $corpo */
    #[DataProvider('recusas')]
    #[TestDox('recusa $_dataName com a mensagem clara e não grava')]
    public function testRecusa(array $corpo, string $mensagem): void
    {
        $this->repositorio->method('buscarPorUf')->willReturn($this->seccional);
        $this->repositorio->expects($this->never())->method('salvar');

        try {
            $this->useCase()->executar(EditarSedeDaSeccionalInput::doJson($this->master, 'SP', $corpo));
            self::fail('deveria recusar');
        } catch (SeccionalRecusada $e) {
            self::assertSame($mensagem, $e->getMessage());
        }
        self::assertNull($this->seccional->getAtualizadoEm());
    }

    #[TestDox('site com http:// é aceito, como no desenho (L903-904: /^https?:\/\//)')]
    public function testSiteHttp(): void
    {
        $this->repositorio->method('buscarPorUf')->willReturn($this->seccional);
        $this->repositorio->expects($this->once())->method('salvar');

        $this->useCase()->executar(EditarSedeDaSeccionalInput::doJson($this->master, 'SP', ['site' => 'http://www.oabsp.org.br']));

        self::assertSame('http://www.oabsp.org.br', $this->seccional->getSite());
    }

    #[TestDox('linha ausente: SeccionalNaoEncontrada')]
    public function testSemLinha(): void
    {
        $this->repositorio->method('buscarPorUf')->willReturn(null);

        $this->expectException(SeccionalNaoEncontrada::class);
        $this->useCase()->executar(new EditarSedeDaSeccionalInput($this->master, 'SP'));
    }

    private function useCase(): EditarSedeDaSeccionalUseCase
    {
        return new EditarSedeDaSeccionalUseCase($this->repositorio, new MockClock('2026-10-21 09:00:00'), $this->escopo);
    }
}
