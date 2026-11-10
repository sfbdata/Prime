<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\TipoDoAjuste;
use App\Ponto\Model\AjusteDaMarcacao;
use App\Ponto\Model\BatidaAjustada;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Service\SaidaDeEsquecimento;
use App\Ponto\UseCase\RegistrarBatidaDoEsquecimentoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * De onde veio a saída (spec `ponto-esquecimento-nao-trava.md` R4) com a batida EFETIVA (E10): a inclusão do
 * esquecimento abonado traz a justificativa no ajuste, e é ele que responde, sem consulta. A saída gravada antes da E10
 * continua pelo caminho da observação fixa com a justificativa abonada ao lado.
 */
#[CoversClass(SaidaDeEsquecimento::class)]
final class SaidaDeEsquecimentoTest extends TestCase
{
    #[TestDox('A saída incluída pelo esquecimento abonado (inclusão com justificativa) veio de esquecimento, sem consulta')]
    public function testInclusaoDoEsquecimentoVeioDeEsquecimento(): void
    {
        self::assertTrue($this->semConsulta()->veioDeEsquecimento($this->ajustada('saida', TipoDoAjuste::Inclusao, 42)));
    }

    #[TestDox('Não veio de esquecimento: a inclusão do gestor, a alteração, a restauração e a entrada incluída pelo esquecimento')]
    public function testOutrosAjustesNaoVieramDeEsquecimento(): void
    {
        $saida = $this->semConsulta();

        self::assertFalse($saida->veioDeEsquecimento($this->ajustada('saida', TipoDoAjuste::Inclusao, null)));
        self::assertFalse($saida->veioDeEsquecimento($this->ajustada('saida', TipoDoAjuste::Alteracao, null)));
        self::assertFalse($saida->veioDeEsquecimento($this->ajustada('saida', TipoDoAjuste::Restauracao, null)));
        self::assertFalse($saida->veioDeEsquecimento($this->ajustada('entrada', TipoDoAjuste::Inclusao, 42)));
    }

    #[TestDox('A saída gravada antes da E10: a observação fixa e a justificativa abonada ao lado, como antes')]
    public function testSaidaAntigaPelaObservacao(): void
    {
        $justificativas = $this->createMock(JustificativaPontoRepository::class);
        $justificativas->expects(self::once())->method('existeEsquecimentoAbonado')->willReturn(true);

        $antiga = $this->registro('saida', RegistrarBatidaDoEsquecimentoUseCase::OBSERVACAO);

        self::assertTrue((new SaidaDeEsquecimento($justificativas))->veioDeEsquecimento($antiga));
    }

    #[TestDox('A saída batida pelo colaborador (sem observação) não veio de esquecimento, sem consulta')]
    public function testSaidaBatidaNaoVeioDeEsquecimento(): void
    {
        self::assertFalse($this->semConsulta()->veioDeEsquecimento($this->registro('saida', null)));
    }

    private function semConsulta(): SaidaDeEsquecimento
    {
        $justificativas = $this->createMock(JustificativaPontoRepository::class);
        $justificativas->expects(self::never())->method('existeEsquecimentoAbonado');

        return new SaidaDeEsquecimento($justificativas);
    }

    private function ajustada(string $tipo, TipoDoAjuste $ajuste, ?int $justificativaId): BatidaAjustada
    {
        $registroId = $ajuste === TipoDoAjuste::Inclusao ? null : 5;

        return new BatidaAjustada(
            $registroId,
            $registroId === null ? 30 : null,
            30,
            3,
            $tipo,
            new \DateTimeImmutable('2026-10-21 18:00:00'),
            new AjusteDaMarcacao($ajuste, 'Gestora', new \DateTimeImmutable('2026-10-22 09:00:00'), $justificativaId),
        );
    }

    private function registro(string $tipo, ?string $observacao): RegistroPonto
    {
        return (new RegistroPonto())->setUser(new User())->setTenant(new Tenant())->setTipo($tipo)
            ->setDataHora(new \DateTimeImmutable('2026-10-21 18:00:00'))->setObservacao($observacao);
    }
}
