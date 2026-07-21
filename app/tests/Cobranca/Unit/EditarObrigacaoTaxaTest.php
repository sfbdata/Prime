<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\EditarObrigacaoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\ConversorTaxaEncargo;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Cobranca\UseCase\EditarObrigacaoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Task 7 (spec taxa-por-obrigacao): editar GRAVA o override de taxa (via `ConversorTaxaEncargo`) em
 * vez de materializar um valor digitado — e a obrigação Viva segue não-congelada (D6).
 *
 * Espelho de `RegistrarObrigacaoTaxaTest`, mas com o modo 'reais': R$ 3,40 de multa sobre um principal
 * de R$ 170,00 (base Principal, herdada — sem carteira) reproduz exatamente 200 bp (2%), provando o
 * espelho R$<->% "editei o R$ hoje = fixei a % equivalente à data de hoje" (spec §5).
 */
#[CoversClass(EditarObrigacaoUseCase::class)]
final class EditarObrigacaoTaxaTest extends TestCase
{
    #[Test]
    public function gravaOverrideDeMultaENaoCongela(): void
    {
        $tenant = new Tenant();
        $caso = (new CasoCobranca())->setTenant($tenant);
        $obrigacao = (new Obrigacao())
            ->setTenant($tenant)
            ->setCaso($caso)
            ->setDescricao('Aluguel')
            ->setValorOriginal(17000)
            ->setVencimentoOriginal(new \DateTimeImmutable('2025-05-06'));

        $obrRepo = $this->createMock(ObrigacaoRepository::class);
        $obrRepo->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $alocacaoRepo = $this->createMock(AlocacaoPagamentoRepository::class);
        $alocacaoRepo->method('totalAlocadoEmObrigacoes')->willReturn(0);
        // RegistrarEventoHistorico é `final` (não mockável, tests/CLAUDE.md): usa-se o serviço REAL
        // com o repositório de eventos mockado, como o padrão já estabelecido nos demais unit tests.
        $eventoRepo = $this->createMock(EventoHistoricoRepository::class);
        $evento = new RegistrarEventoHistorico($eventoRepo);

        $uc = new EditarObrigacaoUseCase(
            $obrRepo, $alocacaoRepo, $evento,
            new CalculadoraEncargos(),
            new ResolvedorConfigEncargos(),
            new ConversorTaxaEncargo(new CalculadoraEncargos()),
        );

        $input = new EditarObrigacaoInput();
        $input->obrigacaoId = 1;
        $input->descricao = 'Aluguel';
        $input->valorOriginal = 17000;
        $input->vencimentoOriginal = new \DateTimeImmutable('2025-05-06');
        $input->modoMulta = 'reais';
        $input->multaReais = 340;
        $input->motivo = 'ajuste da taxa de multa';

        $resultado = $uc->executar($input, $tenant, new User());

        self::assertSame(200, $resultado->getTaxaMultaBp(), 'R$ 3,40 sobre R$ 170,00 (base Principal) equivale a 200 bp (2%)');
        self::assertFalse($resultado->encargosCongelados(), 'D6: editar nunca congela — a obrigação Viva segue Viva');
    }
}
