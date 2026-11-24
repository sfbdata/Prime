<?php

declare(strict_types=1);

namespace App\Produto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Produto\DTO\EscritorioNasFerramentasOutput;
use App\Produto\Enum\Produto;
use App\Produto\Service\ContratacoesDoEscritorio;
use App\Produto\Service\GuardaDoMasterDasFerramentas;
use App\Produto\Service\ProdutosDoEscritorio;
use App\Repository\TenantRepository;
use App\Voto\Repository\LiberacaoDoVotoRepository;

/**
 * A grade da aba "Ferramentas" do Master (spec docs/specs/multiproduto-contratacao-e-voto.md, §10): os escritórios
 * ativos (nem suspensos nem excluídos), cada um com as ferramentas ligadas e as travadas pelo BlueJus. Só leitura;
 * visão de plataforma, por isso exige o Master de novo.
 *
 * "Ligada" segue a mesma régua do cartão do Meu Perfil (§9): a contratação inclui o produto e, para o Voto e a I.A., a
 * liberação do Master também está dada. "Travada" é a D16 (a última linha do produto encerrada com origem `master`).
 */
final class ObterFerramentasDosEscritoriosUseCase
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly ProdutosDoEscritorio $produtos,
        private readonly ContratacoesDoEscritorio $contratacoes,
        private readonly LiberacaoDoVotoRepository $liberacoesDoVoto,
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoesDaIa,
    ) {
    }

    /** @return list<EscritorioNasFerramentasOutput> */
    public function executar(User $master): array
    {
        GuardaDoMasterDasFerramentas::exigir($master);
        $votoLiberado = self::porEscritorio($this->liberacoesDoVoto->listarEscritoriosParaOMaster(), 'liberado');
        $iaLiberada = self::porEscritorio($this->configuracoesDaIa->listarEscritoriosParaOMaster(), 'liberada');

        $saida = [];
        foreach ($this->tenants->findBy(['isActive' => true, 'excluidoEm' => null], ['name' => 'ASC', 'id' => 'ASC']) as $tenant) {
            $id = (int) $tenant->getId();
            $saida[] = $this->linha($tenant, [Produto::Voto->value => $votoLiberado[$id] ?? false, Produto::Ia->value => $iaLiberada[$id] ?? false]);
        }

        return $saida;
    }

    /** @param array<string, bool> $liberacoes a liberação do Master, por produto (só Voto e I.A.) */
    private function linha(Tenant $tenant, array $liberacoes): EscritorioNasFerramentasOutput
    {
        $ligadas = [];
        $travadas = [];
        foreach (Produto::ferramentas() as $produto) {
            if ($this->produtos->inclui($tenant, $produto) && ($liberacoes[$produto->value] ?? true)) {
                $ligadas[] = $produto;
            } elseif ($this->contratacoes->travadoPeloMaster($tenant, $produto)) {
                $travadas[] = $produto;
            }
        }

        return new EscritorioNasFerramentasOutput((int) $tenant->getId(), (string) $tenant->getName(), $this->produtos->ehCompleto($tenant), $ligadas, $travadas);
    }

    /**
     * @param list<array<string, mixed>> $linhas
     *
     * @return array<int, bool>
     */
    private static function porEscritorio(array $linhas, string $campo): array
    {
        $mapa = [];
        foreach ($linhas as $linha) {
            $mapa[(int) $linha['tenant_id']] = (bool) $linha[$campo];
        }

        return $mapa;
    }
}
