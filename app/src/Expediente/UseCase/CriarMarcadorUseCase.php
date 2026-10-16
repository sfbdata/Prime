<?php

namespace App\Expediente\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\DTO\CriarMarcadorDTO;
use App\Expediente\Entity\Marcador;
use App\Expediente\Repository\MarcadorRepository;
use App\Expediente\Service\CoresDoMarcador;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Cria um marcador ou submarcador. Também é o "Duplicar" do desenho 1.1: a tela abre o modal preenchido
 * com "(cópia)" e salva por aqui. O novo entra no fim do seu nível do trilho.
 */
class CriarMarcadorUseCase
{
    public function __construct(
        private readonly MarcadorRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(CriarMarcadorDTO $dto, User $usuario, Tenant $tenant): Marcador
    {
        $pai = null;

        if ($dto->paiId !== null) {
            $pai = $this->repository->findPorTenant($dto->paiId, $tenant);
            if ($pai === null) {
                throw new NotFoundHttpException('Marcador pai não encontrado.');
            }
            // O trilho tem dois níveis: submarcador só de marcador principal.
            if (!$pai->isRaiz()) {
                throw new \DomainException('Escolha um marcador principal.');
            }
        }

        if ($this->repository->existeNomeNoTenant($dto->nome, $tenant)) {
            throw new \DomainException('Já existe um marcador com esse nome');
        }

        if (!CoresDoMarcador::aceita($dto->cor)) {
            throw new \DomainException('Cor inválida.');
        }

        $marcador = new Marcador($dto->nome, $tenant, $usuario, $pai);
        $marcador->setCor($dto->cor);
        $marcador->setDescricao($dto->descricao);
        $marcador->setOrdem(count($this->repository->findIrmaos($pai, $tenant)));

        $this->em->persist($marcador);
        $this->em->flush();

        return $marcador;
    }
}
