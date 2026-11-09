<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\DTO\ResumoDoDocumento;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\EventoDaTrilha;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\TipoDeLogo;
use App\Entity\Tenant\Tenant;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * As formas `Resumo` e `Detalhe` do JSON da tela (contrato da etapa 1, seção 4.1). Datas em ISO 8601; o texto de cada
 * evento sai do catálogo, com os parâmetros guardados no próprio evento.
 */
final readonly class ApresentacaoDoDocumento
{
    public const LOGO_BLUEJUS = 'images/assinatura/logo-bluejus.png';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $tradutor,
        private Packages $assets,
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
            'signatarios' => array_map(self::signatario(...), $documento->getSignatarios()),
            'eventos' => array_map(fn (EventoDaTrilha $e): array => [
                'em' => self::iso($e->getCriadoEm()),
                'texto' => $this->tradutor->trans($e->getTipo()->chaveDoTexto(), $e->getDados()),
            ], $eventos),
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

    private function urlDaLogo(DocumentoParaAssinar $documento): ?string
    {
        return match ($documento->getLogoTipo()) {
            TipoDeLogo::Bluejus => $this->urlDaLogoBluejus(),
            TipoDeLogo::Escritorio => $this->urlDaLogoDoEscritorio($documento->getTenant()),
            TipoDeLogo::Propria => $documento->getLogoChave() !== null
                ? $this->urls->generate('assinatura_eletronica_logo', ['id' => $documento->getId()])
                : null,
        };
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

    /** @return array<string, mixed> */
    private static function signatario(Signatario $s): array
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
        ];
    }

    private static function iso(?\DateTimeImmutable $data): ?string
    {
        return $data?->format(\DATE_ATOM);
    }
}
