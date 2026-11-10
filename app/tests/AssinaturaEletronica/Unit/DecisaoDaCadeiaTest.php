<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\VersaoDoPdf;
use App\AssinaturaEletronica\Enum\SituacaoDaCadeia;
use App\AssinaturaEletronica\Service\Cadeia\DecisaoDaCadeia;
use App\Entity\Tenant\Tenant;
use App\Tests\AssinaturaEletronica\Support\CadeiaDeExemplo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O desfecho da E6-6 e a gravação da E6-7 (etapa 6, bloco 6B): `cadeia`, `cadeia_situacao` e `cadeia_conferida_em` na
 * versão devolvida.
 */
#[CoversClass(DecisaoDaCadeia::class)]
#[Group('assinatura')]
final class DecisaoDaCadeiaTest extends TestCase
{
    #[TestDox('confirmada grava a resposta do validador, a situação e a hora em segundos')]
    public function testConfirmadaGrava(): void
    {
        $versao = self::devolvida();
        $cadeia = CadeiaDeExemplo::resultado([CadeiaDeExemplo::item()]);

        DecisaoDaCadeia::confirmada($cadeia)->gravarEm($versao, new \DateTimeImmutable('2026-11-10 08:30:15.678'));

        self::assertSame(SituacaoDaCadeia::Confirmada, $versao->getCadeiaSituacao());
        self::assertSame($cadeia->paraJson(), $versao->getCadeia());
        self::assertSame('2026-11-10 08:30:15.000000', $versao->getCadeiaConferidaEm()?->format('Y-m-d H:i:s.u'));
        self::assertTrue($versao->cadeiaConfirmada());
    }

    #[TestDox('recusada pelo validador grava a resposta com a situação recusada')]
    public function testRecusadaGrava(): void
    {
        $versao = self::devolvida();
        $cadeia = CadeiaDeExemplo::resultado([CadeiaDeExemplo::item(['revogacao' => 'revogada'])]);

        DecisaoDaCadeia::recusada('revogacao_nao_boa', $cadeia, $cadeia->assinaturas[0])->gravarEm($versao, new \DateTimeImmutable());

        self::assertSame(SituacaoDaCadeia::Recusada, $versao->getCadeiaSituacao());
        self::assertSame($cadeia->paraJson(), $versao->getCadeia());
        self::assertFalse($versao->cadeiaConfirmada());
    }

    #[TestDox('recusa antes do validador não tem o que gravar: LogicException, e a versão fica sem cadeia')]
    public function testRecusaSemCadeiaNaoGrava(): void
    {
        $versao = self::devolvida();
        $decisao = DecisaoDaCadeia::recusada('sem_assinatura_nova', null);

        self::assertFalse($decisao->temCadeia());
        try {
            $decisao->gravarEm($versao, new \DateTimeImmutable());
            self::fail('Devia lançar LogicException.');
        } catch (\LogicException) {
        }
        self::assertNull($versao->getCadeiaSituacao());
        self::assertNull($versao->getCadeia());
    }

    #[TestDox('a versão só é conferida uma vez')]
    public function testSoUmaVez(): void
    {
        $versao = self::devolvida();
        $decisao = DecisaoDaCadeia::confirmada(CadeiaDeExemplo::resultado([CadeiaDeExemplo::item()]));
        $decisao->gravarEm($versao, new \DateTimeImmutable());

        $this->expectException(\LogicException::class);
        $decisao->gravarEm($versao, new \DateTimeImmutable());
    }

    private static function devolvida(): VersaoDoPdf
    {
        $documento = new DocumentoParaAssinar(new Tenant(), null, new \DateTimeImmutable('2026-11-09 09:00:00'));
        $documento->aplicarRascunho(DadosDoRascunho::deJson([
            'nome' => 'Contrato',
            'signatarios' => [['nome' => 'Caio Reis', 'email' => 'caio@exemplo.com', 'nivel' => 'icp']],
        ]), [], new \DateTimeImmutable('2026-11-09 09:00:00'));
        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable('2026-11-09 10:00:00'));

        return VersaoDoPdf::devolvida($documento->getSignatarios()[0], 2, 'dev.pdf', 'assinado.pdf', str_repeat('a', 64), 10, ['v' => 1, 'situacao' => 'integro'], new \DateTimeImmutable());
    }
}
