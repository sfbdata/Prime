<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Enum\TipoDoAjuste;
use App\Ponto\Model\AjusteDaMarcacao;
use App\Ponto\Model\BatidaAjustada;
use App\Ponto\Model\MarcacaoDoPonto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A batida ajustada como a folha a conta (E10, plano-e10-ponto §6): a cabeça da linhagem, com o home office da original,
 * nunca "sem internet" nem "feita pelo colaborador"; o id e o alvo vêm da raiz.
 */
#[CoversClass(BatidaAjustada::class)]
#[CoversClass(AjusteDaMarcacao::class)]
final class BatidaAjustadaTest extends TestCase
{
    #[TestDox('Raiz no registro: getId é o do registro, alvo b{id}, cabeça e ajuste da última linha, home office da original')]
    public function testRaizNoRegistro(): void
    {
        $ajuste = new AjusteDaMarcacao(TipoDoAjuste::Alteracao, 'Gestora', new \DateTimeImmutable('2026-10-22 09:00:00'), null);

        $batida = new BatidaAjustada(31, null, 44, 5, 'saida', new \DateTimeImmutable('2026-10-21 18:30:00'), $ajuste, homeOffice: true, observacao: 'nota');

        self::assertInstanceOf(MarcacaoDoPonto::class, $batida);
        self::assertSame([31, 'b31', 44, 5], [$batida->getId(), $batida->alvo(), $batida->cabeca(), $batida->pessoaId()]);
        self::assertSame(['saida', '2026-10-21 18:30:00'], [$batida->getTipo(), $batida->getDataHora()->format('Y-m-d H:i:s')]);
        self::assertSame([true, false, false, 'nota'], [$batida->isHomeOffice(), $batida->isEnviadaSemInternet(), $batida->feitaPeloColaborador(), $batida->getObservacao()]);
        self::assertSame($ajuste, $batida->ajuste());
    }

    #[TestDox('Raiz na inclusão: getId nulo (não há registro), alvo a{id}, sem home office nem observação')]
    public function testRaizNaInclusao(): void
    {
        $ajuste = new AjusteDaMarcacao(TipoDoAjuste::Inclusao, 'Gestora', new \DateTimeImmutable('2026-10-22 09:00:00'), 70);

        $batida = new BatidaAjustada(null, 50, 50, 5, 'saida', new \DateTimeImmutable('2026-10-21 18:00:00'), $ajuste);

        self::assertSame([null, 'a50', 50], [$batida->getId(), $batida->alvo(), $batida->cabeca()]);
        self::assertSame([false, null, 70], [$batida->isHomeOffice(), $batida->getObservacao(), $batida->ajuste()->justificativaId]);
    }

    #[TestDox('Sem raiz ou com as duas raízes: LogicException')]
    public function testUmaRaizSo(): void
    {
        $ajuste = new AjusteDaMarcacao(TipoDoAjuste::Alteracao, 'Gestora', new \DateTimeImmutable(), null);

        foreach ([[null, null], [31, 50]] as [$registro, $inclusao]) {
            try {
                new BatidaAjustada($registro, $inclusao, 44, 5, 'saida', new \DateTimeImmutable(), $ajuste);
                self::fail('Aceitou raiz inválida.');
            } catch (\LogicException $erro) {
                self::assertStringContainsString('uma raiz só', $erro->getMessage());
            }
        }
    }
}
