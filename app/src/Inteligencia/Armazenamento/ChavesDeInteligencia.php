<?php

declare(strict_types=1);

namespace App\Inteligencia\Armazenamento;

use App\Inteligencia\Entity\VersaoDeDocumentoJuridico;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;

/**
 * Traduz a versão ajustada de um documento jurídico em {@see ChaveDeArquivo} (bloco "Questões jurídicas" da aba
 * "Configurações da I.A." do Master).
 *
 * Escopo GLOBAL (D1), como a imagem da campanha do Comercial: a Política de Privacidade, os Termos de Uso e o aceite
 * da IA são documentos da plataforma, enviados só pelo Master, e a purga de escritório não os leva. Quem decide quem
 * lê é a rota (`ROLE_SUPER_ADMIN`), antes de montar a chave (INV-5).
 */
final class ChavesDeInteligencia
{
    private function __construct()
    {
    }

    public static function versaoDeDocumentoJuridico(VersaoDeDocumentoJuridico $versao): ChaveDeArquivo
    {
        return new ChaveDeArquivo(EscopoDeArquivo::global(), CategoriaDeArquivo::INTELIGENCIA_DOCUMENTO_JURIDICO, $versao->getNomeArquivo());
    }

    /** Versão nova: o nome é cunhado pelo storage (D8); não há escritório a consultar (D1). */
    public static function novaVersaoDeDocumentoJuridico(string $extensao): NovoArquivo
    {
        return new NovoArquivo(EscopoDeArquivo::global(), CategoriaDeArquivo::INTELIGENCIA_DOCUMENTO_JURIDICO, $extensao);
    }
}
