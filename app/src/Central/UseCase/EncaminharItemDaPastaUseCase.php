<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\Contract\AuditoriaDoDespacho;
use App\Central\DTO\EncaminhamentoOutput;
use App\Central\DTO\EncaminharItemInput;
use App\Central\DTO\ItemEncaminhado;
use App\Central\DTO\ReferenciaDeDespacho;
use App\Central\Enum\PrioridadeDoDespacho;
use App\Central\Enum\TipoDeItemEncaminhado;
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
use Doctrine\ORM\EntityManagerInterface;

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
 *
 * Uma transação só: abrir a 1:1, a mensagem, os avisos e a auditoria entram juntos ou não entram (falhou a auditoria,
 * não fica mensagem nem conversa nova). Os `wrapInTransaction` do abrir e do enviar se aninham nesta. A corrida de
 * dois abrindo a mesma 1:1 continua virando {@see ConversaCriadaAoMesmoTempoException} (409, "tente de novo"), e a
 * conversa já existente é reaproveitada como antes.
 *
 * No tipo `pasta` o `itemId` não tem sentido: é ignorado e a auditoria grava `null`.
 *
 * A mensagem leva a referência (pasta + tipo, {@see ReferenciaDeDespacho}): quem lê depois (quem entrou no grupo, quem
 * perdeu o acesso) só vê o texto se vê a pasta naquela hora (`LeituraDasMensagens`). Registro do Financeiro: quem envia
 * e quem recebe também precisam ver o financeiro (`GuardaDoEncaminhamento::podeVer` com o tipo); quem envia sem ver = 404.
 */
final class EncaminharItemDaPastaUseCase
{
    public const MOTIVO_COLEGA = 'Este colega não vê esta pasta.';

    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly GuardaDoEncaminhamento $guarda,
        private readonly ItensEncaminhaveisDaPasta $itens,
        private readonly UserRepository $usuarios,
        private readonly AbrirConversaUseCase $abrirConversa,
        private readonly EnviarMensagemUseCase $enviarMensagem,
        private readonly AvisoDeDespachoUrgente $avisoUrgente,
        private readonly AuditoriaDoDespacho $auditoria,
        private readonly EntityManagerInterface $entityManager,
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
        $acesso = $this->itens->tipoDoAcesso($tenant, $pasta, $input->tipo, $input->itemId);
        if (!$this->guarda->podeVer($eu, $pasta, $acesso)) {
            throw new ItemEncaminhadoNaoEncontradoException();
        }
        $item = $this->itens->resumir($tenant, $pasta, $input->tipo, $input->itemId);

        /** @var EncaminhamentoOutput $saida */
        $saida = $this->entityManager->wrapInTransaction(fn (): EncaminhamentoOutput => $this->despachar($eu, $tenant, $pasta, $item, $input, $acesso));

        return $saida;
    }

    /**
     * Dentro da transação: o destino (abrindo a 1:1 se preciso), a mensagem, os avisos, a auditoria e o flush. Quem recebe
     * e a referência gravada seguem o `$acesso` (o cartão financeiro do Registro vale como Financeiro); a auditoria guarda
     * o tipo pedido, que é o dono do item_id.
     */
    private function despachar(User $eu, Tenant $tenant, Pasta $pasta, ItemEncaminhado $item, EncaminharItemInput $input, TipoDeItemEncaminhado $acesso): EncaminhamentoOutput
    {
        ['conversaId' => $conversaId, 'nome' => $nome, 'pessoas' => $pessoas] = $input->usuarioId !== null
            ? $this->conversaComOColega($eu, $tenant, $pasta, $acesso, $input->usuarioId)
            : $this->conversaQueTodosVeem($eu, $tenant, $pasta, $acesso, (int) $input->conversaId);

        $this->enviarMensagem->executar($eu, $tenant, $conversaId, self::texto($item, $pasta, $input), null, new ReferenciaDeDespacho($pasta, $acesso));
        $this->avisarSeUrgente($input->prioridade, $conversaId, $eu, $tenant, $pessoas);
        $itemId = $input->tipo === TipoDeItemEncaminhado::Pasta ? null : $input->itemId;
        $this->auditoria->registrar($eu, $tenant, $pasta, $input->tipo, $itemId, $conversaId, $input->prioridade);
        $this->entityManager->flush();

        return new EncaminhamentoOutput($conversaId, $nome);
    }

    /**
     * O colega precisa ser deste escritório e ver a pasta; a conversa é a 1:1 (aberta agora ou já existente).
     *
     * @return array{conversaId: int, nome: string, pessoas: list<User>}
     */
    private function conversaComOColega(User $eu, Tenant $tenant, Pasta $pasta, TipoDeItemEncaminhado $tipo, int $usuarioId): array
    {
        $colega = $this->usuarios->find($usuarioId);
        if ($colega === null || !$this->acesso->temVinculoAtivo($colega, $tenant)) {
            throw new PedidoInvalidoNaCentralException('Colega não encontrado neste escritório.');
        }
        if (!$this->guarda->podeVer($colega, $pasta, $tipo)) {
            throw new PedidoInvalidoNaCentralException(self::MOTIVO_COLEGA);
        }

        $conversa = $this->abrirConversa->executar($eu, $tenant, $usuarioId);

        return ['conversaId' => $conversa->id, 'nome' => (string) $colega->getFullName(), 'pessoas' => [$colega]];
    }

    /**
     * Conversa de que eu participo (senão 404), com ao menos um outro membro ativo, e todos eles veem o item.
     *
     * @return array{conversaId: int, nome: string, pessoas: list<User>}
     */
    private function conversaQueTodosVeem(User $eu, Tenant $tenant, Pasta $pasta, TipoDeItemEncaminhado $tipo, int $conversaId): array
    {
        $conversa = $this->acesso->participacao($conversaId, $eu, $tenant)->getConversa();
        if ($conversa->isIa()) {
            // A conversa do BlueJus IA é privada e não recebe mensagem de pessoa: nunca é destino de despacho.
            throw new PedidoInvalidoNaCentralException('Não é possível encaminhar para o BlueJus IA.');
        }
        $pessoas = $this->guarda->quemRecebe($conversa, $eu, $tenant, $this->acesso->idsComVinculoAtivo($tenant));
        if ($pessoas === []) {
            throw new PedidoInvalidoNaCentralException(GuardaDoEncaminhamento::MOTIVO_SEM_DESTINATARIO);
        }
        if (!$this->guarda->todosVeem($pessoas, $pasta, $tipo)) {
            throw new PedidoInvalidoNaCentralException($conversa->isGrupo() ? GuardaDoEncaminhamento::MOTIVO_GRUPO : self::MOTIVO_COLEGA);
        }

        $nome = $conversa->isGrupo() ? (string) $conversa->getNome() : (string) $conversa->outroAlemDe($eu)->getFullName();

        return ['conversaId' => $conversaId, 'nome' => $nome, 'pessoas' => $pessoas];
    }

    /**
     * `[🔴 URGENTE · | 🟠 PARA HOJE · ]Despacho: TÍTULO\nRESUMO\nPasta NUP\n\nINSTRUÇÃO` (desenho BC:6218-6238). A linha
     * da pasta vai sempre (no tipo pasta ela já é o título, e não se repete). Título e resumo já vêm desarmados de
     * menção; a instrução não: a @menção que a pessoa escreveu nela vale.
     */
    private static function texto(ItemEncaminhado $item, Pasta $pasta, EncaminharItemInput $input): string
    {
        $texto = $input->prioridade->prefixo() . 'Despacho: ' . $item->titulo;
        if ($item->resumo !== '') {
            $texto .= "\n" . $item->resumo;
        }
        if ($input->tipo !== TipoDeItemEncaminhado::Pasta) {
            $texto .= "\n" . ItensEncaminhaveisDaPasta::rotuloDaPasta($pasta);
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
