<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\DTO\ItemEncaminhado;
use App\Central\Enum\TipoDeItemEncaminhado;
use App\Central\Exception\ItemEncaminhadoNaoEncontradoException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Service\AcessoACentral;
use App\Central\Service\GuardaDoEncaminhamento;
use App\Central\Service\ItensEncaminhaveisDaPasta;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * O cartão da tela "Despachar via Chat I.A" (desenho BC:6224, `docs/specs/encaminhar-via-chat-ia.md` §4.1).
 *
 * Quem: alguém com vínculo ativo que vê a pasta. O quê: ver, antes de escolher o destino, o título e o resumo do item
 * que vai despachar. A tela só manda pasta, tipo e id; o servidor relê o item pelas MESMAS guardas e pelo MESMO leitor
 * do envio ({@see EncaminharItemDaPastaUseCase}), então o cartão mostra exatamente o que a mensagem vai levar.
 *
 * Recusas: sem vínculo ativo = 403; pasta de outro escritório, excluída, que eu não vejo, ou item que não é dela = 404,
 * sem dizer qual. Só leitura: nada é gravado.
 */
final class ObterItemDoEncaminhamentoUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly GuardaDoEncaminhamento $guarda,
        private readonly ItensEncaminhaveisDaPasta $itens,
    ) {
    }

    /**
     * @throws SemAcessoACentralException
     * @throws ItemEncaminhadoNaoEncontradoException
     */
    public function executar(User $eu, ?Tenant $tenant, int $pastaId, TipoDeItemEncaminhado $tipo, ?int $itemId): ItemEncaminhado
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $pasta = $this->guarda->pastaQueEuVejo($pastaId, $eu, $tenant);
        if (!$this->guarda->podeVer($eu, $pasta, $this->itens->tipoDoAcesso($tenant, $pasta, $tipo, $itemId))) {
            throw new ItemEncaminhadoNaoEncontradoException();   // a prévia do Financeiro também exige ver o financeiro
        }

        return $this->itens->resumir($tenant, $pasta, $tipo, $itemId);
    }
}
