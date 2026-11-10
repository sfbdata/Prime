<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Lotacao;
use App\Ponto\Entity\LotacaoComVigencia;
use App\Ponto\Repository\LotacaoComVigenciaRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Troca de lotação com vigência (spec `docs/specs/ponto-desenho-0811.md`, E6: decisão do Coordenador de 09/11/2026,
 * no molde da jornada): a lotação nova vale a partir de HOJE, e a folha, o feriado e o banco dos dias anteriores
 * continuam com a lotação que a pessoa tinha. A E16 terá aceite mensal: o passado não pode mudar por troca de cadastro.
 *
 * Quem chama: a edição do vínculo (`TenantController::editUserRole`, formulário `EditUserTenantRoleType`), DEPOIS de o
 * formulário escrever a lotação nova no vínculo e ANTES do flush. Compara a lotação do vínculo com a que veio do banco
 * (os dados originais do Doctrine) e grava junto com o formulário: não dá flush.
 *
 * - Sem troca, nada.
 * - Primeira troca de um vínculo sem versões: grava a versão inicial (a lotação de antes, desde sempre) e a de hoje.
 * - Troca no mesmo dia: substitui a versão de hoje (uma versão por dia).
 * - Vínculo novo (ainda não gravado): nada; sem versões, a lotação do vínculo vale para todos os dias.
 */
final class RegistrarTrocaDeLotacaoUseCase
{
    public function __construct(
        private readonly LotacaoComVigenciaRepository $versoes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(UserTenant $vinculo, ?\DateTimeImmutable $hoje = null): void
    {
        $original = $this->em->contains($vinculo) ? $this->em->getUnitOfWork()->getOriginalEntityData($vinculo) : [];
        if ($vinculo->getId() === null || !\array_key_exists('lotacao', $original)) {
            return;
        }
        $anterior = $original['lotacao'] instanceof Lotacao ? $original['lotacao'] : null;
        $nova = $vinculo->getLotacao();
        if ($anterior?->getId() === $nova?->getId()) {
            return;
        }

        $hoje = ($hoje ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $pessoa = $vinculo->getUser();
        $escritorio = $vinculo->getTenant();
        $versoes = $this->versoes->versoesDoVinculo($pessoa, $escritorio);
        if ($versoes === []) {
            $this->em->persist(LotacaoComVigencia::inicial($pessoa, $escritorio, $anterior));
        }
        foreach ($versoes as $versao) {
            if ($versao->ehDoDia($hoje)) {
                $versao->setLotacao($nova);

                return;
            }
        }
        $this->em->persist(LotacaoComVigencia::aPartirDe($pessoa, $escritorio, $nova, $hoje));
    }
}
