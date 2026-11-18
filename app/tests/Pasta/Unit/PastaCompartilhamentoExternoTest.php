<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\EstadoDoCompartilhamento;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaCompartilhamento;
use App\Pasta\Entity\PermissoesDoCompartilhamento;
use App\Pasta\Service\CodigoPessoal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * O convite de outra conta (spec acesso-restrito-e-compartilhar §4.5, fatia 8): nasce pendente, só o destino aceita
 * ou recusa, e só enquanto aguarda; o vencido não aceita mais. Mais a leitura do código pessoal digitado.
 */
#[CoversClass(PastaCompartilhamento::class)]
#[CoversClass(CodigoPessoal::class)]
final class PastaCompartilhamentoExternoTest extends TestCase
{
    private User $origem;
    private User $destino;
    private Pasta $pasta;
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        $this->origem  = $this->usuario(1);
        $this->destino = $this->usuario(2);
        $this->pasta   = (new Pasta())->setTenant(new Tenant());
        $this->agora   = new \DateTimeImmutable('2026-11-18 10:00');
    }

    public function testNasceAguardandoSemValerAinda(): void
    {
        $convite = $this->convite(null);

        self::assertFalse($convite->isInterno());
        self::assertSame(EstadoDoCompartilhamento::Pendente, $convite->getStatus());
        self::assertTrue($convite->aguardaAceite($this->agora));
        self::assertFalse($convite->estaAtivo($this->agora));
    }

    public function testDestinoAceitaEPassaAColaborar(): void
    {
        $convite = $this->convite('2026-11-20');

        $convite->aceitar($this->destino, $this->agora);

        self::assertSame(EstadoDoCompartilhamento::Aceita, $convite->getStatus());
        self::assertEquals($this->agora, $convite->getAceitoEm());
        self::assertTrue($convite->estaAtivo($this->agora));
        self::assertFalse($convite->aguardaAceite($this->agora));
    }

    public function testDestinoRecusa(): void
    {
        $convite = $this->convite(null);

        $convite->recusar($this->destino, $this->agora);

        self::assertSame(EstadoDoCompartilhamento::Recusada, $convite->getStatus());
        self::assertSame($this->destino, $convite->getEncerradoPor());
    }

    public function testQuemNaoEhODestinoNaoAceita(): void
    {
        $convite = $this->convite(null);

        $this->expectException(\DomainException::class);
        $convite->aceitar($this->usuario(3), $this->agora);
    }

    public function testNaoAceitaDuasVezes(): void
    {
        $convite = $this->convite(null);
        $convite->aceitar($this->destino, $this->agora);

        $this->expectException(\DomainException::class);
        $convite->recusar($this->destino, $this->agora);
    }

    public function testVencidoNaoAceitaMasOUltimoDiaConta(): void
    {
        $convite = $this->convite('2026-11-18');
        self::assertTrue($convite->aguardaAceite($this->agora));
        self::assertTrue($convite->venceuEm(new \DateTimeImmutable('2026-11-19 00:01')));

        $this->expectException(\DomainException::class);
        $convite->aceitar($this->destino, new \DateTimeImmutable('2026-11-19 00:01'));
    }

    public function testDeixarDeColaborarEncerra(): void
    {
        $convite = $this->convite(null);
        $convite->aceitar($this->destino, $this->agora);

        $convite->encerrar($this->destino, $this->agora);

        self::assertSame(EstadoDoCompartilhamento::Encerrada, $convite->getStatus());
        self::assertFalse($convite->estaAtivo($this->agora));
    }

    public function testNinguemConvidaASiMesmo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PastaCompartilhamento::externo($this->pasta, $this->origem, $this->origem, new PermissoesDoCompartilhamento(), null, null, $this->agora);
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function codigosDigitados(): iterable
    {
        yield 'certo' => ['BJ-ABCD-EFGH-JKLM', 'BJ-ABCD-EFGH-JKLM'];
        yield 'minúsculo e com espaços' => [' bj-abcd- efgh-jklm ', 'BJ-ABCD-EFGH-JKLM'];
        yield 'letra ambígua (I e O)' => ['BJ-ABCI-EFGH-JKLO', null];
        yield 'curto' => ['BJ-ABCD-EFGH', null];
        yield 'vazio' => ['', null];
        yield 'nulo' => [null, null];
    }

    #[DataProvider('codigosDigitados')]
    public function testNormalizaOCodigoDigitado(?string $digitado, ?string $esperado): void
    {
        self::assertSame($esperado, CodigoPessoal::normalizar($digitado));
    }

    private function convite(?string $validoAte): PastaCompartilhamento
    {
        return PastaCompartilhamento::externo(
            $this->pasta,
            $this->origem,
            $this->destino,
            new PermissoesDoCompartilhamento(movimentar: true),
            $validoAte !== null ? new \DateTimeImmutable($validoAte) : null,
            '  Ajuda na contestação  ',
            $this->agora,
        );
    }

    private function usuario(int $id): User
    {
        $usuario = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($usuario, $id);

        return $usuario;
    }
}
