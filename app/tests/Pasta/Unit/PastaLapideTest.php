<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Invariantes da exclusão-lápide na própria entidade. O que decide ENTRE lápide e exclusão real
 * é o ExcluirPastaUseCase (e tem prova funcional contra o Postgres); aqui prova-se só que a
 * marca, uma vez posta, não pode ser sobrescrita nem desfeita fora de ordem.
 */
#[CoversClass(Pasta::class)]
final class PastaLapideTest extends TestCase
{
    private function autor(string $email = 'autor@test.com'): User
    {
        return (new User())->setEmail($email);
    }

    #[TestDox('Pasta recém-criada não está excluída')]
    public function testPastaNovaNaoEstaExcluida(): void
    {
        $pasta = new Pasta();

        self::assertFalse($pasta->estaExcluida());
        self::assertNull($pasta->getExcluidaEm());
        self::assertNull($pasta->getExcluidaPor());
    }

    #[TestDox('Marcar como excluída guarda quem, quando, e arquiva junto')]
    public function testMarcarExcluidaGuardaAutorDataEArquiva(): void
    {
        $pasta  = new Pasta();
        $autor  = $this->autor('jessica@test.com');
        $quando = new \DateTimeImmutable('2026-08-28 16:57:06');

        self::assertSame(Pasta::SITUACAO_ATIVA, $pasta->getSituacao());

        $pasta->marcarExcluida($autor, $quando);

        self::assertTrue($pasta->estaExcluida());
        self::assertSame($quando, $pasta->getExcluidaEm());
        self::assertSame($autor, $pasta->getExcluidaPor());
        // Arquivar junto é o que tira a pasta das telas que filtram por situação ativa.
        self::assertSame(Pasta::SITUACAO_ARQUIVADA, $pasta->getSituacao());
    }

    #[TestDox('Excluir duas vezes é recusado: sobrescrever apagaria o autor da exclusão real')]
    public function testMarcarExcluidaDuasVezesLancaLogicException(): void
    {
        $pasta    = new Pasta();
        $primeiro = $this->autor('primeiro@test.com');
        $pasta->marcarExcluida($primeiro, new \DateTimeImmutable('2026-08-28 10:00:00'));

        try {
            $pasta->marcarExcluida($this->autor('segundo@test.com'), new \DateTimeImmutable('2026-08-29 10:00:00'));
            self::fail('Esperava LogicException na segunda exclusão.');
        } catch (\LogicException) {
            // O registro da primeira exclusão continua intacto — é o ponto do guard.
            self::assertSame($primeiro, $pasta->getExcluidaPor());
            self::assertEquals(new \DateTimeImmutable('2026-08-28 10:00:00'), $pasta->getExcluidaEm());
        }
    }

    #[TestDox('Restaurar limpa a lápide e devolve a pasta para ATIVA')]
    public function testRestaurarLimpaLapideEVoltaParaAtiva(): void
    {
        $pasta = new Pasta();
        $pasta->marcarExcluida($this->autor(), new \DateTimeImmutable('2026-08-28 16:57:06'));

        $pasta->restaurar();

        self::assertFalse($pasta->estaExcluida());
        self::assertNull($pasta->getExcluidaEm());
        self::assertNull($pasta->getExcluidaPor());
        self::assertSame(Pasta::SITUACAO_ATIVA, $pasta->getSituacao());
    }

    #[TestDox('Restaurar pasta que não foi excluída é recusado')]
    public function testRestaurarPastaNaoExcluidaLancaLogicException(): void
    {
        $pasta = new Pasta();

        $this->expectException(\LogicException::class);
        $pasta->restaurar();
    }

    #[TestDox('Pasta arquivada à mão NÃO é pasta excluída')]
    public function testArquivarNaoMarcaComoExcluida(): void
    {
        $pasta = new Pasta();
        $pasta->setSituacao(Pasta::SITUACAO_ARQUIVADA);

        // A distinção importa na tela: arquivada é caso encerrado, riscada é caso excluído.
        self::assertFalse($pasta->estaExcluida());
    }
}
