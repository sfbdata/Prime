<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\AbrirCasoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoAtivoJaExisteException;
use App\Cobranca\Exception\ObjetoNaoEncontradoException;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Abre um Caso de Cobrança para um Objeto, escolhendo a pessoa cobrada (SPEC §4/§6).
 *
 * História: o gestor inicia um episódio de cobrança sobre um objeto de uma carteira, indicando quem
 * será cobrado. Objeto e pessoa vivem DENTRO do escritório: ambos são resolvidos por id + tenant
 * (guarda multi-tenant, invariável 24) — inexistente/de outro escritório é erro de entrada. No modo A
 * (uma cobrança ativa por objeto, SPEC §6) um segundo caso ativo é rejeitado; enquanto houver caso
 * ativo, novas pendências entram nele. A abertura e o evento "caso aberto" são commitados juntos
 * (flush único no registro do evento).
 *
 * #9-T2 (cascata de encargos ao vivo sem snapshot): o caso NÃO fotografa mais a config de encargos da
 * carteira ao nascer (spec §3.2 — reverte a decisão §18.2/§18.3 da feature de encargos). As colunas de
 * config no `CasoCobranca` (forma/percentual de honorários, taxas, bases, carência, tolerância) ficam
 * no default/null — coluna-sombra morta, mantida por 1 release para rollback seguro (spec §5), mas
 * nunca mais lida: `ResolvedorConfigEncargos::resolverDoCaso` delega ao objeto (T1), e
 * `CalculadoraHonorarios` resolve a política de honorários do objeto/carteira (T2). O caso continua
 * herdando AO VIVO tudo o que a carteira/objeto tiverem configurado, sem UPDATE nenhum nele.
 */
final class AbrirCasoUseCase
{
    public function __construct(
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly ObjetoCobrancaRepository $objetoRepository,
        private readonly PessoaRepository $pessoaRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(AbrirCasoInput $input, Tenant $tenant, User $criadoPor): CasoCobranca
    {
        // Guarda multi-tenant: o objeto tem de pertencer ao próprio escritório.
        $objeto = $this->objetoRepository->findOneByIdDoTenant((int) $input->objetoId, $tenant);

        if ($objeto === null) {
            throw new ObjetoNaoEncontradoException((int) $input->objetoId);
        }

        // Guarda multi-tenant: a pessoa cobrada também tem de ser do próprio escritório.
        $pessoa = $this->pessoaRepository->findOneByIdDoTenant((int) $input->pessoaCobradaId, $tenant);

        if ($pessoa === null) {
            throw new PessoaNaoEncontradaException((int) $input->pessoaCobradaId);
        }

        $carteira = $objeto->getCarteira();

        // Modo A (SPEC §6): enquanto houver caso ativo para o objeto, não se abre um segundo.
        if ($carteira->getModo() === ModoCarteira::Unico && $this->casoRepository->existeCasoAtivoParaObjeto($objeto)) {
            throw new CasoAtivoJaExisteException((int) $objeto->getId());
        }

        // #9-T2: SEM snapshot — o caso nasce sem cópia da config da carteira (spec §3.2). A config de
        // encargos (honorários, taxas, bases, carência, tolerância) cascateia AO VIVO via objeto/carteira.
        $caso = new CasoCobranca();
        $caso->setTenant($tenant);
        $caso->setObjeto($objeto);
        $caso->setPessoaCobradaAtual($pessoa);
        $caso->setCriadoPor($criadoPor);

        // Persiste sem flush; o registro do evento fecha a transação (persiste os dois de uma vez).
        $this->casoRepository->salvar($caso);

        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::CasoAberto,
            $criadoPor,
            'Caso de cobrança aberto.',
            ['pessoaCobradaId' => $pessoa->getId()],
            flush: true,
        );

        return $caso;
    }
}
