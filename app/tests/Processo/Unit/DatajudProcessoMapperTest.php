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
