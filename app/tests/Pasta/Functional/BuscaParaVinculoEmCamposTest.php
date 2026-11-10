<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Repository\PastaRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A busca de pasta do "Vincular à pasta" da Assinatura (spec assinatura-documentos-etapa-3, E3-10), no banco: as mesmas
 * travas da busca de vínculo da Cobrança (escritório, lápide, só a pasta que quem busca EDITA pelo cadeado; número,
 * cliente e ação sem acento nem caixa), com os campos separados.
 */
#[CoversClass(PastaRepository::class)]
final class BuscaParaVinculoEmCamposTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    #[TestDox('campos separados; sem acento nem caixa; fora a pasta de outro escritório, a da lápide, a oculta e a de só visualizar')]
    public function testTravas(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana = $this->criarColaborador($tenant);
        $livre = $this->criarPasta($tenant, 'JOÃO ÁVILA VINCULO');
        $livre->setNomeAcao('Ação de cobrança');
        $lapide = $this->criarPasta($tenant, 'JOÃO ÁVILA VINCULO');
        $lapide->marcarExcluida($primario, new \DateTimeImmutable());
        $this->em()->flush();
        $oculta = $this->criarPasta($tenant, 'JOÃO ÁVILA VINCULO');
        $this->trancar($oculta, $primario);
        $soVer = $this->criarPasta($tenant, 'JOÃO ÁVILA VINCULO');
        $this->trancar($soVer, $primario, [[$ana, NivelDeAcesso::Visualizar]]);
        [, $outro] = $this->criarEscritorio();
        $deOutro = $this->criarPasta($outro, 'JOÃO ÁVILA VINCULO');
        $repo = static::getContainer()->get(PastaRepository::class);

        $achadas = $repo->buscarParaVinculoEmCampos('joao avila vinculo', $tenant, $ana);

        self::assertSame([[
            'id' => (int) $livre->getId(),
            'nup' => (string) $livre->getNup(),
            'cliente' => 'JOÃO ÁVILA VINCULO',
            'acao' => 'AÇÃO DE COBRANÇA',
        ]], $achadas);

        $ids = array_column($repo->buscarParaVinculoEmCampos('', $tenant, $ana), 'id');
        self::assertContains((int) $livre->getId(), $ids, 'termo vazio: as mais recentes');
        foreach ([$lapide, $oculta, $soVer, $deOutro] as $fora) {
            self::assertNotContains((int) $fora->getId(), $ids);
        }
        self::assertSame([(int) $livre->getId()], array_column($repo->buscarParaVinculoEmCampos('cobranca', $tenant, $ana), 'id'), 'pela ação');
        self::assertSame([(int) $livre->getId()], array_column($repo->buscarParaVinculoEmCampos(strtolower((string) $livre->getNup()), $tenant, $ana), 'id'), 'pelo número');
    }

    #[TestDox('o limite vale: as mais recentes primeiro')]
    public function testLimite(): void
    {
        [, $tenant] = $this->criarEscritorio();
        $ana = $this->criarColaborador($tenant);
        $this->criarPasta($tenant, 'PEDRO ANTIGO');
        $nova = $this->criarPasta($tenant, 'PEDRO NOVO');
        $repo = static::getContainer()->get(PastaRepository::class);

        self::assertSame([(int) $nova->getId()], array_column($repo->buscarParaVinculoEmCampos('pedro', $tenant, $ana, 1), 'id'));
        self::assertCount(2, $repo->buscarParaVinculoEmCampos('pedro', $tenant, $ana));
    }
}
