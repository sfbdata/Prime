<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Pasta\DTO\TimelineItemDTO;
use App\Pasta\DTO\TimelineItemType;
use App\Tarefa\DTO\DespachoNaLinhaDoTempoOutput;
use App\Tarefa\DTO\DespachosDaMetaOutput;
use App\Tarefa\DTO\FormularioDoDespachoOutput;
use App\Tarefa\DTO\ItemDaLinhaDoTempoOutput;
use App\Tarefa\UseCase\MontarLinhaDoTempoDaMetaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Linha do tempo no formato único do desenho 04B (L70-71, L127). */
#[CoversClass(MontarLinhaDoTempoDaMetaUseCase::class)]
#[CoversClass(ItemDaLinhaDoTempoOutput::class)]
#[Group('tarefa')]
final class MontarLinhaDoTempoDaMetaUseCaseTest extends TestCase
{
    #[TestDox('Despachos e eventos no mesmo formato, "DESPACHO N" do mais antigo ao mais novo e só o mais novo é MAIS RECENTE')]
    public function testNumeraOHistoricoInteiro(): void
    {
        $itens = [
            self::evento('prazo', '2026-10-22 10:00'),
            self::mensagem(7, '2026-10-21 10:00'),
            self::evento('responsaveis', '2026-10-20 10:00'),
            self::evento('criada', '2026-10-19 10:00'),
        ];

        $linha = $this->executar($itens, [7 => self::despacho(null, '2026-10-21 10:00')]);

        self::assertSame([4, 3, 2, 1], array_map(static fn (ItemDaLinhaDoTempoOutput $l): int => $l->numero, $linha));
        self::assertSame([true, false, false, false], array_map(static fn (ItemDaLinhaDoTempoOutput $l): bool => $l->maisRecente, $linha));
        self::assertSame(['metas.linha.tipo.prazo', 'bi-calendar-event', 'roxo'], [$linha[0]->tipo, $linha[0]->icone, $linha[0]->tom]);
        self::assertSame(['metas.despacho.tipo.despacho', 'bi-chat-left-text', 'azul'], [$linha[1]->tipo, $linha[1]->icone, $linha[1]->tom]);
        self::assertNotNull($linha[1]->despacho);
        self::assertSame(['metas.linha.tipo.responsaveis', 'bi-people', 'roxo'], [$linha[2]->tipo, $linha[2]->icone, $linha[2]->tom]);
        self::assertSame(['metas.linha.tipo.criada', 'cinza'], [$linha[3]->tipo, $linha[3]->tom]);
        self::assertNull($linha[3]->despacho);
    }

    #[TestDox('A troca de status da auditoria junto do despacho de envio é o mesmo ato e sai; longe dele, fica')]
    public function testEnvioNaoDuplica(): void
    {
        $itens = [
            self::evento('revisao', '2026-10-22 10:00:30'),
            self::mensagem(8, '2026-10-22 10:00:00'),
            self::evento('revisao', '2026-10-10 10:00'),
        ];

        $linha = $this->executar($itens, [8 => self::despacho('envio', '2026-10-22 10:00:00')]);

        self::assertCount(2, $linha);
        self::assertSame(['metas.despacho.tipo.envio', 'bi-send', 'ambar'], [$linha[0]->tipo, $linha[0]->icone, $linha[0]->tom]);
        self::assertNotNull($linha[0]->despacho);
        self::assertSame(['metas.despacho.tipo.envio', 'ambar', 1], [$linha[1]->tipo, $linha[1]->tom, $linha[1]->numero], 'envio antigo, sem despacho, continua');
    }

    #[TestDox('Evento desconhecido não some: vira "Evento registrado" em cinza')]
    public function testEventoDesconhecido(): void
    {
        $linha = $this->executar([self::evento(null, '2026-10-22 10:00')], []);

        self::assertSame(['metas.linha.tipo.evento', 'cinza'], [$linha[0]->tipo, $linha[0]->tom]);
    }

    /**
     * @param TimelineItemDTO[]                          $itens
     * @param array<int, DespachoNaLinhaDoTempoOutput> $linhas
     *
     * @return list<ItemDaLinhaDoTempoOutput>
     */
    private function executar(array $itens, array $linhas): array
    {
        $formulario = new FormularioDoDespachoOutput('2026-10-22', '2026-10-29', 5, [], []);

        return (new MontarLinhaDoTempoDaMetaUseCase())->executar($itens, new DespachosDaMetaOutput($formulario, $linhas));
    }

    private static function evento(?string $sistema, string $em): TimelineItemDTO
    {
        return new TimelineItemDTO(TimelineItemType::EVENTO, new \DateTimeImmutable($em), 'Evento', null, 'Ana', null, 'bi-clock', 'x', sistema: $sistema);
    }

    private static function mensagem(int $id, string $em): TimelineItemDTO
    {
        return new TimelineItemDTO(TimelineItemType::MENSAGEM, new \DateTimeImmutable($em), 'Mensagem', 'Texto', 'Ana', null, 'bi-chat', 'x', mensagemId: $id);
    }

    private static function despacho(?string $acao, string $em): DespachoNaLinhaDoTempoOutput
    {
        $tipo = 'metas.despacho.tipo.' . ($acao ?? 'despacho');

        return new DespachoNaLinhaDoTempoOutput(null, $tipo, $acao, '1234.5678', 'Ana', 'Ana', null, new \DateTimeImmutable($em), null, null, false, null, null, null, null, null, false);
    }
}
