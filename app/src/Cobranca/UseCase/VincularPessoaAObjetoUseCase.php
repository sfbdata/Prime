<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\VincularPessoaAObjetoInput;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Cobranca\Exception\ObjetoNaoEncontradoException;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Repository\VinculoPessoaObjetoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Liga uma Pessoa a um Objeto de Cobrança criando um vínculo temporal (SPEC §7).
 *
 * História: o gestor liga alguém (proprietário, inquilino, ocupante...) a um objeto da carteira,
 * escolhendo o tipo de vínculo e a data de início. O vínculo nasce ABERTO — sem data de fim — e só é
 * encerrado depois, num evento real. Pessoa e objeto precisam ser do PRÓPRIO escritório: ambos são
 * resolvidos por id + tenant do usuário (guarda multi-tenant), o que garante que os dois pertencem ao
 * mesmo escritório. Um objeto (ou pessoa) de outro tenant simplesmente não é encontrado e a operação é
 * rejeitada (PessoaNaoEncontradaException / ObjetoNaoEncontradoException), sem persistir nada. O
 * vínculo é temporal e nunca é apagado — o histórico permanece (invariável 11).
 */
final class VincularPessoaAObjetoUseCase
{
    public function __construct(
        private readonly VinculoPessoaObjetoRepository $vinculoRepository,
        private readonly PessoaRepository $pessoaRepository,
        private readonly ObjetoCobrancaRepository $objetoRepository,
    ) {
    }

    public function executar(VincularPessoaAObjetoInput $input, Tenant $tenant, User $criadoPor): VinculoPessoaObjeto
    {
        // Guarda multi-tenant: a pessoa precisa ser do próprio escritório.
        $pessoa = $this->pessoaRepository->findOneByIdDoTenant((int) $input->pessoaId, $tenant);

        if ($pessoa === null) {
            throw new PessoaNaoEncontradaException((int) $input->pessoaId);
        }

        // Guarda multi-tenant: o objeto também é resolvido por id + mesmo tenant.
        // Resolver AMBOS por (id, tenant) garante que pessoa e objeto são do mesmo escritório.
        $objeto = $this->objetoRepository->findOneByIdDoTenant((int) $input->objetoId, $tenant);

        if ($objeto === null) {
            throw new ObjetoNaoEncontradoException((int) $input->objetoId);
        }

        $vinculo = new VinculoPessoaObjeto();
        $vinculo->setTenant($tenant);
        $vinculo->setPessoa($pessoa);
        $vinculo->setObjeto($objeto);
        $vinculo->setTipoVinculo($input->tipoVinculo);
        $vinculo->setDataInicio($input->dataInicio ?? new \DateTimeImmutable());
        $vinculo->setObservacao($this->normalizar($input->observacao));
        $vinculo->setCriadoPor($criadoPor);

        $this->vinculoRepository->salvar($vinculo, true);

        return $vinculo;
    }

    private function normalizar(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $valor = trim($valor);

        return $valor !== '' ? $valor : null;
    }
}
