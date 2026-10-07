<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Tarefa\Tarefa;
use App\Pasta\DTO\PastaMetasResumoOutput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Twig\Environment;

/**
 * Resposta XHR de "Marcar como concluída" e "Reabrir meta" na aba Metas da pasta (PMC-031).
 *
 * A lista troca a linha sem recarregar a página, mas NADA é recontado no navegador: a aba
 * inteira (linhas, contagem dos filtros, trilho e drawer "Relatório da meta") volta renderizada
 * pelo MESMO `pasta/_metas.html.twig` e pelo MESMO `PastaMetasResumoOutput` que a `pasta_show`
 * usa — o pasta-metas.js só troca os pedaços. As contagens vão também em JSON, para quem lê a
 * resposta não precisar raspar HTML.
 *
 * Quem chama (`tarefa_concluir`, `tarefa_reabrir`) já aplicou a guarda e o CSRF; aqui só se
 * monta a resposta. A pasta é a da própria meta — nunca um id vindo da requisição.
 */
final class ListaDeMetasDaPastaEmJson
{
    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    /**
     * @param bool   $mudou    false quando a meta já estava no estado pedido (nada a desfazer)
     * @param string $mensagem o texto do aviso ("Meta concluída", "Meta reaberta"...)
     * @param ?\DateTimeImmutable $dataConclusaoAnterior no reabrir, a data que ele apagou (PMC-061).
     *        Só informativa: o "Desfazer" NÃO a devolve ao servidor — quem a restaura é a
     *        `ReaberturaDesfazivelDaMeta`, guardada na sessão.
     */
    public function responder(Tarefa $tarefa, bool $mudou, string $mensagem, ?\DateTimeImmutable $dataConclusaoAnterior = null): JsonResponse
    {
        $pasta  = $tarefa->getPasta();
        $resumo = PastaMetasResumoOutput::montar($pasta);

        $estado = null;
        foreach ($resumo->linhas as $linha) {
            if ($linha['tarefa']->getId() === $tarefa->getId()) {
                $estado = $linha['estado'];
                break;
            }
        }

        return new JsonResponse([
            'sucesso'   => true,
            'mudou'     => $mudou,
            'mensagem'  => $mensagem,
            'meta'      => (int) $tarefa->getId(),
            'estado'    => $estado,
            'dataConclusaoAnterior' => $dataConclusaoAnterior?->format(\DateTimeInterface::ATOM),
            'contagens' => [
                'abertas'    => $resumo->abertas,
                'atrasadas'  => $resumo->atrasadas,
                'concluidas' => $resumo->concluidas,
                'total'      => $resumo->total,
            ],
            'html'      => $this->twig->render('pasta/_metas.html.twig', [
                'pasta'       => $pasta,
                'metasResumo' => $resumo,
            ]),
        ]);
    }
}
