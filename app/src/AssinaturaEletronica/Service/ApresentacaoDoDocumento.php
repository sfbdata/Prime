<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\DTO\LocalizacaoDoSignatario;
use App\AssinaturaEletronica\DTO\ResumoDoDocumento;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\EventoDaTrilha;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Enum\TipoDeLogo;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\Entity\Tenant\Tenant;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * As formas `Resumo` e `Detalhe` do JSON da tela (contrato da etapa 1, seção 4.1). Datas em ISO 8601; o texto de cada
 * evento sai do catálogo, com os parâmetros guardados no próprio evento e o `{nome}` de quem o evento trata.
 *
 * Etapa 2 (contrato da etapa 2, seção 5.1): o `Detalhe` traz o hash e as datas do envio e, por signatário, o link do
 * signatário (só enquanto ele pode assinar e com o segredo configurado), a rubrica, o motivo da recusa e o nome
 * digitado ao assinar.
 *
 * Etapa 3 (contrato da etapa 3, seção 2, item 11): `codigo`, o código de validação do selo, no `Resumo` e por ele no
 * `Detalhe`; nulo no rascunho.
 *
 * Etapa 5, bloco 5A: `jaAssinado`, o quadro "Documento já assinado" do detalhe (dc:218) a partir da versão mais recente
 * do PDF que chegou assinado (o original ou o último devolvido), com a URL de "Baixar original com as assinaturas";
 * nulo sem ela.
 */
final readonly class ApresentacaoDoDocumento
{
    public const LOGO_BLUEJUS = 'images/assinatura/logo-bluejus.png';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $tradutor,
        private Packages $assets,
        private ChaveDaAssinatura $chave,
        private VersaoDoPdfRepository $versoes,
        private ApresentacaoDaConferencia $conferencia,
    ) {
    }

    /**
     * O `Resumo` da lista e do detalhe: a mesma forma, venha da consulta da lista ou da entidade.
     *
     * @return array<string, mixed>
     */
    public function resumo(ResumoDoDocumento $resumo): array
    {
        return [
            'id' => $resumo->id,
            'nome' => $resumo->nome,
            'categoria' => $resumo->categoria,
            'pasta' => $resumo->pasta,
            'situacao' => $resumo->situacao->value,
            'criadoEm' => self::iso($resumo->criadoEm),
            'venceEm' => self::iso($resumo->venceEm),
            'cor' => $resumo->cor->value,
            'naLixeira' => $resumo->naLixeira,
            'codigo' => $resumo->codigo,
            'assinados' => $resumo->contarAssinados(),
            'total' => \count($resumo->signatarios),
            'signatarios' => array_map(
                static fn (array $s): array => ['nome' => $s['nome'], 'situacao' => $s['situacao']->value],
                $resumo->signatarios,
            ),
        ];
    }

    /**
     * @param list<EventoDaTrilha> $eventos do mais recente para o mais antigo
     *
     * @return array<string, mixed>
     */
    public function detalhe(DocumentoParaAssinar $documento, array $eventos): array
    {
        return array_merge($this->resumo(ResumoDoDocumento::deDocumento($documento)), [
            'modelo' => $documento->getModeloChave(),
            'blocos' => $documento->getBlocos(),
            'layout' => $documento->getLayout(),
            'lateral' => $documento->getLateral(),
            'folhaBranca' => $documento->isFolhaBranca(),
            'emOrdem' => $documento->isEmOrdem(),
            'mensagem' => $documento->getMensagem(),
            'prazoDias' => $documento->getPrazoDias(),
            'logo' => ['tipo' => $documento->getLogoTipo()->value, 'url' => $this->urlDaLogo($documento)],
            'arquivo' => $this->arquivo($documento),
            'jaAssinado' => $this->jaAssinado($documento),
            'hashEnviado' => $documento->getHashEnviado(),
            'enviadoEm' => self::iso($documento->getEnviadoEm()),
            'concluidoEm' => self::iso($documento->getConcluidoEm()),
            'signatarios' => array_map($this->signatario(...), $documento->getSignatarios()),
            'eventos' => array_map(fn (EventoDaTrilha $e): array => [
                'em' => self::iso($e->getCriadoEm()),
                'texto' => $this->tradutor->trans($e->getTipo()->chaveDoTexto(), self::parametrosDoTexto($e)),
            ], $eventos),
        ]);
    }

    /**
     * O link do signatário (URL absoluta com o token): só com o documento aguardando, o signatário pendente, o segredo
     * configurado e o token recalculado batendo com o `token_hash` guardado no envio (depois de uma troca do segredo,
     * nulo: a tela mostra o aviso de sem chave e o e-mail não sai). O token é derivado a cada chamada, nunca guardado.
     */
    public function linkDoSignatario(Signatario $signatario): ?string
    {
        $documento = $signatario->getDocumento();
        if ($documento->getSituacao() !== SituacaoDoDocumento::Aguardando || $documento->estaNaLixeira() || !$signatario->estaPendente()) {
            return null;
        }
        $token = $this->chave->tokenConferido($signatario);

        return $token !== null
            ? $this->urls->generate('assinatura_eletronica_publica', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL)
            : null;
    }

    /** A logo da folha vista de dentro do escritório (detalhe e prévia). */
    public function urlDaLogo(DocumentoParaAssinar $documento): ?string
    {
        return match ($documento->getLogoTipo()) {
            TipoDeLogo::Bluejus => $this->urlDaLogoBluejus(),
            TipoDeLogo::Escritorio => $this->urlDaLogoDoEscritorio($documento->getTenant()),
            TipoDeLogo::Propria => $documento->getLogoChave() !== null
                ? $this->urls->generate('assinatura_eletronica_logo', ['id' => $documento->getId()])
                : null,
        };
    }

    /** A rubrica de quem assinou, vista de dentro do escritório. */
    public function urlDaRubrica(Signatario $signatario): ?string
    {
        if ($signatario->getRubricaChave() === null) {
            return null;
        }

        return $this->urls->generate('assinatura_eletronica_rubrica', [
            'id' => $signatario->getDocumento()->getId(),
            'ordem' => $signatario->getOrdem(),
        ]);
    }

    /** A logo da BlueJus, arquivo estático. */
    public function urlDaLogoBluejus(): string
    {
        return $this->assets->getUrl(self::LOGO_BLUEJUS);
    }

    /** A logo de Meu Escritório, quando há. */
    public function urlDaLogoDoEscritorio(Tenant $tenant): ?string
    {
        $chave = $tenant->getLogoChave();
        if ($chave === null || $chave === '') {
            return null;
        }

        return $this->urls->generate('meu_escritorio_logo', ['tenantId' => $tenant->getId()]);
    }

    /** @return array{url: string, nome: string, paginas: ?int}|null */
    private function arquivo(DocumentoParaAssinar $documento): ?array
    {
        if (!$documento->temArquivo()) {
            return null;
        }

        return [
            'url' => $this->urls->generate('assinatura_eletronica_arquivo', ['id' => $documento->getId()]),
            'nome' => (string) $documento->getArquivoEnviadoNome(),
            'paginas' => $documento->getArquivoEnviadoPaginas(),
        ];
    }

    /**
     * O `pres` do desenho: a versão atual do PDF que chegou assinado
     * ({@see VersaoDoPdfRepository::ultimaQueNaoEParaAssinar()}).
     *
     * @return array<string, mixed>|null
     */
    private function jaAssinado(DocumentoParaAssinar $documento): ?array
    {
        $versao = $this->versoes->ultimaQueNaoEParaAssinar($documento);
        if ($versao === null) {
            return null;
        }
        $quadro = $this->conferencia->quadro(SerializacaoDaConferencia::deJson($versao->getAnalise()));

        return $quadro !== null
            ? $quadro + ['original' => $this->urls->generate('assinatura_eletronica_original', ['id' => $documento->getId()])]
            : null;
    }

    /** @return array<string, mixed> */
    private function signatario(Signatario $s): array
    {
        return [
            'id' => $s->getId(),
            'ordem' => $s->getOrdem(),
            'nome' => $s->getNome(),
            'email' => $s->getEmail(),
            'cpf' => $s->getCpf(),
            'papel' => $s->getPapel(),
            'nivel' => $s->getNivel()->value,
            'situacao' => $s->getSituacao()->value,
            'assinadoEm' => self::iso($s->getAssinadoEm()),
            'metodo' => $s->getMetodo(),
            'link' => $this->linkDoSignatario($s),
            'rubrica' => $this->urlDaRubrica($s),
            'recusadoMotivo' => $s->getMotivoRecusa(),
            'nomeDigitado' => $s->getNomeDigitado(),
            // Etapa 7: se há selfie guardada (a imagem não sai no Detalhe) e a localização como gravada.
            'selfie' => $s->getSelfieChave() !== null,
            'localizacao' => $s->getLatitude() !== null && $s->getLongitude() !== null
                ? ['latitude' => $s->getLatitude(), 'longitude' => $s->getLongitude(), 'precisao' => $s->getPrecisao()]
                : null,
        ];
    }

    /**
     * Os parâmetros do texto do evento: os valores simples de `dados` (o ICU não recebe lista) e o `{nome}` do
     * signatário ou do usuário do evento, quando o evento não traz um nome próprio (o "Duplicado de {nome}" traz).
     * Com latitude e longitude (assinatura Reforçada, etapa 7), também `{local}` com 5 casas, como a tela do desenho.
     * Como o desenho (L375, L390): o envio mostra os 16 primeiros caracteres do hash (`{hash_inicio}`), e a assinatura,
     * o nome que o signatário digitou ao assinar (o cadastrado só na falta dele).
     *
     * @return array<string, scalar>
     */
    private static function parametrosDoTexto(EventoDaTrilha $evento): array
    {
        $parametros = array_filter($evento->getDados(), is_scalar(...));
        if (!isset($parametros['nome'])) {
            $signatario = $evento->getSignatario();
            $digitado = $evento->getTipo() === TipoDeEvento::Assinado ? $signatario?->getNomeDigitado() : null;
            $nome = $digitado ?? $signatario?->getNome() ?? $evento->getUsuario()?->getFullName();
            if ($nome !== null) {
                $parametros['nome'] = $nome;
            }
        }
        if (\is_string($parametros['latitude'] ?? null) && \is_string($parametros['longitude'] ?? null)) {
            $parametros['local'] = LocalizacaoDoSignatario::textoDe($parametros['latitude'], $parametros['longitude']);
        }
        if (\is_string($parametros['hash'] ?? null)) {
            $parametros['hash_inicio'] = substr($parametros['hash'], 0, 16);
        }

        return $parametros;
    }

    private static function iso(?\DateTimeImmutable $data): ?string
    {
        return $data?->format(\DATE_ATOM);
    }
}
