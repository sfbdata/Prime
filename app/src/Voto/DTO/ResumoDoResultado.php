<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Voto\Enum\CenarioDaAssembleia;

/**
 * O resumo para divulgar da assembleia (imagem e PDF): título, QR do link público, código de conferência e as pautas
 * apuradas. O hash SHA-256 é do conteúdo (título, código e pautas); a data de emissão fica de fora dele,
 * assim como o cenário e a entidade, que só rotulam a folha (rótulo da entidade e linhas de assinatura).
 */
final readonly class ResumoDoResultado
{
    /** @param list<PautaNoResumo> $pautas */
    public function __construct(
        public int $assembleiaId,
        public string $titulo,
        public string $link,
        public string $codigoDeConferencia,
        public \DateTimeImmutable $emitidoEm,
        public string $hash,
        public array $pautas,
        public CenarioDaAssembleia $cenario = CenarioDaAssembleia::Condominio,
        public ?string $entidade = null,
        /** O edital anexo (nome enviado e sha256) e a chave do arquivo, que vai ao fim do PDF; nulos sem edital. Fora do hash. */
        public ?string $editalNome = null,
        public ?string $editalSha256 = null,
        public ?ChaveDeArquivo $editalChave = null,
    ) {
    }
}
