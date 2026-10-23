<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Tarefa\Tarefa;
use App\Pasta\Entity\Pasta;
use App\Tarefa\UseCase\ConcluirMetaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O concluir da aba Metas da pasta, com a meta travada e relida do banco (spec `minhas-metas-esteira-2210.md`,
 * Fase 3): uma passagem simultânea que já concluiu a meta não é sobrescrita.
 */
#[CoversClass(ConcluirMetaUseCase::class)]
#[Group('tarefa')]
final class ConcluirMetaUseCaseTest extends TestCase
{
    private const AGORA = '2026-10-22 15:00:00';

    private TransacaoDaPassagemFalsa $transacao;
    private Tarefa $meta;

    protected function setUp(): void
    {
        $this->transacao = new TransacaoDaPassagemFalsa();
        $this->meta = (new Tarefa())
            ->setTitulo('Protocolar recurso')
            ->setDescricao('...')
            ->setPasta(new Pasta())
            ->setStatus(Tarefa::STATUS_PENDENTE);
    }

    #[TestDox('Conclui com a data de agora, numa transação com a meta travada')]
    public function testConclui(): void
    {
        self::assertTrue($this->concluir());

        self::assertSame(Tarefa::STATUS_CONCLUIDA, $this->meta->getStatus());
        self::assertSame(self::AGORA, $this->meta->getDataConclusao()?->format('Y-m-d H:i:s'));
        self::assertSame(1, $this->transacao->concluidas);
    }

    #[TestDox('Corrida: a passagem concluiu a meta depois que esta tela a leu; nada muda e a data original fica')]
    public function testCorridaComAMetaVelhaEmMemoria(): void
    {
        $this->transacao->noBanco = static function (Tarefa $meta): void {
            $meta->setStatus(Tarefa::STATUS_CONCLUIDA)->setDataConclusao(new \DateTimeImmutable('2026-10-22 14:59:00'));
        };

        self::assertFalse($this->concluir());

        self::assertSame('2026-10-22 14:59:00', $this->meta->getDataConclusao()?->format('Y-m-d H:i:s'));
    }

    private function concluir(): bool
    {
        return (new ConcluirMetaUseCase($this->transacao))->executar($this->meta, new \DateTimeImmutable(self::AGORA));
    }
}
