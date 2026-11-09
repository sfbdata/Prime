<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\DTO\CartaDaAssinatura;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\AssinaturaEletronica\Service\ApresentacaoDoDocumento;
use App\AssinaturaEletronica\Service\ChaveDaAssinatura;
use App\AssinaturaEletronica\Service\CorreioDaAssinatura;
use App\AssinaturaEletronica\Service\MascaraDeEmail;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;

/**
 * O lembrete da lista (contrato da etapa 2, seção 5.1): ao primeiro signatário pendente (com "em ordem", quem tem a
 * vez), só com o documento aguardando no prazo e no máximo um por hora por documento, contado pela trilha. O evento é
 * gravado com a linha travada; o e-mail sai depois do COMMIT e a falha dele vira o evento `email_nao_enviado`.
 */
final readonly class EnviarLembreteUseCase
{
    private const INTERVALO_MINIMO = '-1 hour';

    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
        private EventoDaTrilhaRepository $eventos,
        private ChaveDaAssinatura $chave,
        private TrilhaDoDocumento $trilha,
        private CorreioDaAssinatura $correio,
        private ApresentacaoDoDocumento $apresentacao,
        private ClockInterface $relogio,
    ) {
    }

    /**
     * @return array{ok: true, para: string} o nome de quem recebeu
     *
     * @throws DocumentoNaoEncontrado
     * @throws AcaoRecusada
     */
    public function executar(User $ator, Tenant $tenant, int $id): array
    {
        $this->chave->exigirConfigurada();
        $documento = $this->documentos->doEscritorioMesmoNaLixeira($id, $tenant) ?? throw new DocumentoNaoEncontrado();
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());

        $alvo = $this->documentos->emTransacao(function () use ($documento, $tenant, $ator, $agora): Signatario {
            if (!$this->documentos->travarERecarregar($documento, $tenant)) {
                throw new DocumentoNaoEncontrado();
            }
            $this->documentos->recarregarSignatarios($documento);
            $alvo = $this->alvo($documento, $agora);
            $this->trilha->acrescentar($documento, TipoDeEvento::LembreteEnviado, ['para' => MascaraDeEmail::mascarar((string) $alvo->getEmail())], $ator, $alvo);

            return $alvo;
        });

        $carta = CartaDaAssinatura::lembrete($alvo, (string) $this->apresentacao->linkDoSignatario($alvo), $ator);
        if (!$this->correio->entregar([$carta])) {
            throw new AcaoRecusada('assinatura_eletronica.erro.email_nao_enviado', ['nome' => $alvo->getNome()]);
        }

        return ['ok' => true, 'para' => $alvo->getNome()];
    }

    /** @throws AcaoRecusada */
    private function alvo(DocumentoParaAssinar $documento, \DateTimeImmutable $agora): Signatario
    {
        if ($documento->getSituacao() !== SituacaoDoDocumento::Aguardando || $documento->estaNaLixeira() || $documento->estaVencido($agora)) {
            throw new AcaoRecusada('assinatura_eletronica.erro.lembrete_fora_do_envio');
        }

        $alvo = $documento->primeiroPendente() ?? throw new AcaoRecusada('assinatura_eletronica.erro.lembrete_sem_pendente');
        $ultimo = $this->eventos->ultimoDoDocumento($documento, TipoDeEvento::LembreteEnviado);
        if ($ultimo !== null && $ultimo > $agora->modify(self::INTERVALO_MINIMO)) {
            throw new AcaoRecusada('assinatura_eletronica.erro.lembrete_recente');
        }

        return $alvo;
    }
}
