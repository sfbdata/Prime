<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\LinhaAdvogadoDashboardOutput;
use App\Dashboard\DTO\MetricaDaPessoaOutput;
use App\Dashboard\DTO\ObterPerfilDaPessoaInput;
use App\Dashboard\DTO\PerfilDaPessoaOutput;
use App\Dashboard\Exception\ColaboradorForaDoEscritorioException;
use App\Dashboard\Inteligencia\DiagnosticoDaPessoa;
use App\Dashboard\Preferencia\ColunasExtrasDoDashboard as Extras;
use App\Dashboard\Preferencia\MarcasDasPessoas;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Repository\UserRepository;

/**
 * Perfil individual da pessoa no painel BlueJus Intelligence (dc 01, "Análise de desempenho"), por
 * regras fixas. Os números saem do MESMO cálculo da tabela Desempenho (ObterDadosDashboardUseCase,
 * filtrado nesta pessoa, com o cadeado e o "Zerar" já aplicados), então o perfil nunca mostra um
 * número que a tabela não mostra. A pessoa tem de ser colaboradora ativa do escritório da sessão:
 * fora disso, {@see ColaboradorForaDoEscritorioException} (IDOR: o id chega pela URL).
 *
 * A autorização de quem pode abrir a análise (Master ou primário ativo) é do controller.
 * Só lê: a auditoria da visualização é do chamador ({@see RegistrarAuditoriaDoDesempenhoIndividualUseCase}).
 */
final class ObterPerfilDaPessoaUseCase
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ObterDadosDashboardUseCase $obterDados,
        private readonly PreferenciaDoUsuarioRepository $preferencias,
    ) {
    }

    /** @throws ColaboradorForaDoEscritorioException */
    public function executar(ObterPerfilDaPessoaInput $input): PerfilDaPessoaOutput
    {
        $pessoa = $this->userRepository->findColaboradorAtivoPorIdETenant($input->pessoaId, $input->tenant);
        if ($pessoa === null) {
            throw new ColaboradorForaDoEscritorioException();
        }

        $filtros = ['data_de' => $input->filtros['data_de'] ?? '', 'data_ate' => $input->filtros['data_ate'] ?? '', 'responsavel' => (string) $input->pessoaId];
        $painel  = $this->obterDados->executar($input->tenant, $input->agora, $filtros, [Extras::METAS_CONCLUIDAS, Extras::TAXA_CONCLUSAO], $input->pastasOcultas);
        $linha   = $painel->porAdvogado[0] ?? null;
        if ($linha === null || $linha->userId !== $input->pessoaId) {
            throw new ColaboradorForaDoEscritorioException();
        }

        $gravado  = $this->preferencias->valoresDoUsuario($input->tenant, $input->usuario, [MarcasDasPessoas::CHAVE]);
        $marca    = MarcasDasPessoas::lerOuVazio($gravado[MarcasDasPessoas::CHAVE] ?? null)[$input->pessoaId] ?? ['status' => null, 'feedback' => null];
        $metricas = $this->metricas($linha);
        $completo = $painel->periodoAnterior !== null;

        return new PerfilDaPessoaOutput(
            $input->pessoaId,
            $pessoa->getFullName(),
            $linha->cargoNome,
            $linha->fotoUrl,
            $metricas,
            DiagnosticoDaPessoa::comparacoes($linha),
            DiagnosticoDaPessoa::motivos($linha, (int) ($linha->extras[Extras::METAS_CONCLUIDAS] ?? 0), explode(' ', trim($pessoa->getFullName()))[0]),
            DiagnosticoDaPessoa::dados($metricas),
            DiagnosticoDaPessoa::limites($completo),
            $linha->totalMetas === 0,
            $marca['status'],
            $marca['feedback'],
        );
    }

    /**
     * Os seis números do perfil. Cada link leva à lista /tarefas/equipe que conta o MESMO número (regra
     * B12), como a tabela: coluna zerada não é link. Concluídas e Taxa não têm lista equivalente.
     *
     * @return list<MetricaDaPessoaOutput>
     */
    private function metricas(LinhaAdvogadoDashboardOutput $l): array
    {
        $zr      = $l->colunasZeradas;
        $urlDaLista = static fn (string $coluna, string $status): ?string => in_array($coluna, $zr, true) ? null : $status;

        return [
            new MetricaDaPessoaOutput('recebidas', $l->totalMetas, false, $urlDaLista('metas', 'todas'), true),
            new MetricaDaPessoaOutput('concluidas', (int) ($l->extras[Extras::METAS_CONCLUIDAS] ?? 0)),
            new MetricaDaPessoaOutput('pendentes', $l->metasAtivas, false, $urlDaLista('metas_ativas', 'ativas'), true),
            new MetricaDaPessoaOutput('atrasadas', $l->metasVencidas, false, $urlDaLista('metas_vencidas', 'vencidas')),
            new MetricaDaPessoaOutput('prazos', $l->prazosProximos, false, $urlDaLista('prazos', 'prazo_proximo')),
            new MetricaDaPessoaOutput('taxa', $l->extras[Extras::TAXA_CONCLUSAO] ?? null, true),
        ];
    }
}
