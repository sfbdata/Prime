<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaChecklistModelo;
use App\Pasta\Exception\ChecklistModeloJaExisteException;
use App\Pasta\Repository\PastaChecklistModeloRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Troca o nome de um modelo salvo.
 *
 * O nome é a única coisa que a equipe vê antes de aplicar, e "TESTE 2" salvo com pressa
 * fica na lista de todo mundo. Sem renomear, a saída seria excluir e refazer.
 *
 * PRÉ: o modelo é do escritório e o novo nome não colide com OUTRO modelo. Rebatizar um
 * modelo para o nome que ele já tem (só mudando a caixa das letras) é aceito.
 */
final class RenomearChecklistModeloUseCase
{
    private const NOME_MAX = 120;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaChecklistModeloRepository $modeloRepository,
    ) {
    }

    public function executar(PastaChecklistModelo $modelo, string $nome, Tenant $tenant): PastaChecklistModelo
    {
        if ($modelo->getTenant() !== $tenant) {
            throw new AccessDeniedException('Modelo não pertence ao escritório.');
        }

        $nome = trim($nome);

        if ($nome === '') {
            throw new \InvalidArgumentException('Dê um nome ao modelo.');
        }

        if (mb_strlen($nome) > self::NOME_MAX) {
            throw new \InvalidArgumentException(sprintf('O nome deve ter no máximo %d caracteres.', self::NOME_MAX));
        }

        $colisao = $this->modeloRepository->buscarPorNome($nome, $tenant);

        if ($colisao !== null && $colisao->getId() !== $modelo->getId()) {
            throw new ChecklistModeloJaExisteException($colisao->getNome());
        }

        $modelo->setNome($nome);
        $this->em->flush();

        return $modelo;
    }
}
