<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CompartilhamentosDaPastaOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaCompartilhamento;
use App\Pasta\Exception\CompartilhamentoRecusadoException;
use App\Pasta\Repository\PastaCompartilhamentoRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\EscopoDePastas;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Os dados da janela "Compartilhar pasta" e da seção COMPARTILHAR do menu (spec acesso-restrito-e-compartilhar §4.4,
 * desenho L3, L4 e L6): a equipe que pode receber (vínculos ativos, sem o responsável e sem quem pede), quem já colabora
 * e os três destinos recentes do "1 clique". Quem não vê a pasta não chega aqui (a barreira da rota responde antes).
 */
final class ConsultarCompartilhamentosDaPastaUseCase
{
    private const RECENTES = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaCompartilhamentoRepository $compartilhamentos,
        private readonly UserTenantRepository $vinculos,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly EscopoDePastas $escopo,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(int $pastaId, User $quem, Tenant $tenant): CompartilhamentosDaPastaOutput
    {
        $pasta = $this->em->getRepository(Pasta::class)->findOneBy(['id' => $pastaId, 'tenant' => $tenant]);
        if ($pasta === null || !$this->acessoAPasta->podeVer($quem, $pasta)) {
            throw new CompartilhamentoRecusadoException(CompartilhamentoRecusadoException::SEM_PERMISSAO, daPessoa: true);
        }
        $podeCompartilhar = !$this->escopo->ehRestritoNoEscritorio($quem, $tenant) && $this->acessoAPasta->podeEditar($quem, $pasta);
        $equipe = $podeCompartilhar ? $this->equipe($pasta, $quem, $tenant) : [];
        $colaboradores = $this->colaboradores($pasta, $quem, $tenant, $podeCompartilhar);

        return new CompartilhamentosDaPastaOutput(
            $pastaId,
            $podeCompartilhar,
            $equipe,
            $colaboradores,
            $podeCompartilhar ? $this->recentes($quem, $tenant, $equipe, $colaboradores) : [],
        );
    }

    /**
     * Quem pode compartilhar, o responsável e o gestor veem o histórico inteiro; os demais (o colaborador que recebeu,
     * inclusive o restrito) só a própria linha, sem os nomes e as permissões dos outros.
     *
     * @return list<array<string, mixed>>
     */
    private function colaboradores(Pasta $pasta, User $quem, Tenant $tenant, bool $podeCompartilhar): array
    {
        $hoje = $this->relogio->now();
        $gestor = $this->ehGestor($quem, $tenant);
        $tudo = $podeCompartilhar || $gestor || $pasta->getResponsavel()?->getId() === $quem->getId();
        $linhas = [];
        foreach ($this->compartilhamentos->historicoDaPasta($pasta) as $c) {
            $meu = $c->getDestino()->getId() === $quem->getId();
            if ($tudo || $meu) {
                $linhas[] = CompartilhamentosDaPastaOutput::colaborador($c, $hoje, $gestor || $this->envolvido($c, $quem), $meu);
            }
        }

        return $linhas;
    }

    /**
     * Os recentes do "1 clique" (desenho L.826-827): só quem está na equipe que pode receber (vínculo ativo, sem o
     * responsável e sem quem pede) e ainda não colabora nesta pasta.
     *
     * @param list<array{id: int, nome: string}> $equipe
     * @param list<array<string, mixed>>         $colaboradores
     *
     * @return list<array{id: int, nome: string}>
     */
    private function recentes(User $quem, Tenant $tenant, array $equipe, array $colaboradores): array
    {
        $podeReceber = array_column($equipe, 'id');
        $jaColaboram = array_column(array_filter($colaboradores, static fn (array $c): bool => $c['ativo'] === true), 'destinoId');
        $recentes = [];
        foreach ($this->compartilhamentos->destinosRecentes($quem, $tenant, self::RECENTES * 3 + count($jaColaboram)) as $pessoa) {
            $id = $pessoa->getId();
            if (in_array($id, $podeReceber, true) && !in_array($id, $jaColaboram, true) && count($recentes) < self::RECENTES) {
                $recentes[] = CompartilhamentosDaPastaOutput::pessoa($pessoa);
            }
        }

        return $recentes;
    }

    /** @return list<array{id: int, nome: string}> */
    private function equipe(Pasta $pasta, User $quem, Tenant $tenant): array
    {
        $fora = [$quem->getId(), $pasta->getResponsavel()?->getId()];
        $equipe = [];
        foreach ($this->vinculos->findAtivosComPerfilPorTenant($tenant) as $vinculo) {
            if (!in_array($vinculo->getUser()->getId(), $fora, true)) {
                $equipe[] = CompartilhamentosDaPastaOutput::pessoa($vinculo->getUser());
            }
        }

        return $equipe;
    }

    /** O mesmo critério do `EncerrarCompartilhamentoDaPastaUseCase`, para a tela mostrar "Encerrar acesso". */
    private function envolvido(PastaCompartilhamento $c, User $quem): bool
    {
        return in_array($quem->getId(), [$c->getOrigem()->getId(), $c->getDestino()->getId(), $c->getPasta()->getResponsavel()?->getId()], true);
    }

    private function ehGestor(User $quem, Tenant $tenant): bool
    {
        if ($this->regraDosPrimarios->ehMaster($quem)) {
            return true;
        }
        $vinculo = $this->vinculos->findAtivoPorUserETenant($quem, $tenant);

        return $vinculo !== null && $this->regraDosPrimarios->ehPrimario($vinculo);
    }
}
