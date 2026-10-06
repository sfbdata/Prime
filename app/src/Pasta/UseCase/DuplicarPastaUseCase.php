<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CriarPastaDTO;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Repository\PastaChecklistItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Abre uma pasta nova a partir de outra ("Duplicar pasta" do menu ⋮, desenho 1.2.3).
 *
 * QUEM: quem pode VER a pasta de origem e pode CRIAR pasta no escritório (o controller confere
 * as duas coisas). O QUÊ: o mesmo cliente com um caso parecido — em vez de cadastrar tudo de
 * novo, parte-se do que a pasta de origem já tem.
 * PRÉ: a origem é do escritório da sessão e não está excluída (lápide).
 * FLUXO: cria a pasta pelo MESMO caminho da criação normal (`CriarPastaUseCase`, que gera o
 *        número pelo `GerarNumeroDePasta`) → vincula os clientes, com o mesmo principal →
 *        copia responsável e prioridade → copia os itens do checklist, todos PENDENTES.
 * PÓS: existe uma pasta nova, com número novo, no mesmo escritório.
 *
 * O QUE VAI (desenho: "a cópia leva cliente, ação, responsável e checklist"): identificador
 * (`nomeCliente`), ação, clientes vinculados e o principal, responsável, prioridade e os
 * títulos do checklist, na mesma ordem.
 *
 * O QUE NÃO VAI (desenho: "documentos, metas e financeiro não são copiados"): documentos e
 * seções, metas (tarefas), financeiro (valor da causa, situação do contrato, pro bono,
 * pagamentos, observações financeiras), mensagens/anotações, observações de detalhes,
 * processos vinculados (e com eles o Push Processual, que é derivado dos processos) e
 * marcadores. Processo não vai porque o desenho não o lista — e um processo vinculado a duas
 * pastas é exatamente o que o aviso de pasta duplicada existe para evitar.
 *
 * O checklist entra DESMARCADO: o item marcado na origem é conferência feita naquele caso,
 * não neste. Copiar o "feito" faria a pasta nova nascer dizendo que um documento foi
 * conferido sem ninguém ter olhado.
 *
 * Não reaproveita o `AplicarChecklistModeloUseCase` porque ele recebe um modelo persistido;
 * a regra que importa dele (item novo pendente, no fim da lista, um por título) é a mesma
 * daqui, e a pasta nova nasce vazia — não há repetido a pular além dos da própria origem.
 *
 * Efeitos colaterais da criação (criação da pasta no Drive, auditoria) são os da criação
 * normal: o UseCase de criação é o mesmo e o disparo do Drive fica no controller, como no
 * `pasta_new`.
 */
final class DuplicarPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CriarPastaUseCase $criarPasta,
        private readonly PastaChecklistItemRepository $checklistRepository,
    ) {
    }

    /**
     * @throws AccessDeniedException quando a origem não é do escritório
     * @throws \DomainException      quando a origem está excluída (lápide)
     */
    public function executar(Pasta $origem, User $duplicadoPor, Tenant $tenant): Pasta
    {
        if ($origem->getTenant() !== $tenant) {
            throw new AccessDeniedException('Pasta não pertence ao escritório.');
        }

        if ($origem->estaExcluida()) {
            throw new \DomainException('Esta pasta foi excluída e não pode ser duplicada. Restaure a pasta antes de duplicá-la.');
        }

        // Uma transação só: se copiar o checklist falhar, a pasta nova não fica pela metade
        // (e o número não fica gasto). O CriarPastaUseCase abre a dele por dentro — aninha em
        // savepoint (`use_savepoints: true`), e a trava do número segura até o commit DESTA.
        return $this->em->wrapInTransaction(function () use ($origem, $duplicadoPor, $tenant): Pasta {
            $nova = $this->criarPasta->executar(
                new CriarPastaDTO(
                    nup: null,
                    nomeCliente: $origem->getNomeCliente(),
                    nomeAcao: $origem->getNomeAcao(),
                ),
                $duplicadoPor,
                $tenant,
            );

            // O principal entra PRIMEIRO: `addCliente()` grava como principal o primeiro
            // vinculado, então a ordem é o que faz a cópia ter o mesmo principal da origem.
            $principal = $origem->getClientePrincipal();
            if ($principal !== null && $principal->getTenant() === $tenant) {
                $nova->addCliente($principal);
            }

            foreach ($origem->getClientes() as $cliente) {
                // Defesa: vínculo de cliente de outro escritório não atravessa para a cópia.
                if ($cliente->getTenant() === $tenant) {
                    $nova->addCliente($cliente);
                }
            }

            $nova->setResponsavel($origem->getResponsavel());
            $nova->setPrioridade($origem->getPrioridade());

            $ordem = 1;
            foreach ($this->checklistRepository->findByPasta($origem, $tenant) as $itemOrigem) {
                $item = new PastaChecklistItem();
                $item->setPasta($nova);
                $item->setTenant($tenant);
                $item->setTitulo($itemOrigem->getTitulo());
                $item->setOrdem($ordem);
                $item->setConcluido(false);

                $this->em->persist($item);
                ++$ordem;
            }

            $this->em->flush();

            return $nova;
        });
    }
}
