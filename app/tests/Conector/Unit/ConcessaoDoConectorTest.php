<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Entity\ConcessaoDoConector;
use App\Conector\Enum\MotivoDaRevogacaoDaConcessao;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConcessaoDoConector::class)]
final class ConcessaoDoConectorTest extends TestCase
{
    use CenarioDoConector;

    #[TestDox('Pessoa e escritório são os do consentimento; o limite absoluto é 7 dias do autorizado_em')]
    public function testCopiaDoConsentimentoESeteDias(): void
    {
        $tenant = new Tenant();
        $user = new User();
        $consentimento = self::consentimento($tenant, $user);
        $concessao = self::concessao($consentimento);

        self::assertSame($tenant, $concessao->getTenant());
        self::assertSame($user, $concessao->getUser());
        self::assertSame($consentimento->getCliente(), $concessao->getCliente());
        self::assertEquals(self::em('2026-11-23 10:00:00'), $concessao->getExpiraEm());
        self::assertTrue($concessao->estaValidaEm(self::em('2026-11-23 09:59:59')));
        self::assertFalse($concessao->estaValidaEm(self::em('2026-11-23 10:00:00')));
    }

    #[TestDox('Consentimento revogado não abre concessão, e revogá-lo depois invalida a concessão aberta')]
    public function testConsentimentoRevogado(): void
    {
        $consentimento = self::consentimento();
        $concessao = self::concessao($consentimento);
        $consentimento->revogar(self::em('2026-11-16 11:00:00'));

        self::assertFalse($concessao->estaValidaEm(self::em('2026-11-16 11:00:01')));
        $this->expectException(\DomainException::class);
        self::concessao($consentimento);
    }

    #[TestDox('Revogar grava data e motivo uma vez só')]
    public function testRevogar(): void
    {
        $concessao = self::concessao();
        $concessao->revogar(MotivoDaRevogacaoDaConcessao::RevogadaPelaPessoa, self::em('2026-11-16 11:00:00'));
        $concessao->revogar(MotivoDaRevogacaoDaConcessao::RenovacaoReutilizada, self::em('2026-11-16 12:00:00'));

        self::assertEquals(self::em('2026-11-16 11:00:00'), $concessao->getRevogadaEm());
        self::assertSame(MotivoDaRevogacaoDaConcessao::RevogadaPelaPessoa, $concessao->getMotivo());
        self::assertFalse($concessao->estaValidaEm(self::em('2026-11-16 11:30:00')));
    }

    #[TestDox('"Sair de todos os aparelhos" derruba a concessão autorizada até o corte, inclusive no mesmo segundo')]
    public function testCorte(): void
    {
        $concessao = self::concessao(autorizadoEm: '2026-11-16 10:00:00');

        self::assertFalse($concessao->foiCortadoEm(null), 'sem corte gravado, nada cai');
        self::assertTrue($concessao->foiCortadoEm(self::em('2026-11-16 10:00:00')), 'no mesmo segundo também cai');
        self::assertTrue($concessao->foiCortadoEm(self::em('2026-11-17 10:00:00')));
        self::assertFalse($concessao->foiCortadoEm(self::em('2026-11-16 09:59:59')), 'corte antes da autorização não derruba');
    }

    #[TestDox('A impressão da senha precisa bater; guarda hash, recusa a senha crua')]
    public function testImpressaoDaSenha(): void
    {
        $concessao = self::concessao(senha: 'hash-antigo');

        self::assertTrue($concessao->impressaoDaSenhaBate(self::hash('hash-antigo')));
        self::assertFalse($concessao->impressaoDaSenhaBate(self::hash('hash-novo')));
        $this->expectException(\InvalidArgumentException::class);
        new ConcessaoDoConector(self::consentimento(), ['mcp:leitura'], 'https://bluejus.com.br/mcp', 'senha-crua', self::em('now'));
    }

    #[TestDox('Recusa escopo vazio, repetido ou com espaço')]
    public function testRecusaEscoposInvalidos(): void
    {
        $recusados = 0;
        foreach ([[], ['mcp:leitura', 'mcp:leitura'], ['mcp leitura']] as $escopos) {
            try {
                new ConcessaoDoConector(self::consentimento(), $escopos, 'https://bluejus.com.br/mcp', self::hash('s'), self::em('now'));
            } catch (\InvalidArgumentException) {
                ++$recusados;
            }
        }

        self::assertSame(3, $recusados);
    }
}
