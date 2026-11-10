<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Service\RegraDosMasters;
use App\Ponto\DTO\ConfiguracoesDoPontoOutput;
use App\Ponto\DTO\FuncaoDoPontoOutput;
use App\Ponto\Repository\ConfiguracaoDoPontoRepository;
use App\Ponto\Service\FuncoesDoPonto;
use App\Ponto\Service\SeloDoComprovante;

/**
 * O conteúdo da aba Configurações da tela do Ponto (spec docs/specs/ponto-desenho-0811.md, E7, E15 e P67).
 *
 * Quem: quem abre a /ponto. Só leitura.
 * Para quê: mostrar, a quem administra o ponto do escritório, cada função com as suas duas chaves.
 * Pré-condição: a permissão de gerir o ponto no escritório da sessão (o primário e o Master passam, P58 não entra).
 * Sem ela, null: a aba não aparece.
 * Saída: uma linha por função com a camada Escritório (a tela cheia, os sons e o lembrete de saída da E8, o comprovante
 * em imagem da E15, a geolocalização da E7 e os alertas de intervalo da E11, na ordem do desenho), o Global de cada uma e
 * se quem vê é o Master (que ganha a chave "Global" e o outro texto de ajuda). Sem o selo do comprovante (o segredo
 * `PONTO_HMAC_SECRET` ausente), o cartão do comprovante não aparece, como a aba Comprovantes: a chave não teria efeito
 * nenhum.
 */
final class ObterConfiguracoesDoPontoUseCase
{
    public function __construct(
        private readonly FuncoesDoPonto $funcoes,
        private readonly ConfiguracaoDoPontoRepository $configuracoes,
        private readonly RegraDosMasters $regraDosMasters,
        private readonly SeloDoComprovante $selo,
    ) {
    }

    public function executar(User $quem, Tenant $tenant): ?ConfiguracoesDoPontoOutput
    {
        if (!$this->funcoes->podeConfigurar($quem, $tenant)) {
            return null;
        }

        return new ConfiguracoesDoPontoOutput($this->regraDosMasters->ehMaster($quem), $this->funcoesDoEscritorio($tenant));
    }

    /** @return list<FuncaoDoPontoOutput> */
    private function funcoesDoEscritorio(Tenant $tenant): array
    {
        $configuracao = $this->configuracoes->doTenant($tenant);
        $funcoes = [];
        foreach (FuncoesDoPonto::COM_CAMADA_ESCRITORIO as $chave => $desenho) {
            if ($chave === FuncoesDoPonto::COMPROVANTE && !$this->selo->disponivel()) {
                continue;
            }
            $funcoes[] = new FuncaoDoPontoOutput(
                $chave,
                $desenho['titulo'],
                $desenho['icone'],
                $desenho['ajuda'],
                $this->funcoes->liberadaPeloMaster($chave),
                $configuracao?->estaLigada($chave) ?? true,
            );
        }

        return $funcoes;
    }
}
