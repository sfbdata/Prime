<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\DTO\CartaDaAssinatura;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\AssinaturaEletronica\Service\ApresentacaoDoDocumento;
use App\AssinaturaEletronica\Service\ChaveDaAssinatura;
use App\AssinaturaEletronica\Service\ConferenciaDoEnvio;
use App\AssinaturaEletronica\Service\ConteudoCanonicoDoDocumento;
use App\AssinaturaEletronica\Service\CorreioDaAssinatura;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;

/**
 * "Gerar links e enviar" (contrato da etapa 2, seções 4.1 e 6): com a linha travada e relida, confere o documento e os
 * signatários, congela o conteúdo (hash canônico), grava o sha256 do token de cada signatário e registra o envio.
 * Depois do COMMIT, o convite por e-mail: com "em ordem" só ao primeiro; sem, a todos. O link do convite é remontado e
 * conferido com o `token_hash` recém-gravado; sem link, o convite não sai (`email_nao_enviado`).
 */
final readonly class EnviarDocumentoUseCase
{
    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
        private EventoDaTrilhaRepository $eventos,
        private ConteudoCanonicoDoDocumento $conteudo,
        private ChaveDaAssinatura $chave,
        private TrilhaDoDocumento $trilha,
        private CorreioDaAssinatura $correio,
        private ApresentacaoDoDocumento $apresentacao,
        private ClockInterface $relogio,
    ) {
    }

    /**
     * @return array<string, mixed> o `Detalhe` do documento enviado
     *
     * @throws DocumentoNaoEncontrado
     * @throws AcaoRecusada
     */
    public function executar(User $ator, Tenant $tenant, int $id): array
    {
        $this->chave->exigirConfigurada();
        $documento = $this->documentos->doEscritorioMesmoNaLixeira($id, $tenant) ?? throw new DocumentoNaoEncontrado();
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());

        $this->documentos->emTransacao(function () use ($documento, $tenant, $ator, $agora): void {
            if (!$this->documentos->travarERecarregar($documento, $tenant)) {
                throw new DocumentoNaoEncontrado();
            }
            $this->documentos->recarregarSignatarios($documento);
            $documento->exigirEnviavel();
            ConferenciaDoEnvio::exigir($documento);

            $hash = $this->conteudo->hash($documento);
            $documento->enviar($hash, $agora);
            foreach ($documento->getSignatarios() as $signatario) {
                $signatario->definirTokenHash(ChaveDaAssinatura::hashDoToken($this->chave->token($signatario)));
            }
            $this->trilha->acrescentar($documento, TipoDeEvento::DocumentoEnviado, ['hash' => $hash], $ator);
        });

        $this->correio->entregar(array_map(
            fn (Signatario $s): CartaDaAssinatura => CartaDaAssinatura::convite($s, $this->apresentacao->linkDoSignatario($s), $ator),
            self::convidados($documento),
        ));

        return $this->apresentacao->detalhe($documento, $this->eventos->doDocumento($documento));
    }

    /** @return list<Signatario> */
    private static function convidados(DocumentoParaAssinar $documento): array
    {
        $signatarios = $documento->getSignatarios();

        return $documento->isEmOrdem() ? \array_slice($signatarios, 0, 1) : $signatarios;
    }
}
