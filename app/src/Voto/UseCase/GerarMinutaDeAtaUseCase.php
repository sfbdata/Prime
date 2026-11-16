<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Shared\Service\GeradorDePdf;
use App\Voto\DTO\AtaDaAssembleia;
use App\Voto\DTO\MinutaDeAtaGerada;
use App\Voto\DTO\PautaNoPainel;
use App\Voto\Enum\FormatoDaMinuta;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\ModeloDeAtaInvalidoException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Service\ConversorDaMinutaParaDocx;
use App\Voto\Service\DocxDaMinuta;
use App\Voto\Service\HtmlDaMinutaDeAta;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gestor pede a minuta de ata (desenho 18, "Minuta de ata"): Word (.docx), com o papel timbrado do usuário se enviado, ou
 * PDF, da assembleia toda ou de uma votação só. O conteúdo é o da ata imprimível (ObterAtaDaAssembleiaUseCase), sem
 * carregar a relação nominal de votantes. Só com votação encerrada e apurada (o `status === 'apurada'` do desenho). Outro escritório e rascunho (como no resumo para divulgar): a
 * assembleia não existe para quem pede (AssembleiaNaoEncontradaException); sem votação apurada, OperacaoInvalidaNaAssembleiaException;
 * modelo que não serve, ModeloDeAtaInvalidoException. Gera na hora, sem gravar arquivo.
 */
final class GerarMinutaDeAtaUseCase
{
    public function __construct(
        private readonly ObterAtaDaAssembleiaUseCase $obterAta,
        private readonly HtmlDaMinutaDeAta $html,
        private readonly ConversorDaMinutaParaDocx $conversor,
        private readonly DocxDaMinuta $docx,
        private readonly GeradorDePdf $pdf,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    /**
     * @param ?int    $pautaId    a minuta só dessa votação (o desenho oferece por eleição); null = a assembleia toda
     * @param ?string $modeloDocx bytes do .docx com o papel timbrado; só vale para o formato Word
     *
     * @throws AssembleiaNaoEncontradaException
     * @throws OperacaoInvalidaNaAssembleiaException
     * @throws ModeloDeAtaInvalidoException
     */
    public function executar(Tenant $tenant, int $assembleiaId, string $emitidaPor, FormatoDaMinuta $formato, ?string $modeloDocx = null, ?int $pautaId = null, ?\DateTimeImmutable $agora = null): MinutaDeAtaGerada
    {
        $ata = $this->ataApurada($tenant, $assembleiaId, $emitidaPor, $pautaId, $agora);
        $nome = 'minuta-ata-' . $assembleiaId . (null === $pautaId ? '' : '-pauta-' . $pautaId) . '.' . $formato->value;

        if (FormatoDaMinuta::Pdf === $formato) {
            return new MinutaDeAtaGerada($this->pdf->gerar($this->html->folhaDoPdf($ata)), $formato, $nome, false);
        }
        $corpo = $this->conversor->converter($this->html->corpo($ata));

        return new MinutaDeAtaGerada($this->docx->montar($corpo, $modeloDocx), $formato, $nome, null !== $modeloDocx);
    }

    /** A ata sem a lista nominal, só com a votação pedida (ou todas), conferindo que há votação encerrada e apurada. */
    private function ataApurada(Tenant $tenant, int $assembleiaId, string $emitidaPor, ?int $pautaId, ?\DateTimeImmutable $agora): AtaDaAssembleia
    {
        $ata = $this->obterAta->executar($tenant, $assembleiaId, $emitidaPor, $agora, comVotantes: false);
        if (!$ata->assembleia->publicada()) {
            throw new AssembleiaNaoEncontradaException();
        }
        if (null !== $pautaId) {
            $existe = array_filter($ata->assembleia->pautas, static fn (PautaNoPainel $p): bool => $p->id === $pautaId && !$p->retirada());
            if ([] === $existe) {
                throw new AssembleiaNaoEncontradaException();
            }
            $ata = $ata->soComAPauta($pautaId);
        }
        $apuradas = array_filter($ata->assembleia->pautasEncerradas(), static fn (PautaNoPainel $p): bool => null === $pautaId || $p->id === $pautaId);
        if ([] === $apuradas) {
            throw new OperacaoInvalidaNaAssembleiaException($this->tradutor->trans('voto.assembleia.gestor.minuta.sem_votacao'));
        }

        return $ata;
    }
}
