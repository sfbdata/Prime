<?php

declare(strict_types=1);

namespace App\Inteligencia\Juridico;

use App\Inteligencia\Enum\DocumentoJuridico;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A versão ATUAL de cada documento jurídico, para o Master baixar e mandar ao jurídico.
 *
 * Os arquivos moram em `app/resources/legal/`, cópia de `docs/legal/`: o Dockerfile de produção só copia `app/`, então
 * `docs/` não existe no servidor. O aceite da IA não é arquivo: é o texto que a tela do aceite mostra, gerado na hora
 * ({@see TextoDoAceiteDaIa}). São arquivos da aplicação (versionados, de poucos KB), não uploads: não passam pelo
 * armazenamento por chave.
 */
final class DocumentosJuridicosAtuais
{
    private const MIME = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'md' => 'text/markdown; charset=UTF-8',
    ];

    public function __construct(
        private readonly TextoDoAceiteDaIa $aceite,
        #[Autowire('%kernel.project_dir%/resources/legal')]
        private readonly string $diretorio,
    ) {
    }

    public function paraBaixar(DocumentoJuridico $documento): DocumentoJuridicoParaBaixar
    {
        $arquivo = $documento->arquivoAtual();
        if ($arquivo === null) {
            return new DocumentoJuridicoParaBaixar($this->aceite->texto(), $documento->nomeParaDownload(), 'text/plain; charset=UTF-8');
        }
        $conteudo = @file_get_contents($this->diretorio . '/' . $arquivo);
        if ($conteudo === false) {
            throw new \RuntimeException('Documento jurídico ausente em resources/legal: ' . $arquivo);
        }

        return new DocumentoJuridicoParaBaixar($conteudo, $arquivo, self::MIME[pathinfo($arquivo, \PATHINFO_EXTENSION)] ?? 'application/octet-stream');
    }
}
