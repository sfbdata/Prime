<?php

declare(strict_types=1);

namespace App\Recompensa\Armazenamento;

use App\Recompensa\Entity\LancamentoDeRecompensa;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;

/**
 * Traduz o comprovante de um pagamento de recompensa em {@see ChaveDeArquivo} (baixa manual do Master, F4).
 *
 * Escopo GLOBAL (D1), como a imagem da campanha do Comercial: é dado da plataforma, enviado e lido só pelo Master, e a
 * purga de escritório não o leva. Quem decide quem lê é a rota (`ROLE_SUPER_ADMIN`), antes de montar a chave (INV-5).
 */
final class ChavesDeRecompensa
{
    private function __construct()
    {
    }

    /** Só para lançamento que tem comprovante. */
    public static function comprovante(LancamentoDeRecompensa $lancamento): ChaveDeArquivo
    {
        return new ChaveDeArquivo(EscopoDeArquivo::global(), CategoriaDeArquivo::RECOMPENSA_COMPROVANTE, (string) $lancamento->getComprovanteChave());
    }

    /** Comprovante novo: o nome é cunhado pelo storage (D8); não há escritório a consultar (D1). */
    public static function novoComprovante(string $extensao): NovoArquivo
    {
        return new NovoArquivo(EscopoDeArquivo::global(), CategoriaDeArquivo::RECOMPENSA_COMPROVANTE, $extensao);
    }
}
