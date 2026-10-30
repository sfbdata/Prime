<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaDestaqueRepository;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\UseCase\ObterColunasDasDemandasUseCase;
use App\Repository\UserRepository;

/**
 * O que a tabela da Lista (expediente/_lista.html.twig) mostra além da pasta, para as Minhas Demandas (desenho 15):
 * réus, selo "Nx", destaques do usuário, quem é administrador (trancar), cadeados da página, colaboradores do
 * filtro e as colunas salvas NA CHAVE DAS DEMANDAS. Mesmo conteúdo do `extrasDaLista` do ExpedienteController,
 * que fica como está (o Expediente não muda nesta entrega). Uma consulta por item, para a página inteira.
 */
final class ComplementosDaListaDeDemandas
{
    public function __construct(
        private readonly PastaRepository $pastaRepository,
        private readonly UserRepository $userRepository,
        private readonly PastaDestaqueRepository $destaqueRepository,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly ObterColunasDasDemandasUseCase $obterColunas,
    ) {
    }

    /**
     * @param Pasta[] $pastas
     * @return array<string, mixed>
     */
    public function para(array $pastas, User $user, Tenant $tenant): array
    {
        $ids = array_map(static fn (Pasta $p): int => (int) $p->getId(), $pastas);

        return [
            'reusPorPasta'      => $this->pastaRepository->reusPorPasta($ids, $tenant),
            'pastasPorCliente'  => $this->pastaRepository->totalDePastasPorClientePrincipal(self::clientesPrincipais($pastas), $tenant),
            'destaques'         => $this->destaqueRepository->coresDoUsuario($user, $tenant),
            'souAdministrador'  => $this->acessoAPasta->podeConfigurar($user, $tenant),
            'cadeados'          => $this->cadeadosDaPagina($pastas, $user),
            'colunasSalvas'     => $this->obterColunas->executar($tenant, $user),
            'responsaveis'      => $this->userRepository->findColaboradoresAtivosPorTenant($tenant),
            'fotosResponsaveis' => $this->userRepository->findFotoPorColaboradores($tenant),
        ];
    }

    /**
     * @param Pasta[] $pastas
     * @return list<int>
     */
    private static function clientesPrincipais(array $pastas): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (Pasta $p): ?int => $p->getClientePrincipal()?->getId(),
            $pastas,
        ))));
    }

    /**
     * O cadeado de cada pasta da página (spec pasta-cadeado D1 e §6): `[pastaId => ['nivel', 'por', 'em']]`.
     *
     * @param Pasta[] $pastas
     * @return array<int, array{nivel: string, por: ?string, em: string}>
     */
    private function cadeadosDaPagina(array $pastas, User $user): array
    {
        $niveis   = $this->acessoAPasta->niveisEmLote($user, $pastas);
        $cadeados = [];
        foreach ($pastas as $pasta) {
            $cadeado = $this->acessoAPasta->cadeadoDe($pasta);
            if ($cadeado !== null) {
                $cadeados[(int) $pasta->getId()] = [
                    'nivel' => ($niveis[(int) $pasta->getId()] ?? NivelDeAcesso::Oculto)->value,
                    'por'   => $cadeado->getCriadoPor()?->getFullName(),
                    'em'    => $cadeado->getCriadoEm()->format('d/m/Y'),
                ];
            }
        }

        return $cadeados;
    }
}
