<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\CriarAcordoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\ObrigacaoDeOutroCasoException;
use App\Cobranca\Exception\ObrigacaoJaSubstituidaException;
use App\Cobranca\Exception\ObrigacaoNaoEncontradaException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Cria um Acordo num Caso de Cobrança (SPEC §12): substitui uma ou mais obrigações do MESMO caso
 * (invariável 13) por novas obrigações (parcelas).
 *
 * História: o gestor renegocia a dívida — escolhe as obrigações que serão substituídas e define as
 * parcelas do acordo. O caso é resolvido por id + tenant (guarda multi-tenant); caso encerrado NÃO
 * aceita acordos (SPEC §17). As obrigações substituídas NUNCA são apagadas (invariável 14) — só
 * marcadas com `acordoSubstituto` —; a substituição PARCIAL é permitida. As parcelas nascem como
 * novas obrigações do caso, geradas pelo acordo (`acordoOrigem`). NENHUMA reversão de saldo é feita
 * aqui: a CalculadoraSaldo deriva o exigível a partir do estado do acordo (invariável 20). O acordo,
 * as marcações, as parcelas e o evento "acordo criado" são commitados juntos (flush único no evento).
 */
final class CriarAcordoUseCase
{
    public function __construct(
        private readonly AcordoRepository $acordoRepository,
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(CriarAcordoInput $input, Tenant $tenant, User $criadoPor): Acordo
    {
        // Guarda multi-tenant: o caso tem de pertencer ao próprio escritório.
        $caso = $this->casoRepository->findOneByIdDoTenant((int) $input->casoId, $tenant);

        if ($caso === null) {
            throw new CasoNaoEncontradoException((int) $input->casoId);
        }

        // Caso encerrado não aceita acordos (SPEC §17).
        if ($caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso->getId());
        }

        // Status ativo é o default da entidade.
        $acordo = new Acordo();
        $acordo->setTenant($tenant);
        $acordo->setCaso($caso);
        $acordo->setDataAcordo($input->dataAcordo);
        $acordo->setCriadoPor($criadoPor);

        foreach ($input->obrigacoesSubstituidasIds as $obrigacaoId) {
            $obrigacao = $this->obrigacaoRepository->findOneByIdDoTenant((int) $obrigacaoId, $tenant);

            if ($obrigacao === null) {
                throw new ObrigacaoNaoEncontradaException((int) $obrigacaoId);
            }

            // Invariável 13: o acordo só substitui obrigações do MESMO caso — identidade de instância.
            if ($obrigacao->getCaso() !== $caso) {
                throw new ObrigacaoDeOutroCasoException((int) $obrigacaoId, (int) $caso->getId());
            }

            // Só bloqueia se a obrigação está substituída por um acordo VIGENTE (ativo/cumprido).
            // Se o acordo substituto foi rompido/cancelado, a obrigação voltou ao exigível e PODE ser
            // renegociada de novo (SPEC §12 — "ainda não substituída por um acordo vigente").
            $substitutoAtual = $obrigacao->getAcordoSubstituto();

            if ($substitutoAtual !== null && $substitutoAtual->getStatus()->ehVigente()) {
                throw new ObrigacaoJaSubstituidaException((int) $obrigacaoId);
            }

            // Marca (nunca apaga — invariável 14); já gerenciada, salvar sem flush por clareza.
            $obrigacao->setAcordoSubstituto($acordo);
            $this->obrigacaoRepository->salvar($obrigacao);
        }

        foreach ($input->parcelas as $parcela) {
            $novaObrigacao = new Obrigacao();
            $novaObrigacao->setTenant($tenant);
            $novaObrigacao->setCaso($caso);
            $novaObrigacao->setDescricao(trim((string) $parcela->descricao));
            $novaObrigacao->setValorOriginal((int) $parcela->valor);
            $novaObrigacao->setVencimentoOriginal($parcela->vencimento);
            $novaObrigacao->setAcordoOrigem($acordo);
            $novaObrigacao->setCriadoPor($criadoPor);

            // Persiste sem flush; o registro do evento fecha a transação.
            $this->obrigacaoRepository->salvar($novaObrigacao);
        }

        // Persiste sem flush; o registro do evento fecha a transação (persiste tudo de uma vez).
        $this->acordoRepository->salvar($acordo);

        $substituidas = count($input->obrigacoesSubstituidasIds);
        $parcelas = count($input->parcelas);

        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::AcordoCriado,
            $criadoPor,
            sprintf(
                'Acordo criado: %d obrigação(ões) substituída(s) por %d parcela(s)',
                $substituidas,
                $parcelas,
            ),
            [
                'substituidas' => $substituidas,
                'parcelas' => $parcelas,
            ],
            flush: true,
        );

        return $acordo;
    }
}
