<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Entity\TokenDoConector;
use App\Conector\Enum\MotivoDaRevogacaoDaConcessao;
use App\Conector\Enum\RotacaoDaRenovacao;
use App\Conector\Enum\TipoDoTokenDoConector;
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

    #[TestDox('Primeiro giro marca o uso; repetir dentro de 60 s é tolerado (rede caiu) e não derruba a concessão')]
    public function testJanelaDeTolerancia(): void
    {
        $concessao = self::concessao();
        $renovacao = self::renovacao($concessao);

        self::assertSame(RotacaoDaRenovacao::Primeira, $renovacao->girar($concessao->getCliente(), self::em('2026-11-16 11:00:00')));
        self::assertSame(RotacaoDaRenovacao::RepeticaoNaJanela, $renovacao->girar($concessao->getCliente(), self::em('2026-11-16 11:01:00')));
        self::assertEquals(self::em('2026-11-16 11:00:00'), $renovacao->getUsadoEm(), 'a repetição não move o primeiro uso');
        self::assertNull($concessao->getRevogadaEm());
    }

    #[TestDox('Repetir depois de 60 s é reúso: derruba a concessão inteira com o motivo renovacao_reutilizada')]
    public function testReusoForaDaJanela(): void
    {
        $concessao = self::concessao();
        $renovacao = self::renovacao($concessao);
        $renovacao->girar($concessao->getCliente(), self::em('2026-11-16 11:00:00'));

        try {
            $renovacao->girar($concessao->getCliente(), self::em('2026-11-16 11:01:01'));
            self::fail('Reúso fora da janela precisava derrubar a cadeia.');
        } catch (RenovacaoReutilizada) {
        }

        self::assertSame(MotivoDaRevogacaoDaConcessao::RenovacaoReutilizada, $concessao->getMotivo());
        self::assertFalse($concessao->estaValidaEm(self::em('2026-11-16 11:01:02')));
    }

    #[TestDox('Renovação apresentada por outro app derruba a concessão, mesmo no primeiro uso')]
    public function testOutroApp(): void
    {
        $concessao = self::concessao();
        $renovacao = self::renovacao($concessao);

        try {
            $renovacao->girar(self::clienteCimd('https://outro.example/metadata'), self::em('2026-11-16 11:00:00'));
            self::fail('Outro app precisava derrubar a cadeia.');
        } catch (RenovacaoReutilizada) {
        }

        self::assertNull($renovacao->getUsadoEm());
        self::assertSame(MotivoDaRevogacaoDaConcessao::RenovacaoReutilizada, $concessao->getMotivo());
    }

    #[TestDox('Só a renovação gira; revogada, vencida ou com a concessão revogada é recusada sem derrubar nada')]
    public function testGirarRecusado(): void
    {
        $concessao = self::concessao();
        $acesso = TokenDoConector::deAcesso(self::hash('a'), $concessao, self::em('2026-11-16 10:00:00'));
        $revogada = self::renovacao($concessao, 'r1');
        $revogada->revogar(self::em('2026-11-16 10:30:00'));
        $revogada->revogar(self::em('2026-11-16 10:40:00'));
        self::assertEquals(self::em('2026-11-16 10:30:00'), $revogada->getRevogadoEm());

        $outra = self::concessao();
        $daRevogada = self::renovacao($outra, 'r2');
        $outra->revogar(MotivoDaRevogacaoDaConcessao::RevogadaPelaPessoa, self::em('2026-11-16 10:30:00'));

        $tentativas = [
            [$acesso, $concessao, '2026-11-16 10:30:00'],
            [$revogada, $concessao, '2026-11-16 11:00:00'],
            [self::renovacao($concessao, 'r3'), $concessao, '2026-11-23 10:00:00'],
            [$daRevogada, $outra, '2026-11-16 11:00:00'],
        ];
        $recusas = 0;
        foreach ($tentativas as [$token, $c, $quando]) {
            try {
                $token->girar($c->getCliente(), self::em($quando));
            } catch (RenovacaoReutilizada) {
                self::fail('Recusa simples não derruba a cadeia.');
            } catch (\LogicException) {
                ++$recusas;
            }
        }

        self::assertSame(4, $recusas);
        self::assertNull($concessao->getRevogadaEm());
        self::assertSame(MotivoDaRevogacaoDaConcessao::RevogadaPelaPessoa, $outra->getMotivo());
    }
}
