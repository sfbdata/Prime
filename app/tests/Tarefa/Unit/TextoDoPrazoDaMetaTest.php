<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Tarefa\Tarefa;
use App\Tarefa\DTO\TextoDoPrazoDaMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * E5c — texto do prazo na linha de Minhas Metas, como o desenho de 13/10 (`04 - Minhas Metas 1.0.dc.html`,
 * JS `prazoTxt`, `prazoCor`, `prazoPeso`): "vence em N dia(s) · dd/mm/aaaa", "vence hoje", "sem prazo
 * definido", "concluída", "N dia(s) atrasado". Só a meta ATRASADA (balde do `AgrupadorDeMetas`) ganha o
 * destaque vermelho e negrito; todo o resto é cinza normal — inclusive a meta em revisão de prazo vencido,
 * que saiu da fila de quem executa (o desenho faz o mesmo: `classe` = rev, cor #4a6373).
 *
 * `Tarefa::getPrazoInfo` não muda: é usado na tela da meta e na de Equipe.
 */
#[CoversClass(TextoDoPrazoDaMeta::class)]
final class TextoDoPrazoDaMetaTest extends TestCase
{
    private const HOJE = '2026-10-14';

    private function meta(string $status, ?string $prazo): Tarefa
    {
        $t = new Tarefa();
        $t->setTitulo('Meta');
        $t->setDescricao('');
        $t->setStatus($status);
        $t->setPrazo($prazo === null ? null : new \DateTimeImmutable($prazo));

        return $t;
    }

    /** @return iterable<string, array{string, ?string, string, bool}> */
    public static function casos(): iterable
    {
        yield 'atrasada há 1 dia' => [Tarefa::STATUS_PENDENTE, '2026-10-13', '1 dia(s) atrasado', true];
        yield 'atrasada há 12 dias' => [Tarefa::STATUS_PENDENTE, '2026-10-02', '12 dia(s) atrasado', true];
        yield 'vence hoje' => [Tarefa::STATUS_PENDENTE, '2026-10-14', 'vence hoje', false];
        yield 'vence amanhã' => [Tarefa::STATUS_PENDENTE, '2026-10-15', 'vence em 1 dia(s) · 15/10/2026', false];
        yield 'vence em 20 dias' => [Tarefa::STATUS_PENDENTE, '2026-11-03', 'vence em 20 dia(s) · 03/11/2026', false];
        yield 'sem prazo' => [Tarefa::STATUS_PENDENTE, null, 'sem prazo definido', false];
        yield 'em revisão com prazo vencido (sem destaque)' => [Tarefa::STATUS_EM_REVISAO, '2026-10-11', '3 dia(s) atrasado', false];
        yield 'em revisão sem prazo' => [Tarefa::STATUS_EM_REVISAO, null, 'sem prazo definido', false];
        yield 'concluída com prazo' => [Tarefa::STATUS_CONCLUIDA, '2026-10-01', 'concluída', false];
        yield 'concluída sem prazo' => [Tarefa::STATUS_CONCLUIDA, null, 'concluída', false];
    }

    #[DataProvider('casos')]
    #[TestDox('$_dataName: "$texto"')]
    public function testTextoDoDesenho(string $status, ?string $prazo, string $texto, bool $atrasado): void
    {
        $r = TextoDoPrazoDaMeta::de($this->meta($status, $prazo), new \DateTimeImmutable(self::HOJE));

        self::assertSame($texto, $r->texto);
        self::assertSame($atrasado, $r->atrasado);
    }

    #[TestDox('a hora do dia não muda a conta: prazo de amanhã às 00:00 visto às 23:59 de hoje é "vence em 1 dia(s)"')]
    public function testHoraNaoMudaAConta(): void
    {
        $r = TextoDoPrazoDaMeta::de(
            $this->meta(Tarefa::STATUS_PENDENTE, '2026-10-15 00:00'),
            new \DateTimeImmutable('2026-10-14 23:59'),
        );

        self::assertSame('vence em 1 dia(s) · 15/10/2026', $r->texto);
    }
}
