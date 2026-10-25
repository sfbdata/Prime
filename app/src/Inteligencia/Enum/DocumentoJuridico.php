<?php

declare(strict_types=1);

namespace App\Inteligencia\Enum;

/**
 * Os documentos que as questões jurídicas mandam atualizar. O valor é a chave usada no catálogo
 * (`config/inteligencia/questoes_juridicas.json`, campo `documentos`), nas rotas e na coluna `documento` das versões.
 *
 * A versão atual sai de `app/resources/legal/` (a pasta `docs/` não vai para a imagem de produção: o Dockerfile só
 * copia `app/`), menos o aceite da IA, que é gerado na hora a partir do mesmo catálogo de textos da tela do aceite.
 */
enum DocumentoJuridico: string
{
    case PoliticaDePrivacidade = 'politica_privacidade';
    case TermosDeUso = 'termos_uso';
    case RascunhoDaPoliticaDeIa = 'rascunho_politica_ia';
    case AceiteDaIa = 'aceite_ia';

    public function rotulo(): string
    {
        return match ($this) {
            self::PoliticaDePrivacidade => 'Política de Privacidade',
            self::TermosDeUso => 'Termos de Uso',
            self::RascunhoDaPoliticaDeIa => 'Rascunho da política da IA (Anthropic)',
            self::AceiteDaIa => 'Texto do aceite da IA',
        };
    }

    /** O arquivo da versão atual em `app/resources/legal/`; nulo para o aceite, que é gerado. */
    public function arquivoAtual(): ?string
    {
        return match ($this) {
            self::PoliticaDePrivacidade => 'politica-de-privacidade.docx',
            self::TermosDeUso => 'termos-de-uso.docx',
            self::RascunhoDaPoliticaDeIa => 'rascunho-politica-ia-anthropic.md',
            self::AceiteDaIa => null,
        };
    }

    /** Nome do download da versão atual. */
    public function nomeParaDownload(): string
    {
        return $this->arquivoAtual() ?? 'texto-do-aceite-da-ia.txt';
    }
}
