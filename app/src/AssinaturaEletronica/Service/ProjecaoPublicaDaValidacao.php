<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\SituacaoDoSignatario;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O que a validação pública mostra de um documento achado pelo código (etapa 3, E3-7 e E3-8; desenho dc:160 e dc:457):
 * o nome, a situação com o rótulo que a tela já usa (Aguardando com o prazo passado é "Expirado", como na lista), o
 * hash do envio (o impresso no PDF) e, por signatário, o nome (o digitado ao assinar, senão o cadastrado), a data da
 * assinatura e o rótulo da situação dele.
 *
 * É resposta pública (spec 3.6): nunca CPF, e-mail, id, escritório nem link. Só os campos abaixo saem daqui.
 */
final readonly class ProjecaoPublicaDaValidacao
{
    private const ROTULO_DO_DOCUMENTO = 'assinatura_eletronica_tela.js.situacao.';
    private const ROTULO_DO_SIGNATARIO = 'assinatura_eletronica_tela.js.sig.';

    public function __construct(
        private TranslatorInterface $tradutor,
        private ClockInterface $relogio,
    ) {
    }

    /**
     * @return array{codigo: string, nome: string, situacao: string, hash: ?string, signatarios: list<array{nome: string, assinadoEm: ?string, situacao: string}>}
     */
    public function de(DocumentoParaAssinar $documento): array
    {
        return [
            'codigo' => (string) $documento->getCodigo(),
            'nome' => $documento->getNome(),
            'situacao' => $this->situacaoDoDocumento($documento),
            'hash' => $documento->getHashEnviado(),
            'signatarios' => array_map($this->signatario(...), $documento->getSignatarios()),
        ];
    }

    /** O rótulo da lista: "Expirado" é calculado (Aguardando com o prazo passado), como o `stA` da tela. */
    private function situacaoDoDocumento(DocumentoParaAssinar $documento): string
    {
        $situacao = $documento->estaVencido($this->relogio->now()) ? 'expirado' : $documento->getSituacao()->value;

        return $this->tradutor->trans(self::ROTULO_DO_DOCUMENTO . $situacao);
    }

    /** @return array{nome: string, assinadoEm: ?string, situacao: string} */
    private function signatario(Signatario $signatario): array
    {
        $assinadoEm = $signatario->getSituacao() === SituacaoDoSignatario::Assinado ? $signatario->getAssinadoEm() : null;

        return [
            'nome' => self::nomeDe($signatario),
            'assinadoEm' => $assinadoEm?->format(\DATE_ATOM),
            'situacao' => $this->tradutor->trans(self::ROTULO_DO_SIGNATARIO . $signatario->getSituacao()->value),
        ];
    }

    /** `nomeAss || nome` do desenho: o nome digitado ao assinar, quando houver. */
    private static function nomeDe(Signatario $signatario): string
    {
        $digitado = $signatario->getNomeDigitado();

        return $digitado !== null && $digitado !== '' ? $digitado : $signatario->getNome();
    }
}
