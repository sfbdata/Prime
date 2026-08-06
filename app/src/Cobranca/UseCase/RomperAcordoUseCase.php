<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\RomperAcordoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\AcordoComParcelasRenegociadasException;
use App\Cobranca\Exception\AcordoNaoAtivoException;
use App\Cobranca\Exception\AcordoNaoEncontradoException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\RestauradorObrigacoesOriginais;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Rompe MANUALMENTE um Acordo (SPEC §12.9): decisão do gestor quando o devedor descumpre o acordo.
 *
 * Romper e cancelar são os dois modos de desfazer um acordo, e nenhum dos dois APAGA. A diferença é
 * de VISIBILIDADE: o rompido aconteceu de verdade e foi descumprido, então some da seção de dívida em
 * aberto mas continua acessível em "Acordos encerrados", com as parcelas e o motivo; o cancelado some
 * de tudo e a rota dele dá 404 (`CancelarAcordoUseCase`).
 *
 * História: o acordo é resolvido por id + tenant (guarda multi-tenant) e só rompe se estiver `ativo`;
 * o motivo é obrigatório e fica registrado. O saldo continua sendo DERIVADO (invariável 20): a
 * `CalculadoraSaldo` restaura os originais e descarta as parcelas a partir do status. A única escrita
 * sobre as originais é o descongelamento do §D5, sem o qual a obrigação volta ao saldo mas com os
 * juros parados. O vínculo `acordoSubstituto` é PRESERVADO (INV-C5). Acordo e evento vão juntos.
 */
final class RomperAcordoUseCase
{
    public function __construct(
        private readonly AcordoRepository $acordoRepository,
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly RestauradorObrigacoesOriginais $restaurador,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(RomperAcordoInput $input, Tenant $tenant, User $usuario): Acordo
    {
        // Guarda multi-tenant: o acordo tem de pertencer ao próprio escritório.
        $acordo = $this->acordoRepository->findOneByIdDoTenant((int) $input->acordoId, $tenant);

        if ($acordo === null) {
            throw new AcordoNaoEncontradoException((int) $input->acordoId);
        }

        // Só um acordo ativo transiciona de estado (SPEC §12).
        if (!$acordo->estaAtivo()) {
            throw new AcordoNaoAtivoException((int) $acordo->getId(), $acordo->getStatus());
        }

        // Ajuste 9 §2.1: romper com parcelas que outro acordo vigente renegociou DUPLICARIA a dívida no
        // saldo — as originais que este acordo substituiu voltam ao exigível E as parcelas do acordo novo
        // continuam nele.
        //
        // 🔑 Deixou de ser "só dado legado" em 08/2026: com a prova da coluna F da planilha, o importador
        // de acordos passou a CRIAR esse estado de propósito — renegociar parcela de acordo é a operação
        // normal da contábil (spec `cobranca-acordo-assume-parcelas-do-anterior.md`). Este guard virou a
        // proteção PRINCIPAL contra o §2.1, não mais um alarme de acervo antigo.
        $renegociadas = $this->obrigacaoRepository->parcelasRenegociadasPorAcordoVigente($acordo);

        if ($renegociadas !== []) {
            throw new AcordoComParcelasRenegociadasException(
                (int) $acordo->getId(),
                (int) $renegociadas[0]->getAcordoSubstituto()?->getId(),
            );
        }

        // Por QUERY, nunca pela coleção inversa do acordo (ver `substituidasDe`).
        $substituidas = $this->restaurador->substituidasDe($acordo, $tenant);

        $motivo = trim((string) $input->motivo);
        $acordo->romper($motivo);

        // §D5: as originais voltam ao saldo por derivação, mas uma original CONGELADA volta com os juros
        // parados — `EncargosVivos::hidratar` pula congelada. Romper não apaga nem desvincula: só descongela.
        $restauradas = $this->restaurador->restaurar($substituidas);

        // Persiste sem flush; o registro do evento fecha a transação.
        $this->acordoRepository->salvar($acordo);

        $this->registrarEvento->registrar(
            $acordo->getCaso(),
            TipoEventoHistorico::AcordoRompido,
            $usuario,
            sprintf('Acordo rompido: %s', $motivo),
            ['motivo' => $motivo, 'obrigacoes_restauradas' => $restauradas],
            flush: true,
        );

        return $acordo;
    }
}
