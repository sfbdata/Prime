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
 * Cria um vínculo temporal entre uma Pessoa e um Objeto de Cobrança no mesmo escritório (SPEC §7).
 *
 * História: o gestor liga alguém (proprietário, inquilino, devedor, fiador, outro) a um objeto de
 * cobrança, informando o tipo de vínculo e a data de início (hoje, se omitida). O vínculo nasce
 * ABERTO — a data final só é registrada quando um evento real de encerramento ocorrer (SPEC §7).
 * Guarda same-tenant: Pessoa e Objeto são resolvidos por id + tenant da operação; qualquer um
 * inexistente ou de outro escritório vira null e interrompe a operação com exceção de domínio. Como
 * ambos vêm do MESMO tenant, o vínculo nunca cruza escritórios (invariável 24).
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
        // Guarda same-tenant: pessoa e objeto precisam existir DENTRO do próprio escritório.
        $pessoa = $this->pessoaRepository->findOneByIdDoTenant((int) $input->pessoaId, $tenant);

        if ($pessoa === null) {
            throw new PessoaNaoEncontradaException((int) $input->pessoaId);
        }

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
