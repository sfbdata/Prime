<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\AuditoriaDaPastaOutput;
use App\Inteligencia\DTO\RegistroDeAuditoriaOutput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Pasta\Entity\Pasta;

/**
 * Rodapé "Registro de auditoria (N)" do drawer (desenho, dc L1030-1035). Quem: quem pode ver a
 * pasta e usar a IA (guarda do controller). O quê: as ações de IA desta pasta, lidas de
 * `inteligencia_analise` (a tabela já é a trilha: quem pediu, o quê, quando, status), as 50 mais
 * recentes, como o protótipo (`iaLog.slice(0, 50)`). Por quê: transparência sobre o que a IA fez
 * com os dados da pasta.
 *
 * A trilha inclui as excluídas (marcadas): excluir tira o cartão da tela, não apaga o registro.
 * Tenant explícito na consulta; a Pasta chega já conferida pelo chamador (tenant + `canAccessResource`).
 */
final class ListarAuditoriaDaPastaUseCase
{
    public const LIMITE = 50;

    public function __construct(
        private readonly AnaliseDeInteligenciaRepository $analises,
    ) {
    }

    public function executar(Pasta $pasta, Tenant $tenant): AuditoriaDaPastaOutput
    {
        $pastaId = (int) $pasta->getId();
        $linhas = $this->analises->listarTrilhaDoAlvo($tenant, AnaliseDeInteligencia::ALVO_PASTA, $pastaId, self::LIMITE);

        return new AuditoriaDaPastaOutput(
            total: $this->analises->contarTrilhaDoAlvo($tenant, AnaliseDeInteligencia::ALVO_PASTA, $pastaId),
            registros: array_values(array_map(RegistroDeAuditoriaOutput::fromEntity(...), $linhas)),
        );
    }
}
