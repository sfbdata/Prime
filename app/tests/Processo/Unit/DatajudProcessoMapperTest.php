<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Processo\Entity\Processo;
use App\Processo\Repository\ProcessoRepository;
use App\Processo\Service\DatajudProcessoMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatajudProcessoMapper::class)]
final class DatajudProcessoMapperTest extends TestCase
{
    private function mapper(): DatajudProcessoMapper
    {
        // ProcessoRepository é abstração própria do domínio; só o usamos para o lookup do
        // processo-pai, irrelevante para o mapeamento dos escalares — devolve null.
        $repo = $this->createMock(ProcessoRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        return new DatajudProcessoMapper($repo);
    }

    /**
     * _source real de um hit do Datajud (api_publica_tjal), recortado nos campos escalares.
     *
     * @return array<string,mixed>
     */
    private function sourceReal(): array
    {
        return [
            'numeroProcesso' => '07108025520188020001',
            'classe' => ['codigo' => 1689, 'nome' => 'Embargos de Declaração Cível'],
            'sistema' => ['codigo' => 3, 'nome' => 'SAJ'],
            'formato' => ['codigo' => 1, 'nome' => 'Eletrônico'],
            'tribunal' => 'TJAL',
            'grau' => 'G2',
            'nivelSigilo' => 0,
            'id' => 'TJAL_1689_G2_80064_07108025520188020001',
            'orgaoJulgador' => [
                'codigoMunicipioIBGE' => 2704302,
                'codigo' => 80064,
                'nome' => 'Vice-Presidência do Tribunal de Justiça',
            ],
            'assuntos' => [
                ['codigo' => 10433, 'nome' => 'Indenização por Dano Moral'],
                ['codigo' => 7681, 'nome' => 'Obrigações'],
                ['codigo' => 10439, 'nome' => 'Indenização por Dano Material'],
            ],
        ];
    }

    #[TestDox('Mapeia os metadados escalares do Datajud (sigilo, formato, sistema, códigos, id)')]
    public function testMapeiaMetadadosEscalares(): void
    {
        $processo = $this->mapper()->mapFromSource(new Processo(), $this->sourceReal());

        self::assertSame(0, $processo->getNivelSigilo());
        self::assertSame('Eletrônico', $processo->getFormato());
        self::assertSame(1, $processo->getFormatoCodigo());
        self::assertSame('SAJ', $processo->getSistema());
        self::assertSame(3, $processo->getSistemaCodigo());
        self::assertSame(1689, $processo->getClasseCodigo());
        self::assertSame('80064', $processo->getOrgaoJulgadorCodigo());
        self::assertSame(2704302, $processo->getOrgaoJulgadorMunicipioIbge());
        self::assertSame('TJAL_1689_G2_80064_07108025520188020001', $processo->getDatajudId());
    }

    #[TestDox('Nível de sigilo 0 é preservado (público), não vira null')]
    public function testNivelSigiloZeroPreservado(): void
    {
        $processo = $this->mapper()->mapFromSource(new Processo(), $this->sourceReal());

        self::assertSame(0, $processo->getNivelSigilo());
        self::assertSame('Público', $processo->getNivelSigiloLabel());
    }

    #[TestDox('Metadados ausentes viram null, sem quebrar o mapeamento')]
    public function testMetadadosAusentesViramNull(): void
    {
        $processo = $this->mapper()->mapFromSource(new Processo(), ['numeroProcesso' => '0001']);

        self::assertNull($processo->getNivelSigilo());
        self::assertNull($processo->getNivelSigiloLabel());
        self::assertNull($processo->getFormato());
        self::assertNull($processo->getFormatoCodigo());
        self::assertNull($processo->getSistema());
        self::assertNull($processo->getDatajudId());
        self::assertNull($processo->getOrgaoJulgadorCodigo());
        self::assertNull($processo->getOrgaoJulgadorMunicipioIbge());
    }

    #[TestDox('Mapeia TODOS os assuntos como coleção (código+nome), mantendo o principal como string')]
    public function testMapeiaTodosOsAssuntos(): void
    {
        $processo = $this->mapper()->mapFromSource(new Processo(), $this->sourceReal());

        // Assunto principal (string) permanece = primeiro assunto, para compatibilidade das telas.
        self::assertSame('INDENIZAÇÃO POR DANO MORAL', $processo->getAssuntoProcessual());

        // Coleção completa com os 3 assuntos.
        $assuntos = $processo->getAssuntos();
        self::assertCount(3, $assuntos);

        $pares = array_map(
            static fn($a) => [$a->getCodigo(), $a->getNome()],
            $assuntos->toArray()
        );
        self::assertContains([10433, 'INDENIZAÇÃO POR DANO MORAL'], $pares);
        self::assertContains([7681, 'OBRIGAÇÕES'], $pares);
        self::assertContains([10439, 'INDENIZAÇÃO POR DANO MATERIAL'], $pares);
    }

    #[TestDox('Sem assuntos no _source, a coleção fica vazia sem quebrar')]
    public function testSemAssuntosColecaoVazia(): void
    {
        $processo = $this->mapper()->mapFromSource(new Processo(), ['numeroProcesso' => '0003']);

        self::assertCount(0, $processo->getAssuntos());
    }

    #[TestDox('Re-mapear substitui os assuntos (não duplica ao sincronizar de novo)')]
    public function testReMapearSubstituiAssuntos(): void
    {
        $mapper = $this->mapper();

        $processo = $mapper->mapFromSource(new Processo(), $this->sourceReal());
        self::assertCount(3, $processo->getAssuntos());

        // Segunda sincronização com apenas 1 assunto: a coleção é substituída, não somada.
        $mapper->mapFromSource($processo, [
            'numeroProcesso' => '07108025520188020001',
            'assuntos' => [['codigo' => 999, 'nome' => 'Novo Assunto']],
        ]);

        self::assertCount(1, $processo->getAssuntos());
        self::assertSame(999, $processo->getAssuntos()->first()->getCodigo());
    }

    #[TestDox('Movimentação captura complementosTabelados e código do órgão; resumo concatena os nomes')]
    public function testMovimentacaoCapturaComplementosECodigoOrgao(): void
    {
        $processo = $this->mapper()->mapFromSource(new Processo(), [
            'numeroProcesso' => '0004',
            'movimentos' => [
                [
                    'codigo' => 26,
                    'nome' => 'Distribuição',
                    'dataHora' => '2025-11-25T12:10:21.000Z',
                    'complementosTabelados' => [
                        ['codigo' => 2, 'descricao' => 'tipo_de_distribuicao_redistribuicao', 'valor' => 2, 'nome' => 'sorteio'],
                    ],
                    'orgaoJulgador' => ['codigo' => '70867', 'nome' => 'Gab. 05 - 20a Camara'],
                ],
            ],
        ]);

        self::assertCount(1, $processo->getMovimentacoes());
        $mov = $processo->getMovimentacoes()->first();

        self::assertSame('DISTRIBUIÇÃO', $mov->getDescricao());
        self::assertSame('70867', $mov->getOrgaoCodigo());
        self::assertIsArray($mov->getComplementos());
        self::assertCount(1, $mov->getComplementos());
        self::assertSame('sorteio', $mov->getComplementos()[0]['nome']);
        // Resumo legível: "Distribuição — sorteio" no display.
        self::assertSame('sorteio', $mov->getComplementosResumo());
    }

    #[TestDox('Movimentação sem complementos: complementos null e resumo null (sem quebrar)')]
    public function testMovimentacaoSemComplementos(): void
    {
        $processo = $this->mapper()->mapFromSource(new Processo(), [
            'numeroProcesso' => '0005',
            'movimentos' => [
                ['codigo' => 60, 'nome' => 'Expedição de documento', 'dataHora' => '2022-04-12T10:11:37.000Z'],
            ],
        ]);

        $mov = $processo->getMovimentacoes()->first();
        self::assertNull($mov->getComplementos());
        self::assertNull($mov->getComplementosResumo());
        self::assertNull($mov->getOrgaoCodigo());
    }

    #[TestDox('datajudRaw armazena o _source inteiro (rede de segurança / auditoria)')]
    public function testDatajudRawArmazenaSourceInteiro(): void
    {
        $source = $this->sourceReal();
        $processo = $this->mapper()->mapFromSource(new Processo(), $source);

        self::assertSame($source, $processo->getDatajudRaw(), 'o _source completo deve ser guardado como snapshot');
    }

    #[TestDox('dataAjuizamento no formato compacto AAAAMMDDHHMMSS é parseado (antes virava null)')]
    public function testDataAjuizamentoFormatoCompacto(): void
    {
        $processo = $this->mapper()->mapFromSource(new Processo(), [
            'numeroProcesso' => '0006',
            'dataAjuizamento' => '20211021222406', // AAAAMMDDHHMMSS (ex.: TJAL/SAJ)
        ]);

        self::assertNotNull($processo->getDataDistribuicao(), 'formato compacto não pode mais virar null');
        self::assertSame('2021-10-21', $processo->getDataDistribuicao()->format('Y-m-d'));
    }

    #[TestDox('dataAjuizamento em ISO continua funcionando (ex.: PJe)')]
    public function testDataAjuizamentoIsoContinuaFuncionando(): void
    {
        $processo = $this->mapper()->mapFromSource(new Processo(), [
            'numeroProcesso' => '0007',
            'dataAjuizamento' => '2026-05-28T10:00:00.000Z',
        ]);

        self::assertSame('2026-05-28', $processo->getDataDistribuicao()->format('Y-m-d'));
    }

    #[TestDox('Data compacta inválida (overflow, ex.: mês 99) vira null — não uma data plausível-porém-errada')]
    public function testDataCompactaInvalidaViraNull(): void
    {
        $processo = $this->mapper()->mapFromSource(new Processo(), [
            'numeroProcesso' => '0010',
            'dataAjuizamento' => '20219921222406', // mês 99: createFromFormat faria overflow para 2029-03
        ]);

        self::assertNull($processo->getDataDistribuicao(), 'data inválida não pode ser silenciosamente corrigida para uma data errada');
    }

    #[TestDox('Data em formato compacto AAAAMMDD (8 dígitos, sem hora) também parseia')]
    public function testDataFormatoCompactoOitoDigitos(): void
    {
        $processo = $this->mapper()->mapFromSource(new Processo(), [
            'numeroProcesso' => '0008',
            'dataAjuizamento' => '20240115',
        ]);

        self::assertSame('2024-01-15', $processo->getDataDistribuicao()->format('Y-m-d'));
    }

    #[TestDox('orgaoJulgador em formato string (fallback legado) não quebra a leitura dos códigos')]
    public function testOrgaoJulgadorStringNaoQuebra(): void
    {
        $processo = $this->mapper()->mapFromSource(new Processo(), [
            'numeroProcesso' => '0002',
            'orgaoJulgador' => '2ª Vara Cível',
        ]);

        self::assertNull($processo->getOrgaoJulgadorCodigo());
        self::assertNull($processo->getOrgaoJulgadorMunicipioIbge());
    }
}
