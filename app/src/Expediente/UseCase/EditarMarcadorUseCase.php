<?php

namespace App\Expediente\UseCase;

use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Expediente\Repository\MarcadorRepository;
use App\Expediente\Service\CoresDoMarcador;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Edita nome, cor e, pelo desenho 1.1 (E12), descrição e "Dentro de (submarcador)".
 *
 * Trocar o pai (`$trocarPai = true`) segue as regras do modal do desenho: o pai precisa ser um marcador
 * de topo do mesmo escritório, não pode ser o próprio, e um marcador que tem submarcadores não vira
 * submarcador (o trilho tem só dois níveis). `$paiId = null` com `$trocarPai` leva para o topo.
 */
class EditarMarcadorUseCase
{
    public function __construct(
        private readonly MarcadorRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(
        int $id,
        string $nome,
        ?string $cor,
        Tenant $tenant,
        ?string $descricao = null,
        bool $trocarPai = false,
        ?int $paiId = null,
    ): Marcador {
        $marcador = $this->repository->findPorTenant($id, $tenant);

        if ($marcador === null) {
            throw new \DomainException('Marcador não encontrado.');
        }

        if (!CoresDoMarcador::aceita($cor)) {
            throw new \DomainException('Cor inválida.');
        }

        if ($this->repository->existeNomeNoTenant($nome, $tenant, $id)) {
            throw new \DomainException('Já existe um marcador com esse nome');
        }

        if ($trocarPai) {
            $this->trocarPai($marcador, $paiId, $tenant);
        }

        $marcador->setNome($nome);
        $marcador->setCor($cor);
        // Sem o campo (chamador antigo), a descrição fica como está; vazio apaga.
        if ($descricao !== null) {
            $marcador->setDescricao($descricao);
        }
        $this->em->flush();

        return $marcador;
    }

    private function trocarPai(Marcador $marcador, ?int $paiId, Tenant $tenant): void
    {
        $atual = $marcador->getPai()?->getId();
        if ($atual === $paiId) {
            return;
        }

        $novoPai = null;
        if ($paiId !== null) {
            $novoPai = $this->repository->findPorTenant($paiId, $tenant);
            if ($novoPai === null) {
                throw new \DomainException('Marcador pai não encontrado.');
            }
            if ($novoPai === $marcador || !$novoPai->isRaiz()) {
                throw new \DomainException('Escolha um marcador principal.');
            }
            if (!$marcador->getFilhos()->isEmpty()) {
                throw new \DomainException('Um marcador com submarcadores não pode virar submarcador.');
            }
        }

        $marcador->setPai($novoPai);
        $marcador->setOrdem(count($this->repository->findIrmaos($novoPai, $tenant)));
    }
}
