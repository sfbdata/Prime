<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\RegistrarTentativaCobrancaInput;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Registra uma tentativa de cobrança (boleto/valor atualizado enviado, com eventual novo prazo)
 * SÓ no histórico do Caso (SPEC §10).
 *
 * História: o gestor envia um boleto ou valor atualizado e combina, às vezes, um novo prazo. Isso é
 * apenas um MOVIMENTO operacional: preserva a dívida original e o prazo original (invariável 20) —
 * NÃO cria obrigação nem altera valores. O caso é resolvido por id + tenant (guarda multi-tenant);
 * inexistente ou de outro escritório interrompe a operação (CasoNaoEncontradoException). O único
 * efeito é um EventoHistorico, por isso o flush acontece aqui.
 */
final class RegistrarTentativaCobrancaUseCase
{
    public function __construct(
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(RegistrarTentativaCobrancaInput $input, Tenant $tenant, User $usuario): EventoHistorico
    {
        // Guarda multi-tenant: só um caso do próprio escritório pode receber a tentativa.
        $caso = $this->casoRepository->findOneByIdDoTenant((int) $input->casoId, $tenant);

        if ($caso === null) {
            throw new CasoNaoEncontradoException((int) $input->casoId);
        }

        // Caso encerrado não aceita novos lançamentos/movimentos (SPEC §17): fim do ciclo do Caso.
        // Nova inadimplência deve abrir um novo Caso, nunca reativar por mutação indireta.
        if ($caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso->getId());
        }

        // SPEC §10: só registra o movimento — não cria obrigação nem altera o valor original.
        $descricao = $input->observacao !== null && trim($input->observacao) !== ''
            ? trim($input->observacao)
            : 'Tentativa de cobrança registrada (boleto/valor atualizado enviado).';

        $dados = [
            'valorSolicitado' => $input->valorSolicitado,
            'novoPrazo' => $input->novoPrazo?->format('Y-m-d'),
        ];

        // Evento é a ÚNICA escrita do UseCase: flush aqui commita tudo.
        $evento = $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::BoletoEnviado,
            $usuario,
            $descricao,
            $dados,
            flush: true,
        );

        return $evento;
    }
}
