<?php

declare(strict_types=1);

namespace App\Conector\UseCase;

use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Enum\MotivoDaRevogacaoDaConcessao;
use App\Conector\Exception\ClienteRecusado;
use App\Conector\Repository\ConcessaoDoConectorRepository;
use App\Conector\Repository\ConsentimentoDoConectorRepository;
use App\Conector\Service\SemFiltroDoEscritorio;
use App\Entity\Auth\User;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * A pessoa revoga uma conexão de I.A. no Meu Perfil (`docs/specs/mcp-remoto-oauth.md`, seção 4 regra 3, 13.2 e 13.1
 * linha do PR 8). Vale para a conexão de qualquer escritório dela, inclusive o de vínculo já encerrado (decisão 2 do
 * plano do PR 8).
 *
 * Guarda de posse antes de tudo: a conexão é buscada pelo id E pela pessoa logada; a de outra pessoa, de escritório
 * alheio ou já revogada não existe para quem pede ({@see NAO_ENCONTRADA}). Por isso revogar de novo não muda nada.
 *
 * Na mesma transação: o consentimento fica revogado (a linha fica como histórico) e todas as concessões ainda abertas
 * dele caem com o motivo `revogada_pela_pessoa`, num UPDATE só. Com isso o token de acesso para na requisição seguinte,
 * a renovação e o código ainda não trocado dão `invalid_grant`. A revogação do consentimento entra no `audit_log` com o
 * escritório DA CONEXÃO, não o da sessão ({@see EscopoDeAuditoriaDePlataforma::executarComoConta()}).
 */
final class RevogarConexaoUseCase
{
    public const NAO_ENCONTRADA = 'Conexão não encontrada.';

    public function __construct(
        private readonly ConsentimentoDoConectorRepository $consentimentos,
        private readonly ConcessaoDoConectorRepository $concessoes,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDeAuditoria,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $relogio,
    ) {
    }

    /**
     * @return string o host do app da conexão revogada, para o aviso da tela
     *
     * @throws \DomainException com {@see NAO_ENCONTRADA}
     */
    public function executar(int $id, User $pessoa): string
    {
        $consentimento = $this->consentimentos->buscarAtivoDaPessoa($id, $pessoa)
            ?? throw new \DomainException(self::NAO_ENCONTRADA);
        $escritorio = $consentimento->getTenant() ?? throw new \LogicException('Conexão sem escritório.');

        $this->escopoDeAuditoria->executarComoConta(
            (int) $escritorio->getId(),
            fn () => $this->em->wrapInTransaction(fn () => $this->revogar($consentimento)),
        );

        $identificador = $consentimento->getCliente()->getIdentificador();

        return ClienteRecusado::hostDe($identificador) ?? $identificador;
    }

    /**
     * Dentro da transação: o `wrapInTransaction` grava (flush) e confirma no fim, ou desfaz tudo. A conexão é relida
     * com trava de linha antes de revogar: dois cliques ao mesmo tempo entram em fila, e o segundo acha a conexão já
     * revogada (não encontrada), sem regravar a data nem sujar o histórico. O `revogarDoConsentimento` cuida sozinho
     * do filtro do escritório da sessão.
     */
    private function revogar(ConsentimentoDoConector $consentimento): void
    {
        SemFiltroDoEscritorio::executar($this->em, fn () => $this->em->refresh($consentimento, LockMode::PESSIMISTIC_WRITE));
        if (!$consentimento->isAtivo()) {
            throw new \DomainException(self::NAO_ENCONTRADA);
        }

        $agora = $this->relogio->now();
        $consentimento->revogar($agora);
        $this->concessoes->revogarDoConsentimento($consentimento, MotivoDaRevogacaoDaConcessao::RevogadaPelaPessoa, $agora);
    }
}
