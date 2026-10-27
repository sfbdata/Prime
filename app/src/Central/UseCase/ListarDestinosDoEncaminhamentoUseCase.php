<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\DTO\DestinoDoEncaminhamentoOutput;
use App\Central\Entity\Conversa;
use App\Central\Exception\ItemEncaminhadoNaoEncontradoException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Central\Service\GuardaDoEncaminhamento;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;

/**
 * "Para quem?" da tela de despacho (`docs/specs/encaminhar-via-chat-ia.md` §2 e §3).
 *
 * Quem: alguém com vínculo ativo que vê a pasta. O quê: escolher para onde levar um item dela. Vêm primeiro as minhas
 * conversas (na ordem da lista da Central), depois os colegas ativos sem conversa 1:1 comigo, por nome.
 *
 * Regras (decisões 1 e 3 do coordenador): colega que não vê a pasta SOME (na 1:1 e na lista de colegas); grupo com
 * algum membro que não vê fica na lista, indisponível, com o motivo. Pasta de outro escritório, excluída ou que eu não
 * vejo é 404. "Vê a pasta?" é perguntado uma vez por pessoa por execução (a mesma pessoa aparece em vários grupos).
 */
final class ListarDestinosDoEncaminhamentoUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly GuardaDoEncaminhamento $guarda,
        private readonly ParticipanteRepository $participantes,
    ) {
    }

    /**
     * @return list<DestinoDoEncaminhamentoOutput>
     *
     * @throws SemAcessoACentralException
     * @throws ItemEncaminhadoNaoEncontradoException
     */
    public function executar(User $eu, ?Tenant $tenant, int $pastaId): array
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $pasta = $this->guarda->pastaQueEuVejo($pastaId, $eu, $tenant);
        $ativos = $this->acesso->colaboradoresAtivos($tenant);
        $ve = $this->quemVe($pasta);

        $comConversa = [];
        $conversas = $this->minhasConversas($eu, $tenant, $ativos, $ve, $comConversa);

        return [...$conversas, ...$this->colegasSemConversa($eu, $ativos, $ve, $comConversa)];
    }

    /**
     * "Esta pessoa vê a pasta?" com a resposta guardada por id durante esta execução.
     *
     * @return \Closure(User): bool
     */
    private function quemVe(Pasta $pasta): \Closure
    {
        $respostas = [];

        return function (User $pessoa) use ($pasta, &$respostas): bool {
            return $respostas[(int) $pessoa->getId()] ??= $this->guarda->podeVer($pessoa, $pasta);
        };
    }

    /**
     * @param array<int, User>      $ativos
     * @param \Closure(User): bool   $ve
     * @param array<int, true>      $comConversa preenchido com quem já tem 1:1 comigo
     *
     * @return list<DestinoDoEncaminhamentoOutput>
     */
    private function minhasConversas(User $eu, Tenant $tenant, array $ativos, \Closure $ve, array &$comConversa): array
    {
        $destinos = [];
        foreach ($this->participantes->doUsuario($eu, $tenant, semIa: true) as $participacao) {
            $conversa = $participacao->getConversa();
            if ($conversa->isGrupo()) {
                $destinos[] = $this->grupo($conversa, $eu, $tenant, $ativos, $ve);
                continue;
            }

            $outro = $conversa->outroAlemDe($eu);
            $comConversa[(int) $outro->getId()] = true;
            if (isset($ativos[(int) $outro->getId()]) && $ve($outro)) {
                $destinos[] = self::destino(DestinoDoEncaminhamentoOutput::CONVERSA, (int) $conversa->getId(), $outro);
            }
        }

        return $destinos;
    }

    /**
     * @param array<int, User>    $ativos
     * @param \Closure(User): bool $ve
     */
    private function grupo(Conversa $grupo, User $eu, Tenant $tenant, array $ativos, \Closure $ve): DestinoDoEncaminhamentoOutput
    {
        $recebem = $this->guarda->quemRecebe($grupo, $eu, $tenant, $ativos);
        $quemNaoVe = array_filter($recebem, static fn (User $u): bool => !$ve($u));
        $motivo = match (true) {
            $recebem === [] => GuardaDoEncaminhamento::MOTIVO_SEM_DESTINATARIO,
            $quemNaoVe !== [] => GuardaDoEncaminhamento::MOTIVO_GRUPO,
            default => null,
        };

        return new DestinoDoEncaminhamentoOutput(
            tipo: DestinoDoEncaminhamentoOutput::CONVERSA,
            conversaId: (int) $grupo->getId(),
            usuarioId: null,
            nome: (string) $grupo->getNome(),
            grupo: true,
            disponivel: $motivo === null,
            motivo: $motivo,
        );
    }

    /**
     * @param array<int, User>    $ativos
     * @param \Closure(User): bool $ve
     * @param array<int, true>    $comConversa
     *
     * @return list<DestinoDoEncaminhamentoOutput>
     */
    private function colegasSemConversa(User $eu, array $ativos, \Closure $ve, array $comConversa): array
    {
        $destinos = [];
        foreach ($ativos as $id => $colega) {
            if ($id !== (int) $eu->getId() && !isset($comConversa[$id]) && $ve($colega)) {
                $destinos[] = self::destino(DestinoDoEncaminhamentoOutput::COLEGA, null, $colega);
            }
        }

        return $destinos;
    }

    private static function destino(string $tipo, ?int $conversaId, User $colega): DestinoDoEncaminhamentoOutput
    {
        return new DestinoDoEncaminhamentoOutput(
            tipo: $tipo,
            conversaId: $conversaId,
            usuarioId: (int) $colega->getId(),
            nome: (string) $colega->getFullName(),
            grupo: false,
            disponivel: true,
            motivo: null,
        );
    }
}
