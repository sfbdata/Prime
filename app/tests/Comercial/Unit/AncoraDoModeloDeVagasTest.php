<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\Exception\CampanhaRecusada;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A âncora do consentimento de vagas (spec §11): o modelo `vagas` alcança quem só autorizou os alertas de vagas, então
 * só sai no modo simples, com o botão para as vagas. O texto continua editável; os outros modelos não são tocados.
 */
#[CoversClass(ComposicaoDaCampanha::class)]
final class AncoraDoModeloDeVagasTest extends TestCase
{
    #[TestDox('O modelo vagas como vem, ou com o texto editado, passa; os outros modelos passam com qualquer link')]
    public function testAceita(): void
    {
        ComposicaoDaCampanha::exigirAncoraDeVagas(self::mensagem('vagas'));
        ComposicaoDaCampanha::exigirAncoraDeVagas(self::mensagem('vagas', ['assunto' => 'Outro', 'corpo' => 'Outro texto', 'cta' => 'Ver']));
        ComposicaoDaCampanha::exigirAncoraDeVagas(self::mensagem('convite', ['link' => 'https://exemplo.com']));
        ComposicaoDaCampanha::exigirAncoraDeVagas(self::mensagem('convite', ['modo' => 'blocos']));
        $this->addToAssertionCount(4);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function fora(): iterable
    {
        yield 'botão para outra página' => [['link' => 'cadastro']];
        yield 'botão para endereço externo' => [['link' => 'https://exemplo.com/vagas-juridicas']];
        yield 'sem botão' => [['cta' => ' ']];
        yield 'modo blocos' => [['modo' => 'blocos']];
        yield 'modo html' => [['modo' => 'html', 'html' => '<p>Oi</p>']];
    }

    /** @param array<string, mixed> $troca */
    #[DataProvider('fora')]
    #[TestDox('Recusa o modelo vagas: $_dataName')]
    public function testRecusa(array $troca): void
    {
        $this->expectException(CampanhaRecusada::class);
        $this->expectExceptionMessage(ComposicaoDaCampanha::ANCORA_DE_VAGAS);

        ComposicaoDaCampanha::exigirAncoraDeVagas(self::mensagem('vagas', $troca));
    }

    /** @param array<string, mixed> $troca */
    private static function mensagem(string $chave, array $troca = []): MensagemDaCampanha
    {
        return MensagemDaCampanha::deArray($troca + (array) (new ModelosDeCampanha())->fixo($chave));
    }
}
