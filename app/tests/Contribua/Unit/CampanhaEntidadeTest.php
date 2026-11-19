<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\Entity\Campanha;
use App\Contribua\Entity\CampanhaParticipacao;
use App\Contribua\Enum\EstadoDaCampanha;
use App\Contribua\Enum\SituacaoDaParticipacao;
use App\Entity\Auth\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Campanha::class)]
#[CoversClass(CampanhaParticipacao::class)]
final class CampanhaEntidadeTest extends TestCase
{
    /** @return iterable<string, array{string, int, bool, EstadoDaCampanha}> */
    public static function estados(): iterable
    {
        yield 'antes do início' => ['2026-11-18', 0, true, EstadoDaCampanha::Agendada];
        yield 'no primeiro dia' => ['2026-11-19', 0, true, EstadoDaCampanha::Ativa];
        yield 'no último dia' => ['2026-11-30', 0, true, EstadoDaCampanha::Ativa];
        yield 'depois do fim' => ['2026-12-01', 0, true, EstadoDaCampanha::Encerrada];
        yield 'desligada vence tudo' => ['2026-11-25', 0, false, EstadoDaCampanha::Desligada];
        yield 'sem limite de vagas nunca esgota' => ['2026-11-25', 9999, true, EstadoDaCampanha::Ativa];
    }

    #[DataProvider('estados')]
    #[TestDox('Estado da campanha: $dia, $inscricoes inscrição(ões), ligada=$ligada')]
    public function testEstado(string $dia, int $inscricoes, bool $ligada, EstadoDaCampanha $esperado): void
    {
        $campanha = $this->campanha(['ligada' => $ligada]);

        self::assertSame($esperado, $campanha->estado(new \DateTimeImmutable($dia), $inscricoes));
    }

    #[TestDox('Com vagas, esgota quando as inscrições (pagas ou não) chegam ao limite')]
    public function testEsgotada(): void
    {
        $campanha = $this->campanha(['vagas' => 2]);
        $hoje = new \DateTimeImmutable('2026-11-25');

        self::assertSame(EstadoDaCampanha::Ativa, $campanha->estado($hoje, 1));
        self::assertSame(EstadoDaCampanha::Esgotada, $campanha->estado($hoje, 2));
    }

    #[TestDox('Alterar sobe a versão das regras; salvar o mesmo valor não sobe')]
    public function testVersao(): void
    {
        $campanha = Campanha::padrao();
        self::assertSame(1, $campanha->getVersaoRegras());

        self::assertFalse($this->aplicar($campanha, []));
        self::assertSame(1, $campanha->getVersaoRegras());
        self::assertTrue($this->aplicar($campanha, ['multiplicador' => 3]));
        self::assertSame(2, $campanha->getVersaoRegras());
        self::assertSame(300, $campanha->valorDaDevolucaoCentavos());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidos(): iterable
    {
        yield 'preço abaixo de R$ 1' => [['precoCentavos' => 99], 'O preço mínimo é R$ 1,00.'];
        yield 'multiplicador 4' => [['multiplicador' => 4], 'A devolução é em dobro ou em triplo.'];
        yield 'prazo de pedido menor que 7' => [['prazoPedidoDias' => 6], 'O prazo para pedir a devolução é de no mínimo 7 dias.'];
        yield 'prazo de pagamento 0' => [['prazoPagamentoDiasUteis' => 0], 'O prazo para pagar a devolução é de no mínimo 1 dia útil.'];
        yield 'fim antes do início' => [['fim' => '2026-11-01'], 'A data final não pode ser antes da inicial.'];
        yield 'vagas negativas' => [['vagas' => -1], 'O número de vagas não pode ser negativo.'];
        yield 'nome vazio' => [['nome' => ' '], 'O nome da campanha deve ter de 1 a 60 caracteres.'];
    }

    /** @param array<string, mixed> $mudanca */
    #[DataProvider('invalidos')]
    #[TestDox('Recusa configuração inválida')]
    public function testInvalidos(array $mudanca, string $mensagem): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage($mensagem);

        $this->aplicar(Campanha::padrao(), $mudanca);
    }

    #[TestDox('A inscrição congela valor, multiplicador, prazos e versão; a campanha mudar depois não altera')]
    public function testCongela(): void
    {
        $campanha = Campanha::padrao();
        $participacao = $this->participacao($campanha);
        $this->aplicar($campanha, ['multiplicador' => 3, 'precoCentavos' => 500]);

        self::assertSame(100, $participacao->getValorCentavos());
        self::assertSame(2, $participacao->getMultiplicador());
        self::assertSame(200, $participacao->valorDaDevolucaoCentavos());
        self::assertSame(1, $participacao->getVersaoRegras());
    }

    #[TestDox('Prazo da devolução conta do pagamento: no 30º dia ainda pode, no 31º não; aguardando nunca pode')]
    public function testPrazoDaDevolucao(): void
    {
        $participacao = $this->participacao(Campanha::padrao());
        self::assertFalse($participacao->podeDevolver(new \DateTimeImmutable('2026-11-20')), 'aguardando pagamento');

        $participacao->confirmarPagamento(new User(), new \DateTimeImmutable('2026-11-20 15:00:00', new \DateTimeZone('America/Sao_Paulo')));
        self::assertSame(SituacaoDaParticipacao::Ativa, $participacao->getSituacao());
        self::assertTrue($participacao->podeDevolver(new \DateTimeImmutable('2026-12-20', new \DateTimeZone('America/Sao_Paulo'))));
        self::assertSame(0, $participacao->diasRestantes(new \DateTimeImmutable('2026-12-20', new \DateTimeZone('America/Sao_Paulo'))));
        self::assertFalse($participacao->podeDevolver(new \DateTimeImmutable('2026-12-21', new \DateTimeZone('America/Sao_Paulo'))));
        self::assertSame(25, $participacao->diasRestantes(new \DateTimeImmutable('2026-11-25', new \DateTimeZone('America/Sao_Paulo'))));
    }

    #[TestDox('Confirmar duas vezes é recusado')]
    public function testConfirmarDuasVezes(): void
    {
        $participacao = $this->participacao(Campanha::padrao());
        $participacao->confirmarPagamento(new User(), new \DateTimeImmutable());

        $this->expectException(\DomainException::class);
        $participacao->confirmarPagamento(new User(), new \DateTimeImmutable());
    }

    private function participacao(Campanha $campanha): CampanhaParticipacao
    {
        return new CampanhaParticipacao($campanha, new User(), null, 'Fulano', str_repeat('a', 64), '09', '123DF', new \DateTimeImmutable(), '127.0.0.1', new \DateTimeImmutable('2026-11-20 10:00:00', new \DateTimeZone('America/Sao_Paulo')));
    }

    /** @param array<string, mixed> $mudanca */
    private function campanha(array $mudanca): Campanha
    {
        $campanha = Campanha::padrao();
        $this->aplicar($campanha, $mudanca);

        return $campanha;
    }

    /** @param array<string, mixed> $mudanca */
    private function aplicar(Campanha $campanha, array $mudanca): bool
    {
        $v = $mudanca + $campanha->valores();

        return $campanha->aplicar(
            $v['nome'],
            $v['chamada'],
            $v['precoCentavos'],
            $v['multiplicador'],
            $v['prazoPedidoDias'],
            $v['prazoPagamentoDiasUteis'],
            new \DateTimeImmutable($v['inicio']),
            new \DateTimeImmutable($v['fim']),
            $v['vagas'],
            $v['mostrarContador'],
            $v['abaNoTopo'],
            $v['beneficios'],
            $v['ligada'],
            null,
            new \DateTimeImmutable(),
        );
    }
}
