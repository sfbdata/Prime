<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Tarefa\Tarefa;
use App\Tarefa\Service\NumeracaoDeMetas;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Grava uma meta nova já com o número dela na sequência do escritório ("Nº 0001", desenho 02 L5336:
 * "o servidor atribui o nº global ao criar a meta").
 *
 * Quem dispara: quem cria meta pela tela (hoje um caminho só, o modal "Nova meta" da pasta, que o
 * "Criar tarefa" do Push e do drawer da IA reaproveitam: `TarefaController::criarParaPasta`). Toda
 * criação de meta passa por aqui: gravar com `persist` direto deixaria a meta sem número.
 *
 * Reservar o número e gravar a meta ficam na MESMA transação: a trava por escritório da
 * `NumeracaoDeMetas` só segura até o commit, e é ela que impede duas metas criadas ao mesmo tempo de
 * pegarem o mesmo número. Se a gravação falhar, o contador volta junto.
 */
final class GravarMetaNovaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NumeracaoDeMetas $numeracao,
    ) {
    }

    public function executar(Tarefa $tarefa): void
    {
        $tenantId = $tarefa->getTenant()?->getId();
        if ($tenantId === null) {
            throw new \InvalidArgumentException('A meta precisa de um escritório gravado para ser numerada.');
        }

        $this->em->wrapInTransaction(function () use ($tarefa, $tenantId): void {
            $tarefa->numerar($this->numeracao->reservar($tenantId));
            $this->em->persist($tarefa);
            $this->em->flush();
        });
    }
}
