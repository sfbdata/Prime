<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\MarcacaoDeMensagem;
use App\TrabalhoExterno\Entity\MensagemDemanda;
use App\TrabalhoExterno\Enum\OperacaoDeMensagem;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\MarcacaoDeMensagemRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Seleção no chat: lixeira, restaurar, excluir definitivo e favorito (`_chatLote()` do desenho,
 * spec VS 18.10.26 §3.4). Só nas mensagens que o usuário vê; vale só para ele.
 */
final class MarcarMensagensEmLoteUseCase
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly PoliticaDaDemanda $politica,
        private readonly MarcacaoDeMensagemRepository $marcacoes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<int> $ids
     *
     * @return array{aplicados: int, texto: string}
     */
    public function executar(Contexto $ctx, string $codigo, array $ids, OperacaoDeMensagem $op): array
    {
        if ($ctx->previa) {
            throw new AcaoNaoPermitida('Na pré-visualização não é possível alterar nada.');
        }
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);
        $mensagens = $this->visiveisEntre($ctx, $demanda, $ids);
        $existentes = $this->marcacoes->daDemanda($demanda, $ctx->usuario);
        $agora = new \DateTimeImmutable();

        foreach ($mensagens as $id => $m) {
            $marcacao = $existentes[$id] ?? new MarcacaoDeMensagem($m, $ctx->usuario);
            $marcacao->aplicar($op, $agora);
            $this->marcacoes->salvar($marcacao);
        }
        $this->em->flush();

        return ['aplicados' => count($mensagens), 'texto' => $op->aviso(count($mensagens))];
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, MensagemDemanda>
     */
    private function visiveisEntre(Contexto $ctx, DemandaExterna $demanda, array $ids): array
    {
        $pedidas = array_flip($ids);
        $mensagens = [];
        foreach ($demanda->getMensagens() as $m) {
            if (isset($pedidas[(int) $m->getId()]) && $this->politica->mensagemVisivel($ctx, $demanda, $m)) {
                $mensagens[(int) $m->getId()] = $m;
            }
        }

        return $mensagens;
    }
}
