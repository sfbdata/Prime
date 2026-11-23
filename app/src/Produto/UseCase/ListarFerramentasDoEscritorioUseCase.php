<?php

declare(strict_types=1);

namespace App\Produto\UseCase;

use App\Backup\Service\DonoDoEscritorio;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Master\Service\RecursosDoSistema;
use App\Produto\DTO\FerramentaDoCartaoOutput;
use App\Produto\Enum\Produto;
use App\Produto\Service\CatalogoDeProdutos;
use App\Produto\Service\ContratacoesDoEscritorio;
use App\Produto\Service\ProdutosDoEscritorio;
use App\Voto\Repository\LiberacaoDoVotoRepository;

/**
 * O cartão "Ferramentas do escritório" no Meu Perfil (spec docs/specs/multiproduto-contratacao-e-voto.md, §9): só o
 * dono vê (null para os demais). Cada ferramenta aparece:
 *
 *  - indisponível, sem botão, com a chave global desligada (`voto` do Master; a plataforma da I.A.);
 *  - travada pelo Master (D16), sem botão;
 *  - ligada quando o produto está incluído; o Voto e a I.A. pedem também a liberação de hoje (`LiberacaoDoVoto`,
 *    `liberadaPeloMaster`), então um escritório antigo vê ligado tudo o que já usava;
 *  - desligada nos demais casos.
 */
final class ListarFerramentasDoEscritorioUseCase
{
    public function __construct(
        private readonly DonoDoEscritorio $dono,
        private readonly ProdutosDoEscritorio $produtos,
        private readonly ContratacoesDoEscritorio $contratacoes,
        private readonly LiberacaoDoVotoRepository $liberacoes,
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
        private readonly RecursosDoSistema $recursos,
        private readonly DisponibilidadeDeInteligencia $disponibilidade,
    ) {
    }

    /** @return list<FerramentaDoCartaoOutput>|null null: quem pede não é o dono */
    public function executar(User $usuario, Tenant $tenant): ?array
    {
        if (!$this->dono->ehDono($usuario, $tenant)) {
            return null;
        }

        $travados = $this->contratacoes->travadosPeloMaster($tenant);
        $lista = [];
        foreach (Produto::ferramentas() as $produto) {
            $estado = $this->estado($tenant, $produto, $travados);
            $lista[] = new FerramentaDoCartaoOutput(
                $produto->value,
                CatalogoDeProdutos::icone($produto),
                $estado,
                Produto::Ia === $produto && FerramentaDoCartaoOutput::LIGADA === $estado && !$this->iaComAceite($tenant),
            );
        }

        return $lista;
    }

    /** @param list<Produto> $travados */
    private function estado(Tenant $tenant, Produto $produto, array $travados): string
    {
        if (!$this->disponivelNaPlataforma($produto)) {
            return FerramentaDoCartaoOutput::INDISPONIVEL;
        }
        if (in_array($produto, $travados, true)) {
            return FerramentaDoCartaoOutput::TRAVADA;
        }

        return $this->produtos->inclui($tenant, $produto) && $this->liberadoHoje($tenant, $produto)
            ? FerramentaDoCartaoOutput::LIGADA
            : FerramentaDoCartaoOutput::DESLIGADA;
    }

    private function disponivelNaPlataforma(Produto $produto): bool
    {
        return match ($produto) {
            Produto::Voto => $this->recursos->estaAtivo('voto'),
            Produto::Ia => $this->disponibilidade->plataformaConfigurada(),
            default => true,
        };
    }

    private function liberadoHoje(Tenant $tenant, Produto $produto): bool
    {
        return match ($produto) {
            Produto::Voto => true === $this->liberacoes->findDoTenant($tenant)?->isLiberado(),
            Produto::Ia => true === $this->configuracoes->findDoTenant($tenant)?->isLiberadaPeloMaster(),
            default => true,
        };
    }

    private function iaComAceite(Tenant $tenant): bool
    {
        $configuracao = $this->configuracoes->findDoTenant($tenant);

        return null !== $configuracao && $configuracao->isHabilitada() && null !== $configuracao->getConsentimentoEnvioExternoEm();
    }
}
