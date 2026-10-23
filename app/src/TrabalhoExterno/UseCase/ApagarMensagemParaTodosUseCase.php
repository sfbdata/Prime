<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Tenant\Service\AuditoriaDaEdicaoSemPrazo;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Repository\MarcacaoDeMensagemRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Apagar para todos" uma mensagem do chat (spec VS 18.10.26 §3.4, desenho l.1233–1240). Quem: o
 * Master, em qualquer uma; o autor, nas próprias, até 60 min. A mensagem fica na conversa como
 * "Mensagem apagada por X em …", sem o texto, e deixa de ser favorita de todos.
 *
 * Edição sem prazo (spec `edicao-sem-prazo-master-primario.md`, item 8): o primário do escritório dono da demanda
 * também apaga, a qualquer tempo, e isso entra na auditoria.
 */
final class ApagarMensagemParaTodosUseCase
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly PoliticaDaDemanda $politica,
        private readonly MarcacaoDeMensagemRepository $marcacoes,
        private readonly EntityManagerInterface $em,
        private readonly AuditoriaDaEdicaoSemPrazo $auditoria,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, int $mensagemId): void
    {
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);
        $mensagem = null;
        foreach ($demanda->getMensagens() as $m) {
            if ((int) $m->getId() === $mensagemId && $this->politica->mensagemVisivel($ctx, $demanda, $m)) {
                $mensagem = $m;
            }
        }
        if ($mensagem === null) {
            throw new DemandaNaoEncontrada();
        }
        if (!$this->politica->podeApagarMensagem($ctx, $mensagem)) {
            throw new AcaoNaoPermitida('Só o autor (até 60 min após o envio) ou o Master apagam esta mensagem.');
        }

        $pelaRegra = $this->politica->apagaPelaRegraDeSempre($ctx, $mensagem);
        $this->auditoria->registrar(AuditoriaDaEdicaoSemPrazo::EXCLUIR, $ctx->usuario, $demanda->getTenant(), $pelaRegra, $mensagem::class, $mensagem->getId(), $mensagem->getAutor()?->getId());
        $mensagem->apagar($ctx->autor(), new \DateTimeImmutable());
        $this->marcacoes->tirarDasFavoritas($mensagem);
        $this->em->flush();
    }
}
