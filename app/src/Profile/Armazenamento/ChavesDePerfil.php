<?php

declare(strict_types=1);

namespace App\Profile\Armazenamento;

use App\Profile\Entity\UserProfile;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\NovoArquivo;

/**
 * Traduz a foto de perfil em {@see ChaveDeArquivo} (E2.2).
 *
 * A foto é do `User`, não do escritório: escopo **global** (D1). É a única categoria em que a
 * fábrica não procura tenant — e é por isso que a purga de escritório poupa `FOTO_PERFIL` de
 * propósito. A autorização (qual escritório pode ver a foto de quem) continua na rota, acima do
 * storage (INV-5).
 */
final class ChavesDePerfil
{
    private function __construct()
    {
    }

    public static function foto(UserProfile $perfil): ChaveDeArquivo
    {
        $nome = $perfil->getFotoUrl();

        if ($nome === null) {
            throw new ChaveDeArquivoInvalida('Este perfil não possui foto; não há chave a montar.');
        }

        return new ChaveDeArquivo(
            EscopoDeArquivo::global(),
            CategoriaDeArquivo::FOTO_PERFIL,
            $nome,
        );
    }

    /**
     * Foto nova (E2.4A): o nome é cunhado pelo storage (D8). Não há entidade a consultar — o
     * escopo da foto é global por definição (D1).
     */
    public static function novaFoto(string $extensao): NovoArquivo
    {
        return new NovoArquivo(EscopoDeArquivo::global(), CategoriaDeArquivo::FOTO_PERFIL, $extensao);
    }
}
