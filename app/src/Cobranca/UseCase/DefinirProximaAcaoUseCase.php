<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\DefinirProximaAcaoInput;
use App\Cobranca\Entity\ProximaAcao;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\ProximaAcaoAtivaJaExisteException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Define a Próxima Ação operacional de um Caso de Cobrança (SPEC §14): a "próxima coisa a fazer"
 * escolhida MANUALMENTE pelo gestor (verificar pagamento, contatar, enviar boleto, preparar
 * ajuizamento...).
 *
 * História: o caso é resolvido por id + tenant (guarda multi-tenant); um caso já encerrado (§17) não
 * recebe ações. O invariável de negócio §14 exige NO MÁXIMO uma ação pendente por caso — se já houver
 * uma ativa, o gestor deve concluí-la antes. A ação nova nasce pendente, com o gestor como responsável
 * e criador. Próxima ação NÃO é evento de histórico (§13) nem alerta (§14, derivado): nada disso é
 * gravado aqui.
 */
final class DefinirProximaAcaoUseCase
{
    public function __construct(
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly ProximaAcaoRepository $proximaAcaoRepository,
    ) {
    }

    public function executar(DefinirProximaAcaoInput $input, Tenant $tenant, User $usuario): ProximaAcao
    {
        // Guarda multi-tenant: o caso tem de pertencer ao próprio escritório.
        $caso = $this->casoRepository->findOneByIdDoTenant((int) $input->casoId, $tenant);

        if ($caso === null) {
            throw new CasoNaoEncontradoException((int) $input->casoId);
        }

        // Caso encerrado não aceita novos lançamentos/ações (SPEC §17).
        if ($caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso->getId());
        }

        // Invariável §14: no máximo uma ação pendente por caso.
        if ($this->proximaAcaoRepository->findAtivaDoCaso($caso) !== null) {
            throw new ProximaAcaoAtivaJaExisteException((int) $caso->getId());
        }

        $acao = (new ProximaAcao())
            ->setTenant($caso->getTenant())
            ->setCaso($caso)
            ->setDescricao((string) $input->descricao)
            ->setPrazo($input->prazo)
            ->setResponsavel($usuario)
            ->setCriadoPor($usuario);

        $this->proximaAcaoRepository->salvar($acao, flush: true);

        return $acao;
    }
}
