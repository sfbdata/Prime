<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Ponto\Exception\ConfiguracaoDoPontoRecusadaException;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Repository\SedeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Remove uma sede pela aba Configurações (spec `docs/specs/ponto-desenho-0811.md`, E6; D4: a tela confirma antes).
 * A mesma regra da exclusão da página "Sedes do ponto": numa transação, as batidas daquela sede ficam sem a sede e
 * guardam o nome dela (`RegistroPontoRepository::desvincularSede`), e a sede sai. Devolve quantas batidas ficaram sem
 * a sede, para o aviso.
 *
 * A sede é buscada pelo id E pelo escritório da sessão: a de outro escritório é "não encontrada" (404).
 */
final class RemoverSedeDoPontoUseCase
{
    public function __construct(
        private readonly SedeRepository $sedes,
        private readonly RegistroPontoRepository $registros,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @throws ConfiguracaoDoPontoRecusadaException */
    public function executar(int $id, Tenant $escritorio): int
    {
        $sede = $this->sedes->findOneBy(['id' => $id, 'tenant' => $escritorio])
            ?? throw ConfiguracaoDoPontoRecusadaException::naoEncontrada('Sede não encontrada.');

        // Transação na conexão (não `wrapInTransaction`): uma falha volta tudo sem fechar o EntityManager do request.
        $conexao = $this->em->getConnection();
        $conexao->beginTransaction();
        try {
            $desvinculadas = $this->registros->desvincularSede($sede);
            $this->em->remove($sede);
            $this->em->flush();
            $conexao->commit();
        } catch (\Throwable $falha) {
            if ($conexao->isTransactionActive()) {
                $conexao->rollBack();
            }

            throw $falha;
        }

        return $desvinculadas;
    }
}
