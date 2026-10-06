<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Service\JanelaDeEdicaoDeComentario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A janela de editar/excluir comentário da pasta: 15 minutos contados da criação (decisão do
 * dono, 2026-10-05). As bordas importam porque a tela e o servidor perguntam aqui a mesma coisa.
 */
#[CoversClass(JanelaDeEdicaoDeComentario::class)]
final class JanelaDeEdicaoDeComentarioTest extends TestCase
{
    private JanelaDeEdicaoDeComentario $janela;
    private \DateTimeImmutable $criadaEm;

    protected function setUp(): void
    {
        $this->janela   = new JanelaDeEdicaoDeComentario();
        $this->criadaEm = new \DateTimeImmutable('2026-10-05 10:00:00');
    }

    public function testDuracaoEQuinzeMinutos(): void
    {
        self::assertSame('PT15M', JanelaDeEdicaoDeComentario::DURACAO);
        self::assertSame(15, JanelaDeEdicaoDeComentario::MINUTOS);
    }

    public function testAbertaNoInstanteDaCriacao(): void
    {
        self::assertTrue($this->janela->estaAberta($this->criadaEm, $this->criadaEm));
    }

    public function testAbertaExatamenteAosQuinzeMinutos(): void
    {
        self::assertTrue($this->janela->estaAberta($this->criadaEm, $this->em('+15 minutes')));
    }

    public function testFechadaUmSegundoDepoisDosQuinzeMinutos(): void
    {
        self::assertFalse($this->janela->estaAberta($this->criadaEm, $this->em('+15 minutes +1 second')));
    }

    public function testFechadaDepoisDeVinteQuatroHorasMenosUmMinuto(): void
    {
        // A regra antiga (24h) deixaria aberto; a nova fecha.
        self::assertFalse($this->janela->estaAberta($this->criadaEm, $this->em('+23 hours +59 minutes')));
    }

    /** @return iterable<string, array{string, int}> */
    public static function casosDeMinutosRestantes(): iterable
    {
        yield 'recém-criada mostra 15'               => ['+0 seconds', 15];
        yield 'um segundo depois ainda arredonda 15' => ['+1 second', 15];
        yield 'exatamente 1 min depois mostra 14'    => ['+1 minute', 14];
        yield 'faltando 11min01s arredonda para 12'  => ['+3 minutes +59 seconds', 12];
        yield 'faltando 1 segundo mostra 1'          => ['+14 minutes +59 seconds', 1];
        yield 'no limite exato, ainda aberta, 1'     => ['+15 minutes', 1];
        yield 'um segundo após fechar dá 0'          => ['+15 minutes +1 second', 0];
        yield 'muito depois nunca é negativo'        => ['+3 days', 0];
    }

    #[DataProvider('casosDeMinutosRestantes')]
    public function testMinutosRestantes(string $deslocamento, int $esperado): void
    {
        self::assertSame($esperado, $this->janela->minutosRestantes($this->criadaEm, $this->em($deslocamento)));
    }

    public function testAgoraAntesDaCriacaoNaoPassaDeQuinze(): void
    {
        // Relógio fora de sincronia não pode inventar janela maior que a regra.
        self::assertSame(15, $this->janela->minutosRestantes($this->criadaEm, $this->em('-1 minute')));
    }

    private function em(string $deslocamento): \DateTimeImmutable
    {
        return $this->criadaEm->modify($deslocamento);
    }
}
