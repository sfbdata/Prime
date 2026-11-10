<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Exception\AnaliseDeJustificativaRecusadaException;
use App\Ponto\UseCase\AnalisarJustificativaUseCase;
use App\Service\NotificacaoService;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * O abono do "Abonar selecionadas" da aba Equipe (spec ponto-desenho-0811, E5) é o mesmo da ficha do colaborador, com a
 * trava, a releitura, a batida do esquecimento, a trilha e o aviso à pessoa:
 * - a própria de quem analisa: `AnalisarJustificativaUseCase::abonarPorId` (E9), na transação dela, com o evento
 *   "Justificativa abonada";
 * - as da equipe: `AnalisarJustificativaUseCase::abonarDaEquipe`, numa transação só, com UM evento "Abono em lote"
 *   (desenho L818). A recusa de uma vira a frase dela e não desfaz as outras.
 *
 * O aviso do sininho ("Sua justificativa de ponto foi abonada.") sai depois do COMMIT, para cada abonada, como na ficha.
 */
#[AsAlias(id: AbonoDeJustificativa::class)]
final class AbonoPelaAnaliseDaJustificativa implements AbonoDeJustificativa
{
    public function __construct(
        private readonly AnalisarJustificativaUseCase $analise,
        private readonly NotificacaoService $notificacoes,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function abonar(JustificativaPonto $justificativa, Tenant $escritorio, User $analista): ?string
    {
        try {
            $abonada = $this->analise->abonarPorId((int) $justificativa->getId(), $analista, $escritorio);
        } catch (AnaliseDeJustificativaRecusadaException $recusa) {
            return $recusa->getMessage();
        }
        $this->avisar($abonada);

        return null;
    }

    public function abonarDaEquipe(array $daEquipe, Tenant $escritorio, User $analista): array
    {
        $resultado = $this->analise->abonarDaEquipe($daEquipe, $escritorio, $analista);
        foreach ($resultado->abonadas as $abonada) {
            $this->avisar($abonada);
        }

        return $resultado->recusas;
    }

    private function avisar(JustificativaPonto $abonada): void
    {
        $this->notificacoes->notificarJustificativaAprovada($abonada, $this->urls->generate('ponto_index'));
    }
}
