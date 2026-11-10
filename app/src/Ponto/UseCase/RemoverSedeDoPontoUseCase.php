<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Ponto\Exception\ConfiguracaoDoPontoRecusadaException;
use App\Ponto\Service\RemocaoDaSedeDoPonto;
use App\Repository\SedeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Remove uma sede pela aba Configurações (spec `docs/specs/ponto-desenho-0811.md`, E6; D4: a tela confirma antes).
 * A mesma regra do "Remover" da página "Sedes do ponto" (E10, {@see RemocaoDaSedeDoPonto}): a batida gravada não muda,
 * então a sede sem batida é apagada e a com batida é encerrada (some da lista e da cerca; as batidas mantêm a sede).
 * Devolve se a sede foi encerrada, para o aviso.
 *
 * A sede é buscada pelo id E pelo escritório da sessão, só entre as ativas: a de outro escritório ou já encerrada é
 * "não encontrada" (404).
 */
final class RemoverSedeDoPontoUseCase
{
    public function __construct(
        private readonly SedeRepository $sedes,
        private readonly RemocaoDaSedeDoPonto $remocao,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return bool true se a sede tinha batida e foi encerrada; false se foi apagada
     *
     * @throws ConfiguracaoDoPontoRecusadaException
     */
    public function executar(int $id, Tenant $escritorio): bool
    {
        $sede = $this->sedes->ativaDoEscritorio($id, $escritorio)
            ?? throw ConfiguracaoDoPontoRecusadaException::naoEncontrada('Sede não encontrada.');

        // Transação na conexão (não `wrapInTransaction`): uma falha volta tudo sem fechar o EntityManager do request.
        $conexao = $this->em->getConnection();
        $conexao->beginTransaction();
        try {
            $encerrada = $this->remocao->remover($sede);
            $conexao->commit();
        } catch (\Throwable $falha) {
            if ($conexao->isTransactionActive()) {
                $conexao->rollBack();
            }

            throw $falha;
        }

        return $encerrada;
    }
}
