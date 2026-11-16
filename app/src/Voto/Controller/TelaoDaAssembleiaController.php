<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Voto\DTO\AssembleiaNoPainel;
use App\Voto\DTO\PautaNoPainel;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\UseCase\MontarDivulgacaoDaAssembleiaUseCase;
use App\Voto\UseCase\ObterAssembleiaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Telão (modo assembleia, desenho 18, classes .vAo*): tela cheia para projetar e conduzir a assembleia publicada.
 * Ordem do dia = as pautas da assembleia; QR e código de conferência reaproveitam a divulgação. Os números chegam
 * por um JSON sem dado pessoal (só contagens, peso e quórum). Resultado só depois de a pauta ser encerrada e
 * apurada (decisão C12), nunca parcial de votos. Mesma guarda e mesmo escopo de escritório da divulgação.
 *
 * Ciclo de vida (F1-3): a pauta retirada da ordem do dia sai do telão (ordem do dia, painéis e JSON); a assembleia
 * arquivada dá 404, como o desenho, que esconde "Conduzir no telão" nas arquivadas.
 */
#[Route('/voto/assembleias/{id}/telao', requirements: ['id' => '\d+'])]
final class TelaoDaAssembleiaController extends AbstractController
{
    public function __construct(
        private readonly GuardaDaEquipeDoVoto $guarda,
        private readonly ObterAssembleiaUseCase $obter,
        private readonly MontarDivulgacaoDaAssembleiaUseCase $montarDivulgacao,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('', name: 'voto_assembleia_telao', methods: ['GET'])]
    public function mostrar(int $id, Request $request): Response
    {
        $tenant = $this->guarda->exigir();
        $agora = new \DateTimeImmutable();
        try {
            $assembleia = $this->obter->executar($tenant, $id, $agora);
            $divulgacao = $this->montarDivulgacao->executar($tenant, $id);
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        }
        $pautas = self::naOrdemDoDia($assembleia);
        if (!$assembleia->publicada() || $assembleia->arquivada() || [] === $pautas) {
            throw $this->createNotFoundException();
        }

        return $this->render('voto/assembleia/telao.html.twig', [
            'a' => $assembleia,
            'pautas' => $pautas,
            'divulgacao' => $divulgacao,
            'selecionada' => self::escolher($pautas, $request->query->getInt('pauta')),
            'dados' => $this->dados($pautas, $agora),
            'agora' => $agora,
        ]);
    }

    /** Os números ao vivo de todas as pautas (consultado pelo JS a cada 5 s). Sem nome, e-mail nem unidade. */
    #[Route('/dados', name: 'voto_assembleia_telao_dados', methods: ['GET'])]
    public function dadosAoVivo(int $id): JsonResponse
    {
        $tenant = $this->guarda->exigir();
        $agora = new \DateTimeImmutable();
        try {
            $assembleia = $this->obter->executar($tenant, $id, $agora);
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        }
        if (!$assembleia->publicada() || $assembleia->arquivada()) {
            throw $this->createNotFoundException();
        }

        return $this->json(
            ['hora' => $agora->format('H:i:s'), 'pautas' => array_values($this->dados(self::naOrdemDoDia($assembleia), $agora))],
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store, private'],
        );
    }

    /**
     * A ordem do dia do telão: as pautas da assembleia sem as retiradas.
     *
     * @return list<PautaNoPainel>
     */
    private static function naOrdemDoDia(AssembleiaNoPainel $a): array
    {
        return array_values(array_filter($a->pautas, static fn (PautaNoPainel $p): bool => !$p->retirada()));
    }

    /**
     * A pedida, se existir; senão a primeira aberta, a primeira ainda não encerrada ou a última.
     *
     * @param non-empty-list<PautaNoPainel> $pautas
     */
    private static function escolher(array $pautas, int $pedida): int
    {
        foreach ($pautas as $p) {
            if ($p->id === $pedida) {
                return $p->id;
            }
        }
        foreach ($pautas as $p) {
            if ($p->aberta()) {
                return $p->id;
            }
        }
        foreach ($pautas as $p) {
            if (!$p->encerrada()) {
                return $p->id;
            }
        }

        return $pautas[array_key_last($pautas)]->id;
    }

    /**
     * @param list<PautaNoPainel> $pautas
     *
     * @return array<int, array<string, mixed>> por id da pauta
     */
    private function dados(array $pautas, \DateTimeImmutable $agora): array
    {
        $mapa = [];
        foreach ($pautas as $p) {
            $mapa[$p->id] = $this->dadosDaPauta($p, $agora);
        }

        return $mapa;
    }

    /** @return array<string, mixed> */
    private function dadosDaPauta(PautaNoPainel $p, \DateTimeImmutable $agora): array
    {
        $q = $p->quorumTexto();

        return [
            'id' => $p->id,
            'situacao' => $p->situacao(),
            'ciclo' => $p->ciclo(),
            'votantes' => $p->votantes,
            'aptos' => $p->unidadesAptas,
            'percentual' => $p->pctPesoTexto() . '%',
            'largura' => $p->larguraDaBarra(),
            'quorumMarca' => $p->exigeQuorum() ? $q : null,
            'quorumTexto' => $p->exigeQuorum()
                ? $this->tradutor->trans($p->quorumAtingido() ? 'voto.assembleia.gestor.telao.quorum_atingido' : 'voto.assembleia.gestor.telao.quorum_nao_atingido', ['q' => $q])
                : '',
            'segundos' => $p->segundosRestantes($agora),
        ];
    }
}
