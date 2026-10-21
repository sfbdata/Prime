<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDaPasta;
use App\Inteligencia\DTO\DocumentoDoIndiceOutput;
use App\Inteligencia\DTO\DocumentosDoIndiceOutput;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use Psr\Clock\ClockInterface;

/**
 * A lista da aba Documentos do drawer BlueJus IA (spec inteligencia-drawer-da-pasta, F4): os
 * documentos que quem vê pode marcar para o "Índice inteligente". É a MESMA lista contra a qual o
 * {@see SolicitarAnaliseDaPastaUseCase} valida a seleção ({@see MontadorDeContextoDaPasta::documentosMostrados}):
 * os mais recentes, visíveis pelas abas, com tenant explícito. Só metadados.
 *
 * Recebe a Pasta já resolvida e conferida pelo chamador (tenant + `canAccessResource`). Com a aba
 * Documentos oculta para quem vê, devolve nulo: a aba do drawer não aparece.
 */
final class ListarDocumentosDoIndiceUseCase
{
    public function __construct(
        private readonly MontadorDeContextoDaPasta $montador,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @param list<string> $abasOcultas abas da pasta ocultas para quem vê */
    public function executar(Pasta $pasta, Tenant $tenant, array $abasOcultas): ?DocumentosDoIndiceOutput
    {
        if (self::abaDocumentosOculta($abasOcultas)) {
            return null;
        }

        $hoje = $this->relogio->now();

        return new DocumentosDoIndiceOutput(array_map(
            static fn (PastaDocumento $d): DocumentoDoIndiceOutput => DocumentoDoIndiceOutput::fromEntity($d, $hoje),
            $this->montador->documentosMostrados($tenant, $pasta, $abasOcultas),
        ));
    }

    /** @param list<string> $abasOcultas */
    public static function abaDocumentosOculta(array $abasOcultas): bool
    {
        return \in_array(AbaDaPasta::Documentos->value, $abasOcultas, true);
    }
}
