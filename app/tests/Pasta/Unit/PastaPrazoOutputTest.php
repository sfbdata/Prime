<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Pasta\DTO\PastaPrazoOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Cartão "Próximos prazos" do trilho da aba Dados.
 *
 * Os dias são contados de HOJE, então nada aqui pode ser data fixa: a fixture
 * é sempre relativa (`+2 days`), senão o teste passa hoje e quebra amanhã.
 */
#[CoversClass(PastaPrazoOutput::class)]
final class PastaPrazoOutputTest extends TestCase
{
    private function tarefa(string $titulo, ?string $prazoRelativo, string $status = Tarefa::STATUS_PENDENTE, ?string $responsavel = null): Tarefa
    {
        $tarefa = new Tarefa();
        $tarefa->setTitulo($titulo);
        $tarefa->setDescricao('...');
        $tarefa->setStatus($status);

        if ($prazoRelativo !== null) {
            $tarefa->setPrazo(new \DateTimeImmutable($prazoRelativo));
        }

        if ($responsavel !== null) {
            $user = new User();
            $user->setEmail(strtolower(str_replace(' ', '.', $responsavel)) . '@test.com');
            $user->setFullName($responsavel);
            $tarefa->addResponsavel($user);
        }

        return $tarefa;
    }

    #[TestDox('as faixas do desenho: vermelho até 2 dias, âmbar até 8, cinza acima')]
    #[DataProvider('faixasDeTom')]
    public function testTomSegueAsFaixasDoDesenho(string $prazo, string $tomEsperado): void
    {
        $saida = PastaPrazoOutput::deTarefa($this->tarefa('Meta', $prazo));

        self::assertSame($tomEsperado, $saida->tom);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function faixasDeTom(): iterable
    {
        yield 'atrasada'          => ['-3 days', PastaPrazoOutput::TOM_URGENTE];
        yield 'vence hoje'        => ['today',   PastaPrazoOutput::TOM_URGENTE];
        yield 'limite do urgente' => ['+2 days', PastaPrazoOutput::TOM_URGENTE];
        yield 'primeiro âmbar'    => ['+3 days', PastaPrazoOutput::TOM_PROXIMO];
        yield 'limite do âmbar'   => ['+8 days', PastaPrazoOutput::TOM_PROXIMO];
        yield 'primeiro cinza'    => ['+9 days', PastaPrazoOutput::TOM_TRANQUILO];
    }

    #[TestDox('o selo diz em português quantos dias faltam — ou quantos já passaram')]
    #[DataProvider('textosDoSelo')]
    public function testSelo(string $prazo, string $esperado): void
    {
        self::assertSame($esperado, PastaPrazoOutput::deTarefa($this->tarefa('Meta', $prazo))->selo);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function textosDoSelo(): iterable
    {
        yield 'hoje'            => ['today',    'hoje'];
        yield 'singular'        => ['+1 day',   '1 dia'];
        yield 'plural'          => ['+5 days',  '5 dias'];
        yield 'atraso singular' => ['-1 day',   '1 dia em atraso'];
        yield 'atraso plural'   => ['-4 days',  '4 dias em atraso'];
    }

    #[TestDox('vencer HOJE não conta como atraso, mesmo com o prazo gravado à meia-noite')]
    public function testVenceHojeNaoViraAtraso(): void
    {
        /* A conta é entre DATAS. Comparando "agora" com um prazo gravado às
           00:00, "vence hoje" viraria "1 dia em atraso" a partir das 00:01 —
           defeito que só aparece depois do primeiro minuto do dia. */
        $saida = PastaPrazoOutput::deTarefa($this->tarefa('Meta', 'today 00:00'));

        self::assertSame('hoje', $saida->selo);
        self::assertSame(PastaPrazoOutput::TOM_URGENTE, $saida->tom);
    }

    #[TestDox('a meta traz responsável e dia; sem responsável, só o dia')]
    public function testMetaDaLinha(): void
    {
        $prazo = new \DateTimeImmutable('+4 days');

        $comResp = PastaPrazoOutput::deTarefa($this->tarefa('Meta', '+4 days', responsavel: 'Jessica Martins'));
        self::assertSame('Jessica Martins · ' . $prazo->format('d/m'), $comResp->meta);

        $semResp = PastaPrazoOutput::deTarefa($this->tarefa('Meta', '+4 days'));
        self::assertSame($prazo->format('d/m'), $semResp->meta);
    }

    #[TestDox('a lista traz as N metas abertas mais próximas, em ordem de prazo')]
    public function testProximasOrdenaELimita(): void
    {
        $saida = PastaPrazoOutput::proximas([
            $this->tarefa('Distante', '+30 days'),
            $this->tarefa('Amanhã', '+1 day'),
            $this->tarefa('Semana que vem', '+7 days'),
            $this->tarefa('Depois', '+40 days'),
        ]);

        self::assertSame(['Amanhã', 'Semana que vem', 'Distante'], array_map(fn ($p) => $p->titulo, $saida));
    }

    #[TestDox('meta CONCLUÍDA não é um próximo prazo')]
    public function testConcluidaNaoEntra(): void
    {
        $saida = PastaPrazoOutput::proximas([
            $this->tarefa('Feita', '+1 day', Tarefa::STATUS_CONCLUIDA),
            $this->tarefa('Aberta', '+5 days'),
        ]);

        self::assertSame(['Aberta'], array_map(fn ($p) => $p->titulo, $saida));
    }

    #[TestDox('meta SEM prazo não entra: não tem lugar numa lista ordenada por data')]
    public function testSemPrazoNaoEntra(): void
    {
        /* Inventar uma posição (fim ou começo da fila) diria ao usuário algo
           que o sistema não sabe. Fica de fora, e a aba Metas continua sendo
           onde ela aparece. */
        $saida = PastaPrazoOutput::proximas([
            $this->tarefa('Sem prazo', null),
            $this->tarefa('Com prazo', '+2 days'),
        ]);

        self::assertSame(['Com prazo'], array_map(fn ($p) => $p->titulo, $saida));
    }

    #[TestDox('pasta sem meta nenhuma devolve lista vazia, não erro')]
    public function testSemMetas(): void
    {
        self::assertSame([], PastaPrazoOutput::proximas([]));
    }

    #[TestDox('montar uma linha a partir de meta sem prazo é erro de programação, e explode')]
    public function testDeTarefaSemPrazoLanca(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PastaPrazoOutput::deTarefa($this->tarefa('Sem prazo', null));
    }
}
