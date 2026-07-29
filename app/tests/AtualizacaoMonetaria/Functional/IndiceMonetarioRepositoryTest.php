<?php

declare(strict_types=1);

namespace App\Tests\AtualizacaoMonetaria\Functional;

use App\AtualizacaoMonetaria\Entity\IndiceMonetario;
use App\AtualizacaoMonetaria\Enum\SerieIndice;
use App\AtualizacaoMonetaria\Repository\IndiceMonetarioRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IndiceMonetarioRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private IndiceMonetarioRepository $repositorio;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repositorio = $container->get(IndiceMonetarioRepository::class);
    }

    #[Test]
    public function ultimaCompetenciaPublicadaEhAMaiorDaSerie(): void
    {
        $this->gravar(SerieIndice::INPC, ['2026-04-01' => '0.81', '2026-06-01' => '0.14', '2026-05-01' => '0.65']);

        self::assertSame(
            '2026-06-01',
            $this->repositorio->ultimaCompetenciaPublicada(SerieIndice::INPC)?->format('Y-m-d'),
        );
    }

    /**
     * Medido em 29/07/2026: a taxa legal já tinha 07/2026 enquanto o INPC parava em 06/2026. Uma
     * "última competência" global recusaria data final válida — ou, pior, aceitaria data sem índice.
     */
    #[Test]
    public function ultimaCompetenciaEhIndependentePorSerie(): void
    {
        $this->gravar(SerieIndice::INPC, ['2026-06-01' => '0.14']);
        $this->gravar(SerieIndice::TAXA_LEGAL, ['2026-07-01' => '0.706607']);

        self::assertSame('2026-06-01', $this->repositorio->ultimaCompetenciaPublicada(SerieIndice::INPC)?->format('Y-m-d'));
        self::assertSame('2026-07-01', $this->repositorio->ultimaCompetenciaPublicada(SerieIndice::TAXA_LEGAL)?->format('Y-m-d'));
    }

    #[Test]
    public function serieSemNenhumRegistroNaoTemUltimaCompetencia(): void
    {
        $this->gravar(SerieIndice::INPC, ['2026-06-01' => '0.14']);

        self::assertNull($this->repositorio->ultimaCompetenciaPublicada(SerieIndice::IPCA));
    }

    #[Test]
    public function carregarTabelaMontaAFotografiaDasTresSeries(): void
    {
        $this->gravar(SerieIndice::INPC, ['2020-02-01' => '0.17', '2020-01-01' => '0.19']);
        $this->gravar(SerieIndice::IPCA, ['2020-01-01' => '0.21']);
        $this->gravar(SerieIndice::TAXA_LEGAL, ['2024-08-01' => '0.098765']);

        $tabela = $this->repositorio->carregarTabela();

        self::assertSame('0.190000', $tabela->variacao(SerieIndice::INPC, new \DateTimeImmutable('2020-01-01')));
        self::assertSame('0.210000', $tabela->variacao(SerieIndice::IPCA, new \DateTimeImmutable('2020-01-01')));
        self::assertSame('0.098765', $tabela->variacao(SerieIndice::TAXA_LEGAL, new \DateTimeImmutable('2024-08-01')));
        self::assertSame(['2020-01-01', '2020-02-01'], $tabela->competencias(SerieIndice::INPC));
        self::assertSame('2020-02-01', $tabela->ultimaCompetencia(SerieIndice::INPC)?->format('Y-m-d'));
    }

    /**
     * As 6 casas de numeric(12,6) têm de sobreviver à ida e volta pelo banco: é a taxa legal, e um
     * arredondamento aqui vira centavo errado no cálculo.
     */
    #[Test]
    public function carregarTabelaPreservaAsSeisCasasDecimais(): void
    {
        $this->gravar(SerieIndice::TAXA_LEGAL, ['2024-09-01' => '0.450641']);
        $this->em->clear();

        self::assertSame(
            '0.450641',
            $this->repositorio->carregarTabela()->variacao(SerieIndice::TAXA_LEGAL, new \DateTimeImmutable('2024-09-01')),
        );
    }

    #[Test]
    public function tabelaDeBancoVazioNaoQuebraEEhReconhecidamenteVazia(): void
    {
        self::assertTrue($this->repositorio->carregarTabela()->vazia());
    }

    #[Test]
    public function mapaPorCompetenciaIndexaPelaChaveUsadaPeloImportador(): void
    {
        $this->gravar(SerieIndice::INPC, ['2026-05-01' => '0.65', '2026-06-01' => '0.14']);
        $this->gravar(SerieIndice::IPCA, ['2026-06-01' => '0.16']);

        $mapa = $this->repositorio->mapaPorCompetencia(SerieIndice::INPC);

        self::assertSame(['2026-05-01', '2026-06-01'], array_keys($mapa));
        self::assertSame('0.140000', $mapa['2026-06-01']->getVariacaoPct());
        self::assertArrayNotHasKey('2026-07-01', $mapa);
    }

    /**
     * @param array<string, string> $variacoes competência => variação
     */
    private function gravar(SerieIndice $serie, array $variacoes): void
    {
        foreach ($variacoes as $competencia => $variacao) {
            $this->em->persist(new IndiceMonetario(
                $serie,
                new \DateTimeImmutable($competencia),
                $variacao,
                $serie->fonte(),
                new \DateTimeImmutable('2026-07-29 10:00:00'),
            ));
        }

        $this->em->flush();
    }
}
