<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OcorrenciaDaUnidade;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\AvaliacaoDaOcorrencia;
use App\Voto\Enum\OrigemDaOcorrencia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Registrar ocorrência e decidir a avaliação (spec voto-avaliacoes-do-conselho-1611, seção 2), nas regras da entidade e
 * da unidade: apta só registra, não apta abre avaliação pendente, a decisão pede justificativa e não se refaz, e tornar
 * apto muda a unidade sem tocar em `situacao`.
 */
#[CoversClass(OcorrenciaDaUnidade::class)]
#[CoversClass(UnidadeDaAssembleia::class)]
final class OcorrenciaDaUnidadeTest extends TestCase
{
    #[TestDox('Pessoa apta só registra: a ocorrência fica sem avaliação')]
    public function testAptaSemAvaliacao(): void
    {
        $ocorrencia = new OcorrenciaDaUnidade($a = $this->assembleia(), $this->unidade($a, 'quite'), 'Cadastro desatualizado', OrigemDaOcorrencia::Manual, 'Comissão');

        self::assertNull($ocorrencia->getAvaliacao());
        self::assertFalse($ocorrencia->pendente());
        self::assertSame($a->getTenant(), $ocorrencia->getTenant());
    }

    #[TestDox('Pessoa não apta abre a avaliação pendente')]
    public function testNaoAptaAbrePendente(): void
    {
        $ocorrencia = new OcorrenciaDaUnidade($a = $this->assembleia(), $this->unidade($a, 'inadimplente'), 'Quitou ontem', OrigemDaOcorrencia::Manual, 'Comissão', true);

        self::assertSame(AvaliacaoDaOcorrencia::Pendente, $ocorrencia->getAvaliacao());
        self::assertTrue($ocorrencia->pendente());
    }

    #[TestDox('O texto é obrigatório e corta em 80 caracteres')]
    public function testTexto(): void
    {
        self::assertSame(80, mb_strlen(OcorrenciaDaUnidade::textoValido('  ' . str_repeat('a', 120) . '  ')));
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('Escreva a ocorrência.');

        OcorrenciaDaUnidade::textoValido('   ');
    }

    #[TestDox('Decidir exige justificativa, guarda quem e quando e corta a justificativa em 160')]
    public function testDecidir(): void
    {
        $ocorrencia = $this->pendente();
        $agora = new \DateTimeImmutable('2026-11-16 20:30:15');

        $ocorrencia->decidir(AvaliacaoDaOcorrencia::Mantida, str_repeat('j', 200), 'Conselho X', $agora);

        self::assertSame(AvaliacaoDaOcorrencia::Mantida, $ocorrencia->getAvaliacao());
        self::assertSame(160, mb_strlen((string) $ocorrencia->getJustificativa()));
        self::assertSame('Conselho X', $ocorrencia->getDecididaPorNome());
        self::assertSame($agora, $ocorrencia->getDecididaEm());
        self::assertFalse($ocorrencia->pendente());
    }

    #[TestDox('Sem justificativa a decisão é recusada e a avaliação segue pendente')]
    public function testSemJustificativa(): void
    {
        $ocorrencia = $this->pendente();
        try {
            $ocorrencia->decidir(AvaliacaoDaOcorrencia::TornadaApta, '  ', 'Conselho');
            self::fail('deveria recusar');
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            self::assertSame('Informe a justificativa.', $e->getMessage());
        }

        self::assertTrue($ocorrencia->pendente());
    }

    #[TestDox('A decisão tomada não se refaz, e "pendente" não é uma decisão')]
    public function testNaoRefaz(): void
    {
        $ocorrencia = $this->pendente();
        try {
            $ocorrencia->decidir(AvaliacaoDaOcorrencia::Pendente, 'x', 'Conselho');
            self::fail('pendente não é decisão');
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            self::assertSame('Decisão inválida.', $e->getMessage());
        }
        $ocorrencia->decidir(AvaliacaoDaOcorrencia::Mantida, 'Sem comprovante', 'Conselho');

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('Não há avaliação pendente.');

        $ocorrencia->decidir(AvaliacaoDaOcorrencia::TornadaApta, 'Mudei de ideia', 'Conselho');
    }

    #[TestDox('Tornar apto muda a unidade e deixa a situação da lista como veio')]
    public function testTornarAptaPeloConselho(): void
    {
        $unidade = $this->unidade($this->assembleia(), 'inadimplente');
        self::assertFalse($unidade->isApta());

        $unidade->tornarAptaPeloConselho();

        self::assertTrue($unidade->isApta());
        self::assertSame('inadimplente', $unidade->getSituacao());
    }

    #[TestDox('Com peso por fração ideal, tornar apto exige fração na unidade')]
    public function testTornarAptaSemFracao(): void
    {
        $a = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Fracao, 'Gestora');
        $semFracao = new UnidadeDaAssembleia($a, '101', str_repeat('a', 32), str_repeat('b', 64), situacao: 'inapto');
        $comFracao = new UnidadeDaAssembleia($a, '102', str_repeat('c', 32), str_repeat('d', 64), situacao: 'inapto', fracao: '0.050000');

        $comFracao->tornarAptaPeloConselho();
        self::assertTrue($comFracao->isApta());

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $semFracao->tornarAptaPeloConselho();
    }

    private function pendente(): OcorrenciaDaUnidade
    {
        return new OcorrenciaDaUnidade($a = $this->assembleia(), $this->unidade($a, 'inadimplente'), 'Quitou ontem', OrigemDaOcorrencia::Manual, 'Comissão', true);
    }

    private function assembleia(): Assembleia
    {
        return new Assembleia(new Tenant(), 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
    }

    private function unidade(Assembleia $assembleia, string $situacao): UnidadeDaAssembleia
    {
        return new UnidadeDaAssembleia($assembleia, '101', str_repeat('a', 32), str_repeat('b', 64), 'A', $situacao);
    }
}
