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
use App\Ponto\Service\SeloDaFolha;
use App\Ponto\Service\SeloDoComprovante;

/**
 * O conteúdo da aba Configurações da tela do Ponto (spec docs/specs/ponto-desenho-0811.md, E7, E15, E16 e P67).
 *
 * Quem: quem abre a /ponto. Só leitura.
 * Para quê: mostrar, a quem administra o ponto do escritório, cada função com as suas duas chaves.
 * Pré-condição: a permissão de gerir o ponto no escritório da sessão (o primário e o Master passam, P58 não entra).
 * Sem ela, null: a aba não aparece.
 * Saída: uma linha por função com a camada Escritório (a tela cheia, os sons e o lembrete de saída da E8, o comprovante
 * em imagem da E15, a geolocalização da E7, os alertas de intervalo da E11 e o aceite mensal da folha da E16, na ordem
 * do desenho), o Global de cada uma e se quem vê é o Master (que ganha a chave "Global" e o outro texto de ajuda).
 * Sem o segredo `PONTO_HMAC_SECRET`, os cartões do comprovante e do aceite não aparecem, como a aba Comprovantes e o
 * "Assinar folha": as chaves não teriam efeito nenhum.
 */
final class ObterConfiguracoesDoPontoUseCase
{
    public function __construct(
        private readonly FuncoesDoPonto $funcoes,
        private readonly ConfiguracaoDoPontoRepository $configuracoes,
        private readonly RegraDosMasters $regraDosMasters,
        private readonly SeloDoComprovante $selo,
        private readonly SeloDaFolha $seloDaFolha,
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
            if (!$this->temEfeito($chave)) {
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

    /** O comprovante e o aceite só funcionam com o selo deles pronto; sem ele, o cartão não aparece. */
    private function temEfeito(string $chave): bool
    {
        return match ($chave) {
            FuncoesDoPonto::COMPROVANTE => $this->selo->disponivel(),
            FuncoesDoPonto::ACEITE      => $this->seloDaFolha->disponivel(),
            default                     => true,
        };
    }
}
