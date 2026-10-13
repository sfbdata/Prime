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
 *
 * C6-IA-01 (desenho, dc L5742-5743): entram também os downloads e as impressões das análises da
 * pasta, gravados no `audit_log` por {@see RegistrarExportacaoDaAnaliseUseCase} ("Download: …" e
 * "Impressão: …"). Somam no N, e a lista junta as duas fontes por data (mais recente primeiro),
 * cortada no mesmo limite. Só linhas do tenant informado e das análises desta pasta.
 *
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
        $acoes = array_values(RegistrarExportacaoDaAnaliseUseCase::ACOES);

        $analises = array_map(
            RegistroDeAuditoriaOutput::fromEntity(...),
            $this->analises->listarTrilhaDoAlvo($tenant, AnaliseDeInteligencia::ALVO_PASTA, $pastaId, self::LIMITE),
        );
        $exportacoes = array_map(
            static fn (array $linha): RegistroDeAuditoriaOutput => RegistroDeAuditoriaOutput::daExportacao(
                $linha,
                $linha['acao'] === RegistrarExportacaoDaAnaliseUseCase::ACAO_IMPRESSAO
                    ? RegistroDeAuditoriaOutput::ACAO_IMPRESSAO
                    : RegistroDeAuditoriaOutput::ACAO_DOWNLOAD,
            ),
            $this->analises->listarExportacoesDoAlvo($tenant, AnaliseDeInteligencia::ALVO_PASTA, $pastaId, $acoes, self::LIMITE),
        );

        return new AuditoriaDaPastaOutput(
            total: $this->analises->contarTrilhaDoAlvo($tenant, AnaliseDeInteligencia::ALVO_PASTA, $pastaId)
                + $this->analises->contarExportacoesDoAlvo($tenant, AnaliseDeInteligencia::ALVO_PASTA, $pastaId, $acoes),
            registros: $this->intercalar($analises, $exportacoes),
        );
    }

    /**
     * Junta as duas fontes, cada uma já na ordem do repositório (mais recente primeiro, nas LIMITE
     * mais recentes), sem reordenar uma fonte por dentro: a ordem do repositório (data, depois id)
     * fica valendo. A exportação passa à frente só quando é estritamente mais recente.
     *
     * @param list<RegistroDeAuditoriaOutput> $analises
     * @param list<RegistroDeAuditoriaOutput> $exportacoes
     *
     * @return list<RegistroDeAuditoriaOutput>
     */
    private function intercalar(array $analises, array $exportacoes): array
    {
        $registros = [];
        $i = 0;
        $j = 0;
        while (count($registros) < self::LIMITE && ($i < count($analises) || $j < count($exportacoes))) {
            $usarExportacao = $j < count($exportacoes)
                && ($i >= count($analises) || $exportacoes[$j]->quando > $analises[$i]->quando);
            $registros[] = $usarExportacao ? $exportacoes[$j++] : $analises[$i++];
        }

        return $registros;
    }
}
