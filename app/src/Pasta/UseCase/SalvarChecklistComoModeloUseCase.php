<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistModelo;
use App\Pasta\Entity\PastaChecklistModeloItem;
use App\Pasta\Exception\ChecklistModeloJaExisteException;
use App\Pasta\Repository\PastaChecklistItemRepository;
use App\Pasta\Repository\PastaChecklistModeloRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Guarda a lista de documentos desta pasta com um nome, para aplicá-la em outras.
 *
 * QUEM: quem já pode editar a pasta (mesma permissão de mexer nos itens do checklist).
 * O QUÊ: parar de redigitar a mesma conferência de documentos a cada pasta nova.
 * PRÉ: a pasta tem ao menos um item no checklist e o nome do modelo não é vazio.
 * FLUXO: normaliza o nome → se o escritório já tem um modelo assim, só segue com
 *        `substituir` → copia os TÍTULOS dos itens da pasta, na ordem da tela.
 * ALTERNATIVOS: checklist vazio, nome vazio/longo demais, nome repetido sem substituir.
 * PÓS: o escritório passa a ter um modelo com N itens; a pasta de origem não muda em nada.
 * REGRA: o modelo copia só o título. "Concluído" é estado da pasta, não do modelo — senão
 *        aplicar um modelo já traria itens marcados que ninguém conferiu.
 */
final class SalvarChecklistComoModeloUseCase
{
    private const NOME_MAX = 120;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaChecklistItemRepository $checklistRepository,
        private readonly PastaChecklistModeloRepository $modeloRepository,
    ) {
    }

    public function executar(
        Pasta $pasta,
        User $autor,
        string $nome,
        Tenant $tenant,
        bool $substituir = false,
    ): PastaChecklistModelo {
        if ($pasta->getTenant() !== $tenant) {
            throw new AccessDeniedException('Pasta não pertence ao escritório.');
        }

        $nome = trim($nome);

        if ($nome === '') {
            throw new \InvalidArgumentException('Dê um nome ao modelo.');
        }

        if (mb_strlen($nome) > self::NOME_MAX) {
            throw new \InvalidArgumentException(sprintf('O nome deve ter no máximo %d caracteres.', self::NOME_MAX));
        }

        $itens = $this->checklistRepository->findByPasta($pasta, $tenant);

        if ($itens === []) {
            throw new \InvalidArgumentException('Esta pasta não tem itens no checklist para salvar.');
        }

        $modelo = $this->modeloRepository->buscarPorNome($nome, $tenant);

        if ($modelo !== null && !$substituir) {
            throw new ChecklistModeloJaExisteException($modelo->getNome());
        }

        if ($modelo === null) {
            $modelo = new PastaChecklistModelo();
            $modelo->setTenant($tenant);
            $modelo->setAutor($autor);
            $modelo->setNome($nome);
        } else {
            // Substituindo: o autor continua sendo quem criou o modelo. Quem salvou por
            // cima aparece na auditoria, que é onde essa pergunta se responde.
            $modelo->limparItens();
        }

        $ordem = 1;
        foreach ($itens as $item) {
            $linha = new PastaChecklistModeloItem();
            $linha->setTenant($tenant);
            $linha->setTitulo($item->getTitulo());
            $linha->setOrdem($ordem);
            $modelo->adicionarItem($linha);
            ++$ordem;
        }

        $this->modeloRepository->salvar($modelo);
        $this->em->flush();

        return $modelo;
    }
}
