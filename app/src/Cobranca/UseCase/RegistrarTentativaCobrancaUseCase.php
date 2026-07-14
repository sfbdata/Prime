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
 * Registra um CONTATO de cobrança (ajuste 2026-07, supera SPEC §10) SÓ no histórico do Caso.
 *
 * História: o gestor liga/manda mensagem para o cobrado e anota quando, por qual canal e o desfecho
 * (tipicamente "Não atendido"). Isso é apenas um MOVIMENTO operacional: preserva a dívida e o prazo
 * originais (invariável 20) — NÃO cria obrigação nem altera valores. O caso é resolvido por id +
 * tenant (guarda multi-tenant); inexistente ou de outro escritório interrompe a operação
 * (CasoNaoEncontradoException). O único efeito é um EventoHistorico (tipo ContatoRealizado, com a
 * data informada como `ocorridoEm`), por isso o flush acontece aqui.
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

        // Só registra o movimento — não cria obrigação nem altera o valor original (invariável 20).
        // A descrição embute canal/data/resultado porque a timeline só exibe o texto (o payload `dados`
        // não é renderizado). A observação, quando houver, entra como sufixo.
        $descricao = sprintf(
            'Contato por %s em %s — %s.',
            $input->canal->label(),
            $input->dataContato->format('d/m/Y H:i'),
            $input->resultado->label(),
        );
        $observacao = trim((string) $input->observacao);
        if ($observacao !== '') {
            $descricao .= ' ' . $observacao;
        }

        $dados = [
            'canal' => $input->canal->value,
            'resultado' => $input->resultado->value,
        ];

        // Evento é a ÚNICA escrita do UseCase: flush aqui commita tudo. A data do contato vira a data
        // do evento na timeline (`ocorridoEm`), não o "agora" da submissão.
        $evento = $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::ContatoRealizado,
            $usuario,
            $descricao,
            $dados,
            flush: true,
            ocorridoEm: $input->dataContato,
        );

        return $evento;
    }
}
