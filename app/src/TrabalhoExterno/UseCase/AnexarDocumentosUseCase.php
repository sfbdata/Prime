<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\DocumentoDemanda;
use App\TrabalhoExterno\Enum\TipoMensagem;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\ArmazenadorDeDocumentos;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * "Anexar" na seção DOCUMENTOS do Resumo. Quem vê a demanda anexa; o chat recebe
 * "N documento(s) anexado(s): a.pdf, b.pdf" como registro de arquivo.
 */
final class AnexarDocumentosUseCase
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly PoliticaDaDemanda $politica,
        private readonly ArmazenadorDeDocumentos $documentos,
        private readonly DemandaExternaRepository $repositorio,
        private readonly AvisosDoTrabalhoExterno $avisos,
    ) {
    }

    /** @param list<UploadedFile> $arquivos */
    public function executar(Contexto $ctx, string $codigo, array $arquivos): DemandaExterna
    {
        if ($arquivos === []) {
            throw new DadosInvalidos('Nenhum arquivo enviado.');
        }

        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);
        if (!$this->politica->podeAnexar($ctx, $demanda)) {
            throw new AcaoNaoPermitida('Você não pode anexar documentos nesta demanda.');
        }

        $novos = $this->documentos->anexar($demanda, $arquivos, $ctx->autor());
        $nomes = implode(', ', array_map(static fn (DocumentoDemanda $d): string => $d->getNomeOriginal(), $novos));
        $demanda->registrar($ctx->autor(), sprintf('%d documento(s) anexado(s): %s', count($novos), $nomes), TipoMensagem::Arquivo);
        $this->avisos->andamentoDaDemanda($ctx, $demanda);
        $this->repositorio->salvar($demanda, flush: true);

        return $demanda;
    }
}
