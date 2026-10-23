<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use App\Entity\Auth\User;
use App\Master\DTO\DestinoDaResposta;
use App\Master\DTO\MensagemDaCentral;
use App\Master\DTO\RespostaDaCentralOutput;
use App\Master\Entity\CentralMasterResposta;
use App\Master\Exception\RespostaRecusada;
use App\Master\Notificacoes\OrigemDaCentral;
use App\Master\Notificacoes\OrigensDaCentral;
use App\Master\Repository\CentralMasterRespostaRepository;
use App\Service\NotificacaoService;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Enviar resposta" na Central de Notificações do Master (spec master-central-de-notificacoes.md, D5 a D7).
 *
 * Quem dispara: o Master, com a mensagem aberta. Com e-mail válido no destino, a resposta sai pelo Carteiro
 * (transacional, origem `central_master:resposta`, "Re: <assunto>", remetente "BlueJus", rodapé "Você escreveu ao
 * BlueJus"); o corpo é SÓ o texto da resposta, nunca a mensagem original (o e-mail do suporte não é confirmado).
 * Sem e-mail, vira aviso na Central do autor, no escritório da origem. Alerta não aceita resposta.
 *
 * Tudo numa transação: o envio, a resposta no histórico compartilhado e o que a origem guarda (a sugestão fica
 * atendida com a resposta). A auditoria do aviso cai no escritório da origem, nunca no da sessão do Master.
 */
final class ResponderNaCentralUseCase
{
    public const ORIGEM_DO_ENVIO = 'central_master:resposta';
    public const TIPO_NOTIFICACAO = 'central_master.resposta';
    public const MOTIVO_DO_ENVIO = 'Você escreveu ao BlueJus';
    public const TAMANHO_MAXIMO = 5000;

    public function __construct(
        private readonly OrigensDaCentral $origens,
        private readonly CentralMasterRespostaRepository $respostas,
        private readonly CarteiroInterface $carteiro,
        private readonly NotificacaoService $notificacoes,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDeAuditoria,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function executar(User $master, string $origem, int $idOrigem, string $texto): RespostaDaCentralOutput
    {
        $texto = $this->exigirTexto($texto);
        $leitor = $this->origens->da($origem) ?? throw new RespostaRecusada(RespostaRecusada::RECUSA_NAO_ENCONTRADA);
        $mensagem = $leitor->uma($idOrigem) ?? throw new RespostaRecusada(RespostaRecusada::RECUSA_NAO_ENCONTRADA);
        $destino = $this->exigirDestino($leitor, $mensagem);

        $resposta = $this->naConta($mensagem, fn (): CentralMasterResposta => $this->respostas->emTransacao(
            fn (): CentralMasterResposta => $this->entregarERegistrar($leitor, $mensagem, $destino, $texto, $master),
        ));

        return RespostaDaCentralOutput::daResposta($resposta, $master);
    }

    private function exigirTexto(string $texto): string
    {
        $texto = trim($texto);
        if ($texto === '') {
            throw new RespostaRecusada(RespostaRecusada::RECUSA_SEM_TEXTO);
        }
        if (mb_strlen($texto) > self::TAMANHO_MAXIMO) {
            throw new RespostaRecusada(RespostaRecusada::RECUSA_TEXTO_LONGO);
        }

        return $texto;
    }

    private function exigirDestino(OrigemDaCentral $leitor, MensagemDaCentral $mensagem): DestinoDaResposta
    {
        if (!$mensagem->podeResponder) {
            throw new RespostaRecusada(RespostaRecusada::RECUSA_NAO_ACEITA);
        }
        $destino = $leitor->destinoDaResposta($mensagem->idOrigem);
        if ($destino === null || (!$destino->porEmail() && !$destino->pelaCentral())) {
            throw new RespostaRecusada(RespostaRecusada::RECUSA_SEM_DESTINO);
        }

        return $destino;
    }

    private function entregarERegistrar(OrigemDaCentral $leitor, MensagemDaCentral $m, DestinoDaResposta $destino, string $texto, User $master): CentralMasterResposta
    {
        $this->preservarRespostaDaOrigem($leitor, $m);
        $resposta = $destino->porEmail()
            ? $this->porEmail($m, (string) $destino->email, $destino, $texto, $master)
            : $this->pelaCentral($m, $destino, $texto, $master);
        $leitor->registrarResposta($m->idOrigem, $texto, $master);
        $this->respostas->salvar($resposta, flush: true);

        return $resposta;
    }

    /**
     * A resposta dada antes pela página do Contribua só existe na sugestão, e o `registrarResposta` vai sobrescrevê-la:
     * antes, ela entra no histórico da Central (canal `contribua`, com quem e quando respondeu, sem destino).
     */
    private function preservarRespostaDaOrigem(OrigemDaCentral $leitor, MensagemDaCentral $m): void
    {
        $naOrigem = $leitor->respostaNaOrigem($m->idOrigem);
        if ($naOrigem === null) {
            return;
        }
        $textos = array_map(static fn (CentralMasterResposta $r): string => $r->getTexto(), $this->respostas->daMensagem($m->origem, $m->idOrigem));
        if (in_array($naOrigem->texto, $textos, true)) {
            return;
        }
        $this->respostas->salvar(new CentralMasterResposta($m->origem, $m->idOrigem, $m->tenantId, $naOrigem->texto, $naOrigem->por, CentralMasterResposta::CANAL_CONTRIBUA, null, null, $naOrigem->em));
    }

    private function porEmail(MensagemDaCentral $m, string $email, DestinoDaResposta $destino, string $texto, User $master): CentralMasterResposta
    {
        $envio = $this->carteiro->enviar(new MensagemDeEmail(
            para: $email,
            assunto: mb_substr('Re: ' . $m->assunto, 0, 200),
            corpoHtml: '<p>' . nl2br(htmlspecialchars($texto, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')) . '</p>',
            tipo: TipoDeEnvio::Transacional,
            origem: self::ORIGEM_DO_ENVIO,
            escopo: $destino->escritorio,
            nomeDoRemetente: 'BlueJus',
            motivoDoEnvio: self::MOTIVO_DO_ENVIO,
        ));

        return new CentralMasterResposta($m->origem, $m->idOrigem, $m->tenantId, $texto, $master, CentralMasterResposta::CANAL_EMAIL, MensagemDeEmail::normalizar($email), $envio->envioId);
    }

    private function pelaCentral(MensagemDaCentral $m, DestinoDaResposta $destino, string $texto, User $master): CentralMasterResposta
    {
        $usuario = $destino->usuario ?? throw new RespostaRecusada(RespostaRecusada::RECUSA_SEM_DESTINO);
        $escritorio = $destino->escritorio ?? throw new RespostaRecusada(RespostaRecusada::RECUSA_SEM_DESTINO);
        $this->notificacoes->criarNotificacao(
            $usuario,
            $escritorio,
            self::TIPO_NOTIFICACAO,
            mb_substr('Resposta do BlueJus: ' . $m->assunto, 0, 255),
            $this->urls->generate('contribua_index'),
            $texto,
        );

        return new CentralMasterResposta($m->origem, $m->idOrigem, $m->tenantId, $texto, $master, CentralMasterResposta::CANAL_CENTRAL, (string) $usuario->getId());
    }

    /**
     * @template T
     *
     * @param callable(): T $trabalho
     *
     * @return T
     */
    private function naConta(MensagemDaCentral $m, callable $trabalho): mixed
    {
        return $m->tenantId === null
            ? $this->escopoDeAuditoria->executar($trabalho)
            : $this->escopoDeAuditoria->executarComoConta($m->tenantId, $trabalho);
    }
}
