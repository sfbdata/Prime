<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Entity\TokenDoConector;
use App\Conector\Enum\MotivoDaRevogacaoDaConcessao;
use App\Conector\Enum\RotacaoDaRenovacao;
use App\Conector\Enum\TipoDoTokenDoConector;
use App\Conector\Exception\RenovacaoRecusada;
use App\Conector\Exception\RenovacaoReutilizada;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(TokenDoConector::class)]
final class TokenDoConectorTest extends TestCase
{
    use CenarioDoConector;

    #[TestDox('O token de acesso vale 1 hora; a renovação vale até o limite da concessão; os dois herdam escritório e escopos')]
    public function testValidades(): void
    {
        $tenant = new Tenant();
        $concessao = self::concessao(self::consentimento($tenant));
        $acesso = TokenDoConector::deAcesso(self::hash('a'), $concessao, self::em('2026-11-18 10:00:00'));
        $renovacao = self::renovacao($concessao, quando: '2026-11-18 10:00:00');

        self::assertSame(TipoDoTokenDoConector::Acesso, $acesso->getTipo());
        self::assertSame(TipoDoTokenDoConector::Renovacao, $renovacao->getTipo());
        self::assertEquals(self::em('2026-11-18 11:00:00'), $acesso->getExpiraEm());
        self::assertEquals(self::em('2026-11-23 10:00:00'), $renovacao->getExpiraEm());
        self::assertSame($tenant, $acesso->getTenant());
        self::assertSame($concessao->getEscopos(), $renovacao->getEscopos());
        self::assertTrue($acesso->venceuEm(self::em('2026-11-18 11:00:00')));
    }

    #[TestDox('O acesso emitido perto do fim da concessão vence junto com ela, nunca depois')]
    public function testAcessoNaoPassaDoFimDaConcessao(): void
    {
        $concessao = self::concessao(self::consentimento(new Tenant()));

        $acesso = TokenDoConector::deAcesso(self::hash('fim'), $concessao, self::em('2026-11-23 09:30:00'));

        self::assertEquals($concessao->getExpiraEm(), $acesso->getExpiraEm());
        self::assertEquals(self::em('2026-11-23 10:00:00'), $acesso->getExpiraEm());
    }

    #[TestDox('Concessão revogada ou vencida não emite token, e o token guarda só hash')]
    public function testRecusasNaEmissao(): void
    {
        $revogada = self::concessao();
        $revogada->revogar(MotivoDaRevogacaoDaConcessao::RevogadaPelaPessoa, self::em('2026-11-16 10:30:00'));
        $tentativas = [
            fn () => TokenDoConector::deAcesso(self::hash('a'), $revogada, self::em('2026-11-16 11:00:00')),
            fn () => TokenDoConector::deAcesso(self::hash('a'), self::concessao(), self::em('2026-11-23 10:00:00')),
            fn () => TokenDoConector::deAcesso('token-cru', self::concessao(), self::em('2026-11-16 11:00:00')),
        ];
        $recusas = 0;
        foreach ($tentativas as $tentativa) {
            try {
                $tentativa();
            } catch (\LogicException) {
                ++$recusas;
            }
        }

        self::assertSame(3, $recusas);
    }

    #[TestDox('O par só nasce de uma renovação da mesma concessão')]
    public function testGeradoPor(): void
    {
        $concessao = self::concessao();
        $renovacao = self::renovacao($concessao);
        $par = TokenDoConector::deAcesso(self::hash('a2'), $concessao, self::em('2026-11-16 11:00:00'), $renovacao);
        self::assertSame($renovacao, $par->getGeradoPor());

        $acesso = TokenDoConector::deAcesso(self::hash('a'), $concessao, self::em('2026-11-16 10:00:00'));
        $recusas = 0;
        foreach ([[$concessao, $acesso], [self::concessao(), $renovacao]] as [$c, $origem]) {
            try {
                TokenDoConector::deRenovacao(self::hash('r2'), $c, self::em('2026-11-16 11:00:00'), $origem);
            } catch (\LogicException) {
                ++$recusas;
            }
        }

        self::assertSame(2, $recusas);
    }

    #[TestDox('Primeiro giro marca o uso; repetir dentro de 60 s com a filha intocada é tolerado (rede caiu) e não derruba a concessão')]
    public function testJanelaDeTolerancia(): void
    {
        $concessao = self::concessao();
        $r1 = self::renovacao($concessao, 'r1');

        self::assertSame(RotacaoDaRenovacao::Primeira, $r1->girar($concessao->getCliente(), self::em('2026-11-16 11:00:00')));
        $r2 = self::renovacao($concessao, 'r2', '2026-11-16 11:00:00', $r1);
        self::assertSame(RotacaoDaRenovacao::RepeticaoNaJanela, $r1->girar($concessao->getCliente(), self::em('2026-11-16 11:01:00'), $r2));
        self::assertEquals(self::em('2026-11-16 11:00:00'), $r1->getUsadoEm(), 'a repetição não move o primeiro uso');
        self::assertNull($concessao->getRevogadaEm());
    }

    #[TestDox('Repetir depois de 60 s é reúso: derruba a concessão inteira com o motivo renovacao_reutilizada')]
    public function testReusoForaDaJanela(): void
    {
        $concessao = self::concessao();
        $r1 = self::renovacao($concessao, 'r1');
        $r1->girar($concessao->getCliente(), self::em('2026-11-16 11:00:00'));
        $r2 = self::renovacao($concessao, 'r2', '2026-11-16 11:00:00', $r1);

        $this->exigirReuso(fn () => $r1->girar($concessao->getCliente(), self::em('2026-11-16 11:01:01'), $r2));

        self::assertSame(MotivoDaRevogacaoDaConcessao::RenovacaoReutilizada, $concessao->getMotivo());
        self::assertFalse($concessao->estaValidaEm(self::em('2026-11-16 11:01:02')));
    }

    #[TestDox('R1 gira em R2, R2 gira em R3; R1 volta dentro de 60 s: é reúso, a concessão cai e A3 e R3 não valem')]
    public function testFilhaJaUsadaDerrubaACadeia(): void
    {
        $concessao = self::concessao();
        $cliente = $concessao->getCliente();
        $r1 = self::renovacao($concessao, 'r1');
        $r1->girar($cliente, self::em('2026-11-16 11:00:00'));
        $r2 = self::renovacao($concessao, 'r2', '2026-11-16 11:00:00', $r1);
        $r2->girar($cliente, self::em('2026-11-16 11:00:10'));
        $a3 = TokenDoConector::deAcesso(self::hash('a3'), $concessao, self::em('2026-11-16 11:00:10'), $r2);
        $r3 = self::renovacao($concessao, 'r3', '2026-11-16 11:00:10', $r2);
        self::assertTrue($a3->valeEm(self::em('2026-11-16 11:00:15')), 'sanidade: A3 valia');

        $this->exigirReuso(fn () => $r1->girar($cliente, self::em('2026-11-16 11:00:20'), $r2));

        self::assertSame(MotivoDaRevogacaoDaConcessao::RenovacaoReutilizada, $concessao->getMotivo());
        self::assertFalse($a3->valeEm(self::em('2026-11-16 11:00:21')));
        try {
            $r3->girar($cliente, self::em('2026-11-16 11:00:21'));
            self::fail('R3 não podia girar com a concessão caída.');
        } catch (RenovacaoRecusada) {
        }
        self::assertSame(MotivoDaRevogacaoDaConcessao::RenovacaoReutilizada, $concessao->getMotivo(), 'o primeiro motivo fica');
    }

    #[TestDox('Filha revogada dentro da janela também é reúso')]
    public function testFilhaRevogadaDerrubaACadeia(): void
    {
        $concessao = self::concessao();
        $r1 = self::renovacao($concessao, 'r1');
        $r1->girar($concessao->getCliente(), self::em('2026-11-16 11:00:00'));
        $r2 = self::renovacao($concessao, 'r2', '2026-11-16 11:00:00', $r1);
        $r2->revogar(self::em('2026-11-16 11:00:05'));

        $this->exigirReuso(fn () => $r1->girar($concessao->getCliente(), self::em('2026-11-16 11:00:10'), $r2));
        self::assertSame(MotivoDaRevogacaoDaConcessao::RenovacaoReutilizada, $concessao->getMotivo());
    }

    #[TestDox('Renovação apresentada por outro app derruba a concessão, mesmo no primeiro uso')]
    public function testOutroApp(): void
    {
        $concessao = self::concessao();
        $renovacao = self::renovacao($concessao);

        $this->exigirReuso(fn () => $renovacao->girar(self::clienteCimd('https://outro.example/metadata'), self::em('2026-11-16 11:00:00')));

        self::assertNull($renovacao->getUsadoEm());
        self::assertSame(MotivoDaRevogacaoDaConcessao::RenovacaoReutilizada, $concessao->getMotivo());
    }

    #[TestDox('Renovação revogada ou vencida que volta derruba a concessão com falha_na_renovacao (9.2)')]
    public function testRevogadaOuVencidaDerrubaACadeia(): void
    {
        foreach (['revogada' => '2026-11-16 11:00:00', 'vencida' => '2026-11-23 10:00:00'] as $caso => $quando) {
            $concessao = self::concessao();
            $renovacao = self::renovacao($concessao);
            if ($caso === 'revogada') {
                $renovacao->revogar(self::em('2026-11-16 10:30:00'));
                $renovacao->revogar(self::em('2026-11-16 10:40:00'));
                self::assertEquals(self::em('2026-11-16 10:30:00'), $renovacao->getRevogadoEm(), 'revogar de novo não muda a data');
            }

            try {
                $renovacao->girar($concessao->getCliente(), self::em($quando));
                self::fail("Renovação {$caso} não podia girar.");
            } catch (RenovacaoReutilizada) {
                self::fail("Renovação {$caso} é falha, não reúso.");
            } catch (RenovacaoRecusada $recusa) {
                self::assertSame(MotivoDaRevogacaoDaConcessao::FalhaNaRenovacao, $recusa->motivo);
            }

            self::assertSame(MotivoDaRevogacaoDaConcessao::FalhaNaRenovacao, $concessao->getMotivo(), $caso);
            self::assertNotNull($concessao->getRevogadaEm(), $caso);
        }
    }

    #[TestDox('Concessão já revogada pela pessoa: a renovação é recusada e o motivo original fica')]
    public function testConcessaoJaRevogadaMantemOMotivo(): void
    {
        $concessao = self::concessao();
        $renovacao = self::renovacao($concessao);
        $concessao->revogar(MotivoDaRevogacaoDaConcessao::RevogadaPelaPessoa, self::em('2026-11-16 10:30:00'));

        $this->expectException(RenovacaoRecusada::class);
        try {
            $renovacao->girar($concessao->getCliente(), self::em('2026-11-16 11:00:00'));
        } finally {
            self::assertSame(MotivoDaRevogacaoDaConcessao::RevogadaPelaPessoa, $concessao->getMotivo());
        }
    }

    #[TestDox('Erro de programação não derruba nada: acesso não gira, filha errada e já girada sem filha são recusadas')]
    public function testErrosDeProgramacao(): void
    {
        $concessao = self::concessao();
        $cliente = $concessao->getCliente();
        $acesso = TokenDoConector::deAcesso(self::hash('a'), $concessao, self::em('2026-11-16 10:00:00'));
        $r1 = self::renovacao($concessao, 'r1');
        $r1->girar($cliente, self::em('2026-11-16 11:00:00'));
        $deOutra = self::renovacao($concessao, 'x');

        $tentativas = [
            fn () => $acesso->girar($cliente, self::em('2026-11-16 10:30:00')),
            fn () => $r1->girar($cliente, self::em('2026-11-16 11:00:10'), $deOutra),
            fn () => $r1->girar($cliente, self::em('2026-11-16 11:00:10')),
        ];
        $recusas = 0;
        foreach ($tentativas as $tentativa) {
            try {
                $tentativa();
            } catch (RenovacaoRecusada) {
                self::fail('Erro de programação não derruba a cadeia.');
            } catch (\LogicException) {
                ++$recusas;
            }
        }

        self::assertSame(3, $recusas);
        self::assertNull($concessao->getRevogadaEm());
    }

    private function exigirReuso(\Closure $giro): void
    {
        try {
            $giro();
            self::fail('Precisava derrubar a cadeia por reúso.');
        } catch (RenovacaoReutilizada $reuso) {
            self::assertSame(MotivoDaRevogacaoDaConcessao::RenovacaoReutilizada, $reuso->motivo);
        }
    }
}
