<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\DTO\ArquivoParaEntregar;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\ConfiguracaoDaAssinaturaRepository;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\Entity\Tenant\Tenant;

/**
 * Os arquivos que a tela abre: o PDF enviado, a logo própria do documento e a rubrica de quem assinou (mesmo na
 * Lixeira) e a logo padrão do escritório. A chave sai sempre do registro do escritório logado, nunca de parâmetro da
 * requisição.
 */
final readonly class ObterArquivoDaAssinaturaUseCase
{
    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
        private ConfiguracaoDaAssinaturaRepository $configuracoes,
    ) {
    }

    /** @throws DocumentoNaoEncontrado */
    public function pdf(Tenant $tenant, int $id): ArquivoParaEntregar
    {
        $documento = $this->documentos->doEscritorioMesmoNaLixeira($id, $tenant) ?? throw new DocumentoNaoEncontrado();
        $chave = ChavesDeAssinaturaEletronica::arquivo($documento) ?? throw new DocumentoNaoEncontrado();

        return new ArquivoParaEntregar($chave, (string) $documento->getArquivoEnviadoNome());
    }

    /** @throws DocumentoNaoEncontrado */
    public function logo(Tenant $tenant, int $id): ArquivoParaEntregar
    {
        $documento = $this->documentos->doEscritorioMesmoNaLixeira($id, $tenant) ?? throw new DocumentoNaoEncontrado();
        $chave = ChavesDeAssinaturaEletronica::logo($documento) ?? throw new DocumentoNaoEncontrado();

        return new ArquivoParaEntregar($chave, 'logo');
    }

    /**
     * A rubrica de quem já assinou (etapa 2), vista de dentro do escritório.
     *
     * @throws DocumentoNaoEncontrado
     */
    public function rubrica(Tenant $tenant, int $id, int $ordem): ArquivoParaEntregar
    {
        $signatario = ObterPreviaDoSignatarioUseCase::signatario($this->documentos, $tenant, $id, $ordem);
        $chave = ChavesDeAssinaturaEletronica::rubrica($signatario) ?? throw new DocumentoNaoEncontrado();

        return new ArquivoParaEntregar($chave, 'rubrica.png');
    }

    /** @throws DocumentoNaoEncontrado */
    public function logoPadrao(Tenant $tenant): ArquivoParaEntregar
    {
        $configuracao = $this->configuracoes->doEscritorio($tenant);
        $chave = ($configuracao !== null ? ChavesDeAssinaturaEletronica::logoPadrao($configuracao) : null)
            ?? throw new DocumentoNaoEncontrado();

        return new ArquivoParaEntregar($chave, 'logo');
    }
}
