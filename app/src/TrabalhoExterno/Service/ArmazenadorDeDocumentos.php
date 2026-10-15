<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Http\FonteDeUploadHttp;
use App\TrabalhoExterno\Armazenamento\ChavesDeTrabalhoExterno;
use App\TrabalhoExterno\DTO\Autor;
use App\TrabalhoExterno\Entity\DemandaExterna;
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

    public function __construct(
        private readonly ArmazenamentoDeArquivos $armazenamento,
    ) {
    }

    /**
     * @param list<UploadedFile> $arquivos
     *
     * @return list<DocumentoDemanda>
     */
    public function anexar(DemandaExterna $demanda, array $arquivos, Autor $autor): array
    {
        if (count($arquivos) > self::MAX_ARQUIVOS) {
            throw new DadosInvalidos(sprintf('Envie no máximo %d arquivos por vez.', self::MAX_ARQUIVOS));
        }
        foreach ($arquivos as $arquivo) {
            if (!$arquivo->isValid()) {
                throw new DadosInvalidos(sprintf('O arquivo "%s" não chegou inteiro. Tente de novo.', $arquivo->getClientOriginalName()));
            }
            if (($arquivo->getSize() ?: 0) > self::MAX_BYTES) {
                throw new DadosInvalidos(sprintf('O arquivo "%s" passa de 20 MB.', $arquivo->getClientOriginalName()));
            }
        }

        $documentos = [];
        foreach ($arquivos as $arquivo) {
            // Metadados ANTES de gravar: a gravação move o temporário do PHP (ver AdicionarAnexoUseCase do Kanban).
            $nome = mb_substr($arquivo->getClientOriginalName() ?: 'arquivo', 0, 255);
            $tamanho = $arquivo->getSize() ?: 0;
            $mime = mb_substr($arquivo->getMimeType() ?? 'application/octet-stream', 0, 120);

            $upload = FonteDeUploadHttp::de($arquivo);
            $armazenado = $upload->gravarEm($this->armazenamento, ChavesDeTrabalhoExterno::novoDocumento($demanda, $upload->extensao));

            $documento = new DocumentoDemanda($demanda, $nome, $armazenado->chave->nome, $tamanho, $mime, $autor);
            $demanda->anexar($documento);
            $documentos[] = $documento;
        }

        return $documentos;
    }
}
