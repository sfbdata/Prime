<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Armazenamento\ChavesDePonto;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Enum\AcaoDaTrilhaDoPonto;
use App\Ponto\Exception\ExclusaoDeJustificativaRecusadaException;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;
use App\Ponto\Service\TrilhaDoPonto;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * O colaborador exclui a própria justificativa enquanto ela ainda está em análise (P41 do desenho
 * do Ponto, docs/specs/ponto-desenho-0811.md, E3).
 *
 * Quem: o dono da justificativa, pela aba "Minhas Justificativas", depois de confirmar na janela.
 * Para quê: desistir de um pedido enviado por engano antes de o gestor decidir.
 * Pré-condição: a justificativa é deste escritório, é de quem pede e está `pendente` no banco no
 * instante em que sai.
 * Recusas (`ExclusaoDeJustificativaRecusadaException`, nada muda): não existe neste escritório,
 * é de outra pessoa ou já foi analisada.
 * Depois: o registro saiu do banco; o atestado sai do disco só depois do COMMIT e só se nenhum
 * outro dia do lote ainda apontar para ele.
 *
 * ## Por que sob trava
 *
 * A análise do gestor (`TenantController`) comita a qualquer momento e não pega trava nenhuma. O
 * status é lido do banco com a linha travada, na mesma transação da exclusão: uma aprovação que
 * já comitou é vista aqui, e uma que chega depois espera este COMMIT e não acha mais o registro.
 * Mesma regra de `ConfirmarEdicaoDeJustificativaUseCase`.
 */
final class ExcluirJustificativaEmAnaliseUseCase
{
    /** O único status que o colaborador ainda pode desfazer: o gestor não decidiu nada. */
    private const STATUS_EXCLUIVEL = 'pendente';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JustificativaPontoRepository $repositorio,
        private readonly RemocaoAposTransacao $remocao,
        private readonly LoggerInterface $logger,
        private readonly TrilhaDoPonto $trilha,
    ) {
    }

    /**
     * @return \DateTimeInterface o dia da justificativa excluída, para a mensagem da tela
     *
     * @throws ExclusaoDeJustificativaRecusadaException
     */
    public function executar(int $justificativaId, User $dono, Tenant $tenant): \DateTimeInterface
    {
        $justificativa = $this->carregarDoDono($justificativaId, $dono, $tenant);
        $dia           = \DateTimeImmutable::createFromInterface(
            $justificativa->getData() ?? throw new \LogicException('Justificativa sem data no banco.'),
        );

        // `wrapInTransaction`: trava, exclusão, flush e COMMIT. A recusa não muda nada: a transação comita vazia
        // (solta a trava) e a recusa sobe depois, com o EntityManager aberto para o resto da requisição.
        [$status, $anexo] = $this->em->wrapInTransaction(
            fn (): array => $this->excluirSeAindaEmAnalise($justificativa, $tenant),
        );

        $this->exigirQueEstavaEmAnalise($status);
        $this->apagarAnexoSeOrfao($anexo, $justificativa, $tenant);

        return $dia;
    }

    /** Busca SEMPRE pelo escritório informado: id de outro escritório é "não encontrada" (404). */
    private function carregarDoDono(int $justificativaId, User $dono, Tenant $tenant): JustificativaPonto
    {
        $justificativa = $this->repositorio->findOneBy(['id' => $justificativaId, 'tenant' => $tenant]);

        if (!$justificativa instanceof JustificativaPonto) {
            throw ExclusaoDeJustificativaRecusadaException::naoEncontrada();
        }

        if ($justificativa->getUser()?->getId() !== $dono->getId()) {
            throw ExclusaoDeJustificativaRecusadaException::deOutraPessoa();
        }

        return $justificativa;
    }

    /**
     * Roda dentro da transação, com a linha travada. Só exclui o que ainda está em análise; devolve o status lido
     * sob a trava e o nome do atestado COMO ESTÁ NO BANCO (o getter pode estar velho, ver `anexoNoBancoPorId`),
     * lido antes de a linha sair.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function excluirSeAindaEmAnalise(JustificativaPonto $justificativa, Tenant $tenant): array
    {
        $id     = (int) $justificativa->getId();
        $status = $this->repositorio->statusNoBancoTravadoPorId($id, $tenant);

        if ($status !== self::STATUS_EXCLUIVEL) {
            return [$status, null];
        }

        $anexo = $this->repositorio->anexoNoBancoPorId($id, $tenant);
        // Trilha do Ponto (E9): o detalhe sai antes do remove (o id some da entidade depois do flush).
        $this->trilha->registrar($tenant, $justificativa->getUser(), AcaoDaTrilhaDoPonto::JustificativaExcluida->value, DetalheDaTrilhaDoPonto::daJustificativa($justificativa));
        $this->em->remove($justificativa);

        return [$status, $anexo];
    }

    /** Sumir sob a trava é "não encontrada"; qualquer status além de "pendente" já foi analisado. */
    private function exigirQueEstavaEmAnalise(?string $status): void
    {
        if ($status === null) {
            throw ExclusaoDeJustificativaRecusadaException::naoEncontrada();
        }

        if ($status !== self::STATUS_EXCLUIVEL) {
            throw ExclusaoDeJustificativaRecusadaException::jaAnalisada();
        }
    }

    /**
     * Depois do COMMIT: a exclusão já vale. Falha aqui deixa um atestado órfão recuperável e vira
     * registro; nunca dá ao usuário a impressão de que a exclusão falhou.
     */
    private function apagarAnexoSeOrfao(?string $anexo, JustificativaPonto $dona, Tenant $tenant): void
    {
        if ($anexo === null || $anexo === '') {
            return;
        }

        try {
            if ($this->repositorio->contarReferenciasAoAnexo($anexo, $tenant) > 0) {
                return;
            }

            // A chave é montada pela forma adiada: um nome que o storage recusa vira registro, e não
            // erro com a exclusão já confirmada.
            $this->remocao->remover(
                [static fn (): ChaveDeArquivo => ChavesDePonto::anexoDeJustificativaPorNome($dona, $anexo)],
                'ExcluirJustificativaEmAnaliseUseCase: atestado sem referências',
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Não foi possível remover o atestado da justificativa excluída.', [
                'anexo' => $anexo,
                'erro'  => $e->getMessage(),
            ]);
        }
    }
}
