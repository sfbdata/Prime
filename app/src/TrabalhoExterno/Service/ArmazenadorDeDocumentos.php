<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Http\FonteDeUploadHttp;
use App\TrabalhoExterno\Armazenamento\ChavesDeTrabalhoExterno;
use App\TrabalhoExterno\DTO\Autor;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\DespachoDemanda;
use App\TrabalhoExterno\Entity\DocumentoDemanda;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Grava os arquivos enviados e os pendura na demanda. Não faz flush: quem chama é o UseCase,
 * dono da transação. Se o flush falhar depois, sobram arquivos órfãos, que é o lado aceitável da
 * INV-6 (registro apontando para arquivo inexistente é que não é).
 */
final class ArmazenadorDeDocumentos
{
    public const MAX_ARQUIVOS = 10;
    public const MAX_BYTES = 20 * 1024 * 1024;
    /** PDF e imagens que o navegador exibe; sem SVG (marcação executável quando servido inline). */
    private const MIMES_DO_COMPROVANTE = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif', 'image/bmp'];

    public function __construct(
        private readonly ArmazenamentoDeArquivos $armazenamento,
    ) {
    }

    /**
     * `$despacho`: os anexos de um despacho da Central (ficam nele, fora de DOCUMENTOS). `$gravados` recebe a
     * chave de cada arquivo assim que ele é gravado, para a transação de quem chama decidir o destino dele.
     *
     * @param list<UploadedFile>                     $arquivos
     * @param \ArrayObject<int, ChaveDeArquivo>|null $gravados
     *
     * @return list<DocumentoDemanda>
     */
    public function anexar(DemandaExterna $demanda, array $arquivos, Autor $autor, ?DespachoDemanda $despacho = null, ?\ArrayObject $gravados = null): array
    {
        if (count($arquivos) > self::MAX_ARQUIVOS) {
            throw new DadosInvalidos(sprintf('Envie no máximo %d arquivos por vez.', self::MAX_ARQUIVOS));
        }
        foreach ($arquivos as $arquivo) {
            $this->conferir($arquivo);
        }

        $documentos = [];
        foreach ($arquivos as $arquivo) {
            // Metadados ANTES de gravar: a gravação move o temporário do PHP (ver AdicionarAnexoUseCase do Kanban).
            $nome = mb_substr($arquivo->getClientOriginalName() ?: 'arquivo', 0, 255);
            $tamanho = $arquivo->getSize() ?: 0;
            $mime = mb_substr($arquivo->getMimeType() ?? 'application/octet-stream', 0, 120);

            $upload = FonteDeUploadHttp::de($arquivo);
            $armazenado = $upload->gravarEm($this->armazenamento, ChavesDeTrabalhoExterno::novoDocumento($demanda, $upload->extensao));
            $gravados?->append($armazenado->chave);

            $documento = new DocumentoDemanda($demanda, $nome, $armazenado->chave->nome, $tamanho, $mime, $autor, $despacho);
            $demanda->anexar($documento);
            $despacho?->anexar($documento);
            $documentos[] = $documento;
        }

        return $documentos;
    }

    /**
     * O comprovante de pagamento PIX (aba Pagamento, desenho 25/10): UM arquivo, só PDF ou imagem (o `accept=".pdf,image/*"`
     * do desenho, conferido pelo CONTEÚDO, não pelo nome; SVG fica de fora porque a rota entrega o arquivo inline).
     * O documento nasce marcado como comprovante, fora de DOCUMENTOS. Não anexa à demanda: quem chama faz isso
     * ({@see DemandaExterna::enviarComprovante()}), na mesma transação.
     *
     * @param \ArrayObject<int, ChaveDeArquivo> $gravados
     */
    public function gravarComprovante(DemandaExterna $demanda, UploadedFile $arquivo, Autor $autor, \ArrayObject $gravados): DocumentoDemanda
    {
        $this->conferir($arquivo);
        $mime = (string) $arquivo->getMimeType();
        if (!in_array($mime, self::MIMES_DO_COMPROVANTE, true)) {
            throw new DadosInvalidos('O comprovante precisa ser um PDF ou uma imagem.');
        }

        $nome = mb_substr($arquivo->getClientOriginalName() ?: 'comprovante', 0, 255);
        $tamanho = $arquivo->getSize() ?: 0;
        $upload = FonteDeUploadHttp::de($arquivo);
        $armazenado = $upload->gravarEm($this->armazenamento, ChavesDeTrabalhoExterno::novoDocumento($demanda, $upload->extensao));
        $gravados->append($armazenado->chave);

        return new DocumentoDemanda($demanda, $nome, $armazenado->chave->nome, $tamanho, mb_substr($mime, 0, 120), $autor, null, true);
    }

    private function conferir(UploadedFile $arquivo): void
    {
        if (!$arquivo->isValid()) {
            throw new DadosInvalidos(sprintf('O arquivo "%s" não chegou inteiro. Tente de novo.', $arquivo->getClientOriginalName()));
        }
        if (($arquivo->getSize() ?: 0) > self::MAX_BYTES) {
            throw new DadosInvalidos(sprintf('O arquivo "%s" passa de 20 MB.', $arquivo->getClientOriginalName()));
        }
    }
}
