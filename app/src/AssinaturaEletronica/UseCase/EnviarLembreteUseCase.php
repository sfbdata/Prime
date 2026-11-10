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
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;

/**
 * O lembrete da lista (contrato da etapa 2, seção 5.1): ao primeiro signatário pendente (com "em ordem", quem tem a
 * vez), só com o documento aguardando no prazo e no máximo um por hora por documento, contado pelos `lembrete_enviado`
 * da trilha. A regra é conferida com a linha travada e relida; o e-mail sai depois do COMMIT e só o e-mail entregue
 * grava `lembrete_enviado` (pelo correio). Falhou (ou ficou sem link, segredo trocado depois do envio): só
 * `email_nao_enviado`, a resposta é 422 e o lembrete não gasta a regra de 1 por hora.
 */
final readonly class EnviarLembreteUseCase
{
    private const INTERVALO_MINIMO = '-1 hour';

    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
        private EventoDaTrilhaRepository $eventos,
        private ChaveDaAssinatura $chave,
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

        $alvo = $this->documentos->emTransacao(function () use ($documento, $tenant, $agora): Signatario {
            if (!$this->documentos->travarERecarregar($documento, $tenant)) {
                throw new DocumentoNaoEncontrado();
            }
            $this->documentos->recarregarSignatarios($documento);

            return $this->alvo($documento, $agora);
        });

        $carta = CartaDaAssinatura::lembrete($alvo, $this->apresentacao->linkDoSignatario($alvo), $ator);
        if (!$this->correio->entregar([$carta])) {
            // Sem link é permanente (o mesmo aviso de sem chave do Copiar link); falha do envio, tente de novo.
            throw $carta->semLink()
                ? new AcaoRecusada('assinatura_eletronica.erro.sem_chave')
                : new AcaoRecusada('assinatura_eletronica.erro.email_nao_enviado', ['nome' => $alvo->getNome()]);
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
