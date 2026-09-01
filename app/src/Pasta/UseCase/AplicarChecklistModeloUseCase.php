<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\AplicarChecklistModeloOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaChecklistModelo;
use App\Pasta\Repository\PastaChecklistItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Despeja os itens de um modelo no checklist desta pasta.
 *
 * QUEM: quem pode editar a pasta. O QUÊ: montar a conferência de documentos de uma pasta
 * nova num clique, em vez de digitar item a item.
 * PRÉ: modelo e pasta são do mesmo escritório.
 * FLUXO: lê os títulos que a pasta já tem → para cada item do modelo ainda ausente, cria
 *        um item PENDENTE no fim da lista → devolve o que entrou e o que já existia.
 * PÓS: a pasta ganha os itens que faltavam; nada do que já estava lá é tocado.
 *
 * REGRA (a que dá forma a todo o resto): aplicar ACRESCENTA, nunca substitui. Um checklist
 * meio conferido é trabalho humano já feito; um modelo que apagasse itens marcados destruiria
 * esse trabalho de forma silenciosa. Por isso o repetido é PULADO em vez de duplicado, e o
 * item marcado é deixado como está.
 *
 * A comparação de "já existe" é por título exato — e funciona porque as duas entidades
 * normalizam para maiúsculas no setter. Se um dia uma delas parar de normalizar, esta
 * comparação passa a duplicar em silêncio.
 */
final class AplicarChecklistModeloUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaChecklistItemRepository $checklistRepository,
    ) {
    }

    public function executar(Pasta $pasta, PastaChecklistModelo $modelo, Tenant $tenant): AplicarChecklistModeloOutput
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new AccessDeniedException('Pasta não pertence ao escritório.');
        }

        if ($modelo->getTenant() !== $tenant) {
            throw new AccessDeniedException('Modelo não pertence ao escritório.');
        }

        $jaExistem = [];
        foreach ($this->checklistRepository->findByPasta($pasta, $tenant) as $item) {
            $jaExistem[$item->getTitulo()] = true;
        }

        $ordem     = $this->checklistRepository->proximaOrdem($pasta, $tenant);
        $novos     = [];
        $ignorados = [];

        foreach ($modelo->getItens() as $linha) {
            $titulo = $linha->getTitulo();

            if ($titulo === '' || isset($jaExistem[$titulo])) {
                if ($titulo !== '') {
                    $ignorados[] = $titulo;
                }

                continue;
            }

            $item = new PastaChecklistItem();
            $item->setPasta($pasta);
            $item->setTenant($tenant);
            $item->setTitulo($titulo);
            $item->setOrdem($ordem);
            $item->setConcluido(false);

            $this->em->persist($item);

            // Marca aqui também: modelo com dois itens de mesmo título entraria duas vezes.
            $jaExistem[$titulo] = true;
            $novos[]            = $item;
            ++$ordem;
        }

        $this->em->flush();

        $criados = [];
        foreach ($novos as $item) {
            $criados[] = ['id' => (int) $item->getId(), 'titulo' => $item->getTitulo()];
        }

        return new AplicarChecklistModeloOutput($criados, $ignorados);
    }
}
