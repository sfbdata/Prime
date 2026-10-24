<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\LeitorDeDocumentosDaPasta;
use App\Inteligencia\DTO\AnexoDeDocumento;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Exception\DocumentoIlegivelException;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\RegraDaLeituraDeDocumentos;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Tests\Inteligencia\Support\DefineId;
use App\Tests\Inteligencia\Support\FonteDeDadosDaPastaFalsa;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O leitor do worker no "Ler N documentos" (PR F): pega cada arquivo pela chave do armazenamento, só da pasta e do
 * escritório da análise, fora da lixeira e da aba oculta, com o aceite novo, e mede o tipo pelo conteúdo. Toda
 * recusa é `DocumentoIlegivelException`, cuja mensagem leva o id e o motivo, nunca o nome nem o conteúdo.
 */
#[CoversClass(LeitorDeDocumentosDaPasta::class)]
final class LeitorDeDocumentosDaPastaTest extends TestCase
{
    private Tenant $tenant;
    private Pasta $pasta;
    private FonteDeDadosDaPastaFalsa $dados;
    private ArmazenamentoEmMemoria $armazenamento;
    private ConfiguracaoDeInteligencia $config;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        DefineId::em($this->tenant, 42);
        $this->pasta = new Pasta();
        $this->pasta->setTenant($this->tenant);
        DefineId::em($this->pasta, 7);
        $this->dados = new FonteDeDadosDaPastaFalsa();
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->config = new ConfiguracaoDeInteligencia($this->tenant);
        $this->config->atualizar(true, 10, 100, true, new User());
    }

    private function leitor(): LeitorDeDocumentosDaPasta
    {
        $repo = $this->createStub(ConfiguracaoDeInteligenciaRepository::class);
        $repo->method('findDoTenant')->willReturn($this->config);

        return new LeitorDeDocumentosDaPasta($this->dados, $this->armazenamento, new RegraDaLeituraDeDocumentos($repo));
    }

    private function documento(int $id, ?string $conteudo, ?Tenant $tenant = null, ?Pasta $pasta = null): PastaDocumento
    {
        $d = new PastaDocumento();
        $d->setTenant($tenant ?? $this->tenant);
        $d->setPasta($pasta ?? $this->pasta);
        $d->setTitulo('Documento ' . $id);
        $d->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $d->setCaminhoArquivo('doc-' . $id . '.bin');
        $d->setNomeOriginal('doc-' . $id . '.pdf');
        $d->setMimeType('application/pdf');
        $d->setTamanhoBytes(100);
        DefineId::em($d, $id);
        if ($conteudo !== null) {
            $this->armazenamento->semear(ChavesDePasta::documento($d), $conteudo);
        }
        $this->dados->documentos[] = $d;

        return $d;
    }

    /**
     * @param list<int>    $ids
     * @param list<string> $abasOcultas
     */
    private function esperarIlegivel(array $ids, string $trecho, array $abasOcultas = []): void
    {
        try {
            $this->leitor()->anexos($this->tenant, $this->pasta, $ids, $abasOcultas);
            self::fail('deveria recusar');
        } catch (DocumentoIlegivelException $e) {
            self::assertStringContainsString($trecho, $e->getMessage());
            self::assertStringNotContainsString('%PDF', $e->getMessage(), 'nunca o conteúdo');
        }
    }

    #[TestDox('Lê na ordem pedida, com o tipo medido pelo conteúdo (não o declarado)')]
    public function testLeNaOrdemComTipoPeloConteudo(): void
    {
        $this->documento(1, '%PDF-1.7 um');
        $this->documento(2, "\x89PNG\r\n\x1A\ndois");

        $anexos = $this->leitor()->anexos($this->tenant, $this->pasta, [2, 1], []);

        self::assertSame([2, 1], array_map(static fn (AnexoDeDocumento $a): int => $a->documentoId, $anexos));
        self::assertSame(['image/png', AnexoDeDocumento::PDF], array_map(static fn (AnexoDeDocumento $a): string => $a->mediaType, $anexos));
        self::assertSame('%PDF-1.7 um', $anexos[1]->bytes);
    }

    #[TestDox('Documento de outro escritório ou de outra pasta (mesmo que a fonte o devolva) → recusa')]
    public function testOutroTenantOuOutraPasta(): void
    {
        $outro = new Tenant();
        DefineId::em($outro, 99);
        $this->documento(5, '%PDF-1.7 alheio', $outro);
        $this->esperarIlegivel([5], 'documento 5 não pode ser lido');

        $outraPasta = new Pasta();
        $outraPasta->setTenant($this->tenant);
        DefineId::em($outraPasta, 8);
        $this->documento(6, '%PDF-1.7 vizinho', null, $outraPasta);
        $this->esperarIlegivel([6], 'fora da pasta');
        $this->esperarIlegivel([404], 'fora da pasta');
    }

    #[TestDox('Lixeira, aba Documentos oculta, aceite antigo e seleção acima de 3 → recusa')]
    public function testRecusasDoPedido(): void
    {
        $this->documento(1, '%PDF-1.7 x')->marcarExcluido(new User(), new \DateTimeImmutable());
        $this->esperarIlegivel([1], 'na lixeira');

        $this->documento(2, '%PDF-1.7 y');
        $this->esperarIlegivel([2], 'aba Documentos oculta', [AbaDaPasta::Documentos->value]);
        $this->esperarIlegivel([2, 2, 2, 2], 'acima do limite');
        $this->esperarIlegivel([], 'seleção vazia');

        (new \ReflectionProperty($this->config, 'consentimentoEnvioExternoEm'))->setValue($this->config, new \DateTimeImmutable('2026-10-01'));
        $this->esperarIlegivel([2], 'aceite');
    }

    #[TestDox('Arquivo ausente, vazio, com conteúdo que não é PDF nem imagem, ou imagem acima do teto → recusa')]
    public function testConteudoRuim(): void
    {
        $this->documento(1, null);
        $this->esperarIlegivel([1], 'arquivo ausente');

        $this->documento(2, '');
        $this->esperarIlegivel([2], 'arquivo vazio');

        $this->documento(3, "PK\x03\x04 docx disfarçado de pdf");
        $this->esperarIlegivel([3], 'não é PDF nem imagem');

        $this->documento(4, "\xFF\xD8\xFF" . str_repeat('x', RegraDaLeituraDeDocumentos::TAMANHO_MAXIMO_DA_IMAGEM));
        $this->esperarIlegivel([4], 'tamanho máximo');
    }
}
