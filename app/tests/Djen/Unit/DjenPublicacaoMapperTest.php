<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Service\DjenPublicacaoMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DjenPublicacaoMapper::class)]
final class DjenPublicacaoMapperTest extends TestCase
{
    private DjenPublicacaoMapper $sut;

    protected function setUp(): void
    {
        $this->sut = new DjenPublicacaoMapper();
    }

    #[Test]
    public function mapeiaCamposEscalaresEGuardaPayloadBruto(): void
    {
        $item = [
            'id' => 659192456,
            'numeroComunicacao' => 1,
            'hash' => 'XvLko4Pw16XetwhoTGjdA9ZbAKq5Vd',
            'siglaTribunal' => 'CJF',
            'tipoComunicacao' => 'Intimação',
            'nomeOrgao' => 'PRESIDÊNCIA',
            'numero_processo' => '50636766220224047000',
            'numeroprocessocommascara' => '5063676-62.2022.4.04.7000',
            'data_disponibilizacao' => '2026-07-06',
            'meio' => 'D',
            'meiocompleto' => 'Diário de Justiça Eletrônico Nacional',
            'texto' => '<html>teor</html>',
            'link' => 'https://eproctnu.cjf.jus.br/...',
            'tipoDocumento' => 'Intimação',
            'nomeClasse' => 'Pedido de Uniformização',
            'codigoClasse' => '12345',
        ];

        $publicacao = new PublicacaoDjen();
        $this->sut->mapearItem($publicacao, $item);

        self::assertSame('659192456', $publicacao->getDjenId());
        self::assertSame('1', $publicacao->getNumeroComunicacao());
        self::assertSame('XvLko4Pw16XetwhoTGjdA9ZbAKq5Vd', $publicacao->getHash());
        self::assertSame('CJF', $publicacao->getSiglaTribunal());
        self::assertSame('Intimação', $publicacao->getTipoComunicacao());
        self::assertSame('50636766220224047000', $publicacao->getNumeroProcesso());
        self::assertSame('5063676-62.2022.4.04.7000', $publicacao->getNumeroProcessoComMascara());
        self::assertSame('2026-07-06', $publicacao->getDataDisponibilizacao()?->format('Y-m-d'));
        self::assertSame('D', $publicacao->getMeio());
        self::assertSame('<html>teor</html>', $publicacao->getTexto());
        self::assertSame($item, $publicacao->getPayloadDjen());
    }

    #[Test]
    public function normalizaNumeroProcessoComMascaraParaDigitos(): void
    {
        $publicacao = new PublicacaoDjen();
        $this->sut->mapearItem($publicacao, ['id' => 1, 'numero_processo' => '5063676-62.2022.4.04.7000']);

        self::assertSame('50636766220224047000', $publicacao->getNumeroProcesso());
    }

    #[Test]
    public function camposAusentesViramValoresNeutros(): void
    {
        $publicacao = new PublicacaoDjen();
        $this->sut->mapearItem($publicacao, []);

        self::assertSame('0', $publicacao->getDjenId());
        self::assertSame('', $publicacao->getNumeroProcesso());
        self::assertSame('', $publicacao->getSiglaTribunal());
        self::assertNull($publicacao->getHash());
        self::assertNull($publicacao->getDataDisponibilizacao());
        self::assertNull($publicacao->getNumeroComunicacao());
    }

    #[Test]
    public function djenIdDoItemExtraiOuRetornaNull(): void
    {
        self::assertSame('659192456', DjenPublicacaoMapper::djenIdDoItem(['id' => 659192456]));
        self::assertSame('123', DjenPublicacaoMapper::djenIdDoItem(['id' => ' 123 ']));
        self::assertNull(DjenPublicacaoMapper::djenIdDoItem([]));
        self::assertNull(DjenPublicacaoMapper::djenIdDoItem(['id' => 0]));
        self::assertNull(DjenPublicacaoMapper::djenIdDoItem(['id' => 'abc']));
        self::assertNull(DjenPublicacaoMapper::djenIdDoItem(['id' => '12a']));
        self::assertNull(DjenPublicacaoMapper::djenIdDoItem(['id' => ['nested']]));
    }

    #[Test]
    public function djenIdGravadoCoincideComAChaveDeDedup(): void
    {
        // Regressão: a chave de dedup e o valor gravado devem coincidir (mesma normalização),
        // senão a re-execução trataria o item como novo e violaria o unique (tenant_id, djen_id).
        $publicacao = new PublicacaoDjen();
        $this->sut->mapearItem($publicacao, ['id' => ' 456 ', 'numero_processo' => '1']);

        self::assertSame('456', $publicacao->getDjenId());
        self::assertSame($publicacao->getDjenId(), DjenPublicacaoMapper::djenIdDoItem(['id' => ' 456 ']));
    }

    #[Test]
    public function soDigitosRemoveNaoNumericos(): void
    {
        self::assertSame('50636766220224047000', DjenPublicacaoMapper::soDigitos('5063676-62.2022.4.04.7000'));
        self::assertSame('', DjenPublicacaoMapper::soDigitos('abc'));
    }
}
