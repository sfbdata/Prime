<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\AnaliseEmTextoOutput;
use App\Inteligencia\DTO\AnaliseOutput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Pasta\Entity\Pasta;

/**
 * "Baixar .txt" do resultado (desenho, dc L5742: `'BLUEJUS IA\n' + titulo + '\n\n' + texto +
 * '\n\n' + origem`). Quem: quem pode ver a pasta e usar a IA (guarda do controller). O quê: a
 * análise JÁ GRAVADA em `inteligencia_analise`; nada é gerado aqui e o provedor não é chamado.
 *
 * O texto é o `texto_da_analise` (análise integral do agente); sem ele (Push, ou agente que não
 * devolveu texto), o resumo, os pontos e "quem deve agir", na ordem do cartão.
 *
 * Erros: id inexistente, de outro escritório, de outra pasta, excluída ou ainda sem resultado
 * (pendente, processando, falhou, indisponível) → {@see AnaliseNaoEncontradaException} (404).
 * Só a concluída tem texto do modelo para baixar.
 *
 * Recebe a Pasta já resolvida e conferida pelo chamador (tenant + `canAccessResource`).
 */
final class ExportarAnaliseEmTextoUseCase
{
    public function __construct(
        private readonly AnaliseDeInteligenciaRepository $analises,
    ) {
    }

    public function executar(int $analiseId, Pasta $pasta, Tenant $tenant): AnaliseEmTextoOutput
    {
        $analise = $this->analises->findOneDoTenantEAlvo($analiseId, $tenant, AnaliseDeInteligencia::ALVO_PASTA, (int) $pasta->getId());
        if ($analise === null || $analise->estaExcluida() || !$analise->estaConcluida()) {
            throw new AnaliseNaoEncontradaException($analiseId);
        }

        $saida = AnaliseOutput::fromEntity($analise);
        $ehAgente = $saida->agenteNome !== null;
        $nup = (string) $pasta->getNup();

        $linhas = [
            'BLUEJUS IA · ' . ($saida->comandoRotulo ?? $saida->agenteNome ?? 'Análise IA do Push'),
            'Pasta ' . $nup . ' · gerada em ' . $saida->criadaEm->format('d/m/Y H:i'),
            '',
            $this->corpo($saida),
            '',
            $ehAgente
                ? 'Gerado por IA · não é ato oficial do processo. Confira os documentos originais antes de agir.'
                : 'Gerado por IA · não é movimentação oficial. Confira o documento original antes de agir.',
        ];

        return new AnaliseEmTextoOutput(
            nomeDoArquivo: $this->nomeDoArquivo($saida, $nup),
            conteudo: implode("\n", $linhas) . "\n",
        );
    }

    private function corpo(AnaliseOutput $saida): string
    {
        $texto = trim((string) $saida->textoDaAnalise);
        if ($texto !== '') {
            return $texto;
        }

        $partes = [trim((string) $saida->resumo)];
        if ($saida->pontos !== []) {
            $partes[] = '';
            foreach ($saida->pontos as $ponto) {
                $partes[] = '- ' . $ponto['rotulo'] . ': ' . $ponto['texto'];
            }
        }
        if ($saida->quemAge !== null && trim($saida->quemAge) !== '') {
            $partes[] = '';
            $partes[] = 'Quem deve agir: ' . trim($saida->quemAge);
        }

        return implode("\n", $partes);
    }

    /** Só ASCII seguro no nome: o NUP e o comando (ou o agente) entram, o resto vira hífen. */
    private function nomeDoArquivo(AnaliseOutput $saida, string $nup): string
    {
        $base = sprintf('bluejus-ia-%s-pasta-%s-%d', $saida->comando ?? $saida->agente ?? 'push', $nup, $saida->id);
        $base = (string) preg_replace('/[^A-Za-z0-9]+/', '-', $base);

        return strtolower(trim($base, '-')) . '.txt';
    }
}
