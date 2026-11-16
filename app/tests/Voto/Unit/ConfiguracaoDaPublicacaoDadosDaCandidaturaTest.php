<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Enum\TipoDaPauta;
use App\Voto\Service\ConfiguracaoDaPublicacao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Os textos da candidatura são configuração: mudar um deles exige nova conferência; sem eles o código é o de antes. */
#[CoversClass(ConfiguracaoDaPublicacao::class)]
final class ConfiguracaoDaPublicacaoDadosDaCandidaturaTest extends TestCase
{
    private static function opcao(): OpcaoDaPauta
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Fracao, 'Gestora');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Síndico(a)', null, TipoDaPauta::EleicaoDeCargo);
        self::$assembleia = $assembleia;

        return new OpcaoDaPauta($pauta, 1, 'Ana');
    }

    private static Assembleia $assembleia;

    #[TestDox('Cada texto da candidatura muda o código da conferência; sem texto, o código não muda')]
    public function testCadaTextoMudaOCodigo(): void
    {
        $opcao = self::opcao();
        $assembleia = self::$assembleia;
        $semDados = ConfiguracaoDaPublicacao::codigo($assembleia);

        $opcao->detalhar(null);
        self::assertSame($semDados, ConfiguracaoDaPublicacao::codigo($assembleia), 'sem dados o resumo é o de antes');

        $codigos = [$semDados];
        foreach ([['Descrição'], [null, 'Propostas'], [null, null, 'Presidente: Ana'], [null, null, null, '101']] as $dados) {
            $opcao->detalhar(...$dados);
            $codigos[] = ConfiguracaoDaPublicacao::codigo($assembleia);
        }

        self::assertCount(5, array_unique($codigos), 'descrição, propostas, composição e unidade entram no hash');
    }

    #[TestDox('A foto 3x4 fica FORA do hash: enviar, trocar ou tirar a foto não muda o código da conferência')]
    public function testAFotoNaoMudaOCodigo(): void
    {
        $opcao = self::opcao();
        $assembleia = self::$assembleia;
        $opcao->detalhar('Descrição', 'Propostas');
        $semFoto = ConfiguracaoDaPublicacao::codigo($assembleia);

        $opcao->definirFoto('a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg');
        self::assertSame($semFoto, ConfiguracaoDaPublicacao::codigo($assembleia), 'com foto');

        $opcao->definirFoto('0123456789abcdef0123456789abcdef.png');
        self::assertSame($semFoto, ConfiguracaoDaPublicacao::codigo($assembleia), 'foto trocada');

        $opcao->definirFoto(null);
        self::assertSame($semFoto, ConfiguracaoDaPublicacao::codigo($assembleia), 'foto tirada');
    }
}
