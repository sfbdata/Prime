<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\Contract\AuditoriaDoDespacho;
use App\Central\DTO\EncaminhamentoOutput;
use App\Central\DTO\EncaminharItemInput;
use App\Central\DTO\ItemEncaminhado;
use App\Central\Enum\PrioridadeDoDespacho;
use App\Central\Exception\ConversaCriadaAoMesmoTempoException;
use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\ItemEncaminhadoNaoEncontradoException;
use App\Central\Exception\PedidoInvalidoNaCentralException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Service\AcessoACentral;
use App\Central\Service\AvisoDeDespachoUrgente;
use App\Central\Service\GuardaDoEncaminhamento;
use App\Central\Service\ItensEncaminhaveisDaPasta;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Repository\UserRepository;

/**
 * Encaminhar um item da pasta para a Central ("Encaminhar via Chat I.A", `docs/specs/encaminhar-via-chat-ia.md`).
 *
 * Quem: alguém com vínculo ativo que vê a pasta. O quê: levar o item a um colega (abre ou reaproveita a 1:1) ou a uma
 * conversa minha, com instrução e prioridade. O servidor relê o item ({@see ItensEncaminhaveisDaPasta}); o texto da
 * tela não entra.
 *
 * Recusas: pasta ou item fora do escritório/da pasta = 404; destinatário (ou algum membro do grupo) que não vê a pasta
 * = 422 com o motivo. A mensagem passa pelo {@see EnviarMensagemUseCase} (participação, menções da instrução, limite).
 * "Urgente" avisa cada membro no sino; a auditoria guarda tipo, item, conversa e prioridade, nunca texto.
 */
final class EncaminharItemDaPastaUseCase
{
    public const MOTIVO_COLEGA = 'Este colega não vê esta pasta.';
    public const MOTIVO_GRUPO = 'Nem todos deste grupo veem esta pasta.';

    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly GuardaDoEncaminhamento $guarda,
        private readonly ItensEncaminhaveisDaPasta $itens,
        private readonly UserRepository $usuarios,
        private readonly AbrirConversaUseCase $abrirConversa,
        private readonly EnviarMensagemUseCase $enviarMensagem,
        private readonly AvisoDeDespachoUrgente $avisoUrgente,
        private readonly AuditoriaDoDespacho $auditoria,
    ) {
    }

    /**
     * @throws SemAcessoACentralException
     * @throws ItemEncaminhadoNaoEncontradoException
     * @throws ConversaNaoEncontradaException
     * @throws PedidoInvalidoNaCentralException
     * @throws ConversaCriadaAoMesmoTempoException
     */
    public function executar(User $eu, ?Tenant $tenant, EncaminharItemInput $input): EncaminhamentoOutput
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $pasta = $this->guarda->pastaQueEuVejo($input->pastaId, $eu, $tenant);
        $item = $this->itens->resumir($tenant, $pasta, $input->tipo, $input->itemId);

        ['conversaId' => $conversaId, 'nome' => $nome, 'pessoas' => $pessoas] = $input->usuarioId !== null
            ? $this->conversaComOColega($eu, $tenant, $pasta, $input->usuarioId)
            : $this->conversaQueTodosVeem($eu, $tenant, $pasta, (int) $input->conversaId);

        $this->enviarMensagem->executar($eu, $tenant, $conversaId, self::texto($item, $input));
        $this->avisarSeUrgente($input->prioridade, $conversaId, $eu, $tenant, $pessoas);
        $this->auditoria->registrar($eu, $tenant, $pasta, $input->tipo, $input->itemId, $conversaId, $input->prioridade);

        return new EncaminhamentoOutput($conversaId, $nome);
    }

    /**
     * O colega precisa ser deste escritório e ver a pasta; a conversa é a 1:1 (aberta agora ou já existente).
     *
     * @return array{conversaId: int, nome: string, pessoas: list<User>}
     */
    private function conversaComOColega(User $eu, Tenant $tenant, Pasta $pasta, int $usuarioId): array
    {
        $colega = $this->usuarios->find($usuarioId);
        if ($colega === null || !$this->acesso->temVinculoAtivo($colega, $tenant)) {
            throw new PedidoInvalidoNaCentralException('Colega não encontrado neste escritório.');
        }
        if (!$this->guarda->podeVer($colega, $pasta)) {
            throw new PedidoInvalidoNaCentralException(self::MOTIVO_COLEGA);
        }

        $conversa = $this->abrirConversa->executar($eu, $tenant, $usuarioId);

        return ['conversaId' => $conversa->id, 'nome' => (string) $colega->getFullName(), 'pessoas' => [$colega]];
    }

    /**
     * Conversa de que eu participo (senão 404), e todos os outros membros ativos veem a pasta.
     *
     * @return array{conversaId: int, nome: string, pessoas: list<User>}
     */
    private function conversaQueTodosVeem(User $eu, Tenant $tenant, Pasta $pasta, int $conversaId): array
    {
        $conversa = $this->acesso->participacao($conversaId, $eu, $tenant)->getConversa();
        $pessoas = $this->guarda->quemRecebe($conversa, $eu, $tenant, $this->acesso->idsComVinculoAtivo($tenant));
        if (!$this->guarda->todosVeem($pessoas, $pasta)) {
            throw new PedidoInvalidoNaCentralException($conversa->isGrupo() ? self::MOTIVO_GRUPO : self::MOTIVO_COLEGA);
        }

        $nome = $conversa->isGrupo() ? (string) $conversa->getNome() : (string) $conversa->outroAlemDe($eu)->getFullName();

        return ['conversaId' => $conversaId, 'nome' => $nome, 'pessoas' => $pessoas];
    }

    /**
     * `[🔴 URGENTE · | 🟠 PARA HOJE · ]Despacho: TÍTULO\nRESUMO\n\nINSTRUÇÃO` (desenho BC:6218-6238). Título e resumo
     * já vêm desarmados de menção; a instrução não: a @menção que a pessoa escreveu nela vale.
     */
    private static function texto(ItemEncaminhado $item, EncaminharItemInput $input): string
    {
        $texto = $input->prioridade->prefixo() . 'Despacho: ' . $item->titulo;
        if ($item->resumo !== '') {
            $texto .= "\n" . $item->resumo;
        }
        if ($input->instrucao !== null) {
            $texto .= "\n\n" . $input->instrucao;
        }

        return $texto;
    }

    /** @param list<User> $pessoas */
    private function avisarSeUrgente(PrioridadeDoDespacho $prioridade, int $conversaId, User $eu, Tenant $tenant, array $pessoas): void
    {
        if ($prioridade === PrioridadeDoDespacho::Urgente) {
            $this->avisoUrgente->avisar($conversaId, $eu, $tenant, $pessoas);
        }
    }
}
