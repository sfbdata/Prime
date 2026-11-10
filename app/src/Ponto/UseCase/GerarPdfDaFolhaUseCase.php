<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\ConteudoDaFolha;
use App\Ponto\DTO\PdfDaFolhaOutput;
use App\Ponto\Service\ImagemDaFolha\DesenhoDaFolha;
use App\Ponto\Service\NomeDoArquivoDaFolha;
use App\Ponto\Service\PdfDeUmaImagem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A folha do mês em PDF, para o "Ver PDF" e o "Baixar PDF" da /ponto e para o gestor que exporta a folha de outro
 * (spec ponto-desenho-0811, E16, P34, P89, D17; decisões 2, 5 e 16).
 *
 * Quem: a própria pessoa, ou o gestor com permissão (conferida antes, no controller, como hoje).
 * Para quê: ter a folha do mês num PDF de uma página, em imagem, com código BJF, QR e as camadas de segurança.
 * Pré-condições: competência válida.
 * Fluxo principal: emite a folha ({@see EmitirFolhaDoPontoUseCase}: com o segredo, grava a emissão com código próprio e
 * o evento "Folha emitida"); desenha a página com o QR para a validação pública do código (D20); embrulha o JPEG num PDF
 * de uma página; devolve os bytes, o nome do arquivo e o código.
 * Fluxos alternativos: sem o segredo, a folha sai sem código, sem QR e sem nada gravado; competência inválida lança
 * `\InvalidArgumentException` antes de qualquer gravação.
 * Pós-condições: com o segredo, uma folha emitida a mais por PDF gerado (decisão 2: cada PDF, um código).
 * Regras: o nome do arquivo é o do desenho (L726): o nome sem acento, só letras, em maiúsculas.
 */
final class GerarPdfDaFolhaUseCase
{
    public function __construct(
        private readonly EmitirFolhaDoPontoUseCase $emitir,
        private readonly DesenhoDaFolha $desenho,
        private readonly PdfDeUmaImagem $pdf,
        private readonly UrlGeneratorInterface $rotas,
    ) {
    }

    /** @throws \InvalidArgumentException competência fora do calendário */
    public function executar(Tenant $escritorio, User $pessoa, int $ano, int $mes, User $emissor): PdfDaFolhaOutput
    {
        $conteudo = $this->emitir->executar($escritorio, $pessoa, $ano, $mes, $emissor);
        $codigo = $conteudo->validacao?->codigo;
        $jpeg = $this->desenho->gerar($conteudo, $this->enderecoDoQr($codigo), $this->enderecoDaPagina());

        return new PdfDaFolhaOutput(
            $this->pdf->embrulhar($jpeg, 'Folha de Ponto ' . $conteudo->competenciaFormatada()),
            self::nomeDoArquivo($conteudo),
            $codigo,
        );
    }

    /** "folha_ponto_MARIADASILVA-10-2026.pdf": o nome sem acento e só com letras, em maiúsculas (L726, P87). */
    public static function nomeDoArquivo(ConteudoDaFolha $conteudo): string
    {
        return sprintf('folha_ponto_%s-%02d-%04d.pdf', NomeDoArquivoDaFolha::doPdf($conteudo->cabecalho->nome), $conteudo->mes, $conteudo->ano);
    }

    /** O endereço absoluto da validação pública do código (a mesma rota do comprovante, D20). */
    private function enderecoDoQr(?string $codigo): ?string
    {
        return $codigo === null ? null : $this->rotas->generate('validacao_comprovante_ponto_codigo', ['codigo' => $codigo], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** A página de validação sem o protocolo, para o "Confira em ..." (como na imagem do comprovante). */
    private function enderecoDaPagina(): string
    {
        return (string) preg_replace('#^[a-z]+://#i', '', $this->rotas->generate('validacao_comprovante_ponto', [], UrlGeneratorInterface::ABSOLUTE_URL));
    }
}
