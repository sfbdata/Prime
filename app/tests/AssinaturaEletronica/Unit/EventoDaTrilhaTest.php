<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\EventoDaTrilha;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(EventoDaTrilha::class)]
#[CoversClass(TrilhaDoDocumento::class)]
final class EventoDaTrilhaTest extends TestCase
{
    #[TestDox('o hash segue a fórmula do contrato: anterior | documento | tipo | dados ordenados | hora UTC | signatário | usuário | ip | user agent')]
    public function testFormulaDoHash(): void
    {
        $agora = new \DateTimeImmutable('2026-11-09 10:20:30.456789', new \DateTimeZone('America/Sao_Paulo'));
        $evento = new EventoDaTrilha(self::documento(42), TipoDeEvento::Duplicado, ['nome' => 'Contrato', 'a' => 1], $agora, 'f00', self::usuario(7));

        $esperado = hash('sha256', implode('|', [
            'f00',
            '42',
            'duplicado',
            '{"a":1,"nome":"Contrato"}',
            '2026-11-09T13:20:30+00:00',
            '',
            '7',
            '',
            '',
        ]));

        self::assertSame($esperado, $evento->getHash());
        self::assertSame('f00', $evento->getHashAnterior());
        self::assertSame(0, (int) $evento->getCriadoEm()->format('u'), 'a hora vai em segundos, como a coluna');
        self::assertTrue($evento->hashConfere());
    }

    #[TestDox('a ordem das chaves dos dados não muda o hash')]
    public function testOrdemDasChavesNaoMuda(): void
    {
        $agora = new \DateTimeImmutable('2026-11-09 10:00:00');
        $a = new EventoDaTrilha(self::documento(1), TipoDeEvento::Duplicado, ['x' => 1, 'b' => ['z' => 1, 'c' => 2]], $agora, null);
        $b = new EventoDaTrilha(self::documento(1), TipoDeEvento::Duplicado, ['b' => ['c' => 2, 'z' => 1], 'x' => 1], $agora, null);

        self::assertSame($a->getHash(), $b->getHash());
    }

    #[TestDox('linha alterada depois de criada não confere mais')]
    public function testAlteracaoQuebraOHash(): void
    {
        $evento = new EventoDaTrilha(self::documento(1), TipoDeEvento::Duplicado, ['nome' => 'A'], new \DateTimeImmutable(), null);

        (new \ReflectionProperty(EventoDaTrilha::class, 'dados'))->setValue($evento, ['nome' => 'B']);

        self::assertFalse($evento->hashConfere());
    }

    #[TestDox('o hash usa os ids guardados de quem agiu: continua conferindo depois que o SET NULL anula usuário e signatário')]
    public function testHashConfereDepoisDoSetNull(): void
    {
        $documento = self::documento(42);
        $signatario = new Signatario($documento, 0, 'Ana', null, null, 'Contratante', NivelDeAssinatura::Simples);
        (new \ReflectionProperty(Signatario::class, 'id'))->setValue($signatario, 3);
        $evento = new EventoDaTrilha($documento, TipoDeEvento::RascunhoSalvo, [], new \DateTimeImmutable('2026-11-09 10:00:00', new \DateTimeZone('UTC')), null, self::usuario(7), $signatario);
        $hash = $evento->getHash();

        self::assertSame([3, 7], [$evento->getSignatarioRef(), $evento->getUsuarioRef()]);
        self::assertSame(hash('sha256', implode('|', ['', '42', 'rascunho_salvo', '[]', '2026-11-09T10:00:00+00:00', '3', '7', '', ''])), $hash);

        (new \ReflectionProperty(EventoDaTrilha::class, 'usuario'))->setValue($evento, null);
        (new \ReflectionProperty(EventoDaTrilha::class, 'signatario'))->setValue($evento, null);

        self::assertTrue($evento->hashConfere());
        self::assertSame($hash, $evento->calcularHash());
    }

    #[TestDox('evento sem documento gravado é recusado')]
    public function testDocumentoSemId(): void
    {
        $this->expectException(\LogicException::class);

        new EventoDaTrilha(new DocumentoParaAssinar(new Tenant(), null, new \DateTimeImmutable()), TipoDeEvento::RascunhoSalvo, [], new \DateTimeImmutable(), null);
    }

    #[TestDox('a trilha encadeia: cada evento leva o hash do último, lido com a linha do documento travada')]
    public function testTrilhaEncadeia(): void
    {
        $documento = self::documento(5);
        $gravados = [];
        $repositorio = $this->createMock(EventoDaTrilhaRepository::class);
        $repositorio->expects(self::exactly(2))->method('ultimoHashTravando')->with($documento)
            ->willReturnCallback(static function () use (&$gravados): ?string {
                return $gravados === [] ? null : end($gravados)->getHash();
            });
        $repositorio->method('salvar')->willReturnCallback(static function (EventoDaTrilha $e) use (&$gravados): void {
            $gravados[] = $e;
        });
        $trilha = new TrilhaDoDocumento($repositorio, new MockClock('2026-11-09 10:00:00', 'UTC'));

        $primeiro = $trilha->acrescentar($documento, TipoDeEvento::RascunhoSalvo, [], null);
        $segundo = $trilha->acrescentar($documento, TipoDeEvento::MovidoParaLixeira, [], self::usuario(3));

        self::assertNull($primeiro->getHashAnterior());
        self::assertSame($primeiro->getHash(), $segundo->getHashAnterior());
        self::assertNotSame($primeiro->getHash(), $segundo->getHash());
        self::assertSame((new \DateTimeImmutable('2026-11-09 10:00:00', new \DateTimeZone('UTC')))->getTimestamp(), $segundo->getCriadoEm()->getTimestamp(), 'a hora do relógio, no fuso do PHP');
        self::assertCount(2, $gravados);
    }

    private static function documento(int $id): DocumentoParaAssinar
    {
        $documento = new DocumentoParaAssinar(new Tenant(), null, new \DateTimeImmutable());
        (new \ReflectionProperty(DocumentoParaAssinar::class, 'id'))->setValue($documento, $id);

        return $documento;
    }

    private static function usuario(int $id): User
    {
        $usuario = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($usuario, $id);

        return $usuario;
    }
}
