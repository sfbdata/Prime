<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CompartilharPastaInput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaCompartilhamento;
use App\Pasta\Exception\CompartilhamentoRecusadoException;
use App\Pasta\Repository\PastaCompartilhamentoRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\EscopoDePastas;
use App\Repository\UserTenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Compartilhar a pasta com alguém da equipe do escritório (spec acesso-restrito-e-compartilhar §4.4; desenho L3, aba
 * "Equipe do escritório"). Sem aceite: a pasta aparece para a pessoa na hora, inclusive no acesso restrito.
 *
 * Quem compartilha: quem edita a pasta e não tem acesso restrito. O destino tem vínculo ativo no escritório, não é
 * quem compartilha nem o responsável. Um compartilhamento ativo por pasta + pessoa; o vencido é encerrado e dá lugar
 * ao novo (o índice único parcial guarda a vaga).
 */
final class CompartilharPastaComAEquipeUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaCompartilhamentoRepository $compartilhamentos,
        private readonly UserTenantRepository $vinculos,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly EscopoDePastas $escopo,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(int $pastaId, CompartilharPastaInput $input, User $autor, Tenant $tenant): PastaCompartilhamento
    {
        $pasta   = $this->pastaQueOAutorCompartilha($pastaId, $autor, $tenant);
        $destino = $this->destinoDaEquipe($input, $pasta, $autor, $tenant);
        $agora   = $this->relogio->now();
        $this->exigirValidadeFutura($input, $agora);

        $vencido = $this->vagaLivreOuVencida($pasta, $destino, $agora);

        return $this->em->wrapInTransaction(function () use ($pasta, $destino, $input, $autor, $agora, $vencido): PastaCompartilhamento {
            if ($vencido !== null) {
                $vencido->encerrar($autor, $agora);
                $this->em->flush();
            }
            $compartilhamento = PastaCompartilhamento::interno($pasta, $autor, $destino, $input->permissoes(), $input->validoAte, $input->mensagem, $agora);
            $this->compartilhamentos->salvar($compartilhamento, flush: true);

            return $compartilhamento;
        });
    }

    private function pastaQueOAutorCompartilha(int $pastaId, User $autor, Tenant $tenant): Pasta
    {
        $pasta = $this->em->getRepository(Pasta::class)->findOneBy(['id' => $pastaId, 'tenant' => $tenant]);
        if ($pasta === null || $this->escopo->ehRestritoNoEscritorio($autor, $tenant) || !$this->acessoAPasta->podeEditar($autor, $pasta)) {
            throw new CompartilhamentoRecusadoException(CompartilhamentoRecusadoException::SEM_PERMISSAO, daPessoa: true);
        }

        return $pasta;
    }

    private function destinoDaEquipe(CompartilharPastaInput $input, Pasta $pasta, User $autor, Tenant $tenant): User
    {
        $vinculo = $input->destinoId === null ? null
            : $this->vinculos->findOneBy(['user' => $input->destinoId, 'tenant' => $tenant, 'isActive' => true]);
        $destino = $vinculo?->getUser();
        if ($destino === null || $destino->getId() === $autor->getId()) {
            throw new CompartilhamentoRecusadoException(CompartilhamentoRecusadoException::ESCOLHA_O_USUARIO);
        }
        if ($pasta->getResponsavel()?->getId() === $destino->getId()) {
            throw new CompartilhamentoRecusadoException(CompartilhamentoRecusadoException::DESTINO_RESPONSAVEL);
        }

        return $destino;
    }

    private function exigirValidadeFutura(CompartilharPastaInput $input, \DateTimeImmutable $agora): void
    {
        if ($input->validoAte !== null && $input->validoAte->format('Y-m-d') < $agora->format('Y-m-d')) {
            throw new CompartilhamentoRecusadoException(CompartilhamentoRecusadoException::VALIDADE_PASSADA);
        }
    }

    /**
     * Ativo recusa (antes da transação, que fecharia o EntityManager na exceção); o vencido, ainda aceito, volta para
     * ser encerrado e gravado antes do novo, para o índice único não barrar.
     */
    private function vagaLivreOuVencida(Pasta $pasta, User $destino, \DateTimeImmutable $agora): ?PastaCompartilhamento
    {
        $ocupando = $this->compartilhamentos->ocupandoAVaga($pasta, $destino);
        if ($ocupando !== null && $ocupando->estaAtivo($agora)) {
            throw new CompartilhamentoRecusadoException(CompartilhamentoRecusadoException::JA_COMPARTILHADA);
        }

        return $ocupando;
    }
}
