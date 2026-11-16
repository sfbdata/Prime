<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Shared\Service\GeradorDePdf;
use App\Voto\DTO\MinutaDeAtaGerada;
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
 * PDF. O conteúdo é o da ata imprimível (ObterAtaDaAssembleiaUseCase), sem a relação nominal de votantes. Só com ao
 * menos uma votação encerrada e apurada (o `status === 'apurada'` do desenho). Outro escritório e rascunho (como no resumo para divulgar): a
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
     * @param ?string $modeloDocx bytes do .docx com o papel timbrado; só vale para o formato Word
     *
     * @throws AssembleiaNaoEncontradaException
     * @throws OperacaoInvalidaNaAssembleiaException
     * @throws ModeloDeAtaInvalidoException
     */
    public function executar(Tenant $tenant, int $assembleiaId, string $emitidaPor, FormatoDaMinuta $formato, ?string $modeloDocx = null, ?\DateTimeImmutable $agora = null): MinutaDeAtaGerada
    {
        $ata = $this->obterAta->executar($tenant, $assembleiaId, $emitidaPor, $agora);
        if (!$ata->assembleia->publicada()) {
            throw new AssembleiaNaoEncontradaException();
        }
        if ([] === $ata->assembleia->pautasEncerradas()) {
            throw new OperacaoInvalidaNaAssembleiaException($this->tradutor->trans('voto.assembleia.gestor.minuta.sem_votacao'));
        }
        $nome = 'minuta-ata-' . $assembleiaId . '.' . $formato->value;

        if (FormatoDaMinuta::Pdf === $formato) {
            return new MinutaDeAtaGerada($this->pdf->gerar($this->html->folhaDoPdf($ata)), $formato, $nome, false);
        }
        $corpo = $this->conversor->converter($this->html->corpo($ata));

        return new MinutaDeAtaGerada($this->docx->montar($corpo, $modeloDocx), $formato, $nome, null !== $modeloDocx);
    }
}
