<?php

declare(strict_types=1);

namespace App\Comercial\Armazenamento;

use App\Comercial\Entity\ImagemDaCampanha;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;

/**
 * Traduz a imagem da campanha do modo simples em {@see ChaveDeArquivo} (spec `comercial-e-emails.md` §12, F3i).
 *
 * Escopo GLOBAL (D1), como a foto de perfil: a imagem é da plataforma (só o Master a envia e a campanha vai a contas
 * de todos os escritórios), não de um escritório, e a purga de escritório não a leva. Quem decide quem lê é a rota:
 * o envio exige `ROLE_SUPER_ADMIN`; o endereço público confere o token e a validade antes de montar a chave (INV-5).
 */
final class ChavesDeComercial
{
    private function __construct()
    {
    }

    public static function imagem(ImagemDaCampanha $imagem): ChaveDeArquivo
    {
        return self::imagemPorNome($imagem->getNomeArquivo());
    }

    /**
     * Imagem cuja linha já saiu (a purga apaga a linha e só depois o arquivo). O escopo é global de qualquer jeito,
     * então o nome solto não abre porta para outro dono.
     */
    public static function imagemPorNome(string $nome): ChaveDeArquivo
    {
        return new ChaveDeArquivo(EscopoDeArquivo::global(), CategoriaDeArquivo::COMERCIAL_IMAGEM, $nome);
    }

    /** Imagem nova: o nome é cunhado pelo storage (D8); não há escritório a consultar (D1). */
    public static function novaImagem(string $extensao): NovoArquivo
    {
        return new NovoArquivo(EscopoDeArquivo::global(), CategoriaDeArquivo::COMERCIAL_IMAGEM, $extensao);
    }
}
