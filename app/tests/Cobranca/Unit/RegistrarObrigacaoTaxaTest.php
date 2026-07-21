<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\RegistrarObrigacaoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\ConversorTaxaEncargo;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Cobranca\UseCase\RegistrarObrigacaoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Task 7 (spec taxa-por-obrigacao): registrar GRAVA o override de taxa (via `ConversorTaxaEncargo`)
 * em vez de materializar um valor digitado — e a obrigação nunca congela ao registrar (D6).
 */
#[CoversClass(RegistrarObrigacaoUseCase::class)]
final class RegistrarObrigacaoTaxaTest extends TestCase
{
    #[Test]
    public function gravaOverrideDeJurosENaoCongela(): void
    {
        $tenant = new Tenant();
        // Caso ativo (status default) do próprio tenant, sem carteira — a base herdada é neutra
        // (taxa 0), então o override de 150 bp é o que decide o cálculo do dia.
        $caso = (new CasoCobranca())->setTenant($tenant);

        $casoRepo = $this->createMock(CasoCobrancaRepository::class);
        $casoRepo->method('findOneByIdDoTenant')->willReturn($caso);
        $obrRepo = $this->createMock(ObrigacaoRepository::class);
        // RegistrarEventoHistorico é `final` (não mockável, tests/CLAUDE.md): usa-se o serviço REAL
        // com o repositório de eventos mockado, como o padrão já estabelecido nos demais unit tests.
        $eventoRepo = $this->createMock(EventoHistoricoRepository::class);
        $evento = new RegistrarEventoHistorico($eventoRepo);

        $uc = new RegistrarObrigacaoUseCase(
            $obrRepo, $casoRepo, $evento,
            new CalculadoraEncargos(),
            new ResolvedorConfigEncargos(),
            new ConversorTaxaEncargo(new CalculadoraEncargos()),
        );

        $input = new RegistrarObrigacaoInput();
        $input->casoId = 1;
        $input->descricao = 'Aluguel';
        $input->valorOriginal = 17000;
        $input->vencimentoOriginal = new \DateTimeImmutable('2025-05-06');
        $input->modoJuros = 'percent';
        $input->jurosBp = 150;

        $obrigacao = $uc->executar($input, $tenant, new User());

        self::assertSame(150, $obrigacao->getTaxaJurosMensalBp(), 'o override de juros (150 bp) é gravado na obrigação');
        self::assertFalse($obrigacao->encargosCongelados(), 'D6: registrar nunca congela — a obrigação nasce Viva');
    }
}
