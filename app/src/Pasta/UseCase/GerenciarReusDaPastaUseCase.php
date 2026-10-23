<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaReu;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Repository\PastaReuRepository;
use App\Service\PermissionChecker;
use App\Shared\Service\DigitoVerificador;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Aba Réu do Cadastro (desenho 1.2.3, `reuVals`; E12h): a parte adversa da pasta.
 *
 * História: a advogada abre o Cadastro da pasta de Gleisson, vai em Réu e acrescenta "Banco do Brasil S.A." com o
 * CNPJ. Ele entra em maiúsculas como "Principal" e passa a aparecer na coluna Réu da Lista do Expediente. Acrescenta
 * a União: a Lista mostra "BANCO DO BRASIL S.A. e outros". A seta sobe um réu (o de cima vira principal) e a lixeira
 * tira, depois de confirmar na própria linha. Como no desenho, grava na hora, sem esperar o Salvar do cliente.
 * Diferente do desenho: réu repetido avisa em vez de sumir em silêncio, e CPF/CNPJ é conferido pelo dígito.
 * Precisa poder EDITAR a pasta.
 */
final class GerenciarReusDaPastaUseCase
{
    private const LIMITE = 50;

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly PastaReuRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return list<PastaReu> */
    public function adicionar(Pasta $pasta, User $usuario, Tenant $tenant, string $nome, string $documento): array
    {
        $this->guardar($pasta, $usuario, $tenant);
        $nome = PastaReu::normalizarNome($nome);
        if ($nome === '') {
            throw new \DomainException('Informe o nome ou a razão social do réu.');
        }
        if (mb_strlen($nome) > 255) {
            throw new \DomainException('O nome do réu passa de 255 caracteres.');
        }
        $digitos = DigitoVerificador::digitos($documento);
        if ($digitos !== '') {
            if (strlen($digitos) !== 11 && strlen($digitos) !== 14) {
                throw new \DomainException('CPF com 11 ou CNPJ com 14 dígitos');
            }
            if (!(strlen($digitos) === 11 ? DigitoVerificador::cpfValido($digitos) : DigitoVerificador::cnpjValido($digitos))) {
                throw new \DomainException((strlen($digitos) === 11 ? 'CPF' : 'CNPJ') . ' inválido: confira os dígitos.');
            }
        }

        $atuais = $this->repository->daPasta($pasta, $tenant);
        foreach ($atuais as $r) {
            if ($r->getNome() === $nome || ($digitos !== '' && $r->getDocumento() === $digitos)) {
                throw new \DomainException('Este réu já está na lista desta pasta.');
            }
        }
        if (count($atuais) >= self::LIMITE) {
            throw new \DomainException('Limite de ' . self::LIMITE . ' réus por pasta.');
        }

        $this->em->persist(new PastaReu($tenant, $pasta, $nome, $digitos, count($atuais)));
        $this->em->flush();

        return $this->repository->daPasta($pasta, $tenant);
    }

    /** Sobe uma posição (no topo, vira o principal). @return list<PastaReu> */
    public function subir(Pasta $pasta, User $usuario, Tenant $tenant, int $reuId): array
    {
        $this->guardar($pasta, $usuario, $tenant);
        $lista = $this->repository->daPasta($pasta, $tenant);
        $i = $this->posicao($lista, $reuId);
        if ($i > 0) {
            [$lista[$i - 1], $lista[$i]] = [$lista[$i], $lista[$i - 1]];
        }
        $this->renumerar($lista);
        $this->em->flush();

        return $this->repository->daPasta($pasta, $tenant);
    }

    /** @return list<PastaReu> */
    public function remover(Pasta $pasta, User $usuario, Tenant $tenant, int $reuId): array
    {
        $this->guardar($pasta, $usuario, $tenant);
        $lista = $this->repository->daPasta($pasta, $tenant);
        $i = $this->posicao($lista, $reuId);
        $this->em->remove($lista[$i]);
        array_splice($lista, $i, 1);
        $this->renumerar($lista);
        $this->em->flush();

        return $this->repository->daPasta($pasta, $tenant);
    }

    private function guardar(Pasta $pasta, User $usuario, Tenant $tenant): void
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_EDIT)) {
            throw new SemPermissaoParaEditarPastaException('Sem permissão para editar esta pasta.');
        }
    }

    /** @param list<PastaReu> $lista */
    private function posicao(array $lista, int $reuId): int
    {
        foreach ($lista as $i => $r) {
            if ($r->getId() === $reuId) {
                return $i;
            }
        }
        throw new \DomainException('Réu não encontrado.');
    }

    /** @param list<PastaReu> $lista */
    private function renumerar(array $lista): void
    {
        foreach (array_values($lista) as $i => $r) {
            $r->setOrdem($i);
        }
    }
}
